<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolImportBatch;
use App\Models\KolWeeklyStat;
use App\Services\AuditService;
use App\Services\KolAffiliateService;
use App\Services\KolScoringService;
use App\Support\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Affiliate & GMV (Fase 3a): ranking GMV per creator + layar "Belum Cocok"
 * (tautkan username asing ke KOL). Angka uang → gated kol.affiliate.view.
 */
class KolAffiliateController extends Controller
{
    public function index(Request $request, KolAffiliateService $svc, KolScoringService $scoring)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? (string) $request->query('bulan') : now()->format('Y-m');
        $m = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $ranking = $svc->monthly($m);
        $unmatched = $svc->unmatched();
        $canManage = $request->user()->canDo('kol.affiliate.manage');

        // APS per creator (skor potensi affiliate) — kol_id => hasil aps.
        $aps = $ranking->mapWithKeys(fn ($r) => [$r->kol_id => $scoring->aps($svc->apsInput((int) $r->kol_id, $m))]);

        // Views + RPM per creator + agregat; sparkline GMV 4 minggu.
        $viewsMap = $svc->monthlyViews($m);
        $totalGmv = (int) $ranking->sum('gmv');
        $totalViews = (int) $viewsMap->sum();
        $upTo = $m->isSameMonth(now()) ? now() : $m->copy()->endOfMonth();
        $rpmMap = $ranking->mapWithKeys(function ($r) use ($viewsMap) {
            $v = (int) ($viewsMap[$r->kol_id] ?? 0);

            return [$r->kol_id => ['views' => $v, 'rpm' => $v > 0 ? (int) round($r->gmv / $v * 1000) : null]];
        });
        $sparkMap = $ranking->mapWithKeys(fn ($r) => [$r->kol_id => $svc->weeklyGmv((int) $r->kol_id, $upTo, 4)]);
        $gmvTarget = (int) AppSetting::get('kol_gmv_target', '0');

