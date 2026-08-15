<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mitra mengajukan penarikan saldo komisi. Commissions APPEND-ONLY — saldo
 * tersedia susut lewat baris withdrawals (status != 'ditolak'), bukan dengan
 * mengubah Commission.status.
 */
class WithdrawalRequestTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function partnerWithBank(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => "Mitra{$n}", 'fullname' => "Mitra{$n}", 'username' => "mitra{$n}",
            'email' => "mitra{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
            'company_name' => "Toko Mitra {$n}",
            'bank' => 'BCA', 'no_rekening' => '1234567890', 'atas_nama' => "Mitra {$n}",
        ]);
    }

    private function partnerWithoutBank(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => "Mitra{$n}", 'fullname' => "Mitra{$n}", 'username' => "mitra{$n}",
            'email' => "mitra{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
            'company_name' => "Toko Mitra {$n}",
        ]);
    }

    private function staff(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => "Staff{$n}", 'fullname' => "Staff{$n}", 'username' => "staff{$n}",
            'email' => "staff{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function giveCommission(User $mitra, float $amount): Commission
    {
        return Commission::create([
            'user_id' => $mitra->id, 'source_po_id' => null, 'source_user_id' => $mitra->id,
            'type' => 'override', 'level' => 1, 'rate' => 6, 'base_amount' => $amount,
            'amount' => $amount, 'status' => 'saldo',
        ]);
    }

    public function test_mitra_ajukan_penarikan_kurangi_saldo_tersedia(): void
    {
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $svc = app(CommissionService::class);
        $this->assertEqualsWithDelta(500000, $svc->availableBalance($m), 0.01);

        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000])->assertRedirect();

        $this->assertEqualsWithDelta(300000, $svc->availableBalance($m->fresh()), 0.01); // dikunci
        $w = Withdrawal::where('user_id', $m->id)->first();
        $this->assertNotNull($w);
        $this->assertSame('diajukan', $w->status);
        $this->assertSame('BCA', $w->bank); // rekening ke-snapshot
        $this->assertSame('1234567890', $w->no_rekening);
        $this->assertSame('Mitra 1', $w->atas_nama);
        $this->assertNotNull($w->requested_at);
    }

    public function test_ajukan_lebih_dari_saldo_ditolak(): void
    {
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);

        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 999999])->assertRedirect();

        $this->assertSame(0, Withdrawal::where('user_id', $m->id)->count());
    }

    public function test_ajukan_kurang_dari_minimum_ditolak(): void
    {
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);

        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 50000])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Withdrawal::where('user_id', $m->id)->count());
    }

    public function test_ajukan_tanpa_rekening_ditolak(): void
    {
        $m = $this->partnerWithoutBank();
        $this->giveCommission($m, 500000);

        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000])->assertRedirect();

        $this->assertSame(0, Withdrawal::where('user_id', $m->id)->count());
    }

    public function test_bukan_mitra_ditolak_403(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('commissions.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('commissions.withdraw'), ['amount' => 200000])->assertForbidden();
    }

    /** Halaman render OK — termasuk state kosong (belum ada komisi/penarikan) dan tanpa rekening. */
    public function test_halaman_saldo_komisi_render_ok(): void
    {
        $m = $this->partnerWithoutBank();

        $this->actingAs($m)->get(route('commissions.index'))
            ->assertOk()
            ->assertSee('Saldo Komisi')
            ->assertSee('Isi rekening dulu');

        $mBank = $this->partnerWithBank();
        $this->giveCommission($mBank, 500000);
        $this->actingAs($mBank)->post(route('commissions.withdraw'), ['amount' => 200000]);

        $this->actingAs($mBank)->get(route('commissions.index'))
            ->assertOk()
            ->assertSee('diajukan')
            ->assertSee('Batalkan');
    }

    public function test_batalkan_pengajuan_lepas_kunci_saldo(): void
    {
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $svc = app(CommissionService::class);

        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000]);
        $w = Withdrawal::where('user_id', $m->id)->first();
        $this->assertEqualsWithDelta(300000, $svc->availableBalance($m->fresh()), 0.01);

        $this->actingAs($m)->post(route('commissions.withdraw-cancel', $w))->assertRedirect();

        $this->assertSame('ditolak', $w->fresh()->status);
        $this->assertEqualsWithDelta(500000, $svc->availableBalance($m->fresh()), 0.01); // kunci lepas
    }

    /**
     * Bukan tes concurrency sungguhan (request tetap berurutan dalam satu
     * proses) — tapi memastikan pengajuan KEDUA menghormati saldo yang sudah
     * dikunci pengajuan PERTAMA, yaitu perilaku yang ditegakkan oleh
     * transaction+lock di withdraw().
     */
    public function test_dua_pengajuan_berturut_hormati_sisa_saldo(): void
    {
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 300000])->assertRedirect();
        // sisa available 200.000 → ajukan 300.000 lagi HARUS ditolak
        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 300000]);
        $this->assertSame(1, Withdrawal::where('user_id', $m->id)->count()); // cuma 1 berhasil
    }

    public function test_snapshot_rekening_tak_ikut_berubah(): void
    {
        $m = $this->partnerWithBank(); // bank awal 'BCA'
        $this->giveCommission($m, 500000);
        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000]);
        $w = Withdrawal::where('user_id', $m->id)->first();

        $m->update(['bank' => 'MANDIRI', 'no_rekening' => '999']);

        $this->assertSame('BCA', $w->fresh()->bank); // snapshot tetap, tak ikut profil terbaru
        $this->assertSame('1234567890', $w->fresh()->no_rekening);
    }
}
