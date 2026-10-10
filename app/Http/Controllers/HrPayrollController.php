<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollProfile;
use App\Models\PayrollRun;
use App\Services\AuditService;
use App\Services\PayrollService;
use App\Support\Payroll\Pajak;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Menu HR → Payroll (Fase 3): data gaji per karyawan, run bulanan (draf → dikunci + jurnal), slip cetak.
 * Izin payroll.view = lihat angka & slip, payroll.manage = ubah data gaji, jalankan, kunci (keduanya default super
 * admin saja), semua di balik internal. Audit tak pernah mencatat nominal gaji — hanya nama kolom yang berubah.
 */
class HrPayrollController extends Controller
{
    /** Label kolom data gaji untuk audit (tanpa nilai). */
    private const LABEL_KOMPONEN = [
        'base_salary' => 'gaji_pokok', 'fixed_allowance' => 'tunjangan', 'bpjs_kesehatan' => 'bpjs_kesehatan',
        'bpjs_tk' => 'bpjs_ketenagakerjaan', 'bpjs_jp' => 'jaminan_pensiun', 'cost_group' => 'kelompok_biaya',
    ];

    public function __construct(private PayrollService $svc) {}

    public function index(Request $request): View
    {
        $runs = PayrollRun::with('items')->orderByDesc('period')->get();
        $terakhir = $runs->first()?->period;

        return view('hr.payroll.index', [
            'runs' => $runs,
            'saranPeriode' => ($terakhir ? $terakhir->copy()->addMonthNoOverflow() : now()->startOfMonth())->format('Y-m'),
            'belumAdaGaji' => $this->svc->belumAdaGaji(),
            'bolehKelola' => $request->user()->canDo('payroll.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']], ['period.*' => 'Pilih bulan payroll.']);
        $periode = Carbon::createFromFormat('!Y-m', $data['period']);
        if (PayrollRun::whereDate('period', $periode->toDateString())->exists()) {
            return back()->with('error', 'Payroll '.$periode->translatedFormat('F Y').' sudah ada.');
        }
        $run = $this->svc->buat($periode, $request->user()->id);
        $jumlah = $run->items()->count();
        AuditService::log(action: 'create_payroll_run', targetType: 'payroll_run', targetId: $run->id,
            after: ['periode' => $periode->format('Y-m'), 'karyawan' => $jumlah]);

        return redirect()->route('hr.payroll.show', $run)
            ->with('status', "Payroll {$run->label()} dibuat untuk {$jumlah} karyawan — cek, isi lembur/bonus/kasbon, lalu kunci.");
    }

    public function show(Request $request, PayrollRun $run): View
    {
        $run->load(['items.employee.payrollProfile', 'journal', 'locker']);
        $items = $run->items;

        return view('hr.payroll.show', [
            'run' => $run,
            'items' => $items,
            'jurnal' => $run->dikunci() ? [] : array_map(fn ($b) => $b + ['account' => $this->svc->akun($b['akun'], false)], $this->svc->barisJurnal($items)),
            'peringatan' => $run->dikunci() ? [] : $this->peringatan($run, $items),
            'bolehKelola' => $request->user()->canDo('payroll.manage'),
            'lihatJurnal' => $request->user()->canDo('view_accounting'),
        ]);
    }

