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
        private ShopeeReturnService $returns,
        private ShopeeSettlementService $settlements,
        private ShopeeWalletService $wallet,
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

    /**
     * Tarik ULANG seluruh order pada rentang tanggal (menutup lubang histori yang
     * tak tercakup sync rutin yang cuma ~14 hari bergulir). Shopee wajib rentang
     * ≤15 hari → di-iterasi per 14 hari dari $from ke $to. Store-only: idempoten,
     * tak reset stok, tak auto-potong — murni buat melengkapi omzet histori.
     */
    public function backfillOrders(ShopeeConnection $conn, Carbon $from, Carbon $to): array
    {
        $sns = [];
        $winStart = $from->copy();

        for ($w = 0; $w < 120 && $winStart->lt($to); $w++) {  // guard: maks 120 window (~4,5 thn)
            $access = $this->freshToken($conn);  // token 4 jam — refresh tiap window untuk run panjang
            $winEnd = (clone $winStart)->addDays(14);
            if ($winEnd->gt($to)) {
                $winEnd = $to->copy();
            }

            $cursor = '';
            for ($p = 0; $p < 100; $p++) {  // guard halaman per window
                $res = $this->shopee->getOrderList($access, $conn->shop_id, $winStart->timestamp, $winEnd->timestamp, $cursor)['response'] ?? [];
                foreach (($res['order_list'] ?? []) as $row) {
                    if (! empty($row['order_sn'])) {
                        $sns[$row['order_sn']] = true;  // dedup antar-window via key
                    }
                }
                if (empty($res['more']) || empty($res['next_cursor'])) {
                    break;
                }
                $cursor = $res['next_cursor'];
            }

            $winStart = (clone $winEnd)->addSecond();
        }

        $snList = array_keys($sns);

        // Tarik detail per 50 → store (idempoten).
        $access = $this->freshToken($conn);
        $detailOrders = [];
        foreach (array_chunk($snList, 50) as $chunk) {
            $res = $this->shopee->getOrderDetail($access, $conn->shop_id, $chunk)['response'] ?? [];
            foreach (($res['order_list'] ?? []) as $o) {
                $detailOrders[] = $o;
            }
        }

        return ['pulled' => count($snList), 'stored' => $this->orders->store($detailOrders)];
    }

    /**
     * Tarik retur dari Shopee → store. Paginasi page_no (guard 40 halaman).
     * Merge field dari list + detail (detail punya item & alasan).
     */
    public function syncReturns(ShopeeConnection $conn): int
    {
        $access = $this->freshToken($conn);
        $to = now()->timestamp;
        $from = now()->subDays(14)->timestamp; // batas ~15 hari Shopee
        $all = [];
        $pageNo = 0;

        for ($guard = 0; $guard < 40; $guard++) {
            $res = $this->shopee->getReturnList($access, $conn->shop_id, $from, $to, $pageNo, 50);
            $list = $res['response']['return'] ?? [];
            foreach ($list as $r) {
                $sn = $r['return_sn'] ?? null;
                if (! $sn) {
                    continue;
                }
                try {
                    $detail = $this->shopee->getReturnDetail($access, $conn->shop_id, $sn);
                    // detail lebih lengkap → menang; field list (order_sn dsb) jadi fallback
                    $all[] = ($detail['response'] ?? []) + $r;
                } catch (\Throwable $e) {
                    Log::warning("[shopee] gagal ambil detail retur {$sn}: ".$e->getMessage());

                    continue;
                }
            }
            if (empty($res['response']['more'])) {
                break;
            }
            $pageNo++;
            if ($guard === 39) {
                Log::warning('[shopee] getReturnList mentok 40 halaman — data retur mungkin belum lengkap.');
            }
        }

        return $this->returns->store($all);
    }

    /**
     * Tarik escrow (settlement) per-order. get_escrow_list (discovery by release
     * time) → chunk ≤50 → get_escrow_detail_batch → gabung release_time → store.
     *
     * @return array{count:int}
     */
    public function syncSettlements(ShopeeConnection $conn): array
    {
        $access = $this->freshToken($conn);
        $to = now()->timestamp;
        $from = now()->subDays(14)->timestamp;

        $released = []; // order_sn => release_time
        $pageNo = 1;
        for ($guard = 0; $guard < 40; $guard++) {
            $res = $this->shopee->getEscrowList($access, $conn->shop_id, $from, $to, $pageNo, 100);
            foreach ($res['response']['escrow_list'] ?? [] as $e) {
                if (! empty($e['order_sn'])) {
                    $released[$e['order_sn']] = $e['escrow_release_time'] ?? null;
                }
            }
            if (empty($res['response']['more'])) {
                break;
            }
            $pageNo++;
            if ($guard === 39) {
                Log::warning('[shopee] get_escrow_list mentok 40 halaman — data escrow mungkin belum lengkap.');
            }
        }

        $all = [];
        foreach (array_chunk(array_keys($released), 50) as $chunk) {
            try {
                $batch = $this->shopee->getEscrowDetailBatch($access, $conn->shop_id, $chunk);
                foreach ($batch['response'] ?? [] as $item) {
                    $d = $item['escrow_detail'] ?? $item; // batch membungkus tiap order di 'escrow_detail'; single tidak
                    $sn = $d['order_sn'] ?? null;
                    if ($sn && array_key_exists($sn, $released)) {
                        $d['escrow_release_time'] = $released[$sn];
                    }
                    $all[] = $d;
                }
            } catch (\Throwable $e) {
                Log::warning('[shopee] batch escrow gagal: '.$e->getMessage());
            }
        }

        return ['count' => $this->settlements->store($all)];
    }

    /**
     * Tarik mutasi saldo (wallet) dalam rentang waktu → simpan. Lebih sederhana dari
     * escrow: satu panggilan berhalaman langsung berisi datanya (tanpa batch detail).
     * Paginasi page_no (guard 40 halaman).
     *
     * @return array{count:int}
     */
    public function syncWallet(ShopeeConnection $conn): array
    {
        $access = $this->freshToken($conn);
        $to = now()->timestamp;
        $from = now()->subDays(14)->timestamp;

        $all = [];
        $pageNo = 0;
        for ($guard = 0; $guard < 40; $guard++) {
            $res = $this->shopee->getWalletTransactionList($access, $conn->shop_id, $from, $to, $pageNo, 100);
            foreach ($res['response']['transaction_list'] ?? [] as $t) {
                $all[] = $t;
            }
            if (empty($res['response']['more'])) {
                break;
            }
            $pageNo++;
            if ($guard === 39) {
                Log::warning('[shopee] get_wallet_transaction_list mentok 40 halaman — data mutasi saldo mungkin belum lengkap.');
            }
        }

        return ['count' => $this->wallet->store($all)];
    }

    /** Shopee kirim expire_in sbg DETIK-dari-sekarang. */
    public function toTime(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }
}
