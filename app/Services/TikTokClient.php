<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client TikTok Shop Open API (v2). Menangani OAuth token + tanda tangan (HMAC-SHA256)
 * yang wajib di tiap request API. Token endpoint TIDAK butuh tanda tangan; API toko butuh
 * tanda tangan + shop_cipher + header x-tts-access-token.
 *
 * Algoritma sign (resmi TikTok):
 *   1. ambil query params kecuali `sign` & `access_token`, urutkan by key
 *   2. base = path + gabungan {key}{value}
 *   3. tempel body JSON (kalau ada)
 *   4. bungkus: app_secret + base + app_secret
 *   5. HMAC-SHA256(bungkus, key=app_secret) → hex
 */
class TikTokClient
{
    private string $appKey;

    private string $appSecret;

    public function __construct()
    {
        $this->appKey = (string) config('services.tiktok.app_key');
        $this->appSecret = (string) config('services.tiktok.app_secret');
    }

    public function configured(): bool
    {
        return $this->appKey !== '' && $this->appSecret !== '';
    }

    /** URL yang dibuka seller untuk memberi izin (redirect balik ke callback dgn ?code=). */
    public function authorizeUrl(): string
    {
        return rtrim(config('services.tiktok.authorize_base'), '/')
            .'/open/authorize?service_id='.urlencode((string) config('services.tiktok.service_id'));
    }

    /** Tukar auth_code jadi access/refresh token. */
    public function getToken(string $authCode): array
    {
        return $this->authCall('/api/v2/token/get', [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'auth_code' => $authCode,
            'grant_type' => 'authorized_code',
        ]);
    }

    /** Perbarui access token pakai refresh token. */
    public function refreshToken(string $refreshToken): array
    {
        return $this->authCall('/api/v2/token/refresh', [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
    }

    /** Daftar toko yang mengizinkan app ini (dpt shop_cipher). */
    public function getShops(string $accessToken): array
    {
        return $this->request('GET', '/authorization/202309/shops', $accessToken)['shops'] ?? [];
    }

    /** Cari order (dipakai bukti koneksi M1). */
    public function searchOrders(string $accessToken, string $shopCipher, int $pageSize = 20): array
    {
        return $this->request('POST', '/order/202309/orders/search', $accessToken, $shopCipher,
            ['page_size' => $pageSize], []);
    }

    // ---- internal ----

    private function authCall(string $path, array $query): array
    {
        $res = Http::acceptJson()->get(rtrim(config('services.tiktok.auth_base'), '/').$path, $query);
        $json = $res->json() ?? [];
        if (($json['code'] ?? -1) !== 0) {
            throw new RuntimeException('TikTok auth error: '.($json['message'] ?? $res->body()));
        }

        return $json['data'] ?? [];
    }

    /** Request bertanda tangan ke API toko. */
    public function request(string $method, string $path, string $accessToken, ?string $shopCipher = null, array $extraQuery = [], ?array $body = null): array
    {
        $query = array_merge([
            'app_key' => $this->appKey,
            'timestamp' => (string) time(),
        ], $shopCipher ? ['shop_cipher' => $shopCipher] : [], $extraQuery);

        // Body harus JSON object ({}), bukan array ([]). Body yg ditandatangani WAJIB
        // sama persis dgn yg dikirim, jadi hitung sekali di sini.
        $bodyString = $body === null ? '' : json_encode((object) $body);
        $query['sign'] = $this->sign($path, $query, $bodyString);

        $url = rtrim(config('services.tiktok.api_base'), '/').$path;
        $http = Http::withHeaders(['x-tts-access-token' => $accessToken])->acceptJson();

        $res = $method === 'GET'
            ? $http->get($url, $query)
            : $http->withBody($bodyString, 'application/json')->send('POST', $url.'?'.http_build_query($query));

        $json = $res->json() ?? [];
        if (($json['code'] ?? -1) !== 0) {
            throw new RuntimeException('TikTok API error ('.($json['code'] ?? '?').'): '.($json['message'] ?? $res->body()));
        }

        return $json['data'] ?? [];
    }

    /** Hitung tanda tangan HMAC-SHA256 sesuai spesifikasi TikTok. */
    public function sign(string $path, array $query, string $bodyString = ''): string
    {
        $params = $query;
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $base = $path;
        foreach ($params as $k => $v) {
            $base .= $k.$v;
        }
        $base .= $bodyString;
        $wrapped = $this->appSecret.$base.$this->appSecret;

        return hash_hmac('sha256', $wrapped, $this->appSecret);
    }
}