    public function update(Request $request, PayrollRun $run): RedirectResponse
    {
        $angka = ['nullable', 'integer', 'min:0', 'max:9999999999'];
        $data = $request->validate([
            'item' => ['required', 'array'],
            'item.*.base_salary' => $angka,
            'item.*.fixed_allowance' => $angka,
            'item.*.overtime' => $angka,
            'item.*.bonus' => $angka,
            'item.*.kasbon' => $angka,
            'item.*.pph21_override' => ['nullable', 'integer', 'min:-9999999999', 'max:9999999999'],
            'item.*.notes' => ['nullable', 'string', 'max:255'],
        ], ['item.*.*.integer' => 'Isi angka bulat tanpa titik atau koma.', 'item.*.*.min' => 'Angka tidak boleh minus.']);

        try {
            $diubah = $this->svc->simpanBaris($run, $data['item']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        AuditService::log(action: 'update_payroll_items', targetType: 'payroll_run', targetId: $run->id,
            after: ['periode' => $run->period->format('Y-m'), 'baris_diubah' => $diubah]);

        return back()->with('status', $diubah ? "Tersimpan — {$diubah} baris dihitung ulang." : 'Tidak ada perubahan.');
    }

    public function ambilUlang(PayrollRun $run): RedirectResponse
    {
        try {
            $this->svc->ambilUlang($run);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        AuditService::log(action: 'refresh_payroll_run', targetType: 'payroll_run', targetId: $run->id,
            after: ['periode' => $run->period->format('Y-m'), 'karyawan' => $run->items()->count()]);

        return back()->with('status', 'Data gaji diambil ulang — lembur, bonus, kasbon & koreksi PPh 21 tidak berubah.');
    }

    public function kunci(Request $request, PayrollRun $run): RedirectResponse
    {
        try {
            $journal = $this->svc->kunci($run, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        AuditService::log(action: 'lock_payroll', targetType: 'payroll_run', targetId: $run->id,
            after: ['periode' => $run->period->format('Y-m'), 'karyawan' => $run->items()->count(), 'jurnal' => $journal->id]);

        return back()->with('status', "Payroll {$run->label()} dikunci & dicatat ke jurnal Akuntansi. Slip gaji siap dicetak.");
    }

    public function bukaKunci(PayrollRun $run): RedirectResponse
    {
        try {
            $journal = $this->svc->bukaKunci($run);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        AuditService::log(action: 'unlock_payroll', targetType: 'payroll_run', targetId: $run->id,
            after: ['periode' => $run->period->format('Y-m'), 'jurnal_void' => $journal?->id]);

        return back()->with('status', 'Kunci dibuka — jurnal lama di-void dan payroll kembali draf.');
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        if ($run->dikunci()) {
            return back()->with('error', 'Payroll yang sudah dikunci tidak bisa dihapus — buka kunci dulu.');
        }
        $label = $run->label();
        $run->delete();
        AuditService::log(action: 'delete_payroll_run', targetType: 'payroll_run', targetId: $run->id,
            before: ['periode' => $run->period->format('Y-m')]);

        return redirect()->route('hr.payroll.index')->with('status', "Draf payroll {$label} dihapus.");
    }

    public function slip(PayrollRun $run, ?PayrollItem $item = null): View
    {
        abort_if($item && $item->payroll_run_id !== $run->id, 404);

        return view('hr.payroll.slip', [
            'run' => $run,
            'items' => $item ? collect([$item->load('employee')]) : $run->items()->with('employee')->get(),
        ]);
    }

    public function komponen(Request $request): View
    {
        return view('hr.payroll.komponen', [
            'karyawan' => Employee::with('payrollProfile')->where('status', 'aktif')->orderBy('name')->get(),
            'setelan' => $this->svc->setelan(),
            'tahun' => now()->year,
            'bolehKelola' => $request->user()->canDo('payroll.manage'),
        ]);
    }

    public function simpanKomponen(Request $request): RedirectResponse
    {
        $angka = ['required', 'integer', 'min:0', 'max:9999999999'];
        $data = $request->validate([
            'komponen' => ['required', 'array'],
            'komponen.*.base_salary' => $angka,
            'komponen.*.fixed_allowance' => $angka,
            'komponen.*.bpjs_kesehatan' => ['required', 'boolean'],
            'komponen.*.bpjs_tk' => ['required', 'boolean'],
            'komponen.*.bpjs_jp' => ['required', 'boolean'],
            'komponen.*.cost_group' => ['required', Rule::in(array_keys(PayrollProfile::COST_GROUPS))],
        ], ['komponen.*.*.integer' => 'Isi angka bulat tanpa titik atau koma.', 'komponen.*.*.min' => 'Angka tidak boleh minus.']);

        $diubah = 0;
        foreach (Employee::with('payrollProfile')->whereIn('id', array_keys($data['komponen']))->get() as $e) {
            $in = $data['komponen'][$e->id];
            if (! $e->payrollProfile && (int) $in['base_salary'] === 0 && (int) $in['fixed_allowance'] === 0) {
                continue;   // belum diisi — jangan buat data gaji kosong
            }
            $profil = $e->payrollProfile ?? new PayrollProfile(['employee_id' => $e->id]);
            $profil->fill([
                'base_salary' => (int) $in['base_salary'],
                'fixed_allowance' => (int) $in['fixed_allowance'],
                'bpjs_kesehatan' => (bool) $in['bpjs_kesehatan'],
                'bpjs_tk' => (bool) $in['bpjs_tk'],
                'bpjs_jp' => (bool) $in['bpjs_jp'],
                'cost_group' => $in['cost_group'],
            ]);
            if ($profil->exists && ! $profil->isDirty()) {
                continue;
            }
            $kolom = $profil->exists ? array_keys($profil->getDirty()) : array_keys(self::LABEL_KOMPONEN);
            $profil->save();
            $diubah++;
            AuditService::log(action: 'update_payroll_component', targetType: 'employee', targetId: $e->id,
                after: ['nama' => $e->name, 'diubah' => array_values(array_intersect_key(self::LABEL_KOMPONEN, array_flip($kolom)))]);
        }

        return back()->with('status', $diubah ? "Data gaji {$diubah} karyawan disimpan. Draf payroll yang sudah ada: klik \"Ambil ulang data gaji\"." : 'Tidak ada perubahan.');
    }

    public function simpanSaldoAwal(Request $request): RedirectResponse
    {
        $angka = ['nullable', 'integer', 'min:0', 'max:99999999999'];
        $data = $request->validate([
            'tahun' => ['required', 'integer', 'min:2024', 'max:2100'],
            'saldo' => ['required', 'array'],
            'saldo.*.months' => ['nullable', 'integer', 'min:0', 'max:11'],
            'saldo.*.bruto' => $angka,
            'saldo.*.iuran' => $angka,
            'saldo.*.pph21' => $angka,
        ], ['saldo.*.months.max' => 'Jumlah bulan di luar portal paling banyak 11.', 'saldo.*.*.integer' => 'Isi angka bulat tanpa titik atau koma.']);

        $diubah = 0;
        foreach (Employee::with('payrollProfile')->whereIn('id', array_keys($data['saldo']))->get() as $e) {
            $in = $data['saldo'][$e->id];
            $nilai = [
                'opening_months' => (int) ($in['months'] ?? 0), 'opening_bruto' => (int) ($in['bruto'] ?? 0),
                'opening_iuran' => (int) ($in['iuran'] ?? 0), 'opening_pph21' => (int) ($in['pph21'] ?? 0),
            ];
            $kosong = ! array_filter($nilai);
            if (! $e->payrollProfile && $kosong) {
                continue;
            }
            $profil = $e->payrollProfile ?? new PayrollProfile(['employee_id' => $e->id]);
            $profil->fill($nilai + ['opening_year' => $kosong ? null : (int) $data['tahun']]);
            if ($profil->exists && ! $profil->isDirty()) {
                continue;
            }
            $profil->save();
            $diubah++;
            AuditService::log(action: 'update_payroll_opening', targetType: 'employee', targetId: $e->id,
                after: ['nama' => $e->name, 'tahun' => (int) $data['tahun']]);
        }

        return back()->with('status', $diubah ? "Saldo awal {$diubah} karyawan disimpan." : 'Tidak ada perubahan.');
    }

    public function simpanSetelan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kes_cap' => ['required', 'integer', 'min:1000000', 'max:1000000000'],
            'jp_cap' => ['required', 'integer', 'min:1000000', 'max:1000000000'],
            'jkk_bps' => ['required', Rule::in(array_keys(PayrollService::JKK))],
        ], ['*.integer' => 'Isi angka bulat tanpa titik atau koma.']);

        $sebelum = $this->svc->setelan();
        foreach (PayrollService::SETELAN as $k => [$kunci]) {
            AppSetting::put($kunci, (string) (int) $data[$k]);
        }
        AuditService::log(action: 'update_payroll_settings', targetType: 'app_setting', before: $sebelum, after: $this->svc->setelan());

        return back()->with('status', 'Setelan BPJS disimpan — berlaku untuk hitungan berikutnya (draf yang sudah ada: klik "Ambil ulang data gaji").');
    }

    /** Peringatan sebelum dikunci. @return array<int, string> */
    private function peringatan(PayrollRun $run, Collection $items): array
    {
        $out = [];
        $belum = $this->svc->belumAdaGaji();
        if ($belum->isNotEmpty()) {
            $out[] = 'Belum ada data gaji (tidak ikut payroll ini): '.$belum->pluck('name')->implode(', ').'.';
        }
        $tanpaPtkp = $items->filter(fn ($i) => ! isset(Pajak::PTKP[$i->employee?->ptkp_status]))->pluck('employee_name');
        if ($tanpaPtkp->isNotEmpty()) {
            $out[] = 'Status PTKP belum diisi di data karyawan (dihitung TK/0): '.$tanpaPtkp->implode(', ').'.';
        }
        foreach ($items->where('annual', true) as $i) {
            $masuk = $i->employee?->join_date;
            $mulai = $masuk && $masuk->year === $run->period->year ? $masuk->month : 1;
            $kurang = ($run->period->month - $mulai + 1) - (int) ($i->tax_detail['bulan'] ?? 0);
            if ($kurang > 0) {
                $out[] = "PPh 21 setahun {$i->employee_name}: data {$kurang} bulan tahun ini belum ada (payroll belum dikunci / digaji di luar portal) — isi Saldo awal di halaman Data gaji.";
            }
        }
        if (PayrollRun::where('status', 'draf')->whereYear('period', $run->period->year)->whereDate('period', '<', $run->period->toDateString())->exists()) {
            $out[] = 'Payroll bulan sebelumnya di tahun ini masih draf — kunci dulu sebelum mengunci bulan ini.';
        }

        return $out;
    }
}
