<?php

namespace App\Services;

use App\Models\TiktokConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
    public function syncOrders(TiktokConnection $conn, ?int $userId = null, bool $full = false): array
    {
        $access = $this->freshToken($conn);
        $startedAt = now(); // dicatat SEBELUM tarik, supaya perubahan saat proses tak terlewat

        // Tanpa filter waktu, tiap sync cuma melihat "500 order terbaru by create_time".
        // Order lama yang STATUSNYA berubah (mis. IN_TRANSIT → DELIVERED) tak akan pernah
        // terlihat lagi begitu tergeser keluar jendela → stok tak pernah terpotong.
        // Filter update_time menangkap perubahan status, bukan cuma order baru.
        $filters = [];
        if (! $full && $conn->last_synced_at) {
            // Tumpang tindih 2 jam sebagai bantalan (cron telat / jam server geser).
            $filters['update_time_ge'] = $conn->last_synced_at->copy()->subHours(2)->timestamp;
        }

        try {
            $all = $this->pullOrders($access, $conn, $filters);
        } catch (\Throwable $e) {
            // Fail-safe: parameter filter ditolak TikTok → jangan sampai sync mati total.
            if (! $filters) {
                throw $e;
            }
            Log::warning('[tiktok:sync] filter update_time ditolak, mundur ke tarik penuh: '.$e->getMessage());
            $all = $this->pullOrders($access, $conn, []);
        }

        $count = $this->orders->store($all);
        $conn->update(['last_synced_at' => $startedAt]);

        $deducted = $conn->auto_deduct ? $this->orders->deductAllReady($userId) : null;

        return ['count' => $count, 'deducted' => $deducted];
    }

    /**
     * Tarik order berhalaman sampai habis (atau mentok batas pengaman).
     *
     * page_size 100 = maksimum TikTok. Batas halaman WAJIB dilaporkan kalau
     * tercapai: memotong diam-diam bikin data tampak lengkap padahal tidak —
     * ini pernah terjadi (batas 10 halaman × 50 = 500 order, sementara toko
     * membuat >1.300 order/bulan → separuh Juli tak pernah masuk).
     */
    private function pullOrders(string $access, TiktokConnection $conn, array $filters, int $maxPages = 60): array
    {
        $all = [];
        $token = '';
        $pages = 0;
        do {
            $data = $this->tiktok->searchOrders($access, $conn->shop_cipher, 100, $token, $filters);
            $all = array_merge($all, $data['orders'] ?? []);
            $token = $data['next_page_token'] ?? '';
            $pages++;
        } while ($token && $pages < $maxPages);

        if ($token) {
            Log::warning('[tiktok:sync] Batas '.$maxPages.' halaman tercapai tapi TikTok masih punya order tersisa — '
                .count($all).' ditarik, DATA BELUM LENGKAP. Jalankan `tiktok:backfill` dengan rentang lebih sempit.');
        }

        return $all;
    }

    /**
     * Tarik SEMUA order pada rentang tanggal (berdasarkan tanggal order dibuat).
     * Dipakai untuk mengisi riwayat yang belum lengkap — sync rutin hanya melihat
     * perubahan sejak sinkron terakhir, jadi tak pernah menutup lubang lama.
     *
     * @return array{pulled:int, stored:int}
     */
    public function backfillOrders(TiktokConnection $conn, Carbon $from, Carbon $to): array
    {
        $access = $this->freshToken($conn);
        $all = $this->pullOrders($access, $conn, [
            'create_time_ge' => $from->timestamp,
            'create_time_lt' => $to->timestamp,
        ], maxPages: 400);   // 400 × 100 = 40.000 order

        return ['pulled' => count($all), 'stored' => $this->orders->store($all)];
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
        } while ($token && $pages < 40);

        if ($token) {
            Log::warning('[tiktok:sync] Batas halaman retur tercapai — '.count($all).' ditarik, data belum lengkap.');
        }

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
        } while ($token && $pages < 40);

        if ($token) {
            Log::warning('[tiktok:sync] Batas halaman pencairan tercapai — '.count($all).' ditarik, data belum lengkap.');
        }

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
