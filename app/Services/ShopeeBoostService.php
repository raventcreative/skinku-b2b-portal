<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ShopeeBoostItem;
use App\Models\ShopeeConnection;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Naikkan Produk" Shopee otomatis (ala Desty): maks 5 produk pilihan dijaga tetap naik 24 jam. Shopee menaikkan
 * produk 4 jam per sekali naik (v2.product.boost_item, 1–5 item) dan slotnya DIBAGI satu toko (termasuk yang dinaikkan
 * Desty / manual di Seller Centre). Tiap putaran (cron 10 menit): cek semua yang masih naik (get_boosted_list →
 * cool_down_second), lalu naikkan produk pilihan sebanyak slot yang masih kosong. Hasil per produk dicatat di tabel.
 */
class ShopeeBoostService
{
    public const MAKS = 5;

    public const DURASI_JAM = 4;

    public const KUNCI_AKTIF = 'shopee_naikkan_aktif';

    /** Potret slot toko putaran terakhir (JSON): kapan dicek + produk LAIN (bukan pilihan) yang sedang memakai slot. */
    public const KUNCI_SLOT = 'shopee_naikkan_slot';

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

        $kirim = null;
        try {
            $token = $this->sync->freshToken($conn);
            // SEMUA produk toko yang sedang naik — termasuk dari Desty / manual — memakai slot yang sama.
            $naik = collect($this->shopee->getBoostedList($token, (string) $conn->shop_id)['response']['item_list'] ?? [])
                ->mapWithKeys(fn ($r) => [(int) $r['item_id'] => (int) ($r['cool_down_second'] ?? 0)])
                ->filter(fn (int $detik) => $detik > 0);
            $pilihan = $items->pluck('item_id')->all();
            $this->simpanSlot($naik->except($pilihan));
            foreach ($items as $it) {
                if (isset($naik[$it->item_id])) {
                    $it->update(['boosted_until' => now()->addSeconds($naik[$it->item_id]), 'last_status' => 'ok', 'last_error' => null]);
                }
            }

            $perlu = $items->reject(fn (ShopeeBoostItem $it) => isset($naik[$it->item_id]))->values();
            $sedangNaik = $items->count() - $perlu->count();
            if ($perlu->isEmpty()) {
                return ['status' => 'ok', 'naik' => 0, 'gagal' => 0, 'sedang_naik' => $sedangNaik];
            }
            // Kirim hanya sebanyak slot kosong: melebihi batas = SELURUH permintaan ditolak Shopee.
            $kosong = self::MAKS - $naik->count();
            $kirim = $perlu->take(max(0, $kosong));
            $menunggu = $perlu->slice(max(0, $kosong));
            if ($menunggu->isNotEmpty()) {
                ShopeeBoostItem::whereIn('id', $menunggu->pluck('id'))
                    ->update(['last_status' => 'penuh', 'last_error' => $this->pesanPenuh($naik, $pilihan)]);
            }
            if ($kirim->isEmpty()) {
                return ['status' => 'slot_penuh', 'naik' => 0, 'gagal' => 0, 'sedang_naik' => $sedangNaik, 'pesan' => $this->pesanPenuh($naik, $pilihan)];
            }
            $res = $this->shopee->boostItem($token, (string) $conn->shop_id, $kirim->pluck('item_id')->all())['response'] ?? [];
        } catch (Throwable $e) {
            // Slot penuh karena keduluan Desty/manual di sela putaran → "menunggu slot", bukan gagal permanen.
            $penuh = str_contains($e->getMessage(), 'bump slot limit');
            $pesan = $penuh
                ? 'Slot Naikkan Produk toko penuh (5/5) — dipakai produk lain, mis. dari Desty atau Seller Centre. Dicoba lagi otomatis.'
                : mb_substr($e->getMessage(), 0, 500);
            ShopeeBoostItem::whereIn('id', ($kirim ?? $perlu ?? $items)->pluck('id'))
                ->update(['last_status' => $penuh ? 'penuh' : 'failed', 'last_error' => $pesan]);

            return ['status' => $penuh ? 'slot_penuh' : 'error', 'pesan' => $pesan];
        }

        $berhasil = array_map('intval', $res['success_list']['item_id_list'] ?? []);
        $gagal = collect($res['failure_list'] ?? [])->mapWithKeys(fn ($f) => [(int) $f['item_id'] => (string) ($f['failed_reason'] ?? 'gagal')]);
        foreach ($kirim as $it) {
            if (in_array($it->item_id, $berhasil, true)) {
                $it->update(['last_boosted_at' => now(), 'boosted_until' => now()->addHours(self::DURASI_JAM), 'last_status' => 'ok', 'last_error' => null]);
            } elseif (isset($gagal[$it->item_id])) {
                $it->update(['last_status' => 'failed', 'last_error' => $gagal[$it->item_id]]);
            }
        }

        return ['status' => 'ok', 'naik' => count($berhasil), 'gagal' => $gagal->count(), 'sedang_naik' => $sedangNaik,
            'menunggu_slot' => $menunggu->count()];
    }

    /**
     * Potret slot toko putaran terakhir utk halaman: produk LAIN yang memakai slot (item_id => sampai kapan).
     *
     * @return array{dicek: ?string, lain: array<int, array{item_id:int, sampai:string}>}
     */
    public function slotTerakhir(): array
    {
        $data = json_decode((string) AppSetting::get(self::KUNCI_SLOT), true);

        return ['dicek' => $data['dicek'] ?? null, 'lain' => $data['lain'] ?? []];
    }

    /** @param Collection<int,int> $lain item_id => sisa detik */
    private function simpanSlot(Collection $lain): void
    {
        AppSetting::put(self::KUNCI_SLOT, json_encode([
            'dicek' => now()->toIso8601String(),
            'lain' => $lain->map(fn (int $detik, int $id) => ['item_id' => $id, 'sampai' => now()->addSeconds($detik)->toIso8601String()])->values()->all(),
        ]));
    }

    /** @param Collection<int,int> $naik item_id => sisa detik (semua yang sedang naik di toko) */
    private function pesanPenuh(Collection $naik, array $pilihan): string
    {
        $lain = $naik->except($pilihan)->count();
        $menit = (int) ceil(($naik->min() ?? 0) / 60);

        return "Slot Naikkan Produk toko penuh ({$naik->count()}/".self::MAKS.')'
            .($lain > 0 ? " — {$lain} dipakai produk lain (mis. dari Desty atau Seller Centre)" : '')
            .'. Slot kosong berikutnya ±'.intdiv($menit, 60).'j '.($menit % 60).'m lagi, dinaikkan otomatis.';
    }
}
