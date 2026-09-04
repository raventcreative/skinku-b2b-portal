<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolTiktokProfile;
use App\Models\KolUsernameAlias;
use App\Models\TiktokAffiliateConnection;
use App\Services\AuditService;
use App\Services\TikTokAffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "Cek Performa TikTok" — screening kreator lewat Creator Marketplace API.
 * Ketik username → tampilkan GMV 30 hari, follower, views, dsb LANGSUNG dari
 * TikTok, walau kreatornya BELUM pernah jadi affiliate kita — buat memutuskan
 * layak/tidak direkrut ke Tim Gapok. Pakai koneksi app affiliate yang sama
 * (TiktokAffiliateConnection); gate kol.affiliate.view.
 */
class KolTiktokCheckController extends Controller
{
    public function __construct(private TikTokAffiliateService $svc) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $conn = TiktokAffiliateConnection::latest('id')->first();
        $rate = (int) config('services.tiktok_affiliate.usd_idr_rate', 16000);

        // Cache hasil per keyword 10 menit: TikTok punya rate limit BERSAMA
        // (app-group, kode 36009002) yang dipakai bareng sync order/konten. Reload
        // (habis Simpan/Jadikan Gapok) & pencarian ulang keyword sama → ambil dari
        // cache, tak nembak TikTok lagi. Error TIDAK di-cache (remember cuma simpan
        // nilai balik), jadi begitu limit reda, Cari lagi langsung jalan.
        $creators = [];
        $error = null;
        if ($q !== '' && $conn && $conn->shop_cipher) {
            try {
                $creators = Cache::remember(
                    'tt_mkt:'.md5(mb_strtolower($q)),
                    now()->addMinutes(10),
                    fn () => $this->svc->searchCreators($conn, $q),
                );
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        // Tandai kreator yang SUDAH ada di database KOL kita (+ status gapok),
        // biar jelas mana yang baru vs sudah kita pegang.
        $known = collect();
        if ($creators !== []) {
            $lowers = array_values(array_filter(array_map(
                fn ($c) => mb_strtolower(trim((string) $c['username'])), $creators
            )));
            if ($lowers !== []) {
                $known = Kol::whereNotNull('tiktok_username')
                    ->whereIn(DB::raw('LOWER(tiktok_username)'), $lowers)
                    ->get(['id', 'tiktok_username', 'is_gapok', 'name'])
                    ->keyBy(fn ($k) => mb_strtolower(trim((string) $k->tiktok_username)));
            }
        }

        return view('kols.tiktok_check.index', [
            'q' => $q,
            'conn' => $conn,
            'creators' => $creators,
            'error' => $error,
            'known' => $known,
            'rate' => $rate,
            'canManage' => $request->user()->canDo('kol.affiliate.manage'),
        ]);
    }

    /**
     * Simpan performa TikTok kreator ke record Database KOL-nya:
     *  - follower → kols.followers (dipakai juga buat Level)
     *  - GMV asli → screening terbaru (kolom "GMV Asli")
     *  - SNAPSHOT LENGKAP (GMV split video/LIVE, rata-rata views, demografi, dst)
     *    → tabel kol_tiktok_profiles (tampil di halaman Detail KOL)
     *
     * Data lengkap diambil dari CACHE hasil pencarian (data server kita sendiri,
     * di-key keyword `q`) — bukan nembak TikTok lagi & bukan ketikan user. Kalau
     * cache habis (>10 mnt), jatuh ke nilai dasar yang diposkan kartu (follower +
     * GMV), demografi di-skip. Kreator harus SUDAH ada di DB. Gate kol.affiliate.manage.
     */
    public function save(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'username' => ['required', 'string', 'max:150'],
            'q' => ['nullable', 'string', 'max:150'],
            'followers' => ['nullable', 'integer', 'min:0'],
            'gmv_usd' => ['nullable', 'numeric', 'min:0'],
            'open_id' => ['nullable', 'string', 'max:120'],
        ]);

        $norm = KolUsernameAlias::norm($d['username']);
        $kol = Kol::whereRaw('LOWER(tiktok_username) = ?', [$norm])->first()
            ?? Kol::find(KolUsernameAlias::where('username', $norm)->value('kol_id'));
        if (! $kol) {
            return back()->with('error', "@{$norm} belum ada di Database KOL — klik \"Jadikan Gapok\" dulu.");
        }

