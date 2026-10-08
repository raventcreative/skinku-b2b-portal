<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\MarketplaceListing;
use App\Models\ShopeeBoostItem;
use App\Models\ShopeeConnection;
use App\Models\ShopeeProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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

    private const PESAN_SLOT_PENUH = 'Slot Naikkan Produk toko penuh — dipakai produk lain (mis. dari Desty atau Seller Centre). Dinaikkan otomatis begitu ada slot kosong.';

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
            // Foto produk dari Shopee (sekali, lalu di-cache) — utk tabel & pencarian di halaman. Best-effort.
            $this->lengkapiFoto(array_merge($items->pluck('item_id')->all(),
                MarketplaceListing::where('channel', 'shopee')->whereNotNull('item_id')->distinct()->pluck('item_id')->all()), $token, (string) $conn->shop_id);
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
                // Slot yang tadinya kosong dipakai produk pilihan di $kirim → terpakai = yang naik + yang dikirim.
                ShopeeBoostItem::whereIn('id', $menunggu->pluck('id'))
                    ->update(['last_status' => 'penuh', 'last_error' => $this->pesanPenuh($naik, $pilihan, $naik->count() + $kirim->count())]);
            }
            if ($kirim->isEmpty()) {
                return ['status' => 'slot_penuh', 'naik' => 0, 'gagal' => 0, 'sedang_naik' => $sedangNaik, 'pesan' => $this->pesanPenuh($naik, $pilihan, $naik->count())];
            }
        } catch (Throwable $e) {
            // Token / izin / jaringan gagal sebelum mengirim → tandai produk yang belum naik supaya alasannya kelihatan.
            ShopeeBoostItem::whereIn('id', ($perlu ?? $items)->pluck('id'))
                ->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return ['status' => 'error', 'pesan' => $e->getMessage()];
        }

        // Kirim SATU PER SATU: batas slot toko sebenarnya bisa lebih kecil dari perkiraan (atau keduluan Desty/manual)
        // dan Shopee menolak SELURUH permintaan bila kelebihan — satu per satu, yang muat tetap naik, sisanya menunggu.
        $naikBaru = 0;
        $gagal = 0;
        $menungguSlot = $menunggu->count();
        foreach ($kirim->values() as $i => $it) {
            try {
                $res = $this->shopee->boostItem($token, (string) $conn->shop_id, [$it->item_id])['response'] ?? [];
            } catch (Throwable $e) {
                $sisa = $kirim->values()->slice($i)->pluck('id');
                if (! str_contains($e->getMessage(), 'bump slot limit')) {
                    ShopeeBoostItem::whereIn('id', $sisa)->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

                    return ['status' => 'error', 'pesan' => $e->getMessage(), 'naik' => $naikBaru];
                }
                ShopeeBoostItem::whereIn('id', $sisa)->update(['last_status' => 'penuh', 'last_error' => self::PESAN_SLOT_PENUH]);
                $menungguSlot += $sisa->count();
                break;
            }
            if (in_array($it->item_id, array_map('intval', $res['success_list']['item_id_list'] ?? []), true)) {
                $it->update(['last_boosted_at' => now(), 'boosted_until' => now()->addHours(self::DURASI_JAM), 'last_status' => 'ok', 'last_error' => null]);
                $naikBaru++;
            } else {
                $alasan = collect($res['failure_list'] ?? [])->firstWhere('item_id', $it->item_id)['failed_reason'] ?? 'ditolak Shopee tanpa alasan';
                $it->update(['last_status' => 'failed', 'last_error' => (string) $alasan]);
                $gagal++;
            }
        }

        if ($naikBaru === 0 && $gagal === 0) {
            return ['status' => 'slot_penuh', 'naik' => 0, 'gagal' => 0, 'sedang_naik' => $sedangNaik, 'pesan' => self::PESAN_SLOT_PENUH];
        }

        return ['status' => 'ok', 'naik' => $naikBaru, 'gagal' => $gagal, 'sedang_naik' => $sedangNaik, 'menunggu_slot' => $menungguSlot];
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

    /**
     * Foto & judul produk Shopee yang belum ada di cache ShopeeProduct → ambil dari get_item_base_info (maks 50 per
     * panggilan). Best-effort: gagal → halaman tampil tanpa foto, putaran tetap jalan.
     */
    public function lengkapiFoto(array $itemIds, string $token, string $shopId): void
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $ada = ShopeeProduct::whereIn('item_id', array_map('strval', $ids))->where('image_url', '!=', '')->pluck('item_id')->map(fn ($i) => (int) $i)->all();
        $kurang = array_values(array_diff($ids, $ada));
        try {
            foreach (array_chunk($kurang, 50) as $potong) {
                foreach (data_get($this->shopee->getItemBaseInfo($token, $shopId, $potong), 'response.item_list', []) as $info) {
                    ShopeeProduct::simpanDariBaseInfo($info);
                }
            }
        } catch (Throwable $e) {
            Log::warning('shopee: foto Naikkan Produk gagal diambil', ['e' => $e->getMessage()]);
        }
    }

    /** @param Collection<int,int> $naik item_id => sisa detik (semua yang sedang naik di toko) */
    private function pesanPenuh(Collection $naik, array $pilihan, int $terpakai): string
    {
        $lain = $naik->except($pilihan)->count();
        $menit = (int) ceil(($naik->min() ?? 0) / 60);

        return 'Slot Naikkan Produk toko penuh ('.min($terpakai, self::MAKS).'/'.self::MAKS.')'
            .($lain > 0 ? " — {$lain} dipakai produk lain (mis. dari Desty atau Seller Centre)" : '')
            .'. Slot kosong berikutnya ±'.intdiv($menit, 60).'j '.($menit % 60).'m lagi, dinaikkan otomatis.';
    }
}
