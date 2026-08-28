<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolContentSnapshot;
use App\Models\KolDeal;
use App\Models\KolMonthlyTarget;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * Konten & Views KOL: arsip konten per KOL + views bertanggal (snapshot).
 * Label paid/earned anti-dobel-hitung: konten ber-deal DIPAKSA paid. Ringkasan
 * bulanan dengan proyeksi pace vs target views (AppSetting kol_views_target).
 */
class KolContentController extends Controller
{
    private const TARGET_KEY = 'kol_views_target';

    public function index(Request $request)
    {
        $month = $this->month($request);
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $filters = $request->only(['creator', 'platform', 'label', 'type']);

        $contents = KolContent::with(['kol', 'deal', 'latestSnapshot'])
            ->whereBetween('posted_at', [$start, $start->copy()->endOfMonth()])
            ->when($filters['creator'] ?? null, fn ($q, $v) => $q->where('kol_id', $v))
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($filters['label'] ?? null, fn ($q, $v) => $q->where('label', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('content_type', $v))
            ->orderByDesc('posted_at')->get();

        $views = fn ($c) => (int) ($c->latestSnapshot->views ?? 0);
        $total = $contents->sum($views);
        $paid = $contents->where('label', 'paid')->sum($views);
        // Override target per-bulan menang atas setelan global (bila diisi).
        $target = KolMonthlyTarget::forMonth($start)?->views_target ?? (int) AppSetting::get(self::TARGET_KEY, '1000000');
        $isCurrent = $month === now()->format('Y-m');
        $proj = $isCurrent ? (int) round($total * ($start->daysInMonth / max(1, now()->day))) : $total;

        // Kebutuhan views/hari untuk kejar target (bulan berjalan).
        $daysLeft = $isCurrent ? max(1, $start->daysInMonth - now()->day + 1) : 0;
        $perDayNeeded = ($isCurrent && $target > $total) ? (int) ceil(($target - $total) / $daysLeft) : 0;

        return view('kols.konten.index', [
            'month' => $month, 'contents' => $contents, 'total' => $total,
            'paid' => $paid, 'earned' => $total - $paid,
            'target' => $target, 'proj' => $proj, 'isCurrent' => $isCurrent,
            'aman' => $target > 0 && $proj >= 0.95 * $target,
            'daysLeft' => $daysLeft, 'perDayNeeded' => $perDayNeeded,
            'filters' => $filters,
            'kols' => Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']),
            'platforms' => config('kol.platforms'),
            'types' => KolContent::TYPE_LABELS,
            'prevMonth' => $start->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $start->copy()->addMonth()->format('Y-m'),
        ]);
    }

    public function create(Request $request)
    {
        // Prefill dari query (?deal / ?creator / ?url) — mis. dari halaman detail deal.
        $content = new KolContent([
            'posted_at' => now(),
            'kol_id' => ctype_digit((string) $request->query('creator')) ? (int) $request->query('creator') : null,
            'kol_deal_id' => ctype_digit((string) $request->query('deal')) ? (int) $request->query('deal') : null,
            'url' => is_string($request->query('url')) ? $request->query('url') : null,
        ]);
        // Deal diberi tanpa creator → tarik creator dari deal (konten deal = paid).
        if ($content->kol_deal_id && ! $content->kol_id) {
            $content->kol_id = KolDeal::whereKey($content->kol_deal_id)->value('kol_id');
        }

        return view('kols.konten.form', $this->formData($content));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $content = KolContent::create($data + ['created_by' => $request->user()->id]);

        // Views awal + metrik awal → snapshot pertama (opsional).
        if ($request->filled('views_awal')) {
            $content->snapshots()->create([
                'views' => (int) $request->input('views_awal'),
                'likes' => $request->input('likes_awal'), 'comments' => $request->input('comments_awal'),
                'shares' => $request->input('shares_awal'), 'saves' => $request->input('saves_awal'),
                'captured_on' => now()->startOfDay(), 'source' => 'manual', 'created_by' => $request->user()->id,
            ]);
        }

        AuditService::log(action: 'create_kol_content', targetType: 'kol_content', targetId: $content->id,
            after: ['kol' => $content->kol->tiktok_username, 'label' => $content->label, 'url' => $content->url]);

        return redirect()->route('kol-konten.index', ['bulan' => $content->posted_at->format('Y-m')])
            ->with('status', 'Konten ditambahkan.');
    }

    /**
     * Detail konten: grafik pertumbuhan views (snapshot dari waktu ke waktu) +
     * tabel riwayat snapshot (Δ vs snapshot sebelumnya + engagement rate).
     * Snapshot harian sudah tersimpan (append-only) — dulu hanya latestSnapshot
     * yang pernah dibaca; halaman ini menampilkan seluruh riwayatnya.
     */
    public function show(KolContent $content)
    {
        $content->load(['kol', 'deal']);
        $snaps = $content->snapshots()->orderBy('captured_on')->get();

        // Riwayat (baru → lama untuk tabel): Δ views vs snapshot sebelumnya + ER.
        $prev = null;
        $history = $snaps->map(function ($s) use (&$prev) {
            $hasEng = $s->likes !== null || $s->comments !== null || $s->shares !== null || $s->saves !== null;
            $eng = (int) $s->likes + (int) $s->comments + (int) $s->shares + (int) $s->saves;
            $er = ($hasEng && (int) $s->views > 0) ? round($eng / (int) $s->views * 100, 2) : null;
            $delta = $prev !== null ? ((int) $s->views - $prev) : null;
            $prev = (int) $s->views;

            return [
                'id' => $s->id, 'date' => $s->captured_on, 'views' => (int) $s->views,
                'delta' => $delta, 'er' => $er, 'source' => $s->source,
            ];
        })->reverse()->values();

        return view('kols.konten.show', [
            'content' => $content,
            'latest' => $snaps->last(),
            'history' => $history,
            'cpm' => $content->cpm,
            'chart' => [
                'labels' => $snaps->map(fn ($s) => $s->captured_on->format('d M'))->values(),
                'views' => $snaps->map(fn ($s) => (int) $s->views)->values(),
            ],
        ]);
    }

    public function edit(KolContent $content)
    {
        return view('kols.konten.form', $this->formData($content));
    }

    public function update(Request $request, KolContent $content): RedirectResponse
    {
        $content->update($this->validated($request));

        return redirect()->route('kol-konten.index', ['bulan' => $content->posted_at->format('Y-m')])
            ->with('status', 'Konten diperbarui.');
    }

    public function destroy(KolContent $content): RedirectResponse
    {
        $month = $content->posted_at->format('Y-m');
        AuditService::log(action: 'delete_kol_content', targetType: 'kol_content', targetId: $content->id,
            before: ['url' => $content->url]);
        $content->delete(); // snapshot ikut terhapus (cascade)

        return redirect()->route('kol-konten.index', ['bulan' => $month])->with('status', 'Konten dihapus.');
    }

    /** Ambil judul via TikTok oEmbed — host allowlist tiktok.com; gagal = judul manual. */
    public function oembed(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:255']]);
        $host = parse_url($data['url'], PHP_URL_HOST) ?? '';
        if (! preg_match('/(^|\.)tiktok\.com$/i', $host)) {
            return response()->json(['message' => 'Hanya URL tiktok.com.'], 422);
        }
        try {
            $json = Http::timeout(10)->get('https://www.tiktok.com/oembed', ['url' => $data['url']])->json();
            $author = ltrim((string) ($json['author_unique_id'] ?? $json['author_name'] ?? ''), '@');
            $kol = $author !== '' ? Kol::whereRaw('LOWER(tiktok_username) = ?', [mb_strtolower($author)])->first(['id', 'tiktok_username']) : null;

            return response()->json([
                'title' => (string) ($json['title'] ?? ''),
                'thumbnail' => (string) ($json['thumbnail_url'] ?? ''),
                'author' => $author,
                'kol_id' => $kol?->id,
                'hint' => $author === '' ? '' : ($kol ? '✓ cocok creator @'.$kol->tiktok_username : '⚠ author @'.$author.' belum terdaftar'),
            ]);
        } catch (\Throwable) {
            return response()->json(['title' => '']);
        }
    }

    public function updateTarget(Request $request): RedirectResponse
    {
        $d = $request->validate(['target' => ['required', 'integer', 'min:0']]);
        AppSetting::put(self::TARGET_KEY, (string) $d['target']);

        return back()->with('status', 'Target views disimpan.');
    }

    /** Grid isi views massal — semua konten bulan sebagai baris input. */
    public function grid(Request $request)
    {
        $month = $this->month($request);
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return view('kols.konten.grid', [
            'month' => $month,
            'contents' => KolContent::with(['kol', 'latestSnapshot'])
                ->whereBetween('posted_at', [$start, $start->copy()->endOfMonth()])
                ->orderBy('kol_id')->orderByDesc('posted_at')->get(),
        ]);
    }

    public function gridSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.id' => ['required', 'integer', 'exists:kol_contents,id'],
            'rows.*.views' => ['nullable', 'integer', 'min:0'],
            'rows.*.likes' => ['nullable', 'integer', 'min:0'],
            'rows.*.comments' => ['nullable', 'integer', 'min:0'],
            'rows.*.shares' => ['nullable', 'integer', 'min:0'],
            'rows.*.saves' => ['nullable', 'integer', 'min:0'],
        ]);

        // Carbon startOfDay (bukan string Y-m-d) supaya cocok dgn nilai tersimpan
        // saat updateOrCreate — kalau string, unique(kol_content_id,captured_on) meleset.
        $today = now()->startOfDay();
        $saved = 0;
        foreach ($data['rows'] as $row) {
            if (($row['views'] ?? null) === null) {
                continue; // baris kosong dilewati
            }
            KolContentSnapshot::updateOrCreate(
                ['kol_content_id' => $row['id'], 'captured_on' => $today],
                ['views' => $row['views'], 'likes' => $row['likes'] ?? null,
                    'comments' => $row['comments'] ?? null, 'shares' => $row['shares'] ?? null, 'saves' => $row['saves'] ?? null,
                    'source' => 'manual', 'created_by' => $request->user()->id],
            );
            $saved++;
        }

        return redirect()->route('kol-konten.index', ['bulan' => $this->month($request)])
            ->with('status', "{$saved} snapshot views tersimpan (".now()->format('d M').').');
    }

    /** Tambah snapshot tunggal (dari halaman detail). Isi ulang tanggal sama = replace. */
    public function snapshotStore(Request $request, KolContent $content): RedirectResponse
    {
        $data = $request->validate([
            'captured_on' => ['required', 'date'],
            'views' => ['required', 'integer', 'min:0'],
            'likes' => ['nullable', 'integer', 'min:0'],
            'comments' => ['nullable', 'integer', 'min:0'],
            'shares' => ['nullable', 'integer', 'min:0'],
            'saves' => ['nullable', 'integer', 'min:0'],
        ]);
        $content->snapshots()->updateOrCreate(
            ['captured_on' => Carbon::parse($data['captured_on'])->startOfDay()],
            ['views' => $data['views'], 'likes' => $data['likes'] ?? null, 'comments' => $data['comments'] ?? null,
                'shares' => $data['shares'] ?? null, 'saves' => $data['saves'] ?? null,
                'source' => 'manual', 'created_by' => $request->user()->id],
        );

        return redirect()->route('kol-konten.show', $content)->with('status', 'Snapshot ditambahkan.');
    }

    public function snapshotDestroy(KolContentSnapshot $snapshot): RedirectResponse
    {
        $contentId = $snapshot->kol_content_id;
        $snapshot->delete();

        return redirect()->route('kol-konten.show', $contentId)->with('status', 'Snapshot dihapus.');
    }

    // ---- internal ----

    private function month(Request $request): string
    {
        $m = (string) $request->input('bulan', now()->format('Y-m'));

        return preg_match('/^\d{4}-\d{2}$/', $m) ? $m : now()->format('Y-m');
    }

    /** Validasi + paksa label paid saat konten terkait deal + auto-deteksi platform/tipe. */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'kol_deal_id' => ['nullable', 'integer',
                Rule::exists('kol_deals', 'id')->where('kol_id', (int) $request->input('kol_id'))],
            'url' => ['required', 'url', 'max:255'],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'content_type' => ['nullable', Rule::in(KolContent::TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'thumbnail_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'label' => ['required', Rule::in(['paid', 'earned'])],
            'posted_at' => ['required', 'date'],
        ]);

        // Auto-deteksi platform & tipe konten dari URL bila tak diisi manual.
        $data['platform'] = $data['platform'] ?? $this->detectPlatform($data['url']);
        $data['content_type'] = $data['content_type'] ?? $this->detectType($data['url']);
        // Anti-dobel-hitung: konten dari deal berbayar SELALU paid.
        $data['label'] = ! empty($data['kol_deal_id']) ? 'paid' : $data['label'];

        return $data;
    }

    /** Tebak platform dari host URL. */
    private function detectPlatform(string $url): string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'tiktok') => 'tiktok',
            str_contains($host, 'instagram') => 'instagram',
            str_contains($host, 'shopee') => 'shopee',
            str_contains($host, 'youtu') => 'youtube',
            default => 'tiktok',
        };
    }

    /** Tebak tipe konten dari pola URL. */
    private function detectType(string $url): string
    {
        $u = mb_strtolower($url);

        return match (true) {
            str_contains($u, '/reel') => 'reels',
            str_contains($u, '/stories/') || str_contains($u, '/story') => 'story',
            str_contains($u, '/live') => 'live',
            str_contains($u, '/p/') => 'feed',
            default => 'video',
        };
    }

    private function formData(KolContent $content): array
    {
        return [
            'content' => $content,
            'kols' => Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']),
            'deals' => KolDeal::with('kol:id,tiktok_username')->orderByDesc('id')->get(['id', 'kode', 'kol_id']),
            'platforms' => config('kol.platforms'),
            'types' => KolContent::TYPE_LABELS,
        ];
    }
}
