<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolCreatorContent;
use App\Models\KolGapokPayment;
use App\Models\KolUsernameAlias;
use App\Services\AuditService;
use App\Services\KolAffiliateService;
use App\Services\KolGapokService;
use Illuminate\Http\JsonResponse;
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
        if ($d['is_gapok']) {
            // Catat tanggal gabung otomatis (kalau belum pernah diisi).
            Kol::whereKey($d['kol_id'])->whereNull('gapok_joined_at')->update(['gapok_joined_at' => now()->toDateString()]);
        }

        AuditService::log(action: 'toggle_kol_gapok', targetType: 'kol', targetId: (int) $d['kol_id'],
            after: ['is_gapok' => (bool) $d['is_gapok']]);

        return back()->with('status', $d['is_gapok'] ? 'Anggota gapok ditambahkan.' : 'Dikeluarkan dari Tim Gapok.');
    }

    /** Detail konten (video/LIVE) satu kreator untuk satu bulan — daftar + link. */
    public function contents(Request $request, Kol $kol)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? (string) $request->query('bulan') : now()->format('Y-m');
        $period = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();

        $items = KolCreatorContent::where('kol_id', $kol->id)->where('period', $period)
            ->orderByDesc('gmv')->get();

        return view('kols.gapok.contents', [
            'kol' => $kol,
            'month' => $month,
            'videos' => $items->where('type', 'video')->values(),
            'lives' => $items->where('type', 'live')->values(),
            'focus' => in_array($request->query('type'), ['video', 'live'], true) ? (string) $request->query('type') : 'video',
        ]);
    }

    /**
     * Tambah anggota gapok cukup dengan username — bikin KOL baru (peran affiliate)
     * kalau belum ada, lalu tandai gapok + tautkan transaksi affiliate lama yang
     * username-nya cocok (biar angkanya langsung muncul kalau memang sudah jualan).
     */
    public function addByUsername(Request $request, KolAffiliateService $aff): RedirectResponse
    {
        $d = $request->validate(['username' => ['required', 'string', 'max:150']]);
        $norm = KolUsernameAlias::norm($d['username']);
        if ($norm === '') {
            return back()->withErrors(['username' => 'Username kosong.']);
        }

        $kol = Kol::whereRaw('LOWER(tiktok_username) = ?', [$norm])->first()
            ?? Kol::find(KolUsernameAlias::where('username', $norm)->value('kol_id'));

        $baru = $kol === null;
        if ($baru) {
            $kol = Kol::create(['tiktok_username' => $norm, 'role' => 'affiliate', 'followers' => 0, 'is_gapok' => true, 'gapok_joined_at' => now()->toDateString()]);
        } else {
            $kol->update(['is_gapok' => true] + ($kol->gapok_joined_at ? [] : ['gapok_joined_at' => now()->toDateString()]));
        }
        $aff->matchUsername($norm, $kol->id, $request->user()->id);

        AuditService::log(action: 'add_gapok_by_username', targetType: 'kol', targetId: $kol->id, after: ['username' => $norm, 'baru' => $baru]);

        return back()->with('status', $baru
            ? "@{$norm} dibuat & ditandai gapok — isi gajinya di baris tabel."
            : "@{$norm} ditandai gapok.");
    }

    /** Simpan gaji pokok satu anggota untuk bulan terpilih (AJAX → JSON). */
    public function saveSalary(Request $request, KolGapokService $svc): RedirectResponse|JsonResponse
    {
        $d = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'monthly_salary' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $m = Carbon::createFromFormat('Y-m', $d['bulan'])->startOfMonth();
        $svc->setSalary((int) $d['kol_id'], $m, (int) $d['monthly_salary'], $d['note'] ?? null, $request->user()->id);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'salary' => (int) $d['monthly_salary']]);
        }

        return back()->with('status', 'Gaji disimpan.');
    }

    /** Simpan tanggal gabung anggota (AJAX → JSON). Boleh dikosongkan. */
    public function saveJoinDate(Request $request): RedirectResponse|JsonResponse
    {
        $d = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'joined_at' => ['nullable', 'date'],
        ]);
        Kol::whereKey($d['kol_id'])->update(['gapok_joined_at' => $d['joined_at'] ?? null]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Tanggal gabung disimpan.');
    }

    /** Catat satu pembayaran (cicilan) gaji anggota utk bulan terpilih (AJAX → JSON). */
    public function addPayment(Request $request, KolGapokService $svc): RedirectResponse|JsonResponse
    {
        $d = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => ['required', 'integer', 'min:1'],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $m = Carbon::createFromFormat('Y-m', $d['bulan'])->startOfMonth();
        $p = $svc->addPayment((int) $d['kol_id'], $m, (int) $d['amount'], Carbon::parse($d['paid_at']), $d['note'] ?? null, $request->user()->id);

        AuditService::log(action: 'add_gapok_payment', targetType: 'kol', targetId: (int) $d['kol_id'],
            after: ['bulan' => $d['bulan'], 'amount' => (int) $d['amount']]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'payment' => ['id' => $p->id, 'amount' => $p->amount, 'paid_at' => $p->paid_at->toDateString()],
                'paid_total' => $svc->paidTotal((int) $d['kol_id'], $m),
            ]);
        }

        return back()->with('status', 'Pembayaran dicatat.');
    }

    /** Hapus satu pembayaran (AJAX → JSON, kembalikan total dibayar terbaru). */
    public function deletePayment(Request $request, KolGapokPayment $payment, KolGapokService $svc): RedirectResponse|JsonResponse
    {
        $kolId = (int) $payment->kol_id;
        $m = Carbon::parse($payment->period)->startOfMonth();
        $payment->delete();

        AuditService::log(action: 'delete_gapok_payment', targetType: 'kol', targetId: $kolId,
            before: ['amount' => (int) $payment->amount]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'paid_total' => $svc->paidTotal($kolId, $m)]);
        }

        return back()->with('status', 'Pembayaran dihapus.');
    }
}
