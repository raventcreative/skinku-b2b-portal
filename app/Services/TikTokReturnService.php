<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Models\TiktokReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Retur TikTok: tarik dari API (otomatis), lalu review MANUAL sebelum stok ditambah —
 * cuma barang yang masih layak jual yang di-restock; yang cacat ditolak (tidak masuk stok).
 * Pakai "resep SKU" yang sama (TikTokOrderService::resolve) untuk konversi ke produk SKINKU.
 */
class TikTokReturnService
{
    public function __construct(
        private TikTokOrderService $orders,
        private InventoryService $inventory,
    ) {}

    public function store(array $apiReturns): int
    {
        $n = 0;
        foreach ($apiReturns as $r) {
            $id = $r['return_id'] ?? ($r['return_order_id'] ?? null);
            if (! $id) {
                continue;
            }
            $existing = TiktokReturn::where('tiktok_return_id', $id)->first();

            TiktokReturn::updateOrCreate(
                ['tiktok_return_id' => $id],
                [
                    'tiktok_order_id' => $r['order_id'] ?? null,
                    'status' => $r['return_status'] ?? ($r['status'] ?? null),
                    'return_type' => $r['return_type'] ?? null,
                    'line_items' => $this->normalizeItems($r),
                    'return_created_at' => isset($r['create_time']) ? Carbon::createFromTimestamp((int) $r['create_time']) : null,
                    // jangan reset hasil review yang sudah diputuskan
                    'review_status' => $existing->review_status ?? TiktokReturn::REVIEW_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** return_line_items TikTok → [{sku, qty}] (agregasi per SKU). */
    public function normalizeItems(array $ret): array
    {
        $items = $ret['return_line_items'] ?? ($ret['line_items'] ?? []);
        $agg = [];
        foreach ($items as $li) {
            $sku = $li['seller_sku'] ?? ($li['sku_id'] ?? ($li['product_name'] ?? '—'));
            $qty = (int) ($li['quantity'] ?? ($li['return_quantity'] ?? 1));
            $agg[$sku] ??= ['sku' => (string) $sku, 'name' => $li['product_name'] ?? '', 'qty' => 0];
            $agg[$sku]['qty'] += $qty;
        }

        return array_values($agg);
    }

    /** Pratinjau: tiap item retur → komponen produk & qty (pakai resep SKU). */
    public function preview(TiktokReturn $return): array
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
    public function restock(TiktokReturn $return, int $userId, ?string $note = null): void
    {
        if ($return->review_status === TiktokReturn::REVIEW_RESTOCKED) {
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
                        "Retur TikTok {$return->tiktok_return_id} (layak jual)", 'tiktok_return', $return->id,
                    );
                }
            }
            $return->update([
                'review_status' => TiktokReturn::REVIEW_RESTOCKED,
                'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $userId,
            ]);
        });
    }

    /** TOLAK (cacat/tidak layak) → tidak menambah stok. */
    public function reject(TiktokReturn $return, int $userId, ?string $note = null): void
    {
        // kalau sebelumnya sudah restock, tarik lagi stoknya
        if ($return->review_status === TiktokReturn::REVIEW_RESTOCKED) {
            $this->pullBack($return);
        }
        $return->update([
            'review_status' => TiktokReturn::REVIEW_REJECTED,
            'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $userId,
        ]);
    }

    /** Kembalikan ke "pending" (batalkan keputusan); kalau restocked, tarik stok lagi. */
    public function resetReview(TiktokReturn $return): void
    {
        if ($return->review_status === TiktokReturn::REVIEW_RESTOCKED) {
            $this->pullBack($return);
        }
        $return->update(['review_status' => TiktokReturn::REVIEW_PENDING, 'review_note' => null, 'reviewed_at' => null, 'reviewed_by' => null]);
    }

    private function pullBack(TiktokReturn $return): void
    {
        $pv = $this->preview($return);
        DB::transaction(function () use ($return, $pv) {
            foreach ($pv['lines'] as $l) {
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], -1 * (int) $c['add'], StockMovement::TYPE_OUT,
                        "Koreksi retur TikTok {$return->tiktok_return_id}", 'tiktok_return', $return->id,
                    );
                }
            }
        });
    }
}
