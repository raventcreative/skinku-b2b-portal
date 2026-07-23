<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Tools\RingkasDashboardTool;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 3: alat baca ringkas_dashboard mengembalikan angka NYATA dari ReportService.
 */
class AiToolsTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function tool(): RingkasDashboardTool
    {
        return new RingkasDashboardTool(app(ReportService::class));
    }

    public function test_db_kosong_balikin_struktur_dan_nol(): void
    {
        $out = $this->tool()->run([], $this->super());

        // Struktur lengkap + aman saat data kosong (bukan crash).
        foreach (['bulan', 'penjualan_total', 'jumlah_po', 'po_selesai', 'mitra_aktif', 'stok_hq_unit', 'distribusi_status_po', 'stok_menipis'] as $k) {
            $this->assertArrayHasKey($k, $out);
        }
        $this->assertSame(0, $out['jumlah_po']);
        $this->assertSame(0, $out['stok_menipis']['jumlah']);
        $this->assertNotEmpty($out['bulan']);
    }

    public function test_stok_menipis_terdeteksi(): void
    {
        $sa = $this->super();
        $p = Product::create(['name' => 'Serum X', 'sku' => 'SRX-1', 'status' => Product::STATUS_ACTIVE, 'hq_stock' => 5]);
        Inventory::create(['user_id' => $sa->id, 'product_id' => $p->id, 'quantity' => 2, 'minimum_stock' => 10]);

        $out = $this->tool()->run([], $sa);

        $this->assertSame(1, $out['stok_menipis']['jumlah']);
        $this->assertSame('Serum X', $out['stok_menipis']['contoh'][0]['produk']);
        $this->assertSame(2, $out['stok_menipis']['contoh'][0]['sisa']);
    }

    public function test_bulan_tertentu_diterima(): void
    {
        $out = $this->tool()->run(['bulan' => '2026-06'], $this->super());
        $this->assertStringContainsString('2026', $out['bulan']);
    }

    public function test_bukan_alat_tulis(): void
    {
        $this->assertFalse($this->tool()->isWrite());
        $this->assertSame('ringkas_dashboard', $this->tool()->name());
    }
}
