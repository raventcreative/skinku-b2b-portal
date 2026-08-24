<?php

namespace App\Services;

use App\Models\ShopeeReturn;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Retur Shopee: tarik dari API (otomatis), lalu review MANUAL sebelum stok ditambah —
 * cuma barang yang masih layak jual yang di-restock; yang cacat ditolak (tidak masuk stok).
 * Pakai "resep SKU" yang sama (ShopeeOrderService::resolve) untuk konversi ke produk SKINKU.
 * Stok saja — retur TIDAK menyentuh akuntansi (identik pola TikTok).
 */
class ShopeeReturnService
{
    public function __construct(
        private ShopeeOrderService $orders,
        private InventoryService $inventory,
    ) {}

    public function store(array $apiReturns): int
    {
        $n = 0;
        foreach ($apiReturns as $r) {
            $sn = $r['return_sn'] ?? ($r['return_id'] ?? null);
            if (! $sn) {
                continue;
            }
            $existing = ShopeeReturn::where('shopee_return_sn', $sn)->first();

            ShopeeReturn::updateOrCreate(
                ['shopee_return_sn' => (string) $sn],
                [
                    'shopee_order_sn' => $r['order_sn'] ?? null,
                    'status' => $r['status'] ?? ($r['return_status'] ?? null),
                    'return_reason' => $r['reason'] ?? ($r['return_reason'] ?? null),
                    'line_items' => $this->normalizeItems($r),
                    'return_created_at' => isset($r['create_time']) ? Carbon::createFromTimestamp((int) $r['create_time']) : null,
                    // jangan reset hasil review yang sudah diputuskan
                    'review_status' => $existing->review_status ?? ShopeeReturn::REVIEW_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** item retur Shopee → [{sku, name, qty}] (agregasi per SKU). */
    public function normalizeItems(array $ret): array
    {
        $items = $ret['item'] ?? ($ret['return_line_items'] ?? ($ret['line_items'] ?? []));
        $agg = [];
        foreach ($items as $li) {
            $sku = $li['model_sku'] ?? null;
            if (! $sku) {
                $sku = $li['item_sku'] ?? ($li['item_name'] ?? '—');
            }
            $qty = (int) ($li['amount'] ?? ($li['quantity'] ?? ($li['return_quantity'] ?? 1)));
            $agg[$sku] ??= ['sku' => (string) $sku, 'name' => $li['item_name'] ?? '', 'qty' => 0];
            $agg[$sku]['qty'] += $qty;
        }

        return array_values($agg);
    }

    /** Pratinjau: tiap item retur → komponen produk & qty (pakai resep SKU). */
    public function preview(ShopeeReturn $return): array
    {
        $lines = [];
        $allMatched = true;
        foreach ($return->line_items ?? [] as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $comps = $this->orders->resolve($item['sku'] ?? null);
            if (! $comps) {
                $allMatched = false;
            }
            $lines[] = [
                'sku' => $item['sku'] ?? '—',
                'qty' => $qty,
                'components' => array_map(fn ($c) => ['product' => $c['product'], 'add' => $c['qty'] * $qty], $comps),
            ];
        }

        return ['lines' => $lines, 'all_matched' => $allMatched && count($lines) > 0];
    }

    /** APPROVE layak jual → tambah stok. Idempoten (skip kalau sudah restocked). */
    public function restock(ShopeeReturn $return, int $userId, ?string $note = null): void
    {
        if ($return->review_status === ShopeeReturn::REVIEW_RESTOCKED) {
            return;
        }
        $pv = $this->preview($return);
        if (! $pv['all_matched']) {
            throw new RuntimeException('Ada SKU retur yang belum dipetakan ke produk.');
        }

        DB::transaction(function () use ($return, $pv, $userId, $note) {
            foreach ($pv['lines'] as $l) {
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], (int) $c['add'], StockMovement::TYPE_IN,
                        "Retur Shopee {$return->shopee_return_sn} (layak jual)", 'shopee_return', $return->id,
                    );
                }
            }
            $return->update([
                'review_status' => ShopeeReturn::REVIEW_RESTOCKED,
                'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $userId,
            ]);
        });
    }

    /** TOLAK (cacat/tidak layak) → tidak menambah stok. */
    public function reject(ShopeeReturn $return, int $userId, ?string $note = null): void
    {
        if ($return->review_status === ShopeeReturn::REVIEW_RESTOCKED) {
            $this->pullBack($return);
        }
        $return->update([
            'review_status' => ShopeeReturn::REVIEW_REJECTED,
            'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $userId,
        ]);
    }

    /** Kembalikan ke "pending" (batalkan keputusan); kalau restocked, tarik stok lagi. */
    public function resetReview(ShopeeReturn $return): void
    {
        if ($return->review_status === ShopeeReturn::REVIEW_RESTOCKED) {
            $this->pullBack($return);
        }
        $return->update(['review_status' => ShopeeReturn::REVIEW_PENDING, 'review_note' => null, 'reviewed_at' => null, 'reviewed_by' => null]);
    }

    private function pullBack(ShopeeReturn $return): void
    {
        $pv = $this->preview($return);
        DB::transaction(function () use ($return, $pv) {
            foreach ($pv['lines'] as $l) {
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], -1 * (int) $c['add'], StockMovement::TYPE_OUT,
                        "Koreksi retur Shopee {$return->shopee_return_sn}", 'shopee_return', $return->id,
                    );
                }
            }
        });
    }
}
