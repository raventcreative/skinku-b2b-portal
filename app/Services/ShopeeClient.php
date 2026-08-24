<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
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
 * Sign & alur OAuth TERVERIFIKASI di SANDBOX 2026-08-24 (via API Test Tool +
 * shopee:ping; host sandbox: openplatform.sandbox.test-stable.shopee.sg). Sign
 * kita identik byte-per-byte dgn sign valid Shopee. Verifikasi LIVE (partner_id/
 * key produksi) menyusul saat go-live.
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

    /** Daftar retur dalam rentang waktu (batas ~15 hari, sama seperti order). */
    public function getReturnList(string $accessToken, string $shopId, int $from, int $to, int $pageNo = 0, int $pageSize = 50): array
    {
        return $this->shopCall('GET', '/api/v2/returns/get_return_list', $accessToken, $shopId, [
            'create_time_from' => $from,
            'create_time_to' => $to,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    /** Detail retur (per return_sn) — di sinilah item & alasannya. */
    public function getReturnDetail(string $accessToken, string $shopId, string $returnSn): array
    {
        return $this->shopCall('GET', '/api/v2/returns/get_return_detail', $accessToken, $shopId, [
            'return_sn' => $returnSn,
        ]);
    }

    /** Daftar order yang escrow-nya dirilis dalam rentang waktu (discovery ringan). */
    public function getEscrowList(string $accessToken, string $shopId, int $releaseFrom, int $releaseTo, int $pageNo = 1, int $pageSize = 100): array
    {
        return $this->shopCall('GET', '/api/v2/payment/get_escrow_list', $accessToken, $shopId, [
            'release_time_from' => $releaseFrom,
            'release_time_to' => $releaseTo,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    /** Rincian income/fee 1 order. */
    public function getEscrowDetail(string $accessToken, string $shopId, string $orderSn): array
    {
        return $this->shopCall('GET', '/api/v2/payment/get_escrow_detail', $accessToken, $shopId, [
            'order_sn' => $orderSn,
        ]);
    }

    /** Rincian income/fee ≤50 order sekaligus (POST). */
    public function getEscrowDetailBatch(string $accessToken, string $shopId, array $orderSns): array
    {
        return $this->shopCall('POST', '/api/v2/payment/get_escrow_detail_batch', $accessToken, $shopId, [
            'order_sn_list' => array_slice(array_values($orderSns), 0, 50),
        ]);
    }

    /** Daftar mutasi saldo (wallet) dalam rentang waktu — biaya iklan, tarik dana, penyesuaian, dll. */
    public function getWalletTransactionList(string $accessToken, string $shopId, int $from, int $to, int $pageNo = 0, int $pageSize = 100): array
    {
        return $this->shopCall('GET', '/api/v2/payment/get_wallet_transaction_list', $accessToken, $shopId, [
            'create_time_from' => $from,
            'create_time_to' => $to,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * API publik: daftar toko yang mengotorisasi partner ini (tanpa access_token/shop_id).
     * Berguna sebagai uji koneksi — kalau Shopee menerima tanda tangan, kredensial & base URL benar.
     */
    public function getShopsByPartner(int $pageSize = 100, int $pageNo = 1): array
    {
        $path = '/api/v2/public/get_shops_by_partner';
        $ts = time();

        return $this->handle($this->client()->get($this->base().$path, [
            'partner_id' => $this->partnerId,
            'timestamp' => $ts,
            'sign' => $this->sign($path, $ts),
            'page_size' => $pageSize,
            'page_no' => $pageNo,
        ]), $path);
    }

    // ---- internal ----

    /**
     * HTTP client dasar. Bila SHOPEE_INSECURE aktif (HANYA dev lokal di balik
     * proxy/AV yang intersepsi TLS), lewati verifikasi sertifikat. Default: verify penuh.
     */
    private function client(): PendingRequest
    {
        $c = Http::acceptJson();

        return config('services.shopee.insecure') ? $c->withoutVerifying() : $c;
    }

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

        return $this->handle($this->client()->post($url, $body), $path);
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
            ? $this->client()->get($url, $query)
            : $this->client()->post($url.'?'.http_build_query($query), $params);

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
