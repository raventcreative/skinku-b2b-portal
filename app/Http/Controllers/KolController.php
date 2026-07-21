<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KolController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only(['level', 'kategori', 'status', 'verdict']);

        // Arah & kolom sort divalidasi ke daftar putih — nilai ngawur jatuh ke default.
        $sortable = ['username', 'followers', 'level', 'kategori', 'status', 'agency',
            'ratecard', 'total', 'avg', 'median', 'ratio', 'cpm_mean', 'cpm', 'cpv', 'rank',
            'verdict_mean', 'verdict', 'gmv'];
        $sort = in_array($request->query('sort'), $sortable, true)
            ? $request->query('sort') : 'username';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $kols = Kol::query()
            ->with('latestScreening')
            ->when($filters['kategori'] ?? null, fn ($q, $v) => $q->where('kategori', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('tiktok_username')
            ->get();

        // Level & verdict = turunan (accessor), bukan kolom — filter & sort-nya
        // di koleksi, bukan SQL. Skala data KOL (ratusan) aman.
        if ($filters['level'] ?? null) {
            $kols = $kols->filter(fn (Kol $k) => $k->level === $filters['level']);
        }

        if ($filters['verdict'] ?? null) {
            $kols = $kols->filter(fn (Kol $k) => $this->verdictKey($k) === $filters['verdict']);
        }

        $kols = $this->sorted($kols, $sort, $dir)->values();

        return view('kols.index', [
            'kols' => $kols,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'levels' => ['Nano', 'Mikro', 'Middle', 'Makro', 'Mega', 'Super Mega'],
            'kategoriList' => config('kol.kategori'),
            // Rank global (kolom Z Excel) — daftar menampilkan rank milik
            // screening terakhir tiap KOL.
            'ranks' => $this->ranks(),
        ]);
    }

    /** worth / masih / mahal / belum — kunci filter verdict dari screening terakhir. */
    private function verdictKey(Kol $k): string
    {
        if (! $k->latestScreening) {
            return 'belum';
        }

        return match ($k->latestScreening->verdict_median) {
            KolScreening::VERDICT_WORTH => 'worth',
            KolScreening::VERDICT_MASIH => 'masih',
            KolScreening::VERDICT_BELUM_HARGA => 'tanpa_harga',
            default => 'mahal',
        };
    }

    /**
     * Peringkat screening. Rank 1 = CPM MEDIAN termurah, atas SEMUA screening;
     * nilai kembar berbagi rank dan rank berikutnya melompat (perilaku RANK()).
     *
     * DEVIASI SADAR dari Excel: kolom Z me-rank pakai CPM MEAN — dan itu
     * menyesatkan. Kasus nyata @mulmull: satu video meledak 9,8jt views
     * menyeret mean sampai CPM mean ±10rb (rank 1 "termurah") padahal median
     * views cuma 3.000 → CPM median 5jt → Kemahalan. Rank #1 berdampingan
     * dengan verdict merah membingungkan siapa pun. Median kebal outlier dan
     * konsisten dengan verdict yang ditampilkan — itu basis rank di sini.
     *
     * @return array<int, int> [screening_id => rank]
     */
    private function ranks(): array
    {
        $rows = KolScreening::query()->get()
            ->map(fn (KolScreening $s) => ['id' => $s->id, 'cpm' => $s->cpm_median])
            ->filter(fn ($r) => $r['cpm'] !== null)
            ->sortBy('cpm')
            ->values();

        $ranks = [];
        $prevCpm = null;
        $prevRank = 0;
        foreach ($rows as $i => $r) {
            $rank = ($prevCpm !== null && abs($r['cpm'] - $prevCpm) < 0.001) ? $prevRank : $i + 1;
            $ranks[$r['id']] = $rank;
            $prevCpm = $r['cpm'];
            $prevRank = $rank;
        }

        return $ranks;
    }

    private function sorted($kols, string $sort, string $dir)
    {
        // Kolom turunan screening — SEMUA header angka bisa diurutkan, seperti
        // Excel. Yang belum discreening SELALU di bawah, apa pun arahnya:
        // "tak ada data" bukan kecil ataupun besar.
        // rank & verdict & cpv memakai CPM MEDIAN sebagai nilai sort-nya —
        // rank memang turunan langsung CPM median, dan CPV cuma CPM ÷ 1000.
        $screeningVal = match ($sort) {
            'ratecard' => fn (Kol $k) => $k->latestScreening?->ratecard,
            'total' => fn (Kol $k) => $k->latestScreening?->total_views,
            'avg' => fn (Kol $k) => $k->latestScreening?->rata_views,
            'median' => fn (Kol $k) => $k->latestScreening?->median_views,
            'ratio' => fn (Kol $k) => $k->latestScreening?->ratio,
            'gmv' => fn (Kol $k) => $k->latestScreening?->gmv_estimate,
            // Indikator/CPM Mean pakai CPM rata sebagai nilai sort-nya.
            'cpm_mean', 'verdict_mean' => fn (Kol $k) => $k->latestScreening?->cpm_rata,
            'cpm', 'cpv', 'rank', 'verdict' => fn (Kol $k) => $k->latestScreening?->cpm_median,
            default => null,
        };

        if ($screeningVal !== null) {
            $belum = $kols->filter(fn (Kol $k) => $screeningVal($k) === null);
            $ada = $kols->filter(fn (Kol $k) => $screeningVal($k) !== null)
                ->sortBy($screeningVal, SORT_REGULAR, $dir === 'desc');

            return $ada->concat($belum);
        }

        $key = match ($sort) {
            'followers', 'level' => fn (Kol $k) => (int) $k->followers,   // level = turunan followers, urutannya sama
            'kategori' => fn (Kol $k) => mb_strtolower($k->kategori ?? ''),
            'agency' => fn (Kol $k) => mb_strtolower($k->agency ?? ''),
            'status' => fn (Kol $k) => $k->status,
            default => fn (Kol $k) => mb_strtolower($k->tiktok_username),
        };

        return $kols->sortBy($key, SORT_REGULAR, $dir === 'desc');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tiktok_username' => ['required', 'string', 'max:100', 'unique:kols,tiktok_username'],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'max:150'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        $kol = Kol::create($data);

        AuditService::log(
            action: 'create_kol',
            targetType: 'kol',
            targetId: $kol->id,
            after: ['username' => $kol->tiktok_username, 'followers' => $kol->followers],
        );

        return redirect()->route('kols.show', $kol)
            ->with('status', "KOL @{$kol->tiktok_username} ditambahkan (level {$kol->level}).");
    }

    public function show(Kol $kol)
    {
        $kol->load(['screenings', 'deals.pic']);

        return view('kols.show', [
            'kol' => $kol,
            'kategoriList' => config('kol.kategori'),
        ]);
    }

    public function update(Request $request, Kol $kol): RedirectResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'max:150'],
            'status' => ['required', Rule::in(Kol::STATUSES)],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $kol->only(array_keys($data));
        $kol->update($data);

        AuditService::log(
            action: 'update_kol',
            targetType: 'kol',
            targetId: $kol->id,
            before: $before,
            after: $data,
        );

        return back()->with('status', 'Data KOL diperbarui.');
    }
}
