<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Services\AuditService;
use App\Services\KolGapokService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Tim Affiliate Gapok: performa affiliate yang digaji pokok + gaji per bulan +
 * ROI. Angka uang → gate kol.affiliate.view; tandai anggota & set gaji →
 * kol.affiliate.manage.
 */
class KolGapokController extends Controller
{
    public function index(Request $request, KolGapokService $svc)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? (string) $request->query('bulan') : now()->format('Y-m');
        $m = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $rows = $svc->monthly($m);
        $canManage = $request->user()->canDo('kol.affiliate.manage');

        return view('kols.gapok.index', [
            'month' => $month,
            'rows' => $rows,
            'totals' => $svc->totals($rows),
            'canManage' => $canManage,
            // Untuk form "tambah anggota": KOL yang belum ditandai gapok.
            'nonGapok' => $canManage
                ? Kol::where('is_gapok', false)->orderBy('tiktok_username')->get(['id', 'tiktok_username', 'name'])
                : collect(),
            'prevMonth' => $m->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $m->copy()->addMonth()->format('Y-m'),
        ]);
    }

    /** Tandai atau lepas seorang KOL sebagai anggota Tim Gapok. */
    public function toggle(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'is_gapok' => ['required', 'boolean'],
        ]);
        Kol::whereKey($d['kol_id'])->update(['is_gapok' => $d['is_gapok']]);

        AuditService::log(action: 'toggle_kol_gapok', targetType: 'kol', targetId: (int) $d['kol_id'],
            after: ['is_gapok' => (bool) $d['is_gapok']]);

        return back()->with('status', $d['is_gapok'] ? 'Anggota gapok ditambahkan.' : 'Dikeluarkan dari Tim Gapok.');
    }

    /** Simpan gaji pokok satu anggota untuk bulan terpilih. */
    public function saveSalary(Request $request, KolGapokService $svc): RedirectResponse
    {
        $d = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'monthly_salary' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $m = Carbon::createFromFormat('Y-m', $d['bulan'])->startOfMonth();
        $svc->setSalary((int) $d['kol_id'], $m, (int) $d['monthly_salary'], $d['note'] ?? null, $request->user()->id);

        return back()->with('status', 'Gaji disimpan.');
    }
}
