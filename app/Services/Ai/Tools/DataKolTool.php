<?php

namespace App\Services\Ai\Tools;

use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\User;
use App\Services\KolAffiliateService;
use App\Services\KolGapokService;
use App\Services\KolScoringService;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: Database KOL / Affiliate (menu KOL). Izin & kolom mengikuti halamannya:
 *  - kol.view → profil, skor KSS, pipeline, deal tanpa biaya (= Database KOL & detail KOL);
 *  - + kol.affiliate.view → GMV/pesanan/komisi, APS, jumlah video & LIVE, gaji & ROI gapok (= Affiliate, Tim Gapok);
 *  - + kol.deal.finance → total biaya & status bayar deal.
 * Kontak pribadi (telepon, manajer), catatan, & rekening TAK pernah dikirim ke AI.
 */
class DataKolTool extends BaseTool
{
    use CariKol;

    public function __construct(private KolGapokService $gapok, private KolAffiliateService $aff, private KolScoringService $scoring) {}

    public function name(): string
    {
        return 'data_kol';
    }

    public function permission(): ?string
    {
        return 'kol.view';
    }

    public function description(): string
    {
        return 'Data Database KOL / Affiliate (menu KOL). Isi username → profil satu kreator: peran, level, followers, '
            .'status, Tim Gapok, skor KSS, pipeline, deal, plus (bila punya izin Affiliate) GMV/pesanan/komisi/APS/gaji '
            .'gapok bulan itu. Tanpa username → ringkasan jumlah KOL + daftar kreator teratas, urut GMV bulan itu (GMV = '
            .'pesanan affiliate tercatat, sama dgn Tim Gapok) atau followers. Untuk "siapa yang sedang perform/terlaris" '
            .'pakai daftar TANPA filter status (urut GMV), atau alat views_harian_kol untuk views & GMV per video. '
            .'Kontak pribadi tidak tersedia.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'username' => ['type' => 'string', 'description' => 'Username TikTok kreator (opsional) → profil lengkap.'],
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM untuk angka performa. Default bulan ini.'],
                'peran' => ['type' => 'string', 'enum' => Kol::ROLES, 'description' => 'kol / affiliate / both (KOL + Affiliate).'],
                'status' => ['type' => 'string', 'enum' => Kol::STATUSES, 'description' => 'Status kerja sama di Database KOL (diisi manual tim; '
                    .'kreator baru otomatis "prospek") — BUKAN ukuran performa, jangan dipakai untuk mencari yang sedang perform.'],
                'kategori' => ['type' => 'string', 'enum' => config('kol.kategori')],
                'gapok' => ['type' => 'boolean', 'description' => 'true = hanya anggota Tim Gapok.'],
                'urut' => ['type' => 'string', 'enum' => ['gmv', 'followers'], 'description' => 'Default gmv (bila boleh lihat GMV), selain itu followers.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah kreator di daftar, 1-30. Default 10.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $bulan = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($args['bulan'] ?? ''))
            ? Carbon::parse($args['bulan'].'-01')->startOfMonth() : now()->startOfMonth();
        $boleh = ['gmv' => $user->canDo('kol.affiliate.view'), 'biaya' => $user->canDo('kol.deal.finance')];
        $username = trim((string) ($args['username'] ?? ''));

        $out = $username !== '' ? $this->profil($username, $bulan, $boleh) : $this->daftar($args, $bulan, $boleh);
        if (isset($out['error'])) {
            return $out;
        }
        // Beri tahu AI kenapa ada angka yang tak muncul (bukan nol) — sesuai hak akses user.
        $tanpa = array_keys(array_filter([
            'GMV, pesanan, komisi, APS & gaji gapok (izin "Lihat Affiliate & GMV")' => ! $boleh['gmv'],
            'biaya & status bayar deal (izin "Finansial Deal KOL")' => ! $boleh['biaya'] && $username !== '',
        ]));
        if ($tanpa) {
            $out['catatan_akses'] = 'Tidak ditampilkan sesuai hak akses user: '.implode('; ', $tanpa).'.';
        }

        return $out;
    }

    private function profil(string $username, Carbon $bulan, array $boleh): array
    {
        [$kol, $err] = $this->cariKol($username);
        if (! $kol) {
            return $err;
        }
        $kol->load(['scores', 'pipelineCard', 'deals']);
        $kss = $kol->scores->where('type', 'kss')->sortByDesc('captured_on')->first();

        $out = [
            'kreator' => [
                'username' => '@'.$kol->handle(),
                'nama' => $kol->name,
                'platform' => $kol->platformLabel(),
                'peran' => Kol::ROLE_LABELS[$kol->role] ?? $kol->role,
                'followers' => $kol->followers,
                'level' => $kol->level,
                'kategori' => $kol->kategori,
                'provinsi' => $kol->provinsi,
                'status' => $kol->status,
                'alasan_blacklist' => $kol->isBlacklisted() ? $kol->blacklist_reason : null,
                'tim_gapok' => (bool) $kol->is_gapok,
                'gapok_sejak' => $kol->gapok_joined_at?->toDateString(),
                'mau_barter' => (bool) $kol->barter_ok,
                'tiktok_shop_aktif' => (bool) $kol->tiktok_shop_active,
                'shopee_affiliate_aktif' => (bool) $kol->shopee_affiliate_active,
            ],
            'skor_kss' => $kss ? [
                'skor' => $kss->score,
                'label' => KolScoringService::KSS_LABEL[$kss->label] ?? $kss->label,
                'tanggal' => $kss->captured_on?->toDateString(),
            ] : null,
            'pipeline' => $kol->pipelineCard ? ['jalur' => $kol->pipelineCard->track, 'tahap' => $kol->pipelineCard->stageLabel()] : null,
            'deal' => $this->deal($kol, $boleh['biaya']),
        ];

        if ($boleh['gmv']) {
            $out['performa_bulan'] = $this->performa($kol, $bulan);
            // APS = skor saat ini, sama dgn kartu "Skor APS" di detail KOL.
            $aps = $this->scoring->aps($this->aff->apsInput($kol->id, now()));
            $out['skor_aps_saat_ini'] = ['skor' => $aps['score'], 'label' => KolScoringService::APS_LABEL[$aps['label']] ?? $aps['label']];
        }

        return $out;
    }

    /** Deal: kolom yang tampil di detail KOL; biaya & status bayar hanya dgn izin finansial. Rekening/catatan tidak pernah. */
    private function deal(Kol $kol, bool $biaya): array
    {
        $deals = $kol->deals->sortByDesc('id');

        return [
            'jumlah' => $deals->count(),
            'per_status' => $deals->countBy('status')->all(),
            'terbaru' => $deals->take(5)->map(fn (KolDeal $d) => array_filter([
                'kode' => $d->kode,
                'jenis' => $d->jenis,
                'ratecard' => $d->ratecard_deal,
                'periode' => $d->periode_mulai ? $d->periode_mulai->toDateString().' s/d '.($d->periode_selesai?->toDateString() ?? '?') : null,
                'status' => $d->status,
                'total_biaya' => $biaya ? $d->total_biaya : null,
                'status_bayar' => $biaya ? $d->status_bayar : null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    /** Angka bulan itu dari sumber yang sama dgn Tim Gapok (KolGapokService::performa) + gaji & ROI bila anggota gapok. */
    private function performa(Kol $kol, Carbon $bulan): array
    {
        $p = $this->gapok->performa([$kol->id], $bulan->copy()->startOfMonth(), $bulan->copy()->endOfMonth(), $bulan->toDateString())[$kol->id];
        $out = [
            'bulan' => $bulan->format('Y-m'),
            'gmv' => $p['gmv'],
            'pesanan' => $p['orders'],
            'komisi' => $p['commission'],
            'gmv_live' => $p['gmv_live'],
            'gmv_video' => $p['gmv_video'],
            'jumlah_video' => $p['videos'],
            'jumlah_live' => $p['lives'],
        ];
        if ($kol->is_gapok) {
            $g = $this->gapok->monthly($bulan)->first(fn ($r) => $r['kol']->id === $kol->id);
            $out['gaji_gapok'] = $g['salary'] ?? 0;
            $out['roi_gapok'] = $g['roi'] ?? null;
        }

        return $out;
    }

    private function daftar(array $args, Carbon $bulan, array $boleh): array
    {
        $filter = array_filter([
            'peran' => in_array($args['peran'] ?? null, Kol::ROLES, true) ? $args['peran'] : null,
            'status' => in_array($args['status'] ?? null, Kol::STATUSES, true) ? $args['status'] : null,
            'kategori' => in_array($args['kategori'] ?? null, config('kol.kategori'), true) ? $args['kategori'] : null,
            'gapok' => ($args['gapok'] ?? null) === true ? true : null,
        ]);
        $kols = Kol::query()
            ->when(isset($filter['peran']), fn ($q) => $q->where('role', $filter['peran']))
            ->when(isset($filter['status']), fn ($q) => $q->where('status', $filter['status']))
            ->when(isset($filter['kategori']), fn ($q) => $q->where('kategori', $filter['kategori']))
            ->when(isset($filter['gapok']), fn ($q) => $q->where('is_gapok', true))
            ->get(['id', 'tiktok_username', 'name', 'role', 'status', 'followers', 'is_gapok']);
        $urut = $boleh['gmv'] && ($args['urut'] ?? 'gmv') === 'gmv' ? 'gmv' : 'followers';
        $perf = $boleh['gmv']
            ? $this->gapok->performa(null, $bulan->copy()->startOfMonth(), $bulan->copy()->endOfMonth(), $bulan->toDateString())
            : [];

        $rows = $kols->map(fn (Kol $k) => array_filter([
            'username' => '@'.$k->handle(),
            'nama' => $k->name,
            'peran' => Kol::ROLE_LABELS[$k->role] ?? $k->role,
            'status' => $k->status,
            'followers' => $k->followers,
            'tim_gapok' => (bool) $k->is_gapok,
            'gmv' => $boleh['gmv'] ? ($perf[$k->id]['gmv'] ?? 0) : null,
            'pesanan' => $boleh['gmv'] ? ($perf[$k->id]['orders'] ?? 0) : null,
        ], fn ($v) => $v !== null));

        $ringkasan = [
            'jumlah_kol' => $kols->count(),
            'per_peran' => $kols->countBy(fn (Kol $k) => Kol::ROLE_LABELS[$k->role] ?? $k->role)->all(),
            'per_status' => $kols->countBy('status')->all(),
            'tim_gapok' => $kols->where('is_gapok', true)->count(),
        ];
        if ($boleh['gmv']) {
            $ringkasan += ['bulan' => $bulan->format('Y-m'), 'gmv_bulan' => (int) $rows->sum('gmv'), 'pesanan_bulan' => (int) $rows->sum('pesanan')];
        }

        // Saat difilter, sertakan sebaran SEMUA KOL — biar AI tak menyimpulkan "tidak ada" dari filter yang salah arti
        // (mis. status "aktif" dikira "sedang perform", padahal kreator baru otomatis "prospek").
        $semua = $filter ? [
            'jumlah' => Kol::count(),
            'per_status' => Kol::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
        ] : null;

        return array_filter([
            'filter' => $filter ?: null,
            'ringkasan' => $ringkasan,
            'semua_kol' => $semua,
            'catatan' => $filter && $kols->isEmpty()
                ? 'Tidak ada KOL yang cocok dengan filter ini. Lihat semua_kol; untuk pertanyaan performa ulangi tanpa filter status.'
                : null,
            'urut' => $urut,
            'daftar' => $rows->sortByDesc($urut)->take(max(1, min(30, (int) ($args['limit'] ?? 10))))->values()->all(),
        ], fn ($v) => $v !== null);
    }
}
