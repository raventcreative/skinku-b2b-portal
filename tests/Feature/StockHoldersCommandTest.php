<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StockHoldersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function partner(string $co): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => $co, 'fullname' => $co, 'username' => "sh{$n}", 'email' => "sh{$n}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => $co,
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'MIZU BODY WASH - 500ml', 'sku' => 'MZ-1', 'hq_stock' => 1000,
            'status' => 'active', 'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
    }

    public function test_membongkar_pemegang_stok_termasuk_akun_terhapus(): void
    {
        $p = $this->product();
        $hidup = $this->partner('Toko Aktif');
        $mati = $this->partner('Toko Dihapus');

        Inventory::create(['user_id' => $hidup->id, 'product_id' => $p->id, 'quantity' => 95]);
        Inventory::create(['user_id' => $mati->id, 'product_id' => $p->id, 'quantity' => 200]);

        // Hapus mitra — soft delete, barisnya inventory sengaja tak disentuh.
        $mati->delete();

        // Artisan::call + output(), bukan $this->artisan(): yang terakhir memakai
        // OutputStyle tiruan yang tak memuat isi table() — assertion-nya lolos/gagal
        // menurut mock, bukan menurut apa yang benar-benar tercetak.
        $this->assertSame(0, Artisan::call('stock:holders', ['cari' => 'MIZU']));
        $out = Artisan::output();

        $this->assertStringContainsString('Toko Aktif', $out);
        $this->assertStringContainsString('Toko Dihapus', $out);   // tetap kelihatan, tak jadi baris tanpa nama
        $this->assertStringContainsString('DIHAPUS', $out);        // ditandai sebagai akun terhapus
        $this->assertStringContainsString('Stok Mitra (angka di grafik): 295', $out);
        $this->assertStringContainsString('200 unit dipegang 1 akun', $out);
    }

    /**
     * Menegaskan perilaku yang bikin bingung: menghapus mitra TIDAK mengurangi
     * angka "Stok Mitra" di grafik. Kalau suatu saat kita putuskan untuk
     * mengecualikannya, test ini yang harus gagal lebih dulu.
     */
    public function test_grafik_stok_mitra_masih_menghitung_akun_terhapus(): void
    {
        $p = $this->product();
        $hidup = $this->partner('Toko Aktif');
        $mati = $this->partner('Toko Dihapus');

        Inventory::create(['user_id' => $hidup->id, 'product_id' => $p->id, 'quantity' => 95]);
        Inventory::create(['user_id' => $mati->id, 'product_id' => $p->id, 'quantity' => 200]);
        $mati->delete();

        $baris = collect(app(ReportService::class)->inventoryMonitoring(12))
            ->firstWhere('label', 'MIZU BODY WASH - 500ml');

        // 95 + 200 = 295 — akun terhapus tetap ikut terhitung.
        $this->assertSame(295, $baris['partner_stock']);
    }

    public function test_trace_menunjukkan_po_asal_dan_menandai_yang_sudah_dihapus(): void
    {
        $p = $this->product();
        $mitra = $this->partner('ARMUZNAH MART');

        $poHidup = PurchaseOrder::create([
            'po_number' => 'SKN-PO-HIDUP', 'created_by' => $mitra->id, 'user_id' => $mitra->id,
            'company_name' => 'ARMUZNAH MART', 'user_role' => User::ROLE_DISTRIBUTOR,
            'status' => PurchaseOrder::STATUS_COMPLETED, 'total_amount' => 100,
        ]);
        $poHapus = PurchaseOrder::create([
            'po_number' => 'SKN-PO-DIHAPUS', 'created_by' => $mitra->id, 'user_id' => $mitra->id,
            'company_name' => 'ARMUZNAH MART', 'user_role' => User::ROLE_DISTRIBUTOR,
            'status' => PurchaseOrder::STATUS_COMPLETED, 'total_amount' => 200,
        ]);

        StockMovement::create([
            'product_id' => $p->id, 'user_id' => $mitra->id, 'movement_type' => StockMovement::TYPE_PO_FULFILLMENT,
            'quantity' => 95, 'before_qty' => 0, 'after_qty' => 95,
            'reference_type' => 'purchase_order', 'reference_id' => $poHidup->id,
        ]);
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => $mitra->id, 'movement_type' => StockMovement::TYPE_PO_FULFILLMENT,
            'quantity' => 200, 'before_qty' => 95, 'after_qty' => 295,
            'reference_type' => 'purchase_order', 'reference_id' => $poHapus->id,
        ]);
        Inventory::create(['user_id' => $mitra->id, 'product_id' => $p->id, 'quantity' => 295]);

        // PO dihapus SETELAH stoknya keluar — stok tidak ditarik balik.
        $poHapus->delete();

        $this->assertSame(0, Artisan::call('stock:holders', ['cari' => 'MIZU', '--trace' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('SKN-PO-HIDUP', $out);
        $this->assertStringContainsString('SKN-PO-DIHAPUS', $out);   // withTrashed: tak jadi "PO ?"
        $this->assertStringContainsString('DIHAPUS', $out);
        $this->assertStringContainsString('Ada PO terhapus di riwayat ini', $out);
        // Saldo cocok dengan gerakan → tak boleh ada peringatan selisih.
        $this->assertStringNotContainsString('ada perubahan tanpa jejak', $out);
    }

    public function test_trace_menandai_saldo_yang_tak_punya_jejak_gerakan(): void
    {
        $p = $this->product();
        $mitra = $this->partner('Tanpa Jejak');
        Inventory::create(['user_id' => $mitra->id, 'product_id' => $p->id, 'quantity' => 295]);

        $this->assertSame(0, Artisan::call('stock:holders', ['cari' => 'MIZU', '--trace' => true]));
        $this->assertStringContainsString('TANPA satu pun gerakan stok', Artisan::output());
    }

    public function test_trace_menandai_saldo_yang_tak_cocok_dengan_gerakan(): void
    {
        $p = $this->product();
        $mitra = $this->partner('Selisih');

        StockMovement::create([
            'product_id' => $p->id, 'user_id' => $mitra->id, 'movement_type' => StockMovement::TYPE_PO_FULFILLMENT,
            'quantity' => 95, 'before_qty' => 0, 'after_qty' => 95,
        ]);
        // Inventory 295 tapi gerakan berhenti di 95 — 200 unit masuk tanpa jejak.
        Inventory::create(['user_id' => $mitra->id, 'product_id' => $p->id, 'quantity' => 295]);

        $this->assertSame(0, Artisan::call('stock:holders', ['cari' => 'MIZU', '--trace' => true]));
        $this->assertStringContainsString('ada perubahan tanpa jejak', Artisan::output());
    }

    public function test_cari_yang_tak_cocok_gagal_dengan_jelas(): void
    {
        $this->product();

        $this->assertSame(1, Artisan::call('stock:holders', ['cari' => 'TIDAK-ADA']));
        $this->assertStringContainsString('Tidak ada produk yang cocok', Artisan::output());
    }
}
