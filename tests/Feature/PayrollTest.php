<?php

namespace Tests\Feature;

use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollProfile;
use App\Models\PayrollRun;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Menu HR → Payroll (Fase 3): izin payroll.view/manage (default super admin), data gaji, run draf → dikunci (+ jurnal
 * Akuntansi seimbang), buka kunci (jurnal void), Desember hitung ulang setahun dari bulan terkunci, slip, audit tanpa nominal.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
        AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function izinkan(string $role, string $key): void
    {
        RolePermission::create(['role' => $role, 'permission_key' => $key, 'allowed' => true]);
        Permissions::flushCache();
    }

    private function karyawan(string $nama, int $gaji = 10_000_000, array $extra = [], array $profil = []): Employee
    {
        $e = Employee::create($extra + ['name' => $nama, 'position' => 'Admin Gudang', 'employment_type' => 'tetap',
            'status' => 'aktif', 'join_date' => '2025-01-06', 'ptkp_status' => 'TK/0']);
        if ($gaji > 0) {
            PayrollProfile::create($profil + ['employee_id' => $e->id, 'base_salary' => $gaji]);
        }

        return $e;
    }

    private function buat(User $sa, string $bulan): PayrollRun
    {
        $this->actingAs($sa)->post(route('hr.payroll.store'), ['period' => $bulan])->assertRedirect();

        return PayrollRun::whereDate('period', $bulan.'-01')->sole();
    }

    public function test_akses_hanya_izin_payroll_dan_mitra_diblok(): void
    {
        $this->karyawan('Rina Putri');
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $run = $this->buat($sa, '2026-10');
        $admin = $this->user(User::ROLE_ADMIN, 'adm');

        foreach ([route('hr.payroll.index'), route('hr.payroll.komponen'), route('hr.payroll.show', $run), route('hr.payroll.slip', $run)] as $url) {
            $this->actingAs($sa)->get($url)->assertOk();
            $this->actingAs($admin)->get($url)->assertForbidden();   // admin punya hr.view, bukan payroll.view
        }
        $this->actingAs($admin)->get('/dashboard')->assertDontSee(route('hr.payroll.index'), false);

        // payroll.view saja: boleh lihat, tak boleh mengubah.
        $this->izinkan(User::ROLE_ADMIN, 'payroll.view');
        $this->actingAs($admin)->get('/dashboard')->assertSee(route('hr.payroll.index'), false);
        $this->actingAs($admin)->get(route('hr.payroll.show', $run))->assertOk()->assertSee('9.338.650')->assertDontSee('Kunci payroll');
        $this->actingAs($admin)->post(route('hr.payroll.kunci', $run))->assertForbidden();
        $this->actingAs($admin)->post(route('hr.payroll.store'), ['period' => '2026-11'])->assertForbidden();
        $this->actingAs($admin)->put(route('hr.payroll.komponen.update'), ['komponen' => []])->assertForbidden();

        // Mitra tetap diblok walau izinnya dicentang.
        $this->izinkan(User::ROLE_DISTRIBUTOR, 'payroll.view');
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dist'))->get(route('hr.payroll.index'))->assertForbidden();
    }

    public function test_data_gaji_disimpan_dan_audit_tanpa_nominal(): void
    {
        $rina = $this->karyawan('Rina Putri', 0);
        $budi = $this->karyawan('Budi Santoso', 0);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->put(route('hr.payroll.komponen.update'), ['komponen' => [
            $rina->id => ['base_salary' => 7_250_000, 'fixed_allowance' => 750_000, 'bpjs_kesehatan' => '1', 'bpjs_tk' => '1', 'bpjs_jp' => '0', 'cost_group' => 'produksi'],
            $budi->id => ['base_salary' => 0, 'fixed_allowance' => 0, 'bpjs_kesehatan' => '1', 'bpjs_tk' => '1', 'bpjs_jp' => '1', 'cost_group' => 'operasional'],
        ]])->assertRedirect();

        $p = $rina->fresh()->payrollProfile;
        $this->assertSame([7_250_000, 750_000, true, false, 'produksi'], [$p->base_salary, $p->fixed_allowance, $p->bpjs_tk, $p->bpjs_jp, $p->cost_group]);
        $this->assertNull($budi->fresh()->payrollProfile);   // gaji kosong → tak dibuat
        $this->actingAs($sa)->put(route('hr.payroll.komponen.update'), ['komponen' => [
            $rina->id => ['base_salary' => 7_500_000, 'fixed_allowance' => 750_000, 'bpjs_kesehatan' => '1', 'bpjs_tk' => '1', 'bpjs_jp' => '0', 'cost_group' => 'produksi'],
        ]])->assertRedirect();
        $this->actingAs($sa)->put(route('hr.payroll.komponen.update'), ['komponen' => [
            $rina->id => ['base_salary' => '7.500.000', 'fixed_allowance' => 0, 'bpjs_kesehatan' => '1', 'bpjs_tk' => '1', 'bpjs_jp' => '0', 'cost_group' => 'produksi'],
        ]])->assertSessionHasErrors('komponen.'.$rina->id.'.base_salary');

        $logs = AuditLog::where('action', 'update_payroll_component')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['nama' => 'Rina Putri', 'diubah' => ['gaji_pokok']], $logs[1]->after_data);
        $this->assertStringNotContainsString('7250000', json_encode($logs->map->after_data));
        $this->assertStringNotContainsString('7500000', json_encode($logs->map->after_data));

        // Setelan BPJS (bukan data pribadi) tersimpan & dipakai.
        $this->actingAs($sa)->put(route('hr.payroll.setelan.update'), ['kes_cap' => 12_000_000, 'jp_cap' => 11_500_000, 'jkk_bps' => 54])->assertRedirect();
        $this->assertSame(['11500000', '54'], [AppSetting::get('payroll_jp_cap'), AppSetting::get('payroll_jkk_bps')]);
        $this->actingAs($sa)->put(route('hr.payroll.setelan.update'), ['kes_cap' => 12_000_000, 'jp_cap' => 11_500_000, 'jkk_bps' => 50])->assertSessionHasErrors('jkk_bps');
    }

    public function test_buat_payroll_hanya_peserta_yang_memenuhi_syarat(): void
    {
        $rina = $this->karyawan('Rina Putri');
        $this->karyawan('Belum Digaji', 0);
        $this->karyawan('Sudah Keluar', 8_000_000, ['status' => 'keluar', 'resign_date' => '2026-09-30']);
        $this->karyawan('Masuk November', 8_000_000, ['join_date' => '2026-11-02']);
        $citra = $this->karyawan('Citra Keluar Oktober', 6_000_000, ['status' => 'keluar', 'resign_date' => '2026-10-20']);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $run = $this->buat($sa, '2026-10');
        $this->assertSame(['Citra Keluar Oktober', 'Rina Putri'], $run->items->pluck('employee_name')->all());
        $r = $run->items->firstWhere('employee_id', $rina->id);
        $this->assertSame([10_454_000, 250, 261_350, 9_338_650, false], [$r->bruto, $r->ter_rate, $r->pph21, $r->net_pay, $r->annual]);
        // Keluar bulan ini → masa pajak terakhir, PPh dihitung setahun (1 bulan, di bawah PTKP → 0).
        $c = $run->items->firstWhere('employee_id', $citra->id);
        $this->assertSame([true, 0], [$c->annual, $c->pph21]);

        $page = $this->actingAs($sa)->get(route('hr.payroll.show', $run))->assertOk();
        $page->assertSee('Belum ada data gaji (tidak ikut payroll ini): Belum Digaji.')->assertSee('Pratinjau jurnal saat dikunci');
        $this->actingAs($sa)->post(route('hr.payroll.store'), ['period' => '2026-10'])->assertSessionHas('error');
        $this->assertSame(1, PayrollRun::count());
        $this->assertSame(['periode' => '2026-10', 'karyawan' => 2], AuditLog::where('action', 'create_payroll_run')->sole()->after_data);
    }

    public function test_draf_diedit_dan_gaji_bersih_tak_boleh_minus(): void
    {
        $rina = $this->karyawan('Rina Putri');
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $run = $this->buat($sa, '2026-10');
        $item = $run->items->sole();

        $this->actingAs($sa)->put(route('hr.payroll.update', $run), ['item' => [$item->id => [
            'base_salary' => 10_000_000, 'fixed_allowance' => 0, 'overtime' => 500_000, 'bonus' => 1_000_000, 'kasbon' => 250_000,
            'pph21_override' => '', 'notes' => 'Lembur stok opname',
        ]]])->assertRedirect();
        $item->refresh();
        $this->assertSame([11_954_000, 478_160, 10_371_840, 'Lembur stok opname', null], [$item->bruto, $item->pph21, $item->net_pay, $item->notes, $item->pph21_override]);

        // Kasbon melebihi gaji → ditolak, tak ada yang tersimpan.
        $this->actingAs($sa)->put(route('hr.payroll.update', $run), ['item' => [$item->id => [
            'base_salary' => 10_000_000, 'fixed_allowance' => 0, 'overtime' => 0, 'bonus' => 0, 'kasbon' => 20_000_000, 'pph21_override' => '',
        ]]])->assertSessionHasErrors('item');
        $this->assertSame(250_000, $item->fresh()->kasbon);

        // Data gaji naik → "Ambil ulang" memperbarui gaji pokok, lembur/bonus/kasbon tetap.
        $rina->payrollProfile->update(['base_salary' => 11_000_000]);
        $this->actingAs($sa)->post(route('hr.payroll.ambil-ulang', $run))->assertRedirect();
        $item->refresh();
        $this->assertSame([11_000_000, 500_000, 1_000_000, 250_000], [$item->base_salary, $item->overtime, $item->bonus, $item->kasbon]);
        $this->assertSame(['update_payroll_items', 'refresh_payroll_run'], AuditLog::whereIn('action', ['update_payroll_items', 'refresh_payroll_run'])->orderBy('id')->pluck('action')->all());
    }

    public function test_kunci_membuat_jurnal_seimbang_lalu_buka_kunci_void(): void
    {
        $this->karyawan('Rina Putri');
        $this->karyawan('Joko Produksi', 6_000_000, profil: ['cost_group' => 'produksi', 'bpjs_jp' => false]);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $run = $this->buat($sa, '2026-10');
        $joko = $run->items->firstWhere('employee_name', 'Joko Produksi');
        $this->actingAs($sa)->put(route('hr.payroll.update', $run), ['item' => [$joko->id => [
            'base_salary' => 6_000_000, 'fixed_allowance' => 0, 'overtime' => 0, 'bonus' => 0, 'kasbon' => 500_000, 'pph21_override' => '',
        ]]]);

        $this->actingAs($sa)->post(route('hr.payroll.kunci', $run))->assertRedirect()->assertSessionHas('status');
        $run->refresh();
        $this->assertTrue($run->dikunci());
        $j = AccJournal::with('lines.account')->findOrFail($run->journal_id);
        $this->assertSame(['posted', '2026-10-31', 'PAYROLL 2026-10', 'payroll_run', $run->id], [$j->status, $j->date->toDateString(), $j->reference, $j->source_type, $j->source_id]);
        $this->assertTrue($j->isBalanced());
        $per = fn (string $kode, string $sisi) => (int) $j->lines->filter(fn ($l) => $l->account->code === $kode)->sum($sisi);
        // Rina (operasional): gaji 10 jt + BPJS perusahaan 1.024.000; Joko (produksi, tanpa JP): gaji 6 jt + 4% 240rb + JHT 222rb + JKK 14.400 + JKM 18rb.
        $this->assertSame(11_024_000, $per('6002', 'debit'));
        $this->assertSame(6_494_400, $per('5004', 'debit'));
        $this->assertSame(9_338_650, $per('2003', 'credit'));
        $joko->refresh();
        $this->assertSame($joko->net_pay, $per('2002', 'credit'));
        $this->assertSame(261_350 + $joko->pph21, $per('2004', 'credit'));
        $this->assertSame(500_000, $per('1105', 'credit'));
        $this->assertSame(['Hutang BPJS', 'Piutang Karyawan (Kasbon)'], [$j->lines->firstWhere('account.code', '2009')->account->name, $j->lines->firstWhere('account.code', '1105')->account->name]);

        // Terkunci: tak bisa diubah / dihapus / ambil ulang.
        $this->actingAs($sa)->put(route('hr.payroll.update', $run), ['item' => [$joko->id => ['base_salary' => 1, 'kasbon' => 0]]])->assertSessionHas('error');
        $this->actingAs($sa)->delete(route('hr.payroll.destroy', $run))->assertSessionHas('error');
        $this->assertSame(6_000_000, $joko->fresh()->base_salary);
        $this->actingAs($sa)->get(route('hr.payroll.show', $run))->assertOk()->assertSee('Cetak semua slip')->assertSee('Buka kunci');

        // Buka kunci → jurnal void (tak dihitung di saldo), kembali draf.
        $this->actingAs($sa)->post(route('hr.payroll.buka-kunci', $run))->assertRedirect()->assertSessionHas('status');
        $this->assertSame(['draf', null], [$run->fresh()->status, $run->fresh()->journal_id]);
        $this->assertSame(AccJournal::STATUS_VOID, $j->fresh()->status);
        $this->assertSame(['lock_payroll', 'unlock_payroll'], AuditLog::whereIn('action', ['lock_payroll', 'unlock_payroll'])->orderBy('id')->pluck('action')->all());
        // Audit payroll tak memuat nominal gaji.
        $this->assertStringNotContainsString('9338650', json_encode(AuditLog::all()->map->only(['before_data', 'after_data'])));
    }

    public function test_desember_hitung_setahun_dari_bulan_yang_dikunci(): void
    {
        // Masuk Oktober → Okt & Nov dipotong TER, Desember dihitung setahun: di bawah PTKP → PPh Okt–Nov dikembalikan.
        $baru = $this->karyawan('Dewi Baru', 10_000_000, ['join_date' => '2026-10-01']);
        // Masuk 2025 tapi Jan–Sep digaji di luar portal & saldo awal belum diisi → peringatan.
        $lama = $this->karyawan('Agus Lama');
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $okt = $this->buat($sa, '2026-10');
        $nov = $this->buat($sa, '2026-11');
        $this->actingAs($sa)->post(route('hr.payroll.kunci', $nov))->assertSessionHas('error');   // Oktober masih draf
        $this->actingAs($sa)->post(route('hr.payroll.kunci', $okt))->assertSessionHas('status');
        $this->actingAs($sa)->post(route('hr.payroll.kunci', $nov))->assertSessionHas('status');
        $des = $this->buat($sa, '2026-12');

        $d = $des->items->firstWhere('employee_id', $baru->id);
        $this->assertTrue($d->annual);
        $this->assertSame(['bulan' => 3, 'bruto_setahun' => 31_362_000, 'biaya_jabatan' => 1_500_000, 'iuran_pensiun' => 900_000,
            'neto' => 28_962_000, 'ptkp' => 54_000_000, 'pkp' => 0, 'pph21_setahun' => 0, 'pph21_sebelumnya' => 522_700], $d->tax_detail);
        $this->assertSame([-522_700, 10_122_700], [$d->pph21, $d->net_pay]);

        $this->actingAs($sa)->get(route('hr.payroll.show', $des))->assertOk()
            ->assertSee('PPh 21 setahun Agus Lama: data 9 bulan tahun ini belum ada')->assertDontSee('PPh 21 setahun Dewi Baru: data')
            ->assertSee('Rincian PPh 21 setahun');

        // Saldo awal Jan–Sep untuk Agus (9 bulan identik) → Desember dihitung lengkap 12 bulan.
        $this->actingAs($sa)->put(route('hr.payroll.saldo-awal.update'), ['tahun' => 2026, 'saldo' => [
            $lama->id => ['months' => 9, 'bruto' => 94_086_000, 'iuran' => 2_700_000, 'pph21' => 2_352_150],
            $baru->id => ['months' => '', 'bruto' => '', 'iuran' => '', 'pph21' => ''],
        ]])->assertRedirect();
        $this->actingAs($sa)->post(route('hr.payroll.ambil-ulang', $des))->assertRedirect();
        $a = $des->items()->where('employee_id', $lama->id)->sole();
        $this->assertSame([12, 125_448_000, 3_277_200, 2_874_850, 402_350], [$a->tax_detail['bulan'], $a->tax_detail['bruto_setahun'],
            $a->tax_detail['pph21_setahun'], $a->tax_detail['pph21_sebelumnya'], $a->pph21]);
        $this->assertNull($baru->fresh()->payrollProfile->opening_year);   // saldo kosong → tidak diisi

        // Bulan sesudahnya terkunci → Oktober tak bisa dibuka kuncinya.
        $this->actingAs($sa)->post(route('hr.payroll.buka-kunci', $okt))->assertSessionHas('error');
        $this->assertTrue($okt->fresh()->dikunci());
    }

    public function test_slip_gaji_cetak(): void
    {
        $this->karyawan('Rina Putri');
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $run = $this->buat($sa, '2026-10');
        $item = $run->items->sole();

        $this->actingAs($sa)->get(route('hr.payroll.slip.item', [$run, $item]))->assertOk()
            ->assertSee('SLIP GAJI (DRAF)')->assertSee('Rina Putri')->assertSee('KRY-0001')->assertSee('Rp 9.338.650')->assertSee('TER 2,5%');
        $this->actingAs($sa)->post(route('hr.payroll.kunci', $run));
        $this->actingAs($sa)->get(route('hr.payroll.slip', $run))->assertOk()->assertDontSee('DRAF')->assertSee('Rp 261.350');

        $lain = $this->buat($sa, '2026-11');
        $this->actingAs($sa)->get(route('hr.payroll.slip.item', [$lain, $item]))->assertNotFound();
    }

    public function test_hapus_draf(): void
    {
        $this->karyawan('Rina Putri');
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $run = $this->buat($sa, '2026-10');

        $this->actingAs($sa)->delete(route('hr.payroll.destroy', $run))->assertRedirect(route('hr.payroll.index'));
        $this->assertSame([0, 0], [PayrollRun::count(), PayrollItem::count()]);
        $this->assertSame(['periode' => '2026-10'], AuditLog::where('action', 'delete_payroll_run')->sole()->before_data);
    }
}
