<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PoPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'SA', 'fullname' => 'Super Admin', 'username' => 'sa1', 'email' => 'sa1@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function mitra(): User
    {
        return User::create([
            'name' => 'AM', 'fullname' => 'Armuznah', 'username' => 'am1', 'email' => 'am1@skinku.test',
            'password' => Hash::make('secret123'), 'company_name' => 'ARMUZNAH MART',
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(int $hq): Product
    {
        return Product::create([
            'name' => 'MIZU BODY WASH - 500ml', 'sku' => 'MZ-500ML', 'hq_stock' => $hq,
            'status' => 'active', 'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
    }

    /** PO 300 botol ke mitra: HQ 646 → 346, mitra 0 → 300. */
    private function skenario(int $hqSekarang = 346, int $mitraSekarang = 300): array
    {
        $p = $this->product($hqSekarang);
        $m = $this->mitra();

        $po = PurchaseOrder::create([
            'po_number' => 'SKN-PO-20260627-5881', 'created_by' => $m->id, 'user_id' => $m->id,
            'company_name' => 'ARMUZNAH MART', 'user_role' => User::ROLE_DISTRIBUTOR,
            'status' => PurchaseOrder::STATUS_COMPLETED, 'total_amount' => 6_000_000,
        ]);

        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_OUT,
            'quantity' => 300, 'before_qty' => 646, 'after_qty' => 346,
            'reference_type' => 'purchase_order', 'reference_id' => $po->id,
        ]);
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => $m->id, 'movement_type' => StockMovement::TYPE_PO_FULFILLMENT,
            'quantity' => 300, 'before_qty' => 0, 'after_qty' => 300,
            'reference_type' => 'purchase_order', 'reference_id' => $po->id,
        ]);
        Inventory::create(['user_id' => $m->id, 'product_id' => $p->id, 'quantity' => $mitraSekarang]);

        return [$p, $m, $po];
    }

    public function test_purge_mengembalikan_stok_membuang_jejak_dan_menghapus_permanen(): void
    {
        [$p, $m, $po] = $this->skenario();
        $sa = $this->superAdmin();

        $this->assertSame(0, Artisan::call('po:purge', [
            'nomor' => 'SKN-PO-20260627-5881', '--force' => true, '--as' => $sa->username,
        ]));

        // Stok kembali seperti sebelum PO ada.
        $this->assertSame(646, (int) $p->fresh()->hq_stock);
        $this->assertSame(0, (int) Inventory::where('user_id', $m->id)->where('product_id', $p->id)->value('quantity'));

        // Jejaknya benar-benar hilang, bukan sekadar dikompensasi gerakan lawan.
        $this->assertSame(0, StockMovement::where('reference_type', 'purchase_order')->where('reference_id', $po->id)->count());
        $this->assertNull(PurchaseOrder::withTrashed()->find($po->id));

        // Penghapusan permanen wajib tercatat pelakunya.
        $log = AuditLog::where('action', 'purge_po')->first();
        $this->assertNotNull($log);
        $this->assertSame($sa->id, (int) $log->performed_by);
        $this->assertSame($sa->email, $log->performed_by_email);
        // Isi PO tetap terekam walau PO-nya sendiri sudah lenyap permanen.
        $this->assertSame('SKN-PO-20260627-5881', $log->before_data['po_number']);
    }

    /**
     * Persis situasi nyata: PO menaruh 300, lalu 5 keluar lewat gerakan LAIN.
     * Membatalkan 300 dari saldo 295 = -5. Harus DITOLAK, bukan dibulatkan.
     */
    public function test_menolak_bila_saldo_jadi_negatif_karena_gerakan_di_luar_po(): void
    {
        [$p, $m, $po] = $this->skenario(mitraSekarang: 295);
        $sa = $this->superAdmin();

        StockMovement::create([
            'product_id' => $p->id, 'user_id' => $m->id, 'movement_type' => StockMovement::TYPE_OUT,
            'quantity' => 5, 'before_qty' => 300, 'after_qty' => 295, 'notes' => 'Retur rusak',
        ]);

        $this->assertSame(1, Artisan::call('po:purge', [
            'nomor' => 'SKN-PO-20260627-5881', '--force' => true, '--as' => $sa->username,
        ]));
        $this->assertStringContainsString('DIBATALKAN', Artisan::output());

        // TIDAK ADA yang berubah — separuh koreksi lebih berbahaya daripada nol koreksi.
        $this->assertSame(346, (int) $p->fresh()->hq_stock);
        $this->assertSame(295, (int) Inventory::where('user_id', $m->id)->value('quantity'));
        $this->assertNotNull(PurchaseOrder::withTrashed()->find($po->id));
        $this->assertSame(2, StockMovement::where('reference_id', $po->id)->count());
    }

    public function test_dry_run_adalah_default_dan_tidak_mengubah_apa_pun(): void
    {
        [$p, $m, $po] = $this->skenario();

        $this->assertSame(0, Artisan::call('po:purge', ['nomor' => 'SKN-PO-20260627-5881']));
        $out = Artisan::output();

        $this->assertStringContainsString('SIMULASI', $out);
        $this->assertStringContainsString('346 → 646', $out);
        $this->assertStringContainsString('300 → 0', $out);

        $this->assertSame(346, (int) $p->fresh()->hq_stock);
        $this->assertSame(300, (int) Inventory::where('user_id', $m->id)->value('quantity'));
        $this->assertNotNull(PurchaseOrder::withTrashed()->find($po->id));
    }

    public function test_force_tanpa_pelaku_ditolak(): void
    {
        [$p] = $this->skenario();

        $this->assertSame(1, Artisan::call('po:purge', ['nomor' => 'SKN-PO-20260627-5881', '--force' => true]));
        $this->assertStringContainsString('--as=<username> wajib', Artisan::output());
        $this->assertSame(346, (int) $p->fresh()->hq_stock);
    }

    public function test_hanya_super_admin_yang_boleh(): void
    {
        [$p, $m] = $this->skenario();

        $this->assertSame(1, Artisan::call('po:purge', [
            'nomor' => 'SKN-PO-20260627-5881', '--force' => true, '--as' => $m->username,
        ]));
        $this->assertStringContainsString('bukan super admin', Artisan::output());
        $this->assertSame(346, (int) $p->fresh()->hq_stock);
    }

    public function test_po_yang_sudah_soft_delete_tetap_bisa_dipurge(): void
    {
        [$p, $m, $po] = $this->skenario();
        $sa = $this->superAdmin();
        $po->delete(); // persis kondisi nyata: sudah dihapus dari daftar

        $this->assertSame(0, Artisan::call('po:purge', [
            'nomor' => 'SKN-PO-20260627-5881', '--force' => true, '--as' => $sa->username,
        ]));

        $this->assertSame(646, (int) $p->fresh()->hq_stock);
        $this->assertSame(0, (int) Inventory::where('user_id', $m->id)->value('quantity'));
    }

    public function test_nomor_po_tak_dikenal_gagal_dengan_jelas(): void
    {
        $this->assertSame(1, Artisan::call('po:purge', ['nomor' => 'SKN-PO-NGAWUR']));
        $this->assertStringContainsString('tidak ditemukan', Artisan::output());
    }
}
