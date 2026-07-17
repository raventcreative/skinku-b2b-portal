<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Berangkat dari kerusakan nyata: po:purge menyetel products.hq_stock langsung
 * tanpa menulis gerakan, sehingga saldo (646) berselisih 300 dari riwayatnya
 * (346). Riwayat yang benar — tiap gerakannya punya tanggal, sebab, dan pelaku.
 */
class StockReconcileHqTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'SA', 'fullname' => 'Super Admin', 'username' => 'superadmin', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Riwayat berhenti di 346, tapi saldonya 646 — persis kondisi MIZU. */
    private function product(int $saldo = 646, int $riwayat = 346): Product
    {
        $p = Product::create([
            'name' => 'MIZU BODY WASH - 500ml', 'sku' => 'MZ-500ML', 'hq_stock' => $saldo,
            'status' => 'active', 'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);

        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_OUT,
            'quantity' => 1, 'before_qty' => $riwayat + 1, 'after_qty' => $riwayat,
            'reference_type' => 'tiktok_order', 'reference_id' => 15121,
        ]);

        return $p;
    }

    public function test_simulasi_menemukan_selisih_tanpa_mengubah_apa_pun(): void
    {
        $p = $this->product();

        $this->assertSame(0, Artisan::call('stock:reconcile-hq', ['cari' => 'MIZU']));
        $out = Artisan::output();

        $this->assertStringContainsString('SIMULASI', $out);
        $this->assertStringContainsString('646', $out);
        $this->assertStringContainsString('346', $out);
        $this->assertStringContainsString('+300', $out);   // saldo KELEBIHAN 300

        $this->assertSame(646, (int) $p->fresh()->hq_stock);
    }

    public function test_force_menyetel_saldo_mengikuti_riwayat_dan_tercatat(): void
    {
        $p = $this->product();
        $sa = $this->superAdmin();

        $this->assertSame(0, Artisan::call('stock:reconcile-hq', [
            'cari' => 'MIZU', '--force' => true, '--as' => $sa->username,
        ]));

        $this->assertSame(346, (int) $p->fresh()->hq_stock);

        $log = AuditLog::where('action', 'reconcile_hq_stock')->first();
        $this->assertNotNull($log);
        $this->assertSame($sa->id, (int) $log->performed_by);
        $this->assertSame(646, $log->before_data['hq_stock']);
        $this->assertSame(346, $log->after_data['hq_stock']);
    }

    /**
     * Yang diperbaiki adalah perubahan yang memang tak berjejak; menambahinya
     * gerakan koreksi justru mengarang kejadian yang tak pernah ada di gudang.
     */
    public function test_tidak_menulis_gerakan_stok_baru(): void
    {
        $this->product();
        $sa = $this->superAdmin();
        $sebelum = StockMovement::count();

        Artisan::call('stock:reconcile-hq', ['cari' => 'MIZU', '--force' => true, '--as' => $sa->username]);

        $this->assertSame($sebelum, StockMovement::count());
    }

    public function test_produk_yang_sudah_cocok_tidak_disentuh(): void
    {
        $p = $this->product(saldo: 346, riwayat: 346);

        $this->assertSame(0, Artisan::call('stock:reconcile-hq', ['cari' => 'MIZU']));
        $this->assertStringContainsString('sudah cocok', Artisan::output());
        $this->assertSame(346, (int) $p->fresh()->hq_stock);
    }

    /** Riwayat kosong bukan bukti stok nol — jangan nolkan stok yang mungkin benar. */
    public function test_produk_tanpa_gerakan_sama_sekali_dilewati(): void
    {
        $p = Product::create([
            'name' => 'PRODUK BARU', 'sku' => 'PB-1', 'hq_stock' => 500,
            'status' => 'active', 'cogs' => 1000, 'price_distributor' => 2000, 'price_reseller' => 2500,
        ]);
        $sa = $this->superAdmin();

        Artisan::call('stock:reconcile-hq', ['cari' => 'PRODUK BARU', '--force' => true, '--as' => $sa->username]);

        $this->assertSame(500, (int) $p->fresh()->hq_stock);
    }

    public function test_force_tanpa_pelaku_ditolak(): void
    {
        $p = $this->product();

        $this->assertSame(1, Artisan::call('stock:reconcile-hq', ['cari' => 'MIZU', '--force' => true]));
        $this->assertStringContainsString('--as wajib diisi', Artisan::output());
        $this->assertSame(646, (int) $p->fresh()->hq_stock);
    }

    public function test_hanya_super_admin(): void
    {
        $p = $this->product();
        $biasa = User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'adminbiasa', 'email' => 'ab@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertSame(1, Artisan::call('stock:reconcile-hq', [
            'cari' => 'MIZU', '--force' => true, '--as' => $biasa->username,
        ]));
        $this->assertSame(646, (int) $p->fresh()->hq_stock);
    }
}
