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
            $data = $this->svc->client()->searchSellerAffiliateOrders(
                $access, $conn->shop_cipher, 5, '', now()->subDays(7)->timestamp, now()->timestamp
            );
            $conn->update(['last_synced_at' => now()]);

            session()->flash('affiliate_probe', [
                'keys' => array_keys($data),
                'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return redirect()->route('tiktok-affiliate.index')->with('status', 'Probe sukses — lihat struktur respons di bawah.');
        } catch (\Throwable $e) {
            return redirect()->route('tiktok-affiliate.index')->with('error', 'Probe gagal: '.$e->getMessage());
        }
    }

    /**
     * Sync manual 7 hari terakhir → pipeline Tim Gapok. Sengaja pendek (bukan 30
     * hari) supaya request web tak timeout saat volume order besar; rentang penuh
     * 30 hari ditangani cron tiap 6 jam.
     */
    public function syncNow(Request $request): RedirectResponse
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok (app affiliate).');

        try {
            $r = $this->svc->syncOrders($conn, now()->subDays(7), now(), $request->user()->id);

            return back()->with('status', "Sync sukses: {$r['imported']} baris affiliate ({$r['matched']} cocok ke KOL, {$r['unmatched']} belum) dari {$r['pages']} halaman. Cek Tim Gapok.");
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
