<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client Shopee Open Platform API v2.
 *
 * Tanda tangan (beda konsep dari TikTok — TikTok mengurutkan SEMUA query param,
 * Shopee hanya merangkai beberapa nilai secara BERURUTAN):
 *   - API publik (token/refresh) : base = partner_id + api_path + timestamp
 *   - API toko (butuh login)     : base = partner_id + api_path + timestamp + access_token + shop_id
 *   sign = HMAC-SHA256(base, partner_key) → hex
 *
 * Catatan penting:
 *   - access_token cuma berlaku ~4 JAM. refresh_token ~30 hari.
 *   - get_order_list dibatasi rentang waktu maks 15 hari per panggilan.
 *
 * BELUM DIVERIFIKASI LIVE (menunggu Partner ID/Key). Seperti TikTok dulu,
 * kebenaran tanda tangan baru benar-benar terbukti saat panggilan pertama —
 * Shopee akan menolak dengan error "wrong sign" kalau meleset.
 */
class ShopeeClient
{
    private string $partnerId;

    private string $partnerKey;

    public function __construct()
    {
        $this->partnerId = (string) config('services.shopee.partner_id');
        $this->partnerKey = (string) config('services.shopee.partner_key');
    }

    public function configured(): bool
    {
        return $this->partnerId !== '' && $this->partnerKey !== '';
    }

    /** URL izin toko — seller login lalu Shopee balik ke redirect dgn ?code=&shop_id= */
    public function authorizeUrl(string $redirect): string
    {
        $path = '/api/v2/shop/auth_partner';
        $ts = time();

        return $this->base().$path.'?'.http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $ts,
            'sign' => $this->sign($path, $ts),
            'redirect' => $redirect,
        ]);
    }

    /** Tukar code jadi access/refresh token (API publik — tanpa access_token & shop_id). */
    public function getToken(string $code, string $shopId): array
    {
        return $this->publicCall('/api/v2/auth/token/get', [
            'code' => $code,
            'shop_id' => (int) $shopId,
            'partner_id' => (int) $this->partnerId,
        ]);
    }

    /** Perbarui access token (dipanggil sering — token cuma 4 jam). */
    public function refreshToken(string $refreshToken, string $shopId): array
    {
        return $this->publicCall('/api/v2/auth/access_token/get', [
            'refresh_token' => $refreshToken,
            'shop_id' => (int) $shopId,
            'partner_id' => (int) $this->partnerId,
        ]);
    }

    /**
     * Daftar order dalam rentang waktu. WAJIB pakai rentang (maks 15 hari) —
     * tak ada mode "ambil semua". time_range_field=update_time menangkap
     * perubahan STATUS order lama, bukan cuma order baru (pelajaran dari TikTok).
     */
    public function getOrderList(string $accessToken, string $shopId, int $from, int $to, string $cursor = '', int $pageSize = 50): array
    {
        return $this->shopCall('GET', '/api/v2/order/get_order_list', $accessToken, $shopId, [
            'time_range_field' => 'update_time',
            'time_from' => $from,
            'time_to' => $to,
            'page_size' => $pageSize,
            'cursor' => $cursor,
        ]);
    }

    /** Detail order (maks 50 order_sn per panggilan) — di sinilah item & SKU-nya. */
    public function getOrderDetail(string $accessToken, string $shopId, array $orderSns): array
    {
        return $this->shopCall('GET', '/api/v2/order/get_order_detail', $accessToken, $shopId, [
            'order_sn_list' => implode(',', array_slice($orderSns, 0, 50)),
            'response_optional_fields' => 'order_status,total_amount,currency,create_time,update_time,item_list',
        ]);
    }

    // ---- internal ----

    private function base(): string
    {
        return rtrim((string) config('services.shopee.api_base'), '/');
    }

    /**
     * Tanda tangan Shopee: rangkai nilai BERURUTAN (bukan diurutkan by key
     * seperti TikTok), lalu HMAC-SHA256 dengan partner_key.
     */
    public function sign(string $path, int $timestamp, string $accessToken = '', string $shopId = ''): string
    {
        $base = $this->partnerId.$path.$timestamp.$accessToken.$shopId;

        return hash_hmac('sha256', $base, $this->partnerKey);
    }

    /** API publik: tanpa access_token & shop_id di tanda tangan. */
    private function publicCall(string $path, array $body): array
    {
        $ts = time();
        $url = $this->base().$path.'?'.http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $ts,
            'sign' => $this->sign($path, $ts),
        ]);

        return $this->handle(Http::acceptJson()->post($url, $body), $path);
    }

    /** API toko: access_token & shop_id ikut ditandatangani DAN dikirim di query. */
    public function shopCall(string $method, string $path, string $accessToken, string $shopId, array $params = []): array
    {
        $ts = time();
        $query = array_merge([
            'partner_id' => $this->partnerId,
            'timestamp' => $ts,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->sign($path, $ts, $accessToken, $shopId),
        ], $params);

        $url = $this->base().$path;
        $res = $method === 'GET'
            ? Http::acceptJson()->get($url, $query)
            : Http::acceptJson()->post($url.'?'.http_build_query($query), $params);

        return $this->handle($res, $path);
    }

    /** Shopee menandai galat lewat field `error` yang tidak kosong (bukan HTTP status). */
    private function handle($res, string $path): array
    {
        $json = $res->json() ?? [];
        if (! empty($json['error'])) {
            throw new RuntimeException("Shopee API error pada {$path} ({$json['error']}): ".($json['message'] ?? $res->body()));
        }

        return $json;
    }
}
