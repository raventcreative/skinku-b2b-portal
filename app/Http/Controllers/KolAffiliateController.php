<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolImportBatch;
use App\Models\KolMonthlyTarget;
use App\Models\KolUsernameAlias;
use App\Models\KolWeeklyStat;
use App\Services\AuditService;
use App\Services\KolAffiliateService;
use App\Services\KolScoringService;
use App\Support\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
        $gmvTarget = KolMonthlyTarget::forMonth($m)?->gmv_target ?? (int) AppSetting::get('kol_gmv_target', '0');

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

        $n = $svc->matchUsername($data['raw_username'], $data['kol_id'], $request->user()->id);

        AuditService::log(action: 'match_kol_affiliate', targetType: 'kol', targetId: $data['kol_id'],
            after: ['username' => $data['raw_username'], 'orders' => $n]);

        return back()->with('status', "{$n} order ditautkan ke KOL — import berikutnya untuk username ini otomatis cocok.");
    }

    /**
     * Angkat username affiliate belum-cocok jadi entri KOL baru (peran affiliate)
     * + tautkan semua transaksinya. Kalau username sudah jadi KOL, cukup tautkan.
     */
    public function promote(Request $request, KolAffiliateService $svc): RedirectResponse
    {
        $data = $request->validate(['raw_username' => ['required', 'string', 'max:150']]);
        $norm = KolUsernameAlias::norm($data['raw_username']);
        if ($norm === '') {
            return back()->withErrors(['raw_username' => 'Username kosong.']);
        }

        $kol = Kol::whereRaw('LOWER(tiktok_username) = ?', [$norm])->first();
        $baru = $kol === null;
        if ($baru) {
            $kol = Kol::create(['tiktok_username' => $norm, 'role' => 'affiliate', 'followers' => 0]);
        }
        $n = $svc->matchUsername($data['raw_username'], $kol->id, $request->user()->id);

        AuditService::log(action: 'promote_affiliate_to_kol', targetType: 'kol', targetId: $kol->id,
            after: ['username' => $norm, 'baru' => $baru, 'orders' => $n]);

        return back()->with('status', $baru
            ? "@{$norm} ditambahkan ke Database KOL (peran: affiliate) — {$n} order tertaut."
            : "@{$norm} sudah ada di Database KOL — {$n} order tertaut.");
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

    /** Label field (urutan tetap) untuk wizard pemetaan kolom. * = wajib. */
    private const FIELD_LABELS = [
        'order_id' => 'Order ID *',
        'username' => 'Username creator *',
        'gmv' => 'GMV',
        'commission' => 'Komisi (estimasi)',
        'commission_settled' => 'Komisi aktual / settled',
        'content_type' => 'Tipe konten / channel',
        'qty' => 'Qty',
        'product' => 'Produk',
        'status' => 'Status',
        'order_date' => 'Tanggal order',
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
        $this->logBatch($request, $file->getClientOriginalName(), 'import', $res);

        return redirect()->route('kol-affiliate.index')->with('status',
            "{$res['imported']} transaksi diimport — {$res['matched']} cocok, {$res['unmatched']} belum cocok.");
    }

    /**
     * Langkah 1 wizard: baca file → simpan baris terparse ke temp → tampilkan
     * pemetaan kolom (tebakan otomatis + mapping tersimpan) + preview 20 baris.
     */
    public function importPreview(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'platform' => ['required', Rule::in(['tiktok', 'shopee'])],
        ]);

        $file = $request->file('file');
        $rows = SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension());
        if (count($rows) < 2) {
            return back()->withErrors(['file' => 'File kosong atau tak ada baris data di bawah header.']);
        }

        $token = bin2hex(random_bytes(8));
        Storage::disk('local')->put("kol-import/{$token}.json", json_encode($rows));

        $platform = (string) $request->input('platform');
        $saved = json_decode((string) AppSetting::get("kol_import_map_{$platform}", ''), true) ?: [];

        return view('kols.affiliate.import-map', [
            'token' => $token,
            'platform' => $platform,
            'filename' => $file->getClientOriginalName(),
            'header' => $rows[0],
            'preview' => array_slice($rows, 1, 20),
            'guess' => $this->guessColMap($rows[0], is_array($saved) ? $saved : []),
            'fields' => self::FIELD_LABELS,
            'dateOrder' => AppSetting::get('kol_import_date_order', 'auto'),
            'rowCount' => count($rows) - 1,
        ]);
    }

    /** Langkah 2 wizard: terapkan pemetaan pilihan user + urutan tanggal → import. */
    public function importCommit(Request $request, KolAffiliateService $svc): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'regex:/^[a-f0-9]{16}$/'],
            'platform' => ['required', Rule::in(['tiktok', 'shopee'])],
            'filename' => ['nullable', 'string', 'max:255'],
            'date_order' => ['required', 'in:auto,dmy,mdy'],
            'map' => ['required', 'array'],
            'map.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $path = "kol-import/{$data['token']}.json";
        if (! Storage::disk('local')->exists($path)) {
            return redirect()->route('kol-affiliate.import')->withErrors(['file' => 'Sesi import kedaluwarsa — unggah ulang filenya.']);
        }
        $rows = json_decode((string) Storage::disk('local')->get($path), true) ?: [];

        // colMap dari pilihan user: buang yang kosong / field tak dikenal.
        $colMap = [];
        foreach ($data['map'] as $field => $idx) {
            if ($idx !== null && $idx !== '' && isset(self::ALIASES[$field])) {
                $colMap[$field] = (int) $idx;
            }
        }
        if (! isset($colMap['order_id'], $colMap['username'])) {
            return back()->withErrors(['map' => 'Kolom Order ID dan Username creator wajib dipetakan.'])->withInput();
        }

        $mapped = $this->buildRecords($rows, $colMap, $data['date_order']);
        if ($mapped === []) {
            return back()->withErrors(['map' => 'Tak ada baris data untuk diimport.'])->withInput();
        }

        $res = $svc->import($mapped, $data['platform'], $request->user()->id);

        // Ingat pemetaan (field → nama header) + urutan tanggal untuk file berikutnya.
        $header = $rows[0];
        $savedMap = [];
        foreach ($colMap as $field => $idx) {
            $savedMap[$field] = (string) ($header[$idx] ?? '');
        }
        AppSetting::put("kol_import_map_{$data['platform']}", json_encode($savedMap));
        AppSetting::put('kol_import_date_order', $data['date_order']);

        $this->logBatch($request, $data['filename'] ?? 'wizard', 'wizard', $res);
        Storage::disk('local')->delete($path);

        return redirect()->route('kol-affiliate.index')->with('status',
            "{$res['imported']} transaksi diimport — {$res['matched']} cocok, {$res['unmatched']} belum cocok.");
    }

    private function logBatch(Request $request, string $filename, string $source, array $res): void
    {
        KolImportBatch::create([
            'platform' => (string) $request->input('platform'), 'source' => $source,
            'filename' => $filename,
            'imported' => $res['imported'], 'matched' => $res['matched'], 'unmatched' => $res['unmatched'],
            'created_by' => $request->user()->id,
        ]);
        AuditService::log(action: 'import_kol_affiliate', targetType: 'kol_affiliate', targetId: 0,
            after: ['platform' => $request->input('platform'), 'source' => $source] + $res);
    }

    /** Tebak field → index kolom: mapping tersimpan (field→nama header) menang, sisanya via alias. */
    private function guessColMap(array $header, array $saved = []): array
    {
        $norm = fn ($s) => preg_replace('/\s+/', ' ', trim(mb_strtolower((string) $s)));
        $normHeader = array_map($norm, $header);

        $col = [];
        foreach ($saved as $field => $headerName) {
            if (! isset(self::ALIASES[$field])) {
                continue;
            }
            $idx = array_search($norm($headerName), $normHeader, true);
            if ($idx !== false) {
                $col[$field] = $idx;
            }
        }
        foreach ($normHeader as $i => $n) {
            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($col[$field]) && in_array($n, $aliases, true)) {
                    $col[$field] = $i;
                }
            }
        }

        return $col;
    }

    /** Baris → record sesuai colMap (field→index) + urutan tanggal; bersihkan angka. */
    private function buildRecords(array $raw, array $colMap, string $dateOrder): array
    {
        $out = [];
        foreach (array_slice($raw, 1) as $cells) {
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $rec = [];
            foreach ($colMap as $field => $i) {
                $rec[$field] = trim((string) ($cells[$i] ?? ''));
            }
            foreach (['gmv', 'commission', 'commission_settled', 'qty'] as $num) {
                if (isset($rec[$num])) {
                    $rec[$num] = (int) preg_replace('/[^\d]/', '', $rec[$num]);
                }
            }
            if (! empty($rec['order_date'])) {
                $rec['order_date'] = $this->parseDate($rec['order_date'], $dateOrder);
            }
            $out[] = $rec;
        }

        return $out;
    }

    /** Auto-map (buat importStore langsung): tebak header, wajib order_id+username. */
    private function mapRows(array $raw): array
    {
        if ($raw === []) {
            return [];
        }
        $col = $this->guessColMap($raw[0]);
        if (! isset($col['order_id'], $col['username'])) {
            return [];
        }

        return $this->buildRecords($raw, $col, 'auto');
    }

    /** Tanggal string → Y-m-d. dateOrder dmy/mdy menafsirkan "03/04/2026" secara tegas. */
    private function parseDate(string $v, string $dateOrder = 'auto'): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{10,13}$/', $v)) {
            $ts = strlen($v) >= 13 ? (int) ($v / 1000) : (int) $v;

            return Carbon::createFromTimestamp($ts)->toDateString();
        }
        // Urutan eksplisit: pisahkan komponen d/m/y, tafsirkan sesuai pilihan.
        if ($dateOrder !== 'auto' && preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})#', $v, $mt)) {
            $day = $dateOrder === 'dmy' ? (int) $mt[1] : (int) $mt[2];
            $mon = $dateOrder === 'dmy' ? (int) $mt[2] : (int) $mt[1];
            $yr = (int) (strlen($mt[3]) === 2 ? '20'.$mt[3] : $mt[3]);
            if (checkdate($mon, $day, $yr)) {
                return Carbon::create($yr, $mon, $day)->toDateString();
            }
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
