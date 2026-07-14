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

    /**
     * "Resep" 1 SKU TikTok → komponen produk SKINKU [{product, qty}] (qty per 1 unit SKU).
     * Prioritas: peta manual (bisa banyak komponen), lalu cocok SKU langsung (×1).
     *
     * @return array<int, array{product: Product, qty: int}>
     */
    public function resolve(?string $sku): array
    {
        if (! $sku) {
            return [];
        }
        $maps = TiktokSkuMap::with('product')->where('tiktok_sku', $sku)->get();
        if ($maps->isNotEmpty()) {
            return $maps->filter(fn ($m) => $m->product)
                ->map(fn ($m) => ['product' => $m->product, 'qty' => max(1, (int) $m->qty)])->values()->all();
        }
        $p = Product::where('sku', $sku)->first();

        return $p ? [['product' => $p, 'qty' => 1]] : [];
    }

    public function isAutoMatched(string $sku): bool
    {
        return Product::where('sku', $sku)->exists();
    }

    /** Pratinjau dampak stok: tiap item → komponen produk & qty total (×qty order). */
    public function preview(TiktokOrder $order): array
    {
        $lines = [];
        $allMatched = true;
        foreach ($order->line_items ?? [] as $item) {
            $orderQty = (int) ($item['qty'] ?? 0);
            $comps = $this->resolve($item['sku'] ?? null);
            if (! $comps) {
                $allMatched = false;
            }
            $lines[] = [
                'sku' => $item['sku'] ?? '—',
                'name' => $item['name'] ?? '',
                'qty' => $orderQty,
                // total potong = qty resep × qty order
                'components' => array_map(fn ($c) => ['product' => $c['product'], 'deduct' => $c['qty'] * $orderQty], $comps),
            ];
        }

        return ['lines' => $lines, 'all_matched' => $allMatched && count($lines) > 0];
    }

    /**
     * SKU TikTok yang perlu dipetakan manual (tidak auto-cocok by Product.sku).
     * Termasuk yg sudah punya resep — supaya bisa diedit/ditambah komponennya.
     *
     * @return array<string, array{name: string, components: array}>
     */
    public function skusNeedingMap(): array
    {
        $out = [];
        foreach (TiktokOrder::pluck('line_items') as $items) {
            foreach ((array) $items as $it) {
                $sku = $it['sku'] ?? null;
                if (! $sku || $sku === '—' || isset($out[$sku]) || $this->isAutoMatched($sku)) {
                    continue;
                }
                $out[$sku] = [
                    'name' => $it['name'] ?? '',
                    'components' => TiktokSkuMap::with('product')->where('tiktok_sku', $sku)->get(),
                ];
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
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], -1 * (int) $c['deduct'], StockMovement::TYPE_OUT,
                        "Penjualan TikTok {$order->tiktok_order_id}", 'tiktok_order', $order->id,
                    );
                }
            }
            $order->update([
                'stock_status' => TiktokOrder::STATUS_DEDUCTED,
                'deducted_at' => now(), 'deducted_by' => $userId,
            ]);
        });
    }

    /**
     * Potong stok untuk SEMUA order yang siap (dikirim + semua SKU cocok + belum
     * dipotong). Per order try/catch supaya 1 gagal (mis. stok kurang) tak menghentikan
     * yang lain. @return array{done:int, failed:int, skipped:int}
     */
    public function deductAllReady(int $userId): array
    {
        $done = 0;
        $failed = 0;
        $skipped = 0;
        $orders = TiktokOrder::where('stock_status', TiktokOrder::STATUS_PENDING)
            ->whereIn('status', TiktokOrder::SHIPPED_STATUSES)->get();

        foreach ($orders as $o) {
            if (! $this->preview($o)['all_matched']) {
                $skipped++;

                continue; // SKU belum lengkap dipetakan
            }
            try {
                $this->deduct($o, $userId);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return compact('done', 'failed', 'skipped');
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
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], (int) $c['deduct'], StockMovement::TYPE_IN,
                        "Batal penjualan TikTok {$order->tiktok_order_id}", 'tiktok_order', $order->id,
                    );
                }
            }
            $order->update(['stock_status' => TiktokOrder::STATUS_PENDING, 'deducted_at' => null, 'deducted_by' => null]);
        });
    }
}
