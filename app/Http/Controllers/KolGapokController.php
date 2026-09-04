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
        [$from, $to, $mode, $label] = $this->resolveRange($request);
        $m = $from->copy()->startOfMonth();
        $rows = $svc->range($from, $to, $from); // gaji dari bulan tanggal-mulai
        $canManage = $request->user()->canDo('kol.affiliate.manage');

        return view('kols.gapok.index', [
            'month' => $from->format('Y-m'),
            'mode' => $mode,                 // month | today | 7d | 30d | custom
            'periodLabel' => $label,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
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

    /**
     * Rentang tanggal aktif dari query: ?dari&?sampai (custom), ?preset
     * (today|7d|30d), atau ?bulan / default (bulan berjalan).
     *
     * @return array{0:Carbon,1:Carbon,2:string,3:string} [from, to, mode, label]
     */
    private function resolveRange(Request $request): array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $dari = (string) $request->query('dari');
        if (preg_match($re, $dari)) {
            $from = Carbon::parse($dari)->startOfDay();
            $sampai = (string) $request->query('sampai');
            $to = (preg_match($re, $sampai) ? Carbon::parse($sampai) : $from->copy())->endOfDay();
            if ($to->lt($from)) {
                $to = $from->copy()->endOfDay();
            }

            return [$from, $to, 'custom', $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y')];
        }

        $preset = (string) $request->query('preset');
        if ($preset === 'today') {
            return [now()->startOfDay(), now()->endOfDay(), 'today', 'Hari ini'];
        }
        if ($preset === '7d') {
            return [now()->subDays(6)->startOfDay(), now()->endOfDay(), '7d', '7 hari terakhir'];
        }
        if ($preset === '30d') {
            return [now()->subDays(29)->startOfDay(), now()->endOfDay(), '30d', '30 hari terakhir'];
        }

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? (string) $request->query('bulan') : now()->format('Y-m');
        $m = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [$m->copy()->startOfMonth(), $m->copy()->endOfMonth(), 'month', $m->translatedFormat('F Y')];
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