        // Data lengkap dari cache pencarian (kalau masih ada) → snapshot penuh.
        $c = $this->cachedCreator((string) ($d['q'] ?? ''), (string) ($d['open_id'] ?? ''), $norm);

        $rate = (int) config('services.tiktok_affiliate.usd_idr_rate', 16000);
        $followers = $c['followers'] ?? ($d['followers'] ?? null);
        $gmvUsd = $c['gmv_usd'] ?? (isset($d['gmv_usd']) ? (float) $d['gmv_usd'] : null);
        $idr = fn (?float $usd) => $usd === null ? null : (int) round($usd * $rate);

        if ($followers !== null) {
            $kol->update(['followers' => (int) $followers]);
        }

        // GMV asli TikTok → screening terbaru (kolom "GMV Asli" nempel di screening).
        $gmvIdr = $idr($gmvUsd);
        $gmvSaved = false;
        if ($gmvIdr !== null && ($screening = $kol->latestScreening()->first())) {
            $screening->update(['gmv' => $gmvIdr]);
            $gmvSaved = true;
        }

        // Snapshot penuh → kol_tiktok_profiles (updateOrCreate: field yg tak diketahui
        // saat cache habis TIDAK ikut dikirim → nilai lama tak kehapus).
        $snap = array_filter([
            'open_id' => $c['open_id'] ?? ($d['open_id'] ?? null),
            'followers' => $followers !== null ? (int) $followers : null,
            'gmv_usd' => $gmvUsd,
            'gmv_idr' => $gmvIdr,
            'usd_idr_rate' => $rate,
            'synced_at' => now(),
        ], fn ($v) => $v !== null);
        if ($c) {
            $ageLabel = fn ($a) => str_replace(['AGE_RANGE_', '_'], ['', '–'], (string) $a);
            $snap += [
                'gmv_range' => $c['gmv_range'] ?: null,
                'video_gmv_idr' => $idr($c['video_gmv_usd']),
                'live_gmv_idr' => $idr($c['live_gmv_usd']),
                'avg_video_views' => (int) $c['avg_video_views'],
                'avg_live_uv' => (int) $c['avg_live_uv'],
                'region' => $c['region'] ?: null,
                'gender' => $c['gender'] ?: null,
                'gender_pct' => $c['gender_pct'] ?: null,
                'age_ranges' => $c['age_ranges'] ? implode(', ', array_map($ageLabel, $c['age_ranges'])) : null,
            ];
        }
        KolTiktokProfile::updateOrCreate(['kol_id' => $kol->id], $snap);

        AuditService::log(action: 'save_tiktok_perf_to_kol', targetType: 'kol', targetId: $kol->id,
            after: ['followers' => $followers, 'gmv' => $gmvSaved ? $gmvIdr : null, 'full' => (bool) $c]);

        $parts = [];
        if ($followers !== null) {
            $parts[] = number_format((int) $followers, 0, ',', '.').' follower';
        }
        if ($gmvSaved) {
            $parts[] = 'GMV Asli Rp'.number_format($gmvIdr, 0, ',', '.');
        }
        $parts[] = $c ? 'profil lengkap (buka Detail KOL)' : 'data dasar';
        $msg = "Data TikTok @{$norm} disimpan ke Database KOL (".implode(' · ', $parts).').';
        if ($gmvIdr !== null && ! $gmvSaved) {
            $msg .= ' Catatan: GMV Asli butuh screening dulu — data lain tetap tersimpan.';
        }

        return back()->with('status', $msg);
    }

    /**
     * Ambil satu kreator (ternormalisasi) dari cache hasil pencarian keyword $q,
     * dicocokkan by open_id (utama) atau username. null bila cache habis/tak cocok.
     *
     * @return array<string,mixed>|null
     */
    private function cachedCreator(string $q, string $openId, string $norm): ?array
    {
        if ($q === '') {
            return null;
        }
        $cached = Cache::get('tt_mkt:'.md5(mb_strtolower($q)));
        if (! is_array($cached)) {
            return null;
        }

        foreach ($cached as $x) {
            if ($openId !== '' && (string) ($x['open_id'] ?? '') === $openId) {
                return $x;
            }
        }
        foreach ($cached as $x) {
            if (mb_strtolower((string) ($x['username'] ?? '')) === $norm) {
                return $x;
            }
        }

        return null;
    }
}
