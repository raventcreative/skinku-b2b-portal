<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionReportPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * users.username & users.email wajib unique + NOT NULL, tak ada kolom
     * is_active — status pakai User::STATUS_ACTIVE (pola sama dgn
     * CommissionReportTest::mitra()).
     */
    private function admin(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => 'Adm', 'username' => 'crpadm'.$n, 'email' => 'crpadm'.$n.'@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function mitra(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => 'Mit', 'username' => 'crpmit'.$n, 'email' => 'crpmit'.$n.'@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_admin_bisa_lihat_laporan_komisi(): void
    {
        $m = $this->mitra();
        Commission::create(['user_id' => $m->id, 'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => 1_000_000, 'amount' => 40_000, 'status' => 'saldo']);

        $this->actingAs($this->admin())->get('/reports/komisi')
            ->assertOk()->assertSee('Laporan Komisi')->assertSee('Mit');
    }

    public function test_mitra_ditolak_403(): void
    {
        $this->actingAs($this->mitra())->get('/reports/komisi')->assertForbidden();
    }

    public function test_filter_bulan_mempersempit(): void
    {
        $m = $this->mitra();
        $c = Commission::create(['user_id' => $m->id, 'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => 1_000_000, 'amount' => 40_000, 'status' => 'saldo']);
        // Commission::$fillable tak punya created_at, jadi backdate lewat
        // update() query-builder terpisah (pola sama dgn CommissionReportTest::komisi())
        // supaya komisi ini beneran jatuh di Juli, bukan diam-diam ikut "now".
        Commission::where('id', $c->id)->update(['created_at' => '2026-07-01 09:00:00', 'updated_at' => '2026-07-01 09:00:00']);

        $admin = $this->admin();

        // Juli: komisi tsb ADA di periode → "Rp 40.000" tampil di kartu+kolom
        // periode SEKALIGUS kartu+kolom saldo (all-time, cuma 1 baris komisi).
        $juli = $this->actingAs($admin)->get('/reports/komisi?bulan=2026-07');
        $juli->assertOk()->assertSee('Mit');

        // Agustus: komisi periode kosong (backdated ke Juli) tapi saldo total
        // tetap tampak → "Mit" tetap ada, tapi kemunculan "Rp 40.000" BERKURANG
        // (kartu+kolom Komisi periode jadi Rp 0) — bukti filter beneran
        // mempersempit, bukan cuma halaman yang tetap render.
        $agustus = $this->actingAs($admin)->get('/reports/komisi?bulan=2026-08');
        $agustus->assertOk()->assertSee('Mit');

        $juliCount = substr_count($juli->getContent(), 'Rp 40.000');
        $agustusCount = substr_count($agustus->getContent(), 'Rp 40.000');
        $this->assertLessThan($juliCount, $agustusCount);
    }
}
