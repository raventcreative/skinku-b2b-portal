<?php

namespace App\Services;

use App\Models\TiktokConnection;
use Illuminate\Support\Carbon;

/**
 * Logika tarik data dari TikTok — dipakai bersama oleh Controller (tombol manual)
 * dan Command `tiktok:sync` (cron). Semua idempoten: aman dijalankan berulang.
 */
class TikTokSyncService
{
    public function __construct(
        private TikTokClient $tiktok,
        private TikTokOrderService $orders,
        private TikTokReturnService $returns,
        private TikTokSettlementService $settlements,
    ) {}

    public function connection(): ?TiktokConnection
    {
        return TiktokConnection::latest('id')->first();
    }

    /**
     * Tarik order terbaru (maks ~500) → simpan → auto-potong kalau saklarnya aktif.
     *
     * @return array{count:int, deducted:?array}
     */
    public function syncOrders(TiktokConnection $conn, ?int $userId = null): array
    {
        $access = $this->freshToken($conn);
        $all = [];
        $token = '';
        $pages = 0;
        do {
            $data = $this->tiktok->searchOrders($access, $conn->shop_cipher, 50, $token);
            $all = array_merge($all, $data['orders'] ?? []);
            $token = $data['next_page_token'] ?? '';
            $pages++;
        } while ($token && $pages < 10);

        $count = $this->orders->store($all);
        $conn->update(['last_synced_at' => now()]);

        $deducted = $conn->auto_deduct ? $this->orders->deductAllReady($userId) : null;

        return ['count' => $count, 'deducted' => $deducted];
    }

    /** Tarik retur (perlu scope Return & Refund). */
    public function syncReturns(TiktokConnection $conn): int
    {
        $access = $this->freshToken($conn);
        $all = [];
        $token = '';
        $pages = 0;
        do {
            $data = $this->tiktok->searchReturns($access, $conn->shop_cipher, 50, $token);
            $all = array_merge($all, $data['return_orders'] ?? ($data['returns'] ?? []));
            $token = $data['next_page_token'] ?? '';
            $pages++;
        } while ($token && $pages < 10);

        return $this->returns->store($all);
    }

    /**
     * Tarik pencairan (perlu scope Finance).
     *
     * @return array{count:int, keys:array}
     */
    public function syncSettlements(TiktokConnection $conn): array
    {
        $access = $this->freshToken($conn);
        $all = [];
        $token = '';
        $pages = 0;
        $firstKeys = [];
        do {
            $data = $this->tiktok->getStatements($access, $conn->shop_cipher, 50, $token);
            if ($pages === 0) {
                $firstKeys = array_keys($data);
            }
            $all = array_merge($all, $data['statements'] ?? ($data['statement_list'] ?? ($data['list'] ?? [])));
            $token = $data['next_page_token'] ?? '';
            $pages++;
        } while ($token && $pages < 10);

        return ['count' => $this->settlements->store($all), 'keys' => $firstKeys];
    }

    /** Access token yang masih valid (refresh otomatis kalau mau expire). */
    public function freshToken(TiktokConnection $conn): string
    {
        if (! $conn->accessExpiringSoon()) {
            return $conn->access_token;
        }
        $token = $this->tiktok->refreshToken($conn->refresh_token);
        $conn->update([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $conn->refresh_token,
            'access_expires_at' => $this->toTime($token['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->toTime($token['refresh_token_expire_in'] ?? null),
        ]);

        return $token['access_token'];
    }

    /** TikTok kirim expiry sbg epoch detik (atau kadang detik-dari-sekarang). */
    public function toTime(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        $v = (int) $v;

        return $v > 1_000_000_000 ? Carbon::createFromTimestamp($v) : now()->addSeconds($v);
    }
}
