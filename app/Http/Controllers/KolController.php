<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolAccount;
use App\Models\KolContactLog;
use App\Models\KolRateCard;
use App\Models\KolScore;
use App\Models\KolScreening;
use App\Models\User;
use App\Services\AuditService;
use App\Services\KolAffiliateService;
use App\Services\KolScoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KolController extends Controller
{
    public function index(Request $request, KolAffiliateService $aff)
    {
        $filters = $request->only(['level', 'kategori', 'status', 'verdict', 'platform', 'role', 'q']);

        // Arah & kolom sort divalidasi ke daftar putih — nilai ngawur jatuh ke default.
        $sortable = ['username', 'followers', 'level', 'kategori', 'status', 'agency',
            'ratecard', 'total', 'avg', 'median', 'ratio', 'cpm_mean', 'cpm', 'cpv', 'rank',
            'verdict_mean', 'verdict', 'gmv', 'gmv_real'];
        $sort = in_array($request->query('sort'), $sortable, true)
            ? $request->query('sort') : 'username';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $q = trim((string) ($filters['q'] ?? ''));
        $kols = Kol::query()
            ->with('latestScreening')
            ->when($filters['kategori'] ?? null, fn ($qr, $v) => $qr->where('kategori', $v))
            ->when($filters['status'] ?? null, fn ($qr, $v) => $qr->where('status', $v))
            ->when($filters['platform'] ?? null, fn ($qr, $v) => $qr->where('platform', $v))
            ->when($filters['role'] ?? null, fn ($qr, $v) => $qr->where('role', $v))
            // Cari lintas-field: username, nama tampilan, manager, voucher.
            ->when($q !== '', fn ($qr) => $qr->where(fn ($w) => $w
                ->where('tiktok_username', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")
                ->orWhere('manager_name', 'like', "%{$q}%")->orWhere('voucher_code', 'like', "%{$q}%")))
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

        // GMV affiliate bulan ini (real) — dihitung SEBELUM sort supaya bisa jadi
        // kunci urut kolom "GMV Bln". APS/KSS terakhir = jejak skor.
        $canAffiliate = $request->user()->canDo('kol.affiliate.view');
        $gmvMap = $canAffiliate ? $aff->monthly(now())->keyBy('kol_id') : collect();

        $kols = $this->sorted($kols, $sort, $dir, $gmvMap)->values();
        $scores = KolScore::whereIn('type', ['aps', 'kss'])->latest('captured_on')->latest('id')->get();
        $apsMap = $scores->where('type', 'aps')->unique('kol_id')->keyBy('kol_id');
        $kssMap = $scores->where('type', 'kss')->unique('kol_id')->keyBy('kol_id');

        return view('kols.index', [
            'kols' => $kols,
            // Daftar penuh (tak terpengaruh filter) buat kotak cari KOL — cukup
            // id + username, dipakai combobox untuk loncat ke detail.
            'allKols' => Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']),
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'levels' => ['Nano', 'Mikro', 'Middle', 'Makro', 'Mega', 'Super Mega'],
            'kategoriList' => config('kol.kategori'),
            'roleLabels' => Kol::ROLE_LABELS,
            'platforms' => config('kol.platforms'),
            'canAffiliate' => $canAffiliate,
            'gmvMap' => $gmvMap,
            'apsMap' => $apsMap,
            'kssMap' => $kssMap,
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

    private function sorted($kols, string $sort, string $dir, $gmvMap = null)
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
            // GMV Bln = GMV affiliate REAL bulan ini (dari $gmvMap), bukan estimasi screening.
            'gmv_real' => fn (Kol $k) => $gmvMap?->get($k->id)?->gmv,
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
            'name' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(Kol::ROLES)],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ] + $this->crmRules());
        $data = $this->withBooleans($request, $data);

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

    public function show(Request $request, Kol $kol, KolAffiliateService $aff, KolScoringService $scoring)
    {
        $kol->load(['screenings', 'deals.pic', 'contactLogs.creator', 'scores', 'pipelineCard', 'accounts', 'rateCards']);
        $canAffiliate = $request->user()->canDo('kol.affiliate.view');

        // Stat GMV/Views/APS bulan ini (butuh data affiliate — gated).
        $gmvBulan = $viewsBulan = 0;
        $aps = null;
        $weeklyGmv = [];
        if ($canAffiliate) {
            $in = $aff->apsInput($kol->id, now());
            $gmvBulan = (int) $in['monthGmv'];
            $viewsBulan = (int) ($in['monthViews'] ?? 0);
            $aps = $scoring->aps($in);
            $weeklyGmv = $in['weeklyGmv'];
        }

        return view('kols.show', [
            'kol' => $kol,
            'kategoriList' => config('kol.kategori'),
            'canAffiliate' => $canAffiliate,
            'gmvBulan' => $gmvBulan,
            'viewsBulan' => $viewsBulan,
            'aps' => $aps,
            'weeklyGmv' => $weeklyGmv,
            'apsLabels' => KolScoringService::APS_LABEL,
            'decisionLabel' => KolScoringService::KSS_LABEL,
            'channels' => KolContactLog::CHANNEL_LABELS,
            'rateTypes' => KolRateCard::TYPE_LABELS,
            'platforms' => config('kol.platforms'),
            'recentContents' => $kol->contents()->with('latestSnapshot')->orderByDesc('posted_at')->limit(8)->get(),
        ]);
    }

    public function accountStore(Request $request, Kol $kol): RedirectResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(array_keys(config('kol.platforms')))],
            'username' => ['required', 'string', 'max:150'],
            'followers' => ['nullable', 'integer', 'min:0'],
            'profile_link' => ['nullable', 'url', 'max:255'],
        ]);
        $kol->accounts()->create($data);

        return redirect()->route('kols.show', $kol)->with('status', 'Akun platform ditambahkan.');
    }

    public function accountDestroy(KolAccount $account): RedirectResponse
    {
        $kolId = $account->kol_id;
        $account->delete();

        return redirect()->route('kols.show', $kolId)->with('status', 'Akun platform dihapus.');
    }

    public function rateCardStore(Request $request, Kol $kol): RedirectResponse
    {
        $data = $request->validate([
            'content_type' => ['required', Rule::in(KolRateCard::TYPES)],
            'rate' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $kol->rateCards()->create($data + ['created_by' => $request->user()->id]);

        return redirect()->route('kols.show', $kol)->with('status', 'Rate card ditambahkan.');
    }

    public function rateCardDestroy(KolRateCard $rateCard): RedirectResponse
    {
        $kolId = $rateCard->kol_id;
        $rateCard->delete();

        return redirect()->route('kols.show', $kolId)->with('status', 'Rate card dihapus.');
    }

    /** Hapus KOL (arsip/soft-delete) — super_admin saja. */
    public function destroy(Request $request, Kol $kol): RedirectResponse
    {
        abort_unless($request->user()->role === User::ROLE_SUPER_ADMIN, 403);

        AuditService::log(action: 'delete_kol', targetType: 'kol', targetId: $kol->id,
            before: ['username' => $kol->tiktok_username]);
        $kol->delete(); // SoftDeletes — screening/deal tetap tersimpan

        return redirect()->route('kols.index')->with('status', "KOL @{$kol->tiktok_username} diarsipkan.");
    }

    public function contactLogStore(Request $request, Kol $kol): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(KolContactLog::CHANNELS)],
            'note' => ['required', 'string', 'max:2000'],
            'contacted_at' => ['required', 'date'],
        ]);
        $kol->contactLogs()->create($data + ['created_by' => $request->user()->id]);

        return redirect()->route('kols.show', $kol)->with('status', 'Log kontak ditambahkan.');
    }

    public function contactLogDestroy(KolContactLog $log): RedirectResponse
    {
        $kolId = $log->kol_id;
        $log->delete();

        return redirect()->route('kols.show', $kolId)->with('status', 'Log kontak dihapus.');
    }

    /** Aturan validasi field CRM (dipakai store & update). */
    private function crmRules(): array
    {
        return [
            'manager_name' => ['nullable', 'string', 'max:150'],
            'manager_contact' => ['nullable', 'string', 'max:150'],
            'blacklist_reason' => ['nullable', 'string', 'max:2000'],
            'barter_ok' => ['nullable', 'boolean'],
            'tiktok_shop_active' => ['nullable', 'boolean'],
            'shopee_affiliate_active' => ['nullable', 'boolean'],
            'voucher_code' => ['nullable', 'string', 'max:100'],
            'tracking_link' => ['nullable', 'url', 'max:255'],
            'usage_rights' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Set flag boolean dari checkbox (absen = false, supaya uncheck ikut tersimpan). */
    private function withBooleans(Request $request, array $data): array
    {
        foreach (['barter_ok', 'tiktok_shop_active', 'shopee_affiliate_active'] as $b) {
            $data[$b] = $request->boolean($b);
        }

        return $data;
    }

    public function update(Request $request, Kol $kol): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(Kol::ROLES)],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(Kol::STATUSES)],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ] + $this->crmRules());
        $data = $this->withBooleans($request, $data);

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
