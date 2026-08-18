<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommissionEngineTest extends TestCase
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

    private function product(): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 1000, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function commissionSvc(): CommissionService
    {
        return app(CommissionService::class);
    }

    /** PO selesai dengan subtotal PERSIS senilai $subtotal (qty=1, harga di-override). */
    private function completedPoValue(User $buyer, Product $p, float $subtotal): PurchaseOrder
    {
        $po = $this->svc()->createForPartner(
            $buyer,
            [['product_id' => $p->id, 'qty' => 1]],
            null,
            null,
            [$p->id => $subtotal],
        );

        return $this->svc()->complete($po);
    }

    private function commissionFor(User $u, PurchaseOrder $po): float
    {
        return (float) Commission::where('user_id', $u->id)->where('source_po_id', $po->id)->sum('amount');
    }

    public function test_override_1_tingkat_ke_upline_langsung(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $reseller = $this->user(User::ROLE_RESELLER_BRONZE, $dist->id);

        // Reseller order 100.000 ke HQ → HANYA Distributor (upline langsung) dapat 4% = 4000.
        $po = $this->completedPoValue($reseller, $this->product(), 100000);

        $this->assertEqualsWithDelta(4000, $this->commissionFor($dist, $po), 0.01);
        $this->assertSame(0.0, $this->commissionFor($grand, $po)); // TIDAK naik ke Grand
        $this->assertSame(0.0, $this->commissionFor($reseller, $po)); // pembeli tak dapat
        $this->assertSame(1, Commission::where('source_po_id', $po->id)->count()); // tepat 1 baris
        $this->assertSame('override', Commission::where('source_po_id', $po->id)->value('type'));
    }

    public function test_grand_dapat_6_persen_dari_order_distributor(): void
    {
        // Skenario inti model: Distributor pesan ke HQ 1jt → Grand (upline langsung) dapat 6% = 60.000.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        $po = $this->completedPoValue($dist, $this->product(), 1000000);

        $this->assertEqualsWithDelta(60000, $this->commissionFor($grand, $po), 0.01);
        $this->assertSame(0.0, $this->commissionFor($dist, $po)); // pembeli tak dapat
    }

    public function test_order_pertama_pun_override_bukan_join(): void
    {
        // Tak ada lagi perlakuan khusus "order pertama = join". Order pertama pun = override.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        $po = $this->completedPoValue($dist, $this->product(), 100000); // order PERTAMA dist

        $this->assertEqualsWithDelta(6000, $this->commissionFor($grand, $po), 0.01); // 6% override, bukan 10% join
        $this->assertSame('override', Commission::where('source_po_id', $po->id)->value('type'));
        $this->assertSame(0, Commission::where('type', 'join')->count()); // tak ada join sama sekali
    }

    public function test_po_inter_partner_tak_hasilkan_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->completedPoValue($dist, $this->product(), 100000);
        // Jadikan PO ini "inter-partner" (dorman) lalu panggil ulang service → guard harus tolak
        $po->seller_id = $grand->id;
        $po->save();
        Commission::where('source_po_id', $po->id)->delete(); // bersihkan komisi dari complete() sebelumnya
        $this->commissionSvc()->recordForCompletedPo($po->fresh());
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }

    public function test_rate_dari_appsetting(): void
    {
        AppSetting::put('komisi_persen_grand_distributor', '10');

        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        // Dist order → upline langsung = Grand. Rate custom grand 10% → 10.000 (bukan default 6%).
        $po = $this->completedPoValue($dist, $this->product(), 100000);

        $this->assertEqualsWithDelta(10000, $this->commissionFor($grand, $po), 0.01);
    }

    public function test_pembeli_tanpa_upline_nol_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null
        $po = $this->completedPoValue($grand, $this->product(), 100000);
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }

    public function test_idempoten_tidak_dobel(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->completedPoValue($dist, $this->product(), 100000); // override ke grand

        $before = Commission::where('source_po_id', $po->id)->count();
        $this->assertGreaterThan(0, $before); // sanity: complete() sudah menulis komisi via hook

        $this->commissionSvc()->recordForCompletedPo($po); // panggil manual ke-2 kali
        $after = Commission::where('source_po_id', $po->id)->count();

        $this->assertSame($before, $after); // tidak dobel
    }

    public function test_saldo_jumlah_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $this->completedPoValue($dist, $this->product(), 100000); // override ke grand

        $expected = (float) Commission::where('user_id', $grand->id)->where('status', 'saldo')->sum('amount');
        $this->assertGreaterThan(0, $expected);
        $this->assertEqualsWithDelta($expected, $this->commissionSvc()->balance($grand), 0.01);
    }

    public function test_backdated_sale_tak_hasilkan_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $p = $this->product();

        // Tanpa cutoff di-set → lewat jalur NORMAL complete() (bukan skip-stok
        // pra-opname), jadi tes ini benar-benar menguji flag recordCommission=false,
        // bukan kebetulan lolos lewat guard lain.
        $po = $this->svc()->recordBackdatedSale(
            $dist, [['product_id' => $p->id, 'qty' => 2]], Carbon::parse('2026-08-01'), 'backfill', $admin->id,
        );

        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $po->status);
        $this->assertFalse((bool) $po->fresh()->stock_skipped); // konfirmasi: memang jalur normal
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }
}
