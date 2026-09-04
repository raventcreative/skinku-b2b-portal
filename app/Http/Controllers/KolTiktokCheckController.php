<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolUsernameAlias;
use App\Models\TiktokAffiliateConnection;
use App\Services\AuditService;
use App\Services\TikTokAffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $creators = [];
        $error = null;
        if ($q !== '' && $conn && $conn->shop_cipher) {
            try {
                $creators = $this->svc->searchCreators($conn, $q);
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
     * Simpan performa TikTok kreator ke record Database KOL-nya: follower (kolom
     * kols.followers) + GMV asli (screening terbaru → kolom "GMV Asli"). Angka
     * dikirim dari kartu hasil (data server kita sendiri, bukan ketikan user).
     * GMV USD dikonversi ke Rupiah pakai kurs config. Kreator harus SUDAH ada di
     * database (dibuat lewat "Jadikan Gapok" dulu). Gate kol.affiliate.manage.
     */
    public function save(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'username' => ['required', 'string', 'max:150'],
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

        if (isset($d['followers'])) {
            $kol->update(['followers' => (int) $d['followers']]);
        }

        // GMV asli TikTok (USD→Rp) → screening terbaru. Butuh minimal 1 screening
        // (kolom "GMV Asli" nempel di record screening).
        $rate = (int) config('services.tiktok_affiliate.usd_idr_rate', 16000);
        $gmvIdr = isset($d['gmv_usd']) ? (int) round(((float) $d['gmv_usd']) * $rate) : null;
        $gmvSaved = false;
        if ($gmvIdr !== null) {
            $screening = $kol->latestScreening()->first();
            if ($screening) {
                $screening->update(['gmv' => $gmvIdr]);
                $gmvSaved = true;
            }
        }

        AuditService::log(action: 'save_tiktok_perf_to_kol', targetType: 'kol', targetId: $kol->id,
            after: ['followers' => $d['followers'] ?? null, 'gmv' => $gmvSaved ? $gmvIdr : null]);

        $parts = [];
        if (isset($d['followers'])) {
            $parts[] = number_format((int) $d['followers'], 0, ',', '.').' follower';
        }
        if ($gmvSaved) {
            $parts[] = 'GMV Asli Rp'.number_format($gmvIdr, 0, ',', '.');
        }
        $msg = "Data TikTok @{$norm} disimpan ke Database KOL".($parts ? ' ('.implode(' · ', $parts).').' : '.');
        if ($gmvIdr !== null && ! $gmvSaved) {
            $msg .= ' Catatan: GMV Asli butuh screening dulu — follower tetap tersimpan.';
        }

        return back()->with('status', $msg);
    }
}
