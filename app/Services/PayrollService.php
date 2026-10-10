<?php

namespace App\Services;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Payroll\Pajak;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Payroll bulanan (HR Fase 3): hitung BPJS + PPh 21 tiap karyawan, run draf → dikunci (+ jurnal Akuntansi).
 *
 * BPJS (dasar upah = gaji pokok + tunjangan tetap): Kesehatan 4% perusahaan + 1% karyawan, upah maks setelan (bawaan
 * Rp12 jt, Perpres 64/2020) · JHT 3,7% + 2% (PP 46/2015) · JP 2% + 1%, upah maks setelan (bawaan Rp11.086.300 sejak
 * Maret 2026, PP 45/2015) · JKK 0,24–1,74% & JKM 0,3% ditanggung perusahaan (PP 44/2015). Dibulatkan ke rupiah.
 *
 * PPh 21 (tarif di App\Support\Payroll\Pajak): bruto = penghasilan + BPJS Kesehatan 4% + JKK + JKM (premi dibayar
 * perusahaan = penghasilan; JHT & JP bagian perusahaan bukan). Januari–November: TER × bruto (dibulatkan ke bawah).
 * Desember / bulan terakhir bekerja: hitung ulang setahun dengan Pasal 17 − PPh 21 yang sudah dipotong; hasil minus =
 * lebih potong, dikembalikan lewat gaji bulan itu. Data bulan lalu = payroll yang sudah DIKUNCI + saldo awal.
 */
class PayrollService
{
    /** Tarif iuran BPJS (basis poin, 1% = 100). */
    public const KES_PERUSAHAAN = 400;

    public const KES_KARYAWAN = 100;

    public const JHT_PERUSAHAAN = 370;

    public const JHT_KARYAWAN = 200;

    public const JP_PERUSAHAAN = 200;

    public const JP_KARYAWAN = 100;

    public const JKM = 30;

    /** Tarif JKK menurut tingkat risiko usaha (PP 44/2015), dipilih di setelan. */
    public const JKK = [24 => 'Sangat rendah — 0,24%', 54 => 'Rendah — 0,54%', 89 => 'Sedang — 0,89%', 127 => 'Tinggi — 1,27%', 174 => 'Sangat tinggi — 1,74%'];

    /** Setelan payroll: [kunci app_settings, nilai bawaan]. Batas upah BPJS berubah tiap tahun (JP tiap Maret). */
    public const SETELAN = [
        'kes_cap' => ['payroll_kes_cap', 12_000_000],
        'jp_cap' => ['payroll_jp_cap', 11_086_300],
        'jkk_bps' => ['payroll_jkk_bps', 24],
    ];

    /**
     * Akun jurnal saat payroll dikunci: [kode, nama, tipe, subtipe, saldo normal]. Dicari per kode & dibuat bila belum
     * ada (pola integrasi marketplace). Kelompok biaya produksi → akun beban/utang gaji produksi.
     */
    public const AKUN = [
        'beban_operasional' => ['6002', 'Beban Gaji Pegawai', 'expense', 'operating', 'debit'],
        'beban_produksi' => ['5004', 'Beban Gaji Produksi', 'expense', 'cogs', 'debit'],
        'utang_gaji_operasional' => ['2003', 'Hutang Gaji Pegawai', 'liability', 'current', 'credit'],
        'utang_gaji_produksi' => ['2002', 'Hutang Gaji Produksi', 'liability', 'current', 'credit'],
        'utang_pph21' => ['2004', 'Hutang Pajak', 'liability', 'current', 'credit'],
        'utang_bpjs' => ['2009', 'Hutang BPJS', 'liability', 'current', 'credit'],
        'piutang_karyawan' => ['1105', 'Piutang Karyawan (Kasbon)', 'asset', 'receivable', 'debit'],
    ];

    public function __construct(private AccountingService $accounting) {}

    /** @return array{kes_cap:int,jp_cap:int,jkk_bps:int} */
    public function setelan(): array
    {
        return array_map(fn ($s) => (int) (AppSetting::get($s[0]) ?? $s[1]), self::SETELAN);
    }

