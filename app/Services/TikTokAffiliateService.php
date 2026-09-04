<?php

namespace App\Services;

use App\Models\TiktokAffiliateConnection;
use Illuminate\Support\Carbon;

/**
 * Sinkron order affiliate TikTok (Affiliate Seller API) → pipeline
 * KolAffiliateTransaction (source='tiktok_api') yang sama dipakai Tim Gapok &
 * halaman Affiliate. Token/refresh dikelola di sini; pemetaan respons TikTok
 * (mapOrders) dibuat murni supaya bisa dites dengan JSON asli dari probe.
 */
class TikTokAffiliateService
{
    private TikTokClient $client;

    public function __construct(private KolAffiliateService $affiliate)
    {
        $this->client = new TikTokClient('tiktok_affiliate');
    }

    public function client(): TikTokClient
    {
        return $this->client;
    }

    /**
     * Tarik SEMUA order affiliate di rentang [from,to] (paginasi) → import ke
     * pipeline. Return {imported,matched,unmatched,pages}.
     */
    public function syncOrders(TiktokAffiliateConnection $conn, Carbon $from, Carbon $to, ?int $actorId, int $maxPages = 100): array
    {
        $access = $this->freshToken($conn);
        $rows = [];
        $pageToken = '';
        $pages = 0;

        do {
            $data = $this->client->searchSellerAffiliateOrders(
                $access, (string) $conn->shop_cipher, 100, $pageToken, $from->timestamp, $to->timestamp
            );
            $rows = array_merge($rows, $this->mapOrders($data));
            $pageToken = (string) ($data['next_page_token'] ?? '');
            $pages++;
        } while ($pageToken !== '' && $pages < $maxPages);

        $res = $this->affiliate->import($rows, 'tiktok', $actorId, 'tiktok_api');
        $conn->update(['last_synced_at' => now()]);

        return $res + ['pages' => $pages];
    }

    /**
     * Respons `data` (orders[].skus[]) → baris siap import. MURNI (tanpa I/O) →
     * dites dengan JSON asli. Satu SKU = satu baris (order_id sintetis
     * "{orderId}-{skuId}" agar unik & idempoten saat re-sync).
     *
     * @return array<int,array<string,mixed>>
     */
    public function mapOrders(array $data): array
    {
        $rows = [];
        foreach (($data['orders'] ?? []) as $order) {
            foreach (($order['skus'] ?? []) as $sku) {
                $rows[] = $this->mapSku($order, $sku);
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function mapSku(array $order, array $sku): array
    {
        // GMV = dasar komisi (nilai penjualan yang diatribusikan). Fallback price×qty
        // bila base kosong. Angka datang sbg string → cast.
        $qty = (int) ($sku['quantity'] ?? 1);
        $gmv = (float) data_get($sku, 'estimated_commission_base.amount', 0);
        if ($gmv <= 0) {
            $gmv = (float) data_get($sku, 'price.amount', 0) * max(1, $qty);
        }

        return [
            'order_id' => (string) ($order['id'] ?? '').'-'.(string) ($sku['sku_id'] ?? ''),
            'username' => $sku['creator_username'] ?? null,
            'gmv' => (int) round($gmv),
            'commission' => (int) round((float) data_get($sku, 'estimated_paid_commission.amount', 0)),
            'commission_settled' => (int) round((float) data_get($sku, 'actual_paid_commission.amount', 0)),
            'qty' => $qty,
            'product' => $sku['product_id'] ?? null,
            'content_type' => $sku['content_type'] ?? null,          // VIDEO | LIVE | SHOP | LINKSHARE
            'status' => $sku['settlement_status'] ?? null,
            'order_date' => isset($order['create_time'])
                ? Carbon::createFromTimestamp((int) $order['create_time'])->toDateString()
                : now()->toDateString(),
        ];
    }

    /** Access token valid — refresh bila mau habis. */
    public function freshToken(TiktokAffiliateConnection $conn): string
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
    public function toTime(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;

        return $n > 1_000_000_000 ? Carbon::createFromTimestamp($n) : now()->addSeconds($n);
    }
}
