<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\ShopeeOrder;
use App\Models\TiktokOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Produk terlaris per bulan, dipisah per channel (Reseller/PO, TikTok, Shopee) + gabungan.
 *
 * Ukuran = UNIT produk SKINKU terjual. SKU marketplace di-resolve lewat resep
 * (peta SKU / bundle) → "Scrub 3 Pcs" dihitung 3 unit Scrub. SKU yang belum
 * dipetakan tetap tampil dengan nama marketplace-nya (ditandai), biar tak hilang.
 *
 * Order dihitung = berbayar (selesai + masih berjalan), sama dengan "Estimasi"
 * di panel channel. Belum bayar & batal tidak dihitung.
 */
class ProdukTerlarisService
{
    public function __construct(
        private TikTokOrderService $tiktok,
        private ShopeeOrderService $shopee,
    ) {}

    /**
     * Bulan berjalan dibandingkan dengan PERIODE YANG SAMA bulan lalu (mis. 1–2 Okt vs
     * 1–2 Sep), bukan sebulan penuh — kalau tidak, awal bulan selalu tampak "turun".
     *
     * @return array{label:string, prev_label:string, channels: array<string, array{total:int, rows: array<int, array{label:string, qty:int, prev:int, unmapped:bool}>}>}
     */
    public function report(Carbon $month, int $limit = 10, ?Carbon $from = null, ?Carbon $to = null): array
    {
        if ($from && $to) {
            // Rentang bebas → dibanding rentang sepanjang sama tepat sebelumnya.
            $start = $from->copy()->startOfDay();
            $end = $to->copy()->endOfDay();
            $days = (int) $start->diffInDays($to->copy()->startOfDay()) + 1;
            $prevEnd = $start->copy()->subDay()->endOfDay();
            $prevStart = $start->copy()->subDays($days);
            $label = $this->rangeLabel($start, $end);
            $prevLabel = $this->rangeLabel($prevStart, $prevEnd);
        } else {
            $start = $month->copy()->startOfMonth()->startOfDay();
            $end = $month->copy()->endOfMonth()->endOfDay();
            $prevStart = $start->copy()->subMonthNoOverflow();
            $prevEnd = $prevStart->copy()->endOfMonth()->endOfDay();

            $berjalan = now()->between($start, $end);
            if ($berjalan) {
                $end = now()->copy()->endOfDay();
                $prevEnd = $prevStart->copy()->addDays($end->day - 1)->endOfDay()->min($prevEnd);
            }
            $label = $start->translatedFormat('F Y');
            $prevLabel = $berjalan
                ? $prevStart->day.'–'.$prevEnd->day.' '.$prevStart->translatedFormat('M Y')
                : $prevStart->translatedFormat('M Y');
        }

        $now = $this->tally($start, $end);
        $prev = $this->tally($prevStart, $prevEnd);

        $out = [];
        foreach (['semua', 'reseller', 'tiktok', 'shopee'] as $ch) {
            $rows = collect($now[$ch])
                ->map(fn ($r, $key) => $r + ['prev' => $prev[$ch][$key]['qty'] ?? 0])
                ->sortByDesc('qty')->values();
            $out[$ch] = ['total' => (int) $rows->sum('qty'), 'rows' => $rows->take($limit)->all()];
        }

        return ['label' => $label, 'prev_label' => $prevLabel, 'channels' => $out];
    }

    private function rangeLabel(Carbon $a, Carbon $b): string
    {
        return $a->isSameDay($b) ? $a->translatedFormat('d M Y') : $a->translatedFormat('d M').' – '.$b->translatedFormat('d M Y');
    }

    /** @return array<string, array<string, array{label:string, qty:int, unmapped:bool}>> */
    private function tally(Carbon $start, Carbon $end): array
    {
        $acc = ['semua' => [], 'reseller' => [], 'tiktok' => [], 'shopee' => []];
        $add = function (string $ch, string $key, string $label, int $qty, bool $unmapped = false) use (&$acc) {
            foreach ([$ch, 'semua'] as $c) {
                $acc[$c][$key] ??= ['label' => $label, 'qty' => 0, 'unmapped' => $unmapped];
                $acc[$c][$key]['qty'] += $qty;
            }
        };

        // Reseller / PO langsung HQ (bukan PO downline), basis tanggal = order_date ?? created_at.
        DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereNull('po.seller_id')
            ->whereIn('po.status', array_merge([PurchaseOrder::STATUS_COMPLETED], PurchaseOrder::PIPELINE_STATUSES))
            ->whereRaw('COALESCE(po.order_date, DATE(po.created_at)) BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('poi.product_id, MAX(poi.product_name) as name, SUM(poi.qty) as qty')
            ->groupBy('poi.product_id')
            ->get()
            ->each(fn ($r) => $add('reseller', 'p'.$r->product_id, $r->name, (int) $r->qty));

        $marketplace = [
            'tiktok' => ['tiktok_orders', TiktokOrder::class, $this->tiktok],
            'shopee' => ['shopee_orders', ShopeeOrder::class, $this->shopee],
        ];
        foreach ($marketplace as $ch => [$table, $model, $svc]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $cache = [];
            $model::whereIn('status', array_merge($model::DELIVERED_STATUSES, $model::PIPELINE_STATUSES))
                ->whereBetween('order_created_at', [$start, $end])
                ->get(['line_items'])
                ->each(function ($o) use ($ch, $svc, &$cache, $add) {
                    foreach ($o->line_items ?? [] as $item) {
                        $sku = $item['sku'] ?? null;
                        $qty = (int) ($item['qty'] ?? 0);
                        $comps = $sku ? ($cache[$sku] ??= $svc->resolve($sku)) : [];
                        if (! $comps) {
                            $name = trim((string) ($item['name'] ?? '')) ?: (string) $sku;
                            $add($ch, 'x'.$ch.':'.$sku, $name, $qty, true);

                            continue;
                        }
                        foreach ($comps as $c) {
                            $add($ch, 'p'.$c['product']->id, $c['product']->name, $c['qty'] * $qty);
                        }
                    }
                });
        }

        return $acc;
    }
}