    /**
     * Hitung satu baris: BPJS, bruto, PPh 21, gaji bersih. $lalu = data tahun berjalan sebelum bulan ini
     * (bulan, bruto, iuran, pph21) → hitung ulang setahun; null → TER bulanan.
     *
     * @param  array{kes_cap:int,jp_cap:int,jkk_bps:int}  $setelan
     * @param  array{bulan:int,bruto:int,iuran:int,pph21:int}|null  $lalu
     */
    public function hitung(PayrollItem $item, array $setelan, ?array $lalu = null): PayrollItem
    {
        $upah = $item->base_salary + $item->fixed_allowance;
        $kes = $item->bpjs_kesehatan ? min($upah, $setelan['kes_cap']) : 0;
        $tk = $item->bpjs_tk ? $upah : 0;
        $jp = $item->bpjs_jp ? min($upah, $setelan['jp_cap']) : 0;
        $iuran = fn (int $dasar, int $bps) => (int) round($dasar * $bps / 10_000);

        $item->forceFill([
            'kes_company' => $iuran($kes, self::KES_PERUSAHAAN),
            'kes_employee' => $iuran($kes, self::KES_KARYAWAN),
            'jht_company' => $iuran($tk, self::JHT_PERUSAHAAN),
            'jht_employee' => $iuran($tk, self::JHT_KARYAWAN),
            'jkk' => $iuran($tk, $setelan['jkk_bps']),
            'jkm' => $iuran($tk, self::JKM),
            'jp_company' => $iuran($jp, self::JP_PERUSAHAAN),
            'jp_employee' => $iuran($jp, self::JP_KARYAWAN),
        ]);
        $item->bruto = $item->penghasilan() + $item->kes_company + $item->jkk + $item->jkm;

        if ($lalu === null) {
            $item->forceFill(['annual' => false, 'tax_detail' => null, 'ter_rate' => Pajak::ter($item->ter_category, $item->bruto)]);
            $item->pph21_auto = intdiv($item->bruto * $item->ter_rate, 10_000);
        } else {
            $bulan = $lalu['bulan'] + 1;
            $brutoSetahun = $lalu['bruto'] + $item->bruto;
            $biayaJabatan = Pajak::biayaJabatan($brutoSetahun, $bulan);
            $iuranSetahun = $lalu['iuran'] + $item->iuranPensiun();
            $neto = $brutoSetahun - $biayaJabatan - $iuranSetahun;
            $ptkp = Pajak::PTKP[Pajak::ptkpSah($item->ptkp_status)];
            $pkp = max(0, intdiv($neto - $ptkp, 1000) * 1000);   // dibulatkan ke bawah ribuan
            $setahun = Pajak::pasal17($pkp);
            $item->forceFill(['annual' => true, 'ter_rate' => 0, 'tax_detail' => [
                'bulan' => $bulan, 'bruto_setahun' => $brutoSetahun, 'biaya_jabatan' => $biayaJabatan,
                'iuran_pensiun' => $iuranSetahun, 'neto' => $neto, 'ptkp' => $ptkp, 'pkp' => $pkp,
                'pph21_setahun' => $setahun, 'pph21_sebelumnya' => $lalu['pph21'],
            ]]);
            $item->pph21_auto = $setahun - $lalu['pph21'];
        }

        $item->pph21 = $item->pph21_override ?? $item->pph21_auto;
        $item->net_pay = $item->penghasilan() - $item->bpjsKaryawan() - $item->pph21 - $item->kasbon;

        return $item;
    }

    /** Desember, atau bulan karyawan berhenti bekerja → PPh 21 dihitung ulang setahun. */
    public function masaTerakhir(Carbon $periode, Employee $e): bool
    {
        return $periode->month === 12 || ($e->resign_date !== null && $e->resign_date->isSameMonth($periode));
    }

