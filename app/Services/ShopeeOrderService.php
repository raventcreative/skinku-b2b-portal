<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSkuMap;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Potong stok dari order Shopee. Alur & pengaman meniru TikTok yang sudah
 * terbukti live: resep SKU (1 SKU → banyak produk × qty), batas tanggal
 * (deduct_from) agar order pra-opname tidak dipotong dobel, idempoten, dan
 * bisa dibatalkan.
 *
 * Stok keluar dicatat dengan reference_type 'shopee_order' — Laporan Stok HQ
 * SUDAH mengenali itu dan otomatis mengisinya ke kolom Shopee.
 */
class ShopeeOrderService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * Simpan/perbarui order dari API. $apiOrders = hasil get_order_detail.
     *
     * @return int jumlah tersimpan
     */
    public function store(array $apiOrders): int
    {
        $n = 0;
        foreach ($apiOrders as $o) {
            $sn = $o['order_sn'] ?? null;
            if (! $sn) {
                continue;
            }
            $existing = ShopeeOrder::where('order_sn', $sn)->first();

            ShopeeOrder::updateOrCreate(
                ['order_sn' => $sn],
                [
                    'status' => $o['order_status'] ?? null,
                    'total_amount' => (float) ($o['total_amount'] ?? 0),
                    'currency' => $o['currency'] ?? null,
                    'line_items' => $this->normalizeItems($o),
                    'order_created_at' => isset($o['create_time']) ? Carbon::createFromTimestamp((int) $o['create_time']) : null,
                    // jangan reset status potong stok kalau sudah pernah dipotong
                    'stock_status' => $existing->stock_status ?? ShopeeOrder::STATUS_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /**
     * Ringkas item_list Shopee → [{sku, name, qty}], diagregasi per SKU.
     * Shopee punya dua SKU: model_sku (varian) & item_sku (produk induk).
     * Varian lebih spesifik, jadi diutamakan.
     */
    public function normalizeItems(array $order): array
    {
        $agg = [];
        foreach ($order['item_list'] ?? [] as $it) {
            $sku = $it['model_sku'] ?? null;
            if (! $sku) {
                $sku = $it['item_sku'] ?? ($it['item_name'] ?? '—');
            }
            $qty = (int) ($it['model_quantity_purchased'] ?? ($it['quantity_purchased'] ?? 1));
            if (! isset($agg[$sku])) {
                $agg[$sku] = ['sku' => (string) $sku, 'name' => $it['item_name'] ?? '', 'qty' => 0];
            }
            $agg[$sku]['qty'] += $qty;
        }

        return array_values($agg);
    }

    /**
     * "Resep" 1 SKU Shopee → komponen produk SKINKU [{product, qty}].
     * Prioritas: peta manual, lalu cocok langsung dengan Product.sku (×1).
     *
     * @return array<int, array{product: Product, qty: int}>
     */
    public function resolve(?string $sku): array
    {
        if (! $sku) {
            return [];
        }
        $maps = ShopeeSkuMap::with('product')->where('shopee_sku', $sku)->get();
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

    /** Pratinjau dampak stok: tiap item → komponen produk & qty total. */
    public function preview(ShopeeOrder $order): array
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
                'components' => array_map(fn ($c) => ['product' => $c['product'], 'deduct' => $c['qty'] * $orderQty], $comps),
            ];
        }

        return ['lines' => $lines, 'all_matched' => $allMatched && count($lines) > 0];
    }

    /**
     * SKU yang perlu dipetakan manual (belum auto-cocok dengan Product.sku).
     *
     * @return array<string, array{name: string, components: mixed}>
     */
    public function skusNeedingMap(): array
    {
        $out = [];
        foreach (ShopeeOrder::pluck('line_items') as $items) {
            foreach ((array) $items as $it) {
                $sku = $it['sku'] ?? null;
                if (! $sku || $sku === '—' || isset($out[$sku]) || $this->isAutoMatched($sku)) {
                    continue;
                }
                $out[$sku] = [
                    'name' => $it['name'] ?? '',
                    'components' => ShopeeSkuMap::with('product')->where('shopee_sku', $sku)->get(),
                ];
            }
        }

        return $out;
    }

    /** Nilai HPP order = Σ (HPP produk × qty komponen). */
    public function computeHpp(ShopeeOrder $order): float
    {
        $hpp = 0.0;
        foreach ($this->preview($order)['lines'] as $l) {
            foreach ($l['components'] as $c) {
                $hpp += (float) $c['product']->cogs * (int) $c['deduct'];
            }
        }

        return round($hpp, 2);
    }

    /** Tanggal mulai potong stok (order sebelumnya sudah tercakup stok opname). */
    public function cutoff(): ?Carbon
    {
        $c = ShopeeConnection::latest('id')->first();

        return $c?->deduct_from ? Carbon::parse($c->deduct_from)->startOfDay() : null;
    }

    public function isBeforeCutoff(ShopeeOrder $order): bool
    {
        $cut = $this->cutoff();

        return $cut && $order->order_created_at && $order->order_created_at->lt($cut);
    }

    /** POTONG stok untuk 1 order (idempoten, guard status/mapping/cutoff). */
    public function deduct(ShopeeOrder $order, ?int $userId = null): void
    {
        if ($order->stock_status === ShopeeOrder::STATUS_DEDUCTED) {
            return; // sudah pernah — jangan dobel
        }
        if (! $order->isShipped()) {
            throw new RuntimeException('Order belum dikirim — belum boleh potong stok.');
        }
        if ($this->isBeforeCutoff($order)) {
            throw new RuntimeException('Order dibuat sebelum '.$this->cutoff()->format('d M Y')
                .' — barangnya sudah tercakup stok opname, tidak dipotong lagi.');
        }
        $pv = $this->preview($order);
        if (! $pv['all_matched']) {
            throw new RuntimeException('Masih ada SKU yang belum dipetakan ke produk.');
        }

        $hpp = $this->computeHpp($order);

        DB::transaction(function () use ($order, $pv, $userId, $hpp) {
            foreach ($pv['lines'] as $l) {
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], -1 * (int) $c['deduct'], StockMovement::TYPE_OUT,
                        "Penjualan Shopee {$order->order_sn}", 'shopee_order', $order->id,
                    );
                }
            }
            $order->update([
                'stock_status' => ShopeeOrder::STATUS_DEDUCTED,
                'hpp_amount' => $hpp,
                'deducted_at' => now(), 'deducted_by' => $userId,
            ]);
        });
    }

    /**
     * Potong stok untuk SEMUA order yang siap. Per order try/catch supaya satu
     * gagal tak menghentikan sisanya.
     *
     * @return array{done:int, failed:int, skipped:int}
     */
    public function deductAllReady(?int $userId = null): array
    {
        $done = 0;
        $failed = 0;
        $skipped = 0;
        $cut = $this->cutoff();

        $orders = ShopeeOrder::where('stock_status', ShopeeOrder::STATUS_PENDING)
            ->whereIn('status', ShopeeOrder::SHIPPED_STATUSES)
            ->when($cut, fn ($q) => $q->where('order_created_at', '>=', $cut))
            ->get();

        foreach ($orders as $o) {
            if (! $this->preview($o)['all_matched']) {
                $skipped++;

                continue;
            }
            try {
                $this->deduct($o, $userId);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                // Jangan telan: tanpa ini operator cuma lihat angka tanpa sebab.
                Log::warning("[shopee] gagal potong stok order {$o->order_sn}: ".$e->getMessage());
            }
        }

        return compact('done', 'failed', 'skipped');
    }

    /** Batalkan pemotongan (kembalikan stok). */
    public function reverse(ShopeeOrder $order): void
    {
        if ($order->stock_status !== ShopeeOrder::STATUS_DEDUCTED) {
            return;
        }
        $pv = $this->preview($order);

        DB::transaction(function () use ($order, $pv) {
            foreach ($pv['lines'] as $l) {
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], (int) $c['deduct'], StockMovement::TYPE_IN,
                        "Batal penjualan Shopee {$order->order_sn}", 'shopee_order', $order->id,
                    );
                }
            }
            $order->update(['stock_status' => ShopeeOrder::STATUS_PENDING, 'deducted_at' => null, 'deducted_by' => null]);
        });
    }
}
