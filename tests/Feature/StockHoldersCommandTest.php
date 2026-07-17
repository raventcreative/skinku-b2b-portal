<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
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

    public function test_cari_yang_tak_cocok_gagal_dengan_jelas(): void
    {
        $this->product();

        $this->assertSame(1, Artisan::call('stock:holders', ['cari' => 'TIDAK-ADA']));
        $this->assertStringContainsString('Tidak ada produk yang cocok', Artisan::output());
    }
}
