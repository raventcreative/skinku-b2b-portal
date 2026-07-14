<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TiktokOrder;
use App\Models\TiktokSkuMap;
use Illuminate\Support\Carbon;

class TikTokOrderService
{
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
}
