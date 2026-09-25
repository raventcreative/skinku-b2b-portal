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

    private string $configKey;

    /** $configKey: blok config app — 'tiktok' (Shop) atau 'tiktok_affiliate' (Affiliate Seller). */
    public function __construct(string $configKey = 'tiktok')
    {
        $this->configKey = $configKey;
        $this->appKey = (string) config("services.{$configKey}.app_key");
        $this->appSecret = (string) config("services.{$configKey}.app_secret");
    }

    public function configured(): bool
    {
        return $this->appKey !== '' && $this->appSecret !== '';
    }

    /** URL yang dibuka seller untuk memberi izin (redirect balik ke callback dgn ?code=). */
    public function authorizeUrl(): string
    {
        return rtrim(config("services.{$this->configKey}.authorize_base"), '/')
            .'/open/authorize?service_id='.urlencode((string) config("services.{$this->configKey}.service_id"));
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

    /**
     * Cari order affiliate seller (Affiliate Seller API) — order layak komisi affiliate
     * di toko. Butuh scope seller.affiliate_collaboration.read + shop_cipher app affiliate.
     * create_time_ge/lt = epoch UTC (maks rentang 3 bulan/permintaan; kosong = 3 bulan
     * terakhir). Satu halaman; pagination via next_page_token di data respons.
     *
     * @return array data mentah TikTok (struktur field diverifikasi via tiktok:affiliate-probe)
     */
    public function searchSellerAffiliateOrders(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = '', ?int $createTimeGe = null, ?int $createTimeLt = null): array
    {
        $query = ['page_size' => $pageSize];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }
        $body = array_filter([
            'create_time_ge' => $createTimeGe,
            'create_time_lt' => $createTimeLt,
        ], fn ($v) => $v !== null);

        return $this->request('POST', '/affiliate_seller/202410/orders/search', $accessToken, $shopCipher, $query, $body);
    }

    /**
     * Performa video toko (Analytics) — daftar video + metrik + info kreator. Scope
     * data.shop_analytics.public.read. Tanggal ISO 8601 YYYY-MM-DD (zona toko);
     * end_date_lt EKSKLUSIF. account_type=AFFILIATE_ACCOUNTS → video affiliate.
     */
    public function getShopVideoPerformance(string $accessToken, string $shopCipher, string $startDate, string $endDate, int $pageSize = 100, string $pageToken = '', string $accountType = 'AFFILIATE_ACCOUNTS'): array
    {
        return $this->request('GET', '/analytics/202605/shop_videos/performance', $accessToken, $shopCipher,
            $this->analyticsQuery($startDate, $endDate, $pageSize, $pageToken, $accountType));
    }

    /**
     * Performa LIVE toko (Analytics) — daftar sesi LIVE + metrik. Scope
     * data.shop_analytics.public.read. Catatan TikTok: seller hanya bisa query
     * room ID milik akun creator resmi sendiri → data affiliate bisa terbatas.
     */
    public function getShopLivePerformance(string $accessToken, string $shopCipher, string $startDate, string $endDate, int $pageSize = 100, string $pageToken = '', string $accountType = 'AFFILIATE_ACCOUNTS'): array
    {
        return $this->request('GET', '/analytics/202509/shop_lives/performance', $accessToken, $shopCipher,
            $this->analyticsQuery($startDate, $endDate, $pageSize, $pageToken, $accountType));
    }

    /**
     * Cari kreator di Creator Marketplace by keyword (username/nickname). Scope
     * seller.creator_marketplace.read. Data 30 hari terakhir (termasuk GMV).
     * page_size WAJIB 12 atau 20.
     */
    public function searchMarketplaceCreators(string $accessToken, string $shopCipher, string $keyword, int $pageSize = 12, string $pageToken = '', array $gmvRanges = []): array
    {
        $query = ['page_size' => $pageSize];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }
        $body = array_filter([
            'keyword' => $keyword !== '' ? $keyword : null,
            'gmv_ranges' => $gmvRanges !== [] ? $gmvRanges : null,
        ], fn ($v) => $v !== null);

        return $this->request('POST', '/affiliate_seller/202608/marketplace_creators/search', $accessToken, $shopCipher, $query, $body);
    }

    /**
     * Performa satu kreator marketplace by Open ID (30 hari): BASIC/CONTENT/
     * FOLLOWER/COLLABORATION/SALES_AND_GMV. Tanpa data_groups = ambil semua.
     */
    public function getMarketplaceCreatorPerformance(string $accessToken, string $shopCipher, string $openId): array
    {
        return $this->request('GET', '/affiliate_seller/202608/marketplace_creators/'.rawurlencode($openId), $accessToken, $shopCipher);
    }

    /** @return array<string,mixed> query bersama endpoint analytics video/LIVE. */
    private function analyticsQuery(string $startDate, string $endDate, int $pageSize, string $pageToken, string $accountType): array
    {
        $query = [
            'start_date_ge' => $startDate,
            'end_date_lt' => $endDate,
            'page_size' => $pageSize,
            'account_type' => $accountType,
        ];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $query;
    }

    /**
     * Cari order — TERBARU dulu (sort create_time DESC). Satu halaman.
     *
     * @param  array  $filters  body filter, mis. ['update_time_ge' => epoch] untuk menangkap
     *                          perubahan STATUS order lama (bukan cuma order baru).
     */
    public function searchOrders(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = '', array $filters = []): array
    {
        $query = ['page_size' => $pageSize, 'sort_field' => 'create_time', 'sort_order' => 'DESC'];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $this->request('POST', '/order/202309/orders/search', $accessToken, $shopCipher, $query, $filters);
    }

    /** Cari retur/refund — TERBARU dulu. Satu halaman. */
    public function searchReturns(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = ''): array
    {
        $query = ['page_size' => $pageSize, 'sort_field' => 'create_time', 'sort_order' => 'DESC'];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $this->request('POST', '/return_refund/202309/returns/search', $accessToken, $shopCipher, $query, []);
    }

    /**
     * Customer Service API — daftar percakapan (TERBARU dulu). Satu halaman.
     * NB: path/versi = keluarga /customer_service/202309; cocokkan dgn docs TikTok.
     */
    public function getConversations(string $accessToken, string $shopCipher, int $pageSize = 10, string $pageToken = ''): array
    {
        $query = ['page_size' => max(1, min($pageSize, 10))]; // CS API: 0 < page_size <= 10
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $this->request('GET', '/customer_service/202309/conversations', $accessToken, $shopCipher, $query);
    }

    /** Pesan dalam satu percakapan (TERBARU dulu). Satu halaman. */
    public function getConversationMessages(string $accessToken, string $shopCipher, string $conversationId, int $pageSize = 10, string $pageToken = ''): array
    {
        $query = ['page_size' => max(1, min($pageSize, 10))]; // CS API: 0 < page_size <= 10
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $this->request('GET', "/customer_service/202309/conversations/{$conversationId}/messages", $accessToken, $shopCipher, $query);
    }

    /** Kirim balasan teks ke satu percakapan. */
    public function sendMessage(string $accessToken, string $shopCipher, string $conversationId, string $text): array
    {
        return $this->request(
            'POST',
            "/customer_service/202309/conversations/{$conversationId}/messages",
            $accessToken,
            $shopCipher,
            [],
            // CS API: `content` WAJIB string JSON {"content":"..."} (bukan teks polos),
            // konsisten dgn bentuk content di getConversationMessages.
            ['type' => 'TEXT', 'content' => json_encode(['content' => $text])],
        );
    }

    /**
     * Detail satu produk (Products API) — judul, foto utama, SKU + harga. Dipakai
     * memperkaya kartu produk di chat (TikTok cuma kirim product_id). Butuh scope
     * produk + shop_cipher app SHOP (bukan affiliate).
     */
    public function getProduct(string $accessToken, string $shopCipher, string $productId): array
    {
        return $this->request('GET', '/product/202309/products/'.rawurlencode($productId), $accessToken, $shopCipher);
    }

    /**
     * Events API — daftar langganan webhook app untuk toko ini (event_type + address).
     * Dipakai mendiagnosa apakah NEW_MESSAGE sudah benar-benar ter-subscribe.
     */
    public function getWebhooks(string $accessToken, string $shopCipher): array
    {
        return $this->request('GET', '/event/202309/webhooks', $accessToken, $shopCipher);
    }

    /**
     * Events API — daftarkan/perbarui satu langganan webhook: event_type → address.
     * Idempoten di sisi TikTok (satu address per event_type). PUT, bukan POST.
     */
    public function updateWebhook(string $accessToken, string $shopCipher, string $eventType, string $address): array
    {
        return $this->request('PUT', '/event/202309/webhooks', $accessToken, $shopCipher, [], [
            'event_type' => $eventType,
            'address' => $address,
        ]);
    }

    /** Daftar pencairan (settlement statements) — TERBARU dulu. Satu halaman. */
    public function getStatements(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = ''): array
    {
        $query = ['page_size' => $pageSize, 'sort_field' => 'statement_time', 'sort_order' => 'DESC'];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $this->request('GET', '/finance/202309/statements', $accessToken, $shopCipher, $query);
    }

    /** Rincian transaksi dalam 1 pencairan (buat tahu jenis tiap potongan). Satu halaman. */
    public function getStatementTransactions(string $accessToken, string $shopCipher, string $statementId, int $pageSize = 50, string $pageToken = ''): array
    {
        // sort_field WAJIB utk endpoint ini (error 36009004 kalau tak ada).
        $query = ['page_size' => $pageSize, 'sort_field' => 'order_create_time', 'sort_order' => 'DESC'];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }
        $path = '/finance/202309/statements/'.rawurlencode($statementId).'/statement_transactions';

        return $this->request('GET', $path, $accessToken, $shopCipher, $query);
    }

    /** Perbarui stok satu SKU di satu gudang (Inventory API). */
    public function updateStock(string $accessToken, string $shopCipher, string $productId, string $skuId, string $warehouseId, int $qty): array
    {
        return $this->request('POST', "/product/202309/products/{$productId}/inventory/update", $accessToken, $shopCipher, [], [
            'skus' => [['id' => $skuId, 'inventory' => [['warehouse_id' => $warehouseId, 'quantity' => $qty]]]],
        ]);
    }

    /** Perbarui harga satu SKU (Product Price API 202309). Amount = string, currency IDR. */
    public function updatePrice(string $accessToken, string $shopCipher, string $productId, string $skuId, float $price): array
    {
        return $this->request('POST', "/product/202309/products/{$productId}/prices/update", $accessToken, $shopCipher, [], [
            'skus' => [['id' => $skuId, 'price' => ['amount' => (string) (int) round($price), 'currency' => 'IDR']]],
        ]);
    }

    /** Cari produk aktif toko — dipakai memetakan produk lokal ↔ product/SKU TikTok. */
    public function searchProducts(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = ''): array
    {
        $q = ['page_size' => $pageSize];
        if ($pageToken !== '') {
            $q['page_token'] = $pageToken;
        }

        return $this->request('POST', '/product/202309/products/search', $accessToken, $shopCipher, $q, ['status' => 'ACTIVATE']);
    }

    /** Daftar gudang toko — sumber warehouse_id untuk updateStock. */
    public function getWarehouses(string $accessToken, string $shopCipher): array
    {
        return $this->request('GET', '/logistics/202309/warehouses', $accessToken, $shopCipher);
    }

    // ---- internal ----

    private function authCall(string $path, array $query): array
    {
        $res = Http::acceptJson()->get(rtrim(config("services.{$this->configKey}.auth_base"), '/').$path, $query);
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

        $url = rtrim(config("services.{$this->configKey}.api_base"), '/').$path;
        $http = Http::withHeaders(['x-tts-access-token' => $accessToken])->acceptJson();

        $res = $method === 'GET'
            ? $http->get($url, $query)
            : $http->withBody($bodyString, 'application/json')->send($method, $url.'?'.http_build_query($query));

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
