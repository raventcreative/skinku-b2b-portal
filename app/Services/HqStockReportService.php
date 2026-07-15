<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * Laporan Mutasi Stok HQ (pusat) per produk per periode (harian / bulanan).
 *
 * Rumus: Stok Akhir = Stok Awal + Produksi + Penyesuaian − (TikTok + Shopee + Reseller + lain).
 *
 * Semua diturunkan dari `stock_movements` milik HQ (user_id NULL). Delta tiap
 * gerakan = after_qty − before_qty (robust utk IN/OUT/ADJUSTMENT/TRANSFER).
 * Saldo diturunkan dari stok sekarang dikurangi gerakan setelahnya, jadi laporan
 * SELALU balance apa pun urutan penulisannya.
 */
class HqStockReportService
{
    /**
     * @return array{
     *   mode:string, start:Carbon, end:Carbon, label:string,
     *   prev:string, next:string,
     *   rows: array<int, array<string,mixed>>,
     *   totals: array<string,int>,
     *   baseline: ?Carbon
     * }
     */
    public function report(string $mode, Carbon $anchor): array
    {
        [$start, $end, $label, $prev, $next] = $this->periodBounds($mode, $anchor);

        $products = Product::query()
            ->where('status', '!=', Product::STATUS_DELETED)
            ->orderBy('name')->get();

        $stockNow = $products->pluck('hq_stock', 'id')->map(fn ($v) => (int) $v);

        // Saldo: stok sekarang − Σdelta pada/ sesudah batas → saldo di batas itu.
        $sumFrom = fn (Carbon $t) => StockMovement::query()
            ->whereNull('user_id')
            ->where('created_at', '>=', $t)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(after_qty - before_qty) as s')
            ->pluck('s', 'product_id');

        $fromStart = $sumFrom($start);          // untuk stok awal
        $afterEnd = $sumFrom($end->copy()->addSecond()); // untuk stok akhir (> end)

        // Gerakan dalam periode → dikelompokkan per produk & kategori.
        $inPeriod = StockMovement::query()
            ->whereNull('user_id')
            ->whereBetween('created_at', [$start, $end])
            ->get(['product_id', 'movement_type', 'reference_type', 'before_qty', 'after_qty']);

        $buckets = [];
        foreach ($inPeriod as $m) {
            $delta = (int) $m->after_qty - (int) $m->before_qty;
            $b = &$buckets[$m->product_id];
            $b ??= $this->emptyBuckets();
            $this->bucketize($b, $m->reference_type, $m->movement_type, $delta);
        }
        unset($b);

        $rows = [];
        $totals = $this->emptyBuckets() + ['awal' => 0, 'akhir' => 0];
        foreach ($products as $p) {
            $now = $stockNow[$p->id] ?? 0;
            $awal = $now - (int) ($fromStart[$p->id] ?? 0);
            $akhir = $now - (int) ($afterEnd[$p->id] ?? 0);
            $b = $buckets[$p->id] ?? $this->emptyBuckets();

            // Sembunyikan produk yang benar-benar tak bergerak & saldo nol.
            $moved = $awal !== 0 || $akhir !== 0 || array_sum($b) !== 0;
            if (! $moved) {
                continue;
            }

            $row = $b + ['product' => $p, 'awal' => $awal, 'akhir' => $akhir];
            $rows[] = $row;

            foreach ($this->emptyBuckets() as $k => $_) {
                $totals[$k] += $b[$k];
            }
            $totals['awal'] += $awal;
            $totals['akhir'] += $akhir;
        }

        $baselineRaw = StockMovement::whereNull('user_id')->where('reference_type', 'opname')->min('created_at');
        $baseline = $baselineRaw ? Carbon::parse($baselineRaw) : null;

        return compact('mode', 'start', 'end', 'label', 'prev', 'next', 'rows', 'totals', 'baseline');
    }

    /** @return array{0:Carbon,1:Carbon,2:string,3:string,4:string} */
    private function periodBounds(string $mode, Carbon $anchor): array
    {
        if ($mode === 'bulanan') {
            $start = $anchor->copy()->startOfMonth();
            $end = $anchor->copy()->endOfMonth();

            return [$start, $end, $start->translatedFormat('F Y'),
                $start->copy()->subMonth()->format('Y-m-d'),
                $start->copy()->addMonth()->format('Y-m-d')];
        }

        $start = $anchor->copy()->startOfDay();
        $end = $anchor->copy()->endOfDay();

        return [$start, $end, $start->translatedFormat('l, d F Y'),
            $start->copy()->subDay()->format('Y-m-d'),
            $start->copy()->addDay()->format('Y-m-d')];
    }

    private function emptyBuckets(): array
    {
        return [
            'produksi' => 0, 'masuk_lain' => 0,
            'tiktok' => 0, 'shopee' => 0, 'reseller' => 0, 'keluar_lain' => 0,
            'penyesuaian' => 0,
        ];
    }

    /** Masukkan satu gerakan ke kolom yang tepat (keluar disimpan positif). */
    private function bucketize(array &$b, ?string $ref, string $type, int $delta): void
    {
        if ($type === StockMovement::TYPE_ADJUSTMENT || $ref === 'opname' || $type === StockMovement::TYPE_TRANSFER) {
            $b['penyesuaian'] += $delta; // bertanda

            return;
        }
        switch ($ref) {
            case 'production':
            case 'stock_receipt':
                $b['produksi'] += $delta;

                return;
            case 'tiktok_order':
                $b['tiktok'] += -$delta; // keluar → positif; retur/reversal mengurangi

                return;
            case 'purchase_order':
                $b['reseller'] += -$delta;

                return;
            case 'shopee_order':
                $b['shopee'] += -$delta;

                return;
        }
        // Sisanya: masuk/keluar lain berdasar tanda.
        if ($delta >= 0) {
            $b['masuk_lain'] += $delta;
        } else {
            $b['keluar_lain'] += -$delta;
        }
    }
}
