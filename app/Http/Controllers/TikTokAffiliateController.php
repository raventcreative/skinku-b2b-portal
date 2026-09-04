<?php

namespace App\Http\Controllers;

use App\Models\TiktokAffiliateConnection;
use App\Services\AuditService;
use App\Services\TikTokClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Integrasi TikTok Affiliate Seller API (app terpisah "Seller Analitik") — OAuth
 * + probe. Fase B: sambungkan toko & pastikan bisa narik order affiliate. Parser
 * + sync ke pipeline gapok menyusul (Fase B2) setelah struktur respons dipastikan
 * lewat probe — jangan nebak nama field.
 */
class TikTokAffiliateController extends Controller
{
    private TikTokClient $client;

    public function __construct()
    {
        $this->client = new TikTokClient('tiktok_affiliate');
    }

    public function index()
    {
        return view('tiktok_affiliate.index', [
            'configured' => $this->client->configured(),
            'connection' => TiktokAffiliateConnection::latest('id')->first(),
            'probe' => session('affiliate_probe'),
        ]);
    }

    /** Mulai OAuth app affiliate — arahkan ke halaman izin TikTok. */
    public function connect(): RedirectResponse
    {
        abort_unless($this->client->configured(), 400, 'App key/secret affiliate belum diisi di .env server (TIKTOK_AFFILIATE_*).');

        return redirect()->away($this->client->authorizeUrl());
    }

    /** Callback TikTok (?code) → tukar token, ambil toko (shop_cipher), simpan. */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code') ?: $request->query('auth_code');
        if (! $code) {
            return redirect()->route('tiktok-affiliate.index')->with('error', 'Otorisasi dibatalkan / tidak ada kode dari TikTok.');
        }

        try {
            $token = $this->client->getToken($code);
        } catch (\Throwable $e) {
            return redirect()->route('tiktok-affiliate.index')->with('error', 'Gagal tukar token (app_key/secret salah?): '.$e->getMessage());
        }

        $access = $token['access_token'] ?? '';
        // Diagnostik: scope yang BENAR-BENAR dibawa token ini (bukti apakah otorisasi
        // sudah menyertakan seller.authorization.info atau masih pakai grant lama).
        $granted = $token['granted_scopes'] ?? ($token['scope'] ?? null);
        $grantedStr = $granted === null ? '(TikTok tak mengirim daftar granted_scopes)'
            : (is_array($granted) ? implode(', ', $granted) : (string) $granted);

        try {
            $shops = $this->client->getShops($access);
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
                'access_expires_at' => $this->toTime($token['access_token_expire_in'] ?? null),
                'refresh_expires_at' => $this->toTime($token['refresh_token_expire_in'] ?? null),
                'connected_by' => $request->user()->id,
            ],
        );

        AuditService::log(action: 'connect_tiktok_affiliate', targetType: 'tiktok', after: ['shop' => $shop['name'] ?? null]);

        return redirect()->route('tiktok-affiliate.index')->with('status', 'App affiliate terhubung: '.($shop['name'] ?? 'toko').' ┃ scope token: ['.$grantedStr.']');
    }

    /**
     * Probe: tarik 1 halaman order affiliate & tampilkan struktur respons MENTAH.
     * Dipakai memastikan nama field sebelum bikin parser (jangan nebak).
     */
    public function probe(Request $request): RedirectResponse
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok (app affiliate).');

        try {
            $access = $this->freshToken($conn);
            $data = $this->client->searchSellerAffiliateOrders(
                $access, $conn->shop_cipher, 5, '', now()->subDays(7)->timestamp, now()->timestamp
            );
            $conn->update(['last_synced_at' => now()]);

            session()->flash('affiliate_probe', [
                'keys' => array_keys($data),
                'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return redirect()->route('tiktok-affiliate.index')->with('status', 'Probe sukses — lihat struktur respons di bawah, kirim ke Claude buat bikin parser.');
        } catch (\Throwable $e) {
            return redirect()->route('tiktok-affiliate.index')->with('error', 'Probe gagal: '.$e->getMessage());
        }
    }

    public function disconnect(): RedirectResponse
    {
        TiktokAffiliateConnection::query()->delete();
        AuditService::log(action: 'disconnect_tiktok_affiliate', targetType: 'tiktok');

        return redirect()->route('tiktok-affiliate.index')->with('status', 'Koneksi app affiliate diputus.');
    }

    /** Access token valid — refresh kalau mau habis. */
    private function freshToken(TiktokAffiliateConnection $conn): string
    {
        if (! $conn->accessExpiringSoon()) {
            return (string) $conn->access_token;
        }
        $t = $this->client->refreshToken((string) $conn->refresh_token);
        $conn->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $conn->refresh_token,
            'access_expires_at' => $this->toTime($t['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->toTime($t['refresh_token_expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    /** Epoch detik (atau detik-dari-sekarang) → Carbon. */
    private function toTime(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;

        return $n > 1_000_000_000 ? Carbon::createFromTimestamp($n) : now()->addSeconds($n);
    }
}
