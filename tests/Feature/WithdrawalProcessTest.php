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
 * HQ memproses antrean penarikan mitra: setujui → cair, atau tolak. Tolak
 * otomatis melepas kunci saldo lewat CommissionService::availableBalance
 * (mengecualikan status 'ditolak') — Commission TETAP append-only, tak
 * pernah disentuh di sini. Gated izin process_withdrawal (default admin).
 */
class WithdrawalProcessTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => "Admin{$n}", 'fullname' => "Admin{$n}", 'username' => "admin{$n}",
            'email' => "admin{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

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

    private function giveCommission(User $mitra, float $amount): Commission
    {
        return Commission::create([
            'user_id' => $mitra->id, 'source_po_id' => null, 'source_user_id' => $mitra->id,
            'type' => 'override', 'level' => 1, 'rate' => 6, 'base_amount' => $amount,
            'amount' => $amount, 'status' => 'saldo',
        ]);
    }

    public function test_hq_setujui_lalu_cair(): void
    {
        $admin = $this->admin(); // super_admin/admin
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000]);
        $w = Withdrawal::first();

        $this->actingAs($admin)->post(route('withdrawals.process', $w), ['status' => 'disetujui'])->assertRedirect();
        $this->assertSame('disetujui', $w->fresh()->status);
        $this->assertSame($admin->id, $w->fresh()->processed_by);
        $this->assertNotNull($w->fresh()->processed_at);

        $this->actingAs($admin)->post(route('withdrawals.process', $w), ['status' => 'cair']);
        $this->assertSame('cair', $w->fresh()->status);
    }

    /** tolak → available mitra balik ke 500000 */
    public function test_tolak_lepas_kunci_saldo(): void
    {
        $admin = $this->admin();
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $svc = app(CommissionService::class);

        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000]);
        $w = Withdrawal::first();
        $this->assertEqualsWithDelta(300000, $svc->availableBalance($m->fresh()), 0.01);

        $this->actingAs($admin)->post(route('withdrawals.process', $w), ['status' => 'ditolak'])->assertRedirect();

        $this->assertSame('ditolak', $w->fresh()->status);
        $this->assertEqualsWithDelta(500000, $svc->availableBalance($m->fresh()), 0.01); // kunci lepas
    }

    /** mitra (tanpa process_withdrawal) POST withdrawals.process → 403 */
    public function test_mitra_tak_bisa_proses(): void
    {
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000]);
        $w = Withdrawal::first();

        $this->actingAs($m)->post(route('withdrawals.process', $w), ['status' => 'disetujui'])->assertForbidden();
        $this->assertSame('diajukan', $w->fresh()->status);
    }

    /** withdrawal yang sudah 'cair' final — tak boleh diproses ulang. */
    public function test_tidak_bisa_proses_ulang_yang_sudah_cair(): void
    {
        $admin = $this->admin();
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000]);
        $w = Withdrawal::first();

        $this->actingAs($admin)->post(route('withdrawals.process', $w), ['status' => 'cair']);
        $this->assertSame('cair', $w->fresh()->status);

        $this->actingAs($admin)->post(route('withdrawals.process', $w), ['status' => 'ditolak']);
        $this->assertSame('cair', $w->fresh()->status); // tak berubah — sudah final
    }

    /**
     * Skenario over-withdrawal: A(300k) ditolak sudah melepas kunci, mitra
     * mengajukan lagi B(500k) yang menghabiskan sisa saldo. HQ (tab basi)
     * mencoba "menghidupkan" A yang sudah ditolak jadi disetujui — HARUS
     * diblokir, kalau tidak Σ(non-ditolak) = A+B = 800k > saldo 500k.
     */
    public function test_ditolak_tak_bisa_dihidupkan_ulang(): void
    {
        $admin = $this->admin();
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $svc = app(CommissionService::class);

        $a = Withdrawal::create([
            'user_id' => $m->id, 'amount' => 300000, 'status' => 'ditolak',
            'bank' => $m->bank, 'no_rekening' => $m->no_rekening, 'atas_nama' => $m->atas_nama,
            'requested_at' => now(),
        ]);
        Withdrawal::create([
            'user_id' => $m->id, 'amount' => 500000, 'status' => 'diajukan',
            'bank' => $m->bank, 'no_rekening' => $m->no_rekening, 'atas_nama' => $m->atas_nama,
            'requested_at' => now(),
        ]);
        $this->assertEqualsWithDelta(0.0, $svc->availableBalance($m->fresh()), 0.01); // sisa habis oleh B

        $this->actingAs($admin)->post(route('withdrawals.process', $a), ['status' => 'disetujui'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('ditolak', $a->fresh()->status); // tetap final, tak dihidupkan ulang
        $this->assertEqualsWithDelta(0.0, $svc->availableBalance($m->fresh()), 0.01); // BUKAN -300000
    }

    public function test_index_render_ok(): void
    {
        $admin = $this->admin();
        $m = $this->partnerWithBank();
        $this->giveCommission($m, 500000);
        $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000]);

        $this->actingAs($admin)->get(route('withdrawals.index'))
            ->assertOk()
            ->assertSee('diajukan')
            ->assertSee('BCA');
    }
}
