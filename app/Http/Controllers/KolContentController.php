<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolDeal;
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
        $contents = KolContent::with(['kol', 'deal', 'latestSnapshot'])
            ->whereBetween('posted_at', [$start, $start->copy()->endOfMonth()])
            ->orderByDesc('posted_at')->get();

        $views = fn ($c) => (int) ($c->latestSnapshot->views ?? 0);
        $total = $contents->sum($views);
        $paid = $contents->where('label', 'paid')->sum($views);
        $target = (int) AppSetting::get(self::TARGET_KEY, '1000000');
        $isCurrent = $month === now()->format('Y-m');
        $proj = $isCurrent ? (int) round($total * ($start->daysInMonth / max(1, now()->day))) : $total;

        return view('kols.konten.index', [
            'month' => $month, 'contents' => $contents, 'total' => $total,
            'paid' => $paid, 'earned' => $total - $paid,
            'target' => $target, 'proj' => $proj, 'isCurrent' => $isCurrent,
            'aman' => $target > 0 && $proj >= 0.95 * $target,
            'prevMonth' => $start->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $start->copy()->addMonth()->format('Y-m'),
        ]);
    }

    public function create()
    {
        return view('kols.konten.form', $this->formData(new KolContent(['posted_at' => now()])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $content = KolContent::create($data + ['created_by' => $request->user()->id]);

        AuditService::log(action: 'create_kol_content', targetType: 'kol_content', targetId: $content->id,
            after: ['kol' => $content->kol->tiktok_username, 'label' => $content->label, 'url' => $content->url]);

        return redirect()->route('kol-konten.index', ['bulan' => $content->posted_at->format('Y-m')])
            ->with('status', 'Konten ditambahkan.');
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
            $res = Http::timeout(10)->get('https://www.tiktok.com/oembed', ['url' => $data['url']]);

            return response()->json(['title' => (string) $res->json('title', '')]);
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

    // ---- internal ----

    private function month(Request $request): string
    {
        $m = (string) $request->query('bulan', now()->format('Y-m'));

        return preg_match('/^\d{4}-\d{2}$/', $m) ? $m : now()->format('Y-m');
    }

    /** Validasi + paksa label paid saat konten terkait deal. */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'kol_deal_id' => ['nullable', 'integer',
                Rule::exists('kol_deals', 'id')->where('kol_id', (int) $request->input('kol_id'))],
            'url' => ['required', 'url', 'max:255'],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'title' => ['nullable', 'string', 'max:255'],
            'label' => ['required', Rule::in(['paid', 'earned'])],
            'posted_at' => ['required', 'date'],
        ]);

        $data['platform'] = $data['platform'] ?? 'tiktok';
        // Anti-dobel-hitung: konten dari deal berbayar SELALU paid.
        $data['label'] = ! empty($data['kol_deal_id']) ? 'paid' : $data['label'];

        return $data;
    }

    private function formData(KolContent $content): array
    {
        return [
            'content' => $content,
            'kols' => Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']),
            'deals' => KolDeal::with('kol:id,tiktok_username')->orderByDesc('id')->get(['id', 'kode', 'kol_id']),
            'platforms' => config('kol.platforms'),
        ];
    }
}
