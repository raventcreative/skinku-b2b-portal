<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\TiktokAffiliateConnection;
use App\Services\TikTokAffiliateService;
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

        return view('kols.tiktok_check.index', compact('q', 'conn', 'creators', 'error', 'known', 'rate'));
    }
}
