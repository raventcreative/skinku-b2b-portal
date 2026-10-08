<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ShopeeBoostItem;
use App\Models\ShopeeConnection;
use Throwable;

/**
 * "Naikkan Produk" Shopee otomatis (ala Desty): maks 5 produk pilihan dijaga tetap naik 24 jam. Shopee menaikkan
 * produk 4 jam per sekali naik (v2.product.boost_item, 1–5 item); tiap putaran (cron 10 menit) cek dulu yang masih
 * naik (v2.product.get_boosted_list → cool_down_second), lalu naikkan sisanya. Hasil per produk dicatat di tabel.
 */
class ShopeeBoostService
{
    public const MAKS = 5;

    public const DURASI_JAM = 4;

    public const KUNCI_AKTIF = 'shopee_naikkan_aktif';

    public function __construct(private ShopeeClient $shopee, private ShopeeSyncService $sync) {}

    public function aktif(): bool
    {
        return AppSetting::get(self::KUNCI_AKTIF) === '1';
    }

    /**
     * Satu putaran. $paksa = tombol "Jalankan sekarang" (jalan walau saklar mati).
     *
     * @return array{status:string, naik?:int, gagal?:int, sedang_naik?:int, pesan?:string}
     */
    public function jalankan(bool $paksa = false): array
    {
        if (! $paksa && ! $this->aktif()) {
            return ['status' => 'nonaktif'];
        }
        $items = ShopeeBoostItem::orderBy('id')->get();
        if ($items->isEmpty()) {
            return ['status' => 'kosong'];
        }
        $conn = ShopeeConnection::latest('id')->first();
        if (! $conn) {
            return ['status' => 'belum_terhubung'];
        }

        try {
            $token = $this->sync->freshToken($conn);
            $sedang = collect($this->shopee->getBoostedList($token, (string) $conn->shop_id)['response']['item_list'] ?? [])
                ->mapWithKeys(fn ($r) => [(int) $r['item_id'] => (int) ($r['cool_down_second'] ?? 0)])
                ->filter(fn (int $detik) => $detik > 0);
            foreach ($items as $it) {
                if (isset($sedang[$it->item_id])) {
                    $it->update(['boosted_until' => now()->addSeconds($sedang[$it->item_id]), 'last_status' => 'ok', 'last_error' => null]);
                }
            }

            $perlu = $items->reject(fn (ShopeeBoostItem $it) => isset($sedang[$it->item_id]))->values();
            if ($perlu->isEmpty()) {
                return ['status' => 'ok', 'naik' => 0, 'gagal' => 0, 'sedang_naik' => $sedang->count()];
            }
            $res = $this->shopee->boostItem($token, (string) $conn->shop_id, $perlu->pluck('item_id')->all())['response'] ?? [];
        } catch (Throwable $e) {
            // Gagal total (token/izin/jaringan) → tandai produk yang belum naik supaya alasannya kelihatan di halaman.
            ShopeeBoostItem::whereIn('id', ($perlu ?? $items)->pluck('id'))
                ->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return ['status' => 'error', 'pesan' => $e->getMessage()];
        }

        $berhasil = array_map('intval', $res['success_list']['item_id_list'] ?? []);
        $gagal = collect($res['failure_list'] ?? [])->mapWithKeys(fn ($f) => [(int) $f['item_id'] => (string) ($f['failed_reason'] ?? 'gagal')]);
        foreach ($perlu as $it) {
            if (in_array($it->item_id, $berhasil, true)) {
                $it->update(['last_boosted_at' => now(), 'boosted_until' => now()->addHours(self::DURASI_JAM), 'last_status' => 'ok', 'last_error' => null]);
            } elseif (isset($gagal[$it->item_id])) {
                $it->update(['last_status' => 'failed', 'last_error' => $gagal[$it->item_id]]);
            }
        }

        return ['status' => 'ok', 'naik' => count($berhasil), 'gagal' => $gagal->count(), 'sedang_naik' => $sedang->count()];
    }
}