    /**
     * Data PPh 21 tahun berjalan sebelum $periode: payroll yang sudah dikunci + saldo awal (bulan di luar portal).
     *
     * @return array{bulan:int,bruto:int,iuran:int,pph21:int}
     */
    public function tahunBerjalan(Employee $e, Carbon $periode): array
    {
        $row = PayrollItem::where('employee_id', $e->id)
            ->whereHas('run', fn ($q) => $q->where('status', 'dikunci')->whereYear('period', $periode->year)
                ->whereDate('period', '<', $periode->copy()->startOfMonth()->toDateString()))
            ->selectRaw('count(*) as bulan, coalesce(sum(bruto), 0) as bruto, coalesce(sum(jht_employee + jp_employee), 0) as iuran, coalesce(sum(pph21), 0) as pph21')
            ->first();
        $p = $e->payrollProfile;
        $awal = $p !== null && $p->opening_year === $periode->year;

        return [
            'bulan' => (int) $row->bulan + ($awal ? $p->opening_months : 0),
            'bruto' => (int) $row->bruto + ($awal ? $p->opening_bruto : 0),
            'iuran' => (int) $row->iuran + ($awal ? $p->opening_iuran : 0),
            'pph21' => (int) $row->pph21 + ($awal ? $p->opening_pph21 : 0),
        ];
    }

    /** Hitung satu baris dalam konteks run (masa pajak terakhir → pakai data tahun berjalan). */
    public function hitungBaris(PayrollItem $item, PayrollRun $run, Employee $e, array $setelan): PayrollItem
    {
        return $this->hitung($item, $setelan, $this->masaTerakhir($run->period, $e) ? $this->tahunBerjalan($e, $run->period) : null);
    }

    /** Karyawan yang ikut payroll bulan $periode: ada data gaji, sudah mulai kerja, belum keluar sebelum bulan itu. */
    public function peserta(Carbon $periode): Collection
    {
        return Employee::with('payrollProfile')
            ->whereHas('payrollProfile', fn ($q) => $q->where(fn ($q) => $q->where('base_salary', '>', 0)->orWhere('fixed_allowance', '>', 0)))
            ->where(fn ($q) => $q->whereNull('join_date')->orWhereDate('join_date', '<=', $periode->copy()->endOfMonth()->toDateString()))
            ->where(fn ($q) => $q->where('status', 'aktif')->orWhereDate('resign_date', '>=', $periode->copy()->startOfMonth()->toDateString()))
            ->orderBy('name')->get();
    }

    /** Karyawan aktif yang belum diisi gajinya (tidak ikut payroll). */
    public function belumAdaGaji(): Collection
    {
        return Employee::where('status', 'aktif')
            ->whereDoesntHave('payrollProfile', fn ($q) => $q->where(fn ($q) => $q->where('base_salary', '>', 0)->orWhere('fixed_allowance', '>', 0)))
            ->orderBy('name')->get(['id', 'name']);
    }

    public function buat(Carbon $periode, int $oleh): PayrollRun
    {
        return DB::transaction(function () use ($periode, $oleh) {
            $run = PayrollRun::create(['period' => $periode->copy()->startOfMonth(), 'status' => 'draf', 'created_by' => $oleh]);
            $this->ambilUlang($run);

            return $run;
        });
    }

    /**
     * Ambil ulang data gaji ke draf: nama, jabatan, PTKP, BPJS, gaji pokok & tunjangan diperbarui; lembur, bonus, kasbon,
     * koreksi PPh & catatan dipertahankan. Karyawan baru masuk, yang tak lagi memenuhi syarat dikeluarkan.
     */
    public function ambilUlang(PayrollRun $run): void
    {
        $this->pastikanDraf($run);
        $setelan = $this->setelan();
        $ada = $run->items()->get()->keyBy('employee_id');
        $peserta = $this->peserta($run->period);

        DB::transaction(function () use ($run, $setelan, $ada, $peserta) {
            foreach ($peserta as $e) {
                $p = $e->payrollProfile;
                $item = $ada->get($e->id) ?? new PayrollItem(['payroll_run_id' => $run->id, 'employee_id' => $e->id,
                    'overtime' => 0, 'bonus' => 0, 'kasbon' => 0]);
                $item->fill([
                    'employee_name' => $e->name, 'position' => $e->position,
                    'ptkp_status' => Pajak::ptkpSah($e->ptkp_status), 'ter_category' => Pajak::kategori($e->ptkp_status),
                    'cost_group' => $p->cost_group, 'bpjs_kesehatan' => $p->bpjs_kesehatan, 'bpjs_tk' => $p->bpjs_tk,
                    'bpjs_jp' => $p->bpjs_jp, 'base_salary' => $p->base_salary, 'fixed_allowance' => $p->fixed_allowance,
                ]);
                $this->hitungBaris($item, $run, $e, $setelan)->save();
            }
            PayrollItem::where('payroll_run_id', $run->id)->whereNotIn('employee_id', $peserta->pluck('id'))->delete();
        });
    }

