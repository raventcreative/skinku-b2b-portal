<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Kol;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Kolom "Perubahan" di Audit Log: isi before → after terbaca (angka bertitik ribuan, rahasia disensor) dan target
 * ditampilkan dgn nama (kreator @username, produk, user, nomor PO) — bukan cuma "kol #12".
 */
class AuditLogPerubahanTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_ringkas_perubahan_mudah_dibaca(): void
    {
        $ubah = new AuditLog(['before_data' => ['bulan' => '2026-10', 'gaji' => 1_000_000], 'after_data' => ['bulan' => '2026-10', 'gaji' => 1_500_000]]);
        $this->assertSame(['bulan: 2026-10', 'gaji: 1.000.000 → 1.500.000'], $ubah->ringkasPerubahan());

        $baru = new AuditLog(['after_data' => ['item_id' => 2300069665, 'produk' => 'Serum', 'aktif' => true, 'token' => 'abc', 'catatan' => null, 'rate' => 2.5]]);
        $this->assertSame(['item_id: 2300069665', 'produk: Serum', 'aktif: ya', 'token: ***', 'catatan: —', 'rate: 2,50'], $baru->ringkasPerubahan());

        $hapus = new AuditLog(['before_data' => ['amount' => 400_000, 'dibayar' => '2026-10-05']]);
        $this->assertSame(['amount: 400.000 → —', 'dibayar: 2026-10-05 → —'], $hapus->ringkasPerubahan());

        $this->assertSame([], (new AuditLog)->ringkasPerubahan());
    }

    public function test_halaman_audit_log_menampilkan_perubahan_dan_nama_target(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $spec = $this->user('kol_specialist', 'spec');
        $kol = Kol::create(['tiktok_username' => 'dewick02', 'followers' => 1, 'is_gapok' => true]);
        $bulan = now()->format('Y-m');
        foreach ([1_000_000, 1_500_000] as $gaji) {
            $this->actingAs($spec)->postJson(route('kol-gapok.salary'), ['kol_id' => $kol->id, 'bulan' => $bulan, 'monthly_salary' => $gaji])->assertOk();
        }
        $produk = Product::create(['name' => 'Serum Glow', 'sku' => 'SG-1', 'status' => 'active']);
        AuditLog::create(['action' => 'update_product', 'target_type' => 'product', 'target_id' => $produk->id,
            'before_data' => ['harga' => 25000], 'after_data' => ['harga' => 27500], 'created_at' => now()]);

        $this->actingAs($sa)->get(route('audit-logs.index'))->assertOk()
            ->assertSee('Perubahan')
            ->assertSee('@dewick02')->assertSee('gaji: 1.000.000 → 1.500.000')->assertSee("bulan: {$bulan}")
            ->assertSee('Serum Glow')->assertSee('harga: 25.000 → 27.500');
    }
}
