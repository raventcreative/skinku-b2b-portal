<?php

namespace App\Http\Controllers;

use App\Models\TiktokAffiliateConnection;
use App\Services\AuditService;
use App\Services\TikTokAffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Integrasi TikTok Affiliate Seller API (app "Seller Analitik") — OAuth, probe,
 * dan sync order affiliate → pipeline Tim Gapok. Logika token & pemetaan di
 * TikTokAffiliateService. Gate manage_tiktok.
 */
class TikTokAffiliateController extends Controller
{
    public function __construct(private TikTokAffiliateService $svc) {}

    public function index()
    {
        return view('tiktok_affiliate.index', [
            'configured' => $this->svc->client()->configured(),
            'connection' => TiktokAffiliateConnection::latest('id')->first(),
            'probe' => session('affiliate_probe'),
        ]);
    }

    /** Mulai OAuth app affiliate — arahkan ke halaman izin TikTok. */
    public function connect(): RedirectResponse
    {
        abort_unless($this->svc->client()->configured(), 400, 'App key/secret affiliate belum diisi di .env server (TIKTOK_AFFILIATE_*).');

        return redirect()->away($this->svc->client()->authorizeUrl());
    }

    /** Callback TikTok (?code) → tukar token, ambil toko (shop_cipher), simpan. */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code') ?: $request->query('auth_code');
        if (! $code) {
            return redirect()->route('tiktok-affiliate.index')->with('error', 'Otorisasi dibatalkan / tidak ada kode dari TikTok.');
        }

        try {
            $token = $this->svc->client()->getToken($code);
        } catch (\Throwable $e) {
            return redirect()->route('tiktok-affiliate.index')->with('error', 'Gagal tukar token (app_key/secret salah?): '.$e->getMessage());
        }

        $access = $token['access_token'] ?? '';
        $granted = $token['granted_scopes'] ?? ($token['scope'] ?? null);
        $grantedStr = $granted === null ? '(TikTok tak mengirim daftar granted_scopes)'
            : (is_array($granted) ? implode(', ', $granted) : (string) $granted);

        try {
            $shops = $this->svc->client()->getShops($access);
        } catch (\Throwable $e) {
            return redirect()->route('tiktok-affiliate.index')->with('error',
                'Token BERHASIL didapat, tapi ambil toko (getShops /authorization/202309/shops) DITOLAK: '.$e->getMessage()
                .' ┃ Scope yang dibawa token: ['.$grantedStr.']. '
                .(str_contains($grantedStr, 'seller.authorization.info')
                    ? 'Scope-nya ADA di token — kemungkinan propagasi TikTok belum sinkron, tunggu beberapa menit & coba lagi.'
                    : 'Scope "seller.authorization.info" TIDAK ada di token → otorisasi lama dipakai ulang. CABUT otorisasi app "Seller Analitik" di Seller Center, lalu authorize ulang.'));
        }

        $shop = $shops[0] ?? [];

        TiktokAffiliateConnection::updateOrCreate(
            ['shop_id' => $shop['id'] ?? ($token['open_id'] ?? 'default')],
            [
                'shop_cipher' => $shop['cipher'] ?? null,
                'shop_name' => $shop['name'] ?? ($token['seller_name'] ?? null),
                'region' => $shop['region'] ?? ($token['seller_base_region'] ?? null),
                'seller_name' => $token['seller_name'] ?? null,
                'access_token' => $access,
                'refresh_token' => $token['refresh_token'] ?? null,
                'access_expires_at' => $this->svc->toTime($token['access_token_expire_in'] ?? null),
                'refresh_expires_at' => $this->svc->toTime($token['refresh_token_expire_in'] ?? null),
                'connected_by' => $request->user()->id,
            ],
        );

        AuditService::log(action: 'connect_tiktok_affiliate', targetType: 'tiktok', after: ['shop' => $shop['name'] ?? null]);

        return redirect()->route('tiktok-affiliate.index')->with('status', 'App affiliate terhubung: '.($shop['name'] ?? 'toko').' ┃ scope token: ['.$grantedStr.']');
    }

    /** Probe: tarik 5 order affiliate & tampilkan struktur respons MENTAH. */
    public function probe(): RedirectResponse
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok (app affiliate).');

        try {
            $access = $this->svc->freshToken($conn);
            $client = $this->svc->client();
            $cipher = (string) $conn->shop_cipher;
            $start = now()->subDays(30)->toDateString();
            $end = now()->addDay()->toDateString(); // end_date_lt eksklusif → +1 hari biar hari ini masuk

            $out = [];
            $out['orders'] = $client->searchSellerAffiliateOrders($access, $cipher, 3, '', now()->subDays(7)->timestamp, now()->timestamp);
            // Video & LIVE dipisah try — kalau salah satu ditolak, yang lain tetap kelihatan.
            try {
                $out['videos'] = $client->getShopVideoPerformance($access, $cipher, $start, $end, 3);
            } catch (\Throwable $e) {
                $out['videos_ERROR'] = $e->getMessage();
            }
            try {
                $out['lives'] = $client->getShopLivePerformance($access, $cipher, $start, $end, 3);
            } catch (\Throwable $e) {
                $out['lives_ERROR'] = $e->getMessage();
            }
            // Creator Marketplace: cari kreator contoh + performa (buat screening).
            try {
                $mk = $client->searchMarketplaceCreators($access, $cipher, 'dewick02', 12);
                $out['marketplace_search'] = $mk;
                $openId = data_get($mk, 'creators.0.creator_open_id');
                if ($openId) {
                    try {
                        $out['marketplace_performance'] = $client->getMarketplaceCreatorPerformance($access, $cipher, (string) $openId);
                    } catch (\Throwable $e) {
                        $out['marketplace_performance_ERROR'] = $e->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                $out['marketplace_search_ERROR'] = $e->getMessage();
            }

            $conn->update(['last_synced_at' => now()]);

            session()->flash('affiliate_probe', [
                'keys' => array_keys($out),
                'json' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return redirect()->route('tiktok-affiliate.index')->with('status', 'Probe sukses — struktur orders + videos + lives di bawah.');
        } catch (\Throwable $e) {
            return redirect()->route('tiktok-affiliate.index')->with('error', 'Probe gagal: '.$e->getMessage());
        }
    }

    /**
     * Sync manual → pipeline Tim Gapok. days=7 (default, cepat) atau 30 (sebulan
     * penuh; volume besar → naikkan time limit). Cron tetap jalan 30 hari tiap 6 jam.
     */
    public function syncNow(Request $request): RedirectResponse
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok (app affiliate).');

        $days = (int) $request->input('days') === 30 ? 30 : 7;
        if ($days >= 30) {
            @set_time_limit(300); // sebulan bisa puluhan halaman
        }

        try {
            $r = $this->svc->syncOrders($conn, now()->subDays($days), now(), $request->user()->id);

            return back()->with('status', "Sync {$days} hari sukses: {$r['imported']} baris affiliate ({$r['matched']} cocok ke KOL, {$r['unmatched']} belum) dari {$r['pages']} halaman. Cek Tim Gapok.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Sync gagal: '.$e->getMessage());
        }
    }

    public function disconnect(): RedirectResponse
    {
        TiktokAffiliateConnection::query()->delete();
        AuditService::log(action: 'disconnect_tiktok_affiliate', targetType: 'tiktok');

        return redirect()->route('tiktok-affiliate.index')->with('status', 'Koneksi app affiliate diputus.');
    }
}
