<?php

namespace App\Services;

use App\Models\ShopeeConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator sync Shopee — meniru TikTokSyncService (bagian order).
 * Token Shopee cuma ~4 jam, jadi freshToken() dipanggil sebelum tiap tarik.
 * getOrderList wajib rentang waktu (maks 15 hari) → window dibangun di sini.
 */
class ShopeeSyncService
{
    public function __construct(
        private ShopeeClient $shopee,
        private ShopeeOrderService $orders,
    ) {}

    public function connection(): ?ShopeeConnection
    {
        return ShopeeConnection::latest('id')->first();
    }

    /** Pastikan access token valid (refresh kalau hampir habis — token 4 jam). */
    public function freshToken(ShopeeConnection $conn): string
    {
        if (! $conn->accessExpiringSoon()) {
            return (string) $conn->access_token;
        }
        $t = $this->shopee->refreshToken($conn->refresh_token, $conn->shop_id);
        $conn->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $conn->refresh_token,
            'access_expires_at' => $this->toTime($t['expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    /**
     * Tarik order dalam window waktu → simpan → (opsi) auto-potong stok.
     * $full = window lebih lebar (14 hari); selain itu sejak last_synced_at−2 jam.
     */
    public function syncOrders(ShopeeConnection $conn, ?int $userId = null, bool $full = false): array
    {
        $access = $this->freshToken($conn);
        $startedAt = now();

        $to = now()->timestamp;
        // Shopee tolak rentang >15 hari. Kalau last_synced_at basi (cron mati lama),
        // clamp ke floor 14 hari biar rentang tetap valid & self-heal, bukan wedge selamanya.
        $from = ($full || ! $conn->last_synced_at)
            ? now()->subDays(14)->timestamp
            : max(now()->subDays(14)->timestamp, $conn->last_synced_at->copy()->subHours(2)->timestamp);

        // 1) kumpulkan order_sn berhalaman (cursor)
        $sns = [];
        $cursor = '';
        $capped = true; // tetap true kecuali loop berhenti wajar (habis data)
        for ($guard = 0; $guard < 50; $guard++) {
            $res = $this->shopee->getOrderList($access, $conn->shop_id, $from, $to, $cursor)['response'] ?? [];
            foreach (($res['order_list'] ?? []) as $row) {
                if (! empty($row['order_sn'])) {
                    $sns[] = $row['order_sn'];
                }
            }
            if (empty($res['more']) || empty($res['next_cursor'])) {
                $capped = false;
                break;
            }
            $cursor = $res['next_cursor'];
        }
        if ($capped) {
            // Sama seperti TikTokSyncService::pullOrders(): batas halaman kena
            // padahal Shopee masih punya order tersisa → jangan diam-diam,
            // last_synced_at tetap maju jadi order yang terlewat bisa hilang permanen.
            Log::warning('[shopee:sync] batas halaman tercapai — sebagian order mungkin terlewat, jalankan --full untuk sapu ulang.');
        }

        // 2) tarik detail per 50 → kumpulkan
        $detailOrders = [];
        foreach (array_chunk($sns, 50) as $chunk) {
            $res = $this->shopee->getOrderDetail($access, $conn->shop_id, $chunk)['response'] ?? [];
            foreach (($res['order_list'] ?? []) as $o) {
                $detailOrders[] = $o;
            }
        }

        // 3) simpan + catat waktu sync
        $count = $this->orders->store($detailOrders);
        $conn->update(['last_synced_at' => $startedAt]);

        // 4) auto-potong bila diaktifkan
        $deducted = $conn->auto_deduct ? $this->orders->deductAllReady($userId) : null;

        return ['count' => $count, 'deducted' => $deducted];
    }

    /** Shopee kirim expire_in sbg DETIK-dari-sekarang. */
    public function toTime(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }
}
