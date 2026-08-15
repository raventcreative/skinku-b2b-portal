<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\OkrBusinessSnapshotService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OkrSellerExclusionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function product(int $hqStock = 1000): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => $hqStock, 'status' => 'active',
        ]);
    }

    private function stock(User $u, Product $p, int $qty): void
    {
        Inventory::create(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => $qty]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function snapshotService(): OkrBusinessSnapshotService
    {
        return app(OkrBusinessSnapshotService::class);
    }

    /** Bikin PO selesai. seller null (HQ) kalau buyer tanpa upline; inter-partner kalau buyer punya upline (stok upline disiapkan). */
    private function completedPo(User $buyer, Product $p, int $qty): void
    {
        if ($buyer->upline_id) {
            $seller = User::find($buyer->upline_id);
            $this->stock($seller, $p, $qty);
        }
        $po = $this->svc()->createForPartner($buyer, [['product_id' => $p->id, 'qty' => $qty]], null, null);
        if ($buyer->upline_id) {
            // Routing Model X mati — createForPartner tak lagi set seller_id.
            // Set manual di sini biar skenario inter-partner (dorman) tetap
            // teruji (lihat InterPartnerFulfillmentTest, Task 1).
            $po->seller_id = $buyer->upline_id;
            $po->save();
        }
        $this->svc()->complete($po);
    }

    public function test_okr_omzet_distributor_kecualikan_inter_partner_tapi_funnel_tetap_inklusif(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();

        // Urutan WAJIB: inter-partner dulu, baru HQ→grand — completedPo() men-seed stok upline
        // via Inventory::create (raw insert, bukan upsert). Kalau HQ→grand jalan duluan, grand
        // sudah dikreditkan Inventory oleh PurchaseOrderService::complete(), dan insert kedua
        // (seed stok upline utk PO inter-partner) akan tabrakan unique(user_id, product_id).
        // Pola sama seperti HqReportSellerExclusionTest::completedPo() (Task 1).
        $this->completedPo($dist, $p, 5);   // grand→dist (inter-partner): dist AKTIF beli, BUKAN omzet HQ
        $this->completedPo($grand, $p, 3);  // HQ→grand: seller_id null, ikut omzet/status-count HQ

        $input = ['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()];

        // --- CMO: snapshot distributor (omzet + funnel) ---
        $snap = $this->snapshotService()->for('cmo', $admin, $input);
        $distributor = $snap['distributor'];

        // distributorSnapshot() di-scope ke user_role=DISTRIBUTOR — $dist satu-satunya
        // pembeli berperan distributor di fixture ini, dan SELURUH PO-nya inter-partner.
        // Omzet (uang) HARUS 0 walau dia jelas bertransaksi (uang inter-partner dibuang).
        $allDistributorRolePoTotal = (float) PurchaseOrder::where('user_role', User::ROLE_DISTRIBUTOR)
            ->where('status', PurchaseOrder::STATUS_COMPLETED)
            ->sum('total_amount');
        $this->assertGreaterThan(0, $allDistributorRolePoTotal); // ada transaksi dist yg harus dibuang dari uang HQ
        $this->assertSame(0.0, (float) $distributor['omzet_selesai']);
        $this->assertLessThan($allDistributorRolePoTotal, $distributor['omzet_selesai']);
        $this->assertSame([], $distributor['top_distributor']);

        // Funnel TETAP inklusif (spec A4/carve-out): dist yang cuma beli dari upline
        // tetap terhitung "pernah PO" (onboarding < terdaftar) dan "aktif 30 hari".
        $this->assertLessThan($distributor['terdaftar'], $distributor['onboarding']);
        $this->assertGreaterThanOrEqual(1, $distributor['aktif_30_hari']);

        // --- COO: status-count PO juga HQ-only (filter kedua di Step 3) ---
        $snapCoo = $this->snapshotService()->for('coo', $admin, $input);
        $expectedCompleted = (int) PurchaseOrder::whereNull('seller_id')
            ->where('status', PurchaseOrder::STATUS_COMPLETED)->count();
        $allCompletedCount = (int) PurchaseOrder::where('status', PurchaseOrder::STATUS_COMPLETED)->count();
        $this->assertSame($expectedCompleted, (int) ($snapCoo['purchase_order'][PurchaseOrder::STATUS_COMPLETED] ?? 0));
        $this->assertLessThan($allCompletedCount, $snapCoo['purchase_order'][PurchaseOrder::STATUS_COMPLETED]);
    }

    /** Varian completedPo(): selesai TAPI ditandai TEMPO & tetap belum lunas (piutang). */
    private function completedTempoPo(User $buyer, Product $p, int $qty): PurchaseOrder
    {
        if ($buyer->upline_id) {
            $seller = User::find($buyer->upline_id);
            $this->stock($seller, $p, $qty);
        }
        $po = $this->svc()->createForPartner($buyer, [['product_id' => $p->id, 'qty' => $qty]], null, null);
        if ($buyer->upline_id) {
            // Routing Model X mati — createForPartner tak lagi set seller_id.
            // Set manual di sini biar skenario inter-partner (dorman) tetap
            // teruji (lihat InterPartnerFulfillmentTest, Task 1).
            $po->seller_id = $buyer->upline_id;
            $po->save();
        }
        $this->svc()->complete($po);
        $this->svc()->setTempo($po->fresh(), true, 'tes piutang tempo', now()->addDays(14)->toDateString());

        return $po->fresh();
    }

    public function test_okr_piutang_tempo_cfo_kecualikan_po_tempo_inter_partner(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();

        // Urutan WAJIB sama seperti test di atas: inter-partner dulu, baru HQ→grand
        // (completedTempoPo() men-seed stok upline via Inventory::create raw insert —
        // lihat komentar completedPo() soal tabrakan unique(user_id, product_id)).
        $poInterPartner = $this->completedTempoPo($dist, $p, 2);   // dist beli dari grand: seller_id = grand->id
        $poHq = $this->completedTempoPo($grand, $p, 1);            // grand beli dari HQ: seller_id null

        $input = ['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()];
        $cfo = $this->snapshotService()->for('cfo', $admin, $input);
        $piutang = $cfo['piutang_tempo'];

        // Ada 2 PO tempo & belum lunas total — tapi HANYA satu (HQ, seller_id null)
        // yang boleh dihitung sebagai piutang HQ. Uang inter-partner dibuang (spec A4),
        // sama seperti omzet_selesai/status-count PO di test sebelumnya.
        $allTempoUnpaidCount = (int) PurchaseOrder::where('is_tempo', true)
            ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DELETED])
            ->count();
        $this->assertSame(2, $allTempoUnpaidCount);
        $this->assertSame(1, $piutang['jumlah_po']);
        $this->assertLessThan($allTempoUnpaidCount, $piutang['jumlah_po']);

        // sisa_tagihan HARUS sama persis dengan PO HQ saja (belum ada cicilan sama
        // sekali → sisa = total_amount), BUKAN gabungan kedua PO tempo.
        $totalSemuaTempoUnpaid = (float) $poInterPartner->total_amount + (float) $poHq->total_amount;
        $this->assertGreaterThan((float) $poHq->total_amount, $totalSemuaTempoUnpaid); // ada nominal inter-partner yg harus dibuang
        $this->assertEqualsWithDelta((float) $poHq->total_amount, $piutang['sisa_tagihan'], 0.01);
        $this->assertLessThan($totalSemuaTempoUnpaid, $piutang['sisa_tagihan']);
    }
}