        return view('kols.affiliate.index', [
            'month' => $month,
            'ranking' => $ranking,
            'aps' => $aps,
            'apsLabels' => KolScoringService::APS_LABEL,
            'summary' => [
                'gmv' => $totalGmv,
                'commission' => (int) $ranking->sum('commission'),
                'settled' => (int) $ranking->sum('commission_settled'),
                'orders' => (int) $ranking->sum('orders'),
                'affiliates' => $ranking->count(),
                'rpm' => $totalViews > 0 ? (int) round($totalGmv / $totalViews * 1000) : null,
                'views' => $totalViews,
            ],
            'gmvTarget' => $gmvTarget,
            'rpmMap' => $rpmMap,
            'sparkMap' => $sparkMap,
            'weekly' => $svc->monthlyWeeklyGmv($m),
            'unmatched' => $unmatched,
            'canManage' => $canManage,
            'kols' => $canManage ? Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']) : collect(),
            'weeklyStats' => $canManage ? KolWeeklyStat::with('kol')->latest('week_start')->latest('id')->limit(24)->get() : collect(),
            'batches' => $canManage ? KolImportBatch::with('creator')->latest('id')->limit(10)->get() : collect(),
            'prevMonth' => $m->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $m->copy()->addMonth()->format('Y-m'),
        ]);
    }

    /**
     * Daftar transaksi affiliate per-order (data sudah tersimpan di
     * kol_affiliate_transactions, sebelumnya hanya diagregat jadi ranking).
     * Filter platform/creator/status + paginasi. Gated kol.affiliate.view.
     */
    public function transactions(Request $request, KolAffiliateService $svc)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? (string) $request->query('bulan') : now()->format('Y-m');
        $m = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $start = $m->copy()->startOfMonth();
        $end = $m->copy()->endOfMonth();

        $platform = in_array($request->query('platform'), ['tiktok', 'shopee'], true) ? $request->query('platform') : null;
        $kolId = ctype_digit((string) $request->query('kol_id')) ? (int) $request->query('kol_id') : null;
        $status = in_array($request->query('status'), ['valid', 'cancelled'], true) ? $request->query('status') : 'all';

        // Basis lingkup (bulan + platform + creator), tanpa toggle status.
        $base = fn () => KolAffiliateTransaction::query()
            ->whereBetween('order_date', [$start, $end])
            ->when($platform, fn ($q) => $q->where('platform', $platform))
            ->when($kolId, fn ($q) => $q->where('kol_id', $kolId));

        // Daftar = basis + toggle status.
        $list = $base()->with('kol')->latest('order_date')->orderByDesc('id');
        if ($status === 'valid') {
            $list->notCancelled();
        } elseif ($status === 'cancelled') {
            $list->whereRaw('LOWER(status) IN (?, ?, ?, ?)', KolAffiliateTransaction::CANCELLED);
        }

        return view('kols.affiliate.transactions', [
            'month' => $month,
            'rows' => $list->paginate(30)->withQueryString(),
            'stats' => [
                'total' => $base()->count(),
                'cancelled' => $base()->whereRaw('LOWER(status) IN (?, ?, ?, ?)', KolAffiliateTransaction::CANCELLED)->count(),
                'gmv' => (int) $base()->notCancelled()->sum('gmv'),
                'commission' => (int) $base()->notCancelled()->sum('commission'),
                'unmatched' => $base()->unmatched()->count(),
            ],
            'filters' => ['platform' => $platform, 'kol_id' => $kolId, 'status' => $status],
            'kols' => Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']),
            'prevMonth' => $m->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $m->copy()->addMonth()->format('Y-m'),
        ]);
    }

    /** Tautkan semua transaksi sebuah username ke KOL (dari layar Belum Cocok). */
    public function match(Request $request, KolAffiliateService $svc): RedirectResponse
    {
        $data = $request->validate([
            'raw_username' => ['required', 'string', 'max:150'],
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
        ]);

        $n = $svc->matchUsername($data['raw_username'], $data['kol_id']);

        AuditService::log(action: 'match_kol_affiliate', targetType: 'kol', targetId: $data['kol_id'],
            after: ['username' => $data['raw_username'], 'orders' => $n]);

        return back()->with('status', "{$n} order ditautkan ke KOL.");
    }

    public function saveGmvTarget(Request $request): RedirectResponse
    {
        $d = $request->validate(['gmv_target' => ['required', 'integer', 'min:0']]);
        AppSetting::put('kol_gmv_target', (string) $d['gmv_target']);

        return back()->with('status', 'Target GMV disimpan.');
    }

    /** Input statistik mingguan manual per creator (isi ulang minggu sama = perbarui). */
    public function weeklyStatStore(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'week_start' => ['required', 'date'],
            'gmv' => ['nullable', 'integer', 'min:0'], 'orders' => ['nullable', 'integer', 'min:0'],
            'commission' => ['nullable', 'integer', 'min:0'], 'content_count' => ['nullable', 'integer', 'min:0'],
            'views' => ['nullable', 'integer', 'min:0'],
        ]);
        KolWeeklyStat::updateOrCreate(
            ['kol_id' => $d['kol_id'], 'week_start' => Carbon::parse($d['week_start'])->startOfWeek()->toDateString()],
            ['gmv' => $d['gmv'] ?? 0, 'orders' => $d['orders'] ?? 0, 'commission' => $d['commission'] ?? 0,
                'content_count' => $d['content_count'] ?? 0, 'views' => $d['views'] ?? 0, 'created_by' => $request->user()->id],
        );

        return back()->with('status', 'Statistik mingguan disimpan.');
    }

    public function weeklyStatDestroy(KolWeeklyStat $stat): RedirectResponse
    {
        $stat->delete();

        return back()->with('status', 'Statistik mingguan dihapus.');
    }

    /** Alias header umum (TikTok Affiliate Center / Shopee / export app Iyuro). */
    private const ALIASES = [
        'username' => ['username', 'creator username', 'creator', 'handle', 'nama creator', 'akun', 'creator name'],
        'order_id' => ['order id', 'order_id', 'id pesanan', 'no pesanan', 'nomor pesanan', 'order', 'order sn'],
        'gmv' => ['gmv', 'total', 'penjualan', 'total penjualan', 'omzet', 'sales', 'total amount', 'pay amount', 'subtotal'],
        'commission' => ['commission', 'komisi', 'estimasi komisi', 'est commission', 'estimated commission', 'komisi estimasi'],
        'commission_settled' => ['actual commission', 'settled commission', 'komisi aktual', 'komisi bersih', 'komisi settled', 'net commission'],
        'content_type' => ['content type', 'tipe konten', 'channel', 'kanal', 'jenis konten'],
        'qty' => ['qty', 'quantity', 'jumlah', 'item sold', 'units', 'kuantitas'],
        'product' => ['product', 'produk', 'product name', 'nama produk', 'item'],
        'status' => ['status', 'order status', 'status pesanan'],
        'order_date' => ['date', 'order date', 'tanggal', 'tanggal pesanan', 'create time', 'created time', 'waktu pesanan', 'order create time'],
    ];

    public function importForm()
    {
        return view('kols.affiliate.import');
    }

    public function importStore(Request $request, KolAffiliateService $svc): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'platform' => ['required', Rule::in(['tiktok', 'shopee'])],
        ]);

        $file = $request->file('file');
        $rows = SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension());
        $mapped = $this->mapRows($rows);
        if ($mapped === []) {
            return back()->withErrors(['file' => 'File kosong atau header tak dikenali (butuh kolom username, order id, gmv).']);
        }

        $res = $svc->import($mapped, (string) $request->input('platform'), $request->user()->id);
        KolImportBatch::create([
            'platform' => (string) $request->input('platform'), 'source' => 'import',
            'filename' => $file->getClientOriginalName(),
            'imported' => $res['imported'], 'matched' => $res['matched'], 'unmatched' => $res['unmatched'],
            'created_by' => $request->user()->id,
        ]);
        AuditService::log(action: 'import_kol_affiliate', targetType: 'kol_affiliate', targetId: 0,
            after: ['platform' => $request->input('platform')] + $res);

        return redirect()->route('kol-affiliate.index')->with('status',
            "{$res['imported']} transaksi diimport — {$res['matched']} cocok, {$res['unmatched']} belum cocok.");
    }

    /** Baris file → shape KolAffiliateService::import (auto-map header + bersihkan angka/tanggal). */
    private function mapRows(array $raw): array
    {
        if ($raw === []) {
            return [];
        }
        $norm = fn ($s) => preg_replace('/\s+/', ' ', trim(mb_strtolower((string) $s)));

        $col = [];
        foreach ($raw[0] as $i => $name) {
            $n = $norm($name);
            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($col[$field]) && in_array($n, $aliases, true)) {
                    $col[$field] = $i;
                }
            }
        }
        if (! isset($col['order_id'], $col['username'])) {
            return []; // header wajib tak ketemu
        }

        $out = [];
        foreach (array_slice($raw, 1) as $cells) {
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $rec = [];
            foreach ($col as $field => $i) {
                $rec[$field] = trim((string) ($cells[$i] ?? ''));
            }
            foreach (['gmv', 'commission', 'commission_settled', 'qty'] as $num) {
                if (isset($rec[$num])) {
                    $rec[$num] = (int) preg_replace('/[^\d]/', '', $rec[$num]);
                }
            }
            if (! empty($rec['order_date'])) {
                $rec['order_date'] = $this->parseDate($rec['order_date']);
            }
            $out[] = $rec;
        }

        return $out;
    }

    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^\d{10,13}$/', $v)) {
            $ts = strlen($v) >= 13 ? (int) ($v / 1000) : (int) $v;

            return Carbon::createFromTimestamp($ts)->toDateString();
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