    /**
     * Simpan isian draf per baris (gaji pokok, tunjangan, lembur, bonus, kasbon, koreksi PPh, catatan) lalu hitung
     * ulang. Gaji bersih tak boleh minus. Mengembalikan jumlah baris yang berubah.
     *
     * @param  array<int|string, array<string, mixed>>  $isian  per id baris
     */
    public function simpanBaris(PayrollRun $run, array $isian): int
    {
        $this->pastikanDraf($run);
        $setelan = $this->setelan();

        return DB::transaction(function () use ($run, $isian, $setelan) {
            $diubah = 0;
            foreach ($run->items()->with('employee.payrollProfile')->get() as $item) {
                $in = $isian[$item->id] ?? null;
                if (! is_array($in)) {
                    continue;
                }
                $koreksi = trim((string) ($in['pph21_override'] ?? ''));
                $item->fill([
                    'base_salary' => (int) ($in['base_salary'] ?? 0),
                    'fixed_allowance' => (int) ($in['fixed_allowance'] ?? 0),
                    'overtime' => (int) ($in['overtime'] ?? 0),
                    'bonus' => (int) ($in['bonus'] ?? 0),
                    'kasbon' => (int) ($in['kasbon'] ?? 0),
                    'pph21_override' => $koreksi === '' ? null : (int) $koreksi,
                    'notes' => filled($in['notes'] ?? null) ? trim((string) $in['notes']) : null,
                ]);
                $this->hitungBaris($item, $run, $item->employee, $setelan);
                $this->cekBersih($item);
                if ($item->isDirty()) {
                    $item->save();
                    $diubah++;
                }
            }

            return $diubah;
        });
    }

    /** Kunci: hitung ulang final, catat jurnal Akuntansi, status dikunci (angka beku). */
    public function kunci(PayrollRun $run, int $oleh): AccJournal
    {
        $this->pastikanDraf($run);
        if (PayrollRun::where('status', 'draf')->whereYear('period', $run->period->year)
            ->whereDate('period', '<', $run->period->toDateString())->exists()) {
            throw new RuntimeException('Kunci dulu payroll bulan sebelumnya di tahun ini — hitungan PPh 21 setahun memakai bulan yang sudah dikunci.');
        }
        $items = $run->items()->with('employee.payrollProfile')->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('Payroll ini belum berisi karyawan — isi data gaji lalu klik "Ambil ulang data gaji".');
        }
        $setelan = $this->setelan();

