<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TiktokOrder;
use App\Models\TiktokSkuMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TikTokOrderService
{
    public function __construct(private InventoryService $inventory) {}

    /** Simpan/opsir order dari API TikTok. Return jumlah yg tersimpan. */
    public function store(array $apiOrders): int
    {
        $n = 0;
        foreach ($apiOrders as $o) {
            $id = $o['id'] ?? null;
            if (! $id) {
                continue;
            }
            $existing = TiktokOrder::where('tiktok_order_id', $id)->first();

            TiktokOrder::updateOrCreate(
                ['tiktok_order_id' => $id],
                [
                    'status' => $o['status'] ?? null,
                    'total_amount' => (float) ($o['payment']['total_amount'] ?? 0),
                    'currency' => $o['payment']['currency'] ?? null,
                    'line_items' => $this->normalizeItems($o),
                    'order_created_at' => isset($o['create_time']) ? Carbon::createFromTimestamp((int) $o['create_time']) : null,
                    // jangan reset status potong stok kalau sudah pernah dipotong
                    'stock_status' => $existing->stock_status ?? TiktokOrder::STATUS_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** Ringkas line_items TikTok → [{sku, name, qty}] (agregasi per SKU). */
    public function normalizeItems(array $order): array
    {
        $agg = [];
        foreach ($order['line_items'] ?? [] as $li) {
            $sku = $li['seller_sku'] ?? ($li['sku_id'] ?? ($li['product_name'] ?? '—'));
            $qty = (int) ($li['quantity'] ?? 1);
            if (! isset($agg[$sku])) {
                $agg[$sku] = ['sku' => (string) $sku, 'name' => $li['product_name'] ?? '', 'qty' => 0];
            }
            $agg[$sku]['qty'] += $qty;
        }

        return array_values($agg);
    }

    /** Cari produk SKINKU untuk sebuah SKU TikTok: cocokkan SKU, lalu peta manual. */
    public function resolveProduct(?string $sku): ?Product
    {
        if (! $sku) {
            return null;
        }
        $p = Product::where('sku', $sku)->first();
        if ($p) {
            return $p;
        }
        $map = TiktokSkuMap::where('tiktok_sku', $sku)->first();

        return $map?->product;
    }

    /** Pratinjau dampak stok: tiap item + produk tercocok + apakah semua ke-map. */
    public function preview(TiktokOrder $order): array
    {
        $lines = [];
        $allMatched = true;
        foreach ($order->line_items ?? [] as $item) {
            $product = $this->resolveProduct($item['sku'] ?? null);
            if (! $product) {
                $allMatched = false;
            }
            $lines[] = ['sku' => $item['sku'] ?? '—', 'name' => $item['name'] ?? '', 'qty' => $item['qty'] ?? 0, 'product' => $product];
        }

        return ['lines' => $lines, 'all_matched' => $allMatched && count($lines) > 0];
    }

    /** SKU TikTok yang belum ke-map (unik, di semua order). [sku => contoh nama]. */
    public function unmatchedSkus(): array
    {
        $out = [];
        foreach (TiktokOrder::pluck('line_items') as $items) {
            foreach ((array) $items as $it) {
                $sku = $it['sku'] ?? null;
                if ($sku && $sku !== '—' && ! isset($out[$sku]) && ! $this->resolveProduct($sku)) {
                    $out[$sku] = $it['name'] ?? '';
                }
            }
        }

        return $out;
    }

    /** POTONG stok internal untuk 1 order (idempoten, guard status & mapping). */
    public function deduct(TiktokOrder $order, int $userId): void
    {
        if ($order->stock_status === TiktokOrder::STATUS_DEDUCTED) {
            return; // sudah pernah — jangan dobel
        }
        if (! $order->isShipped()) {
            throw new RuntimeException('Order belum dikirim — belum boleh potong stok.');
        }
        $pv = $this->preview($order);
        if (! $pv['all_matched']) {
            throw new RuntimeException('Masih ada SKU yang belum dipetakan ke produk.');
        }

        DB::transaction(function () use ($order, $pv, $userId) {
            foreach ($pv['lines'] as $l) {
                $this->inventory->adjustHqStock(
                    $l['product'], -1 * (int) $l['qty'], StockMovement::TYPE_OUT,
                    "Penjualan TikTok {$order->tiktok_order_id}", 'tiktok_order', $order->id,
                );
            }
            $order->update([
                'stock_status' => TiktokOrder::STATUS_DEDUCTED,
                'deducted_at' => now(), 'deducted_by' => $userId,
            ]);
        });
    }

    /** Batalkan pemotongan (kembalikan stok). */
    public function reverse(TiktokOrder $order): void
    {
        if ($order->stock_status !== TiktokOrder::STATUS_DEDUCTED) {
            return;
        }
        $pv = $this->preview($order);

        DB::transaction(function () use ($order, $pv) {
            foreach ($pv['lines'] as $l) {
                if ($l['product']) {
                    $this->inventory->adjustHqStock(
                        $l['product'], (int) $l['qty'], StockMovement::TYPE_IN,
                        "Batal penjualan TikTok {$order->tiktok_order_id}", 'tiktok_order', $order->id,
                    );
                }
            }
            $order->update(['stock_status' => TiktokOrder::STATUS_PENDING, 'deducted_at' => null, 'deducted_by' => null]);
        });
    }
}