        return DB::transaction(function () use ($run, $items, $setelan, $oleh) {
            foreach ($items as $item) {
                $this->cekBersih($this->hitungBaris($item, $run, $item->employee, $setelan));
                $item->save();
            }
            $journal = $this->catatJurnal($run, $items);
            $run->update(['status' => 'dikunci', 'locked_at' => now(), 'locked_by' => $oleh, 'journal_id' => $journal->id]);

            return $journal;
        });
    }

    /** Buka kunci: jurnal di-void, kembali draf. Hanya bila tak ada payroll sesudahnya yang dikunci. */
    public function bukaKunci(PayrollRun $run): ?AccJournal
    {
        if (! $run->dikunci()) {
            throw new RuntimeException('Payroll ini masih draf.');
        }
        if (PayrollRun::where('status', 'dikunci')->whereDate('period', '>', $run->period->toDateString())->exists()) {
            throw new RuntimeException('Buka kunci payroll bulan sesudahnya dulu — PPh 21 setahunnya ikut memakai bulan ini.');
        }

        return DB::transaction(function () use ($run) {
            $journal = $run->journal;
            if ($journal && $journal->status !== AccJournal::STATUS_VOID) {
                $this->accounting->void($journal);
            }
            $run->update(['status' => 'draf', 'locked_at' => null, 'locked_by' => null, 'journal_id' => null]);

            return $journal;
        });
    }

    /**
     * Baris jurnal payroll (nilai + = debit, − = kredit; PPh 21 total bisa minus saat lebih potong dikembalikan):
     * Dr beban gaji (penghasilan + BPJS perusahaan) / Cr utang gaji (gaji bersih), utang PPh 21, utang BPJS (perusahaan +
     * karyawan), piutang karyawan (kasbon).
     *
     * @return array<int, array{akun:string,debit:int,credit:int,memo:string}>
     */
    public function barisJurnal(Collection $items): array
    {
        $baris = [];
        foreach ($items->groupBy(fn ($i) => $i->cost_group === 'produksi' ? 'produksi' : 'operasional') as $grup => $isi) {
            $baris[] = ["beban_{$grup}", $isi->sum(fn ($i) => $i->penghasilan()), 'Gaji, tunjangan, lembur & bonus'];
            $baris[] = ["beban_{$grup}", $isi->sum(fn ($i) => $i->bpjsPerusahaan()), 'Iuran BPJS bagian perusahaan'];
            $baris[] = ["utang_gaji_{$grup}", -$isi->sum('net_pay'), 'Gaji bersih yang harus dibayar'];
        }
        $baris[] = ['utang_pph21', -$items->sum('pph21'), 'PPh 21 karyawan'];
        $baris[] = ['utang_bpjs', -$items->sum(fn ($i) => $i->bpjsPerusahaan() + $i->bpjsKaryawan()), 'Iuran BPJS (perusahaan + karyawan)'];
        $baris[] = ['piutang_karyawan', -$items->sum('kasbon'), 'Potongan kasbon'];

        return collect($baris)->map(fn ($b) => [(string) $b[0], (int) $b[1], (string) $b[2]])
            ->filter(fn ($b) => $b[1] !== 0)
            ->map(fn ($b) => ['akun' => $b[0], 'debit' => max(0, $b[1]), 'credit' => max(0, -$b[1]), 'memo' => $b[2]])
            ->values()->all();
    }

    /** Akun jurnal per kunci self::AKUN. $buat = false → hanya untuk pratinjau (tidak menyimpan akun baru). */
    public function akun(string $kunci, bool $buat): AccAccount
    {
        [$kode, $nama, $tipe, $subtipe, $normal] = self::AKUN[$kunci];
        $atribut = ['name' => $nama, 'type' => $tipe, 'subtype' => $subtipe, 'normal_balance' => $normal, 'is_active' => true];

        return $buat ? AccAccount::firstOrCreate(['code' => $kode], $atribut) : AccAccount::firstOrNew(['code' => $kode], $atribut);
    }

    private function catatJurnal(PayrollRun $run, Collection $items): AccJournal
    {
        $branch = AccBranch::active()->orderBy('id')->first();
        if (! $branch) {
            throw new RuntimeException('Belum ada cabang di Akuntansi — jurnal payroll tidak bisa dibuat.');
        }

        return $this->accounting->record([
            'branch_id' => $branch->id,
            'date' => $run->period->copy()->endOfMonth()->toDateString(),
            'reference' => 'PAYROLL '.$run->period->format('Y-m'),
            'description' => 'Payroll '.$run->label(),
            'type' => 'general',
            'source_type' => 'payroll_run',
            'source_id' => $run->id,
        ], array_map(fn ($b) => [
            'account_id' => $this->akun($b['akun'], true)->id,
            'debit' => $b['debit'],
            'credit' => $b['credit'],
            'memo' => $b['memo'],
        ], $this->barisJurnal($items)));
    }

    private function cekBersih(PayrollItem $item): void
    {
        if ($item->net_pay < 0) {
            throw ValidationException::withMessages(['item' => "Gaji bersih {$item->employee_name} jadi minus — kurangi kasbon atau koreksi PPh 21."]);
        }
    }

    private function pastikanDraf(PayrollRun $run): void
    {
        if ($run->dikunci()) {
            throw new RuntimeException('Payroll yang sudah dikunci tidak bisa diubah — buka kunci dulu.');
        }
    }
}
