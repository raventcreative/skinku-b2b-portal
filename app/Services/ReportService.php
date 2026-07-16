<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ShopeeOrder;
use App\Models\TiktokOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * All reporting is SQL-aggregate based — never mock data. "Sales" counts
 * completed POs only (revenue actually realised).
 */
class ReportService
{
    private const REVENUE_STATUS = PurchaseOrder::STATUS_COMPLETED;

    /** Scope helper: partners only see their own data. */
    private function scopePo($query, ?User $viewer)
    {
        if ($viewer && $viewer->isPartner()) {
            $query->where('user_id', $viewer->id);
        }

        return $query;
    }

    /** Top-line KPI cards for the dashboard. */
    public function summary(?User $viewer = null): array
    {
        $completed = $this->scopePo(
            PurchaseOrder::query()->where('status', self::REVENUE_STATUS),
            $viewer
        );

        $allPo = $this->scopePo(PurchaseOrder::query(), $viewer);

        return [
            'total_sales' => (float) (clone $completed)->sum('total_amount'),
            'total_po' => (clone $allPo)->count(),
            'pending_po' => (clone $allPo)->where('status', PurchaseOrder::STATUS_PENDING)->count(),
            'completed_po' => (clone $completed)->count(),
            'total_partners' => User::whereIn('role', [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])
                ->where('status', User::STATUS_ACTIVE)->count(),
            'total_products' => Product::where('status', Product::STATUS_ACTIVE)->count(),
            'hq_stock_units' => (int) Product::where('status', Product::STATUS_ACTIVE)->sum('hq_stock'),
            'partner_stock_units' => $viewer && $viewer->isPartner()
                ? (int) Inventory::where('user_id', $viewer->id)->sum('quantity')
                : (int) Inventory::sum('quantity'),
        ];
    }

    /**
     * Penjualan per channel untuk SATU BULAN, dipecah dua:
     *   - confirmed : order sudah selesai (PO completed, TikTok/Shopee delivered)
     *   - pipeline  : order berbayar yang masih berjalan (belum selesai)
     * Estimasi bulan itu = confirmed + pipeline.
     *
     * Basis waktu = TANGGAL ORDER MASUK (bukan tanggal selesai). Ini satu-satunya
     * basis yang masuk akal untuk estimasi: order yang masih berjalan belum punya
     * tanggal selesai. Beda dari KPI "Total Penjualan"/tren yang memakai
     * completed_at — makanya panel ini diberi label periodenya sendiri.
     *
     * UNPAID/draft/cancelled tidak dihitung di mana pun — belum tentu jadi uang.
     * Shopee 0 sampai integrasinya jalan (tabel mungkin belum ada di produksi).
     *
     * @return array<int, array{key:string, label:string, color:string, confirmed:float, pipeline:float}>
     */
    public function channelSales(?Carbon $month = null): array
    {
        $month ??= Carbon::now();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $po = fn (array $statuses) => (float) PurchaseOrder::query()
            ->whereIn('status', $statuses)
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_amount');

        $sum = fn (string $table, string $model, array $statuses) => Schema::hasTable($table)
            ? (float) $model::whereIn('status', $statuses)
                ->whereBetween('order_created_at', [$start, $end])
                ->sum('total_amount')
            : 0.0;

        return [
            [
                'key' => 'reseller', 'label' => 'Reseller / PO', 'color' => '#0f4c3a',
                'confirmed' => round($po([self::REVENUE_STATUS]), 2),
                'pipeline' => round($po(PurchaseOrder::PIPELINE_STATUSES), 2),
            ],
            [
                'key' => 'tiktok', 'label' => 'TikTok', 'color' => '#ef4444',
                'confirmed' => round($sum('tiktok_orders', TiktokOrder::class, TiktokOrder::DELIVERED_STATUSES), 2),
                'pipeline' => round($sum('tiktok_orders', TiktokOrder::class, TiktokOrder::PIPELINE_STATUSES), 2),
            ],
            [
                'key' => 'shopee', 'label' => 'Shopee', 'color' => '#f97316',
                'confirmed' => round($sum('shopee_orders', ShopeeOrder::class, ShopeeOrder::DELIVERED_STATUSES), 2),
                'pipeline' => round($sum('shopee_orders', ShopeeOrder::class, ShopeeOrder::PIPELINE_STATUSES), 2),
            ],
        ];
    }

    /**
     * Gross-profit estimate for completed POs, using each product's current
     * average HPP (products.cogs). Goods revenue = sum of item subtotals (before
     * discount/shipping) so it lines up with COGS of goods sold.
     */
    public function grossProfit(): array
    {
        $rows = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->join('products as p', 'p.id', '=', 'poi.product_id')
            ->where('po.status', self::REVENUE_STATUS)
            ->whereNull('po.deleted_at')
            ->selectRaw('COALESCE(SUM(poi.total_price), 0) as revenue, COALESCE(SUM(poi.qty * p.cogs), 0) as cogs')
            ->first();

        $revenue = (float) ($rows->revenue ?? 0);
        $cogs = (float) ($rows->cogs ?? 0);
        $profit = $revenue - $cogs;

        return [
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'profit' => round($profit, 2),
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
        ];
    }

    /** Sales totals grouped by day/week/month for the trend line chart. */
    public function salesTrend(string $granularity = 'day', int $points = 14, ?User $viewer = null): array
    {
        $driver = DB::connection()->getDriverName();
        $format = $this->dateFormatExpr('completed_at', $granularity, $driver);

        $rows = $this->scopePo(
            PurchaseOrder::query()->where('status', self::REVENUE_STATUS)->whereNotNull('completed_at'),
            $viewer
        )
            ->selectRaw("$format as bucket, SUM(total_amount) as total, COUNT(*) as orders")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->limit($points * 3)
            ->get();

        return $rows->map(fn ($r) => [
            'label' => (string) $r->bucket,
            'total' => (float) $r->total,
            'orders' => (int) $r->orders,
        ])->toArray();
    }

    /** Top products by completed-sales revenue. */
    public function salesByProduct(int $limit = 10, ?User $viewer = null): array
    {
        $q = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.status', self::REVENUE_STATUS);

        if ($viewer && $viewer->isPartner()) {
            $q->where('po.user_id', $viewer->id);
        }

        return $q->selectRaw('poi.product_name, SUM(poi.qty) as qty, SUM(poi.total_price) as revenue')
            ->groupBy('poi.product_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'label' => $r->product_name,
                'qty' => (int) $r->qty,
                'revenue' => (float) $r->revenue,
            ])->toArray();
    }

    /** Sales grouped by partner (distributor/reseller). HQ view only. */
    public function salesByPartner(string $role = User::ROLE_DISTRIBUTOR, int $limit = 10): array
    {
        return PurchaseOrder::query()
            ->where('status', self::REVENUE_STATUS)
            ->where('user_role', $role)
            ->selectRaw('company_name, SUM(total_amount) as revenue, COUNT(*) as orders')
            ->groupBy('company_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'label' => $r->company_name ?: '(Tanpa Nama)',
                'revenue' => (float) $r->revenue,
                'orders' => (int) $r->orders,
            ])->toArray();
    }

    /** Sales grouped by region (fallback to "Lainnya" when null). */
    public function salesByRegion(): array
    {
        return PurchaseOrder::query()
            ->leftJoin('users', 'users.id', '=', 'purchase_orders.user_id')
            ->where('purchase_orders.status', self::REVENUE_STATUS)
            ->selectRaw('COALESCE(NULLIF(users.region, ""), "Lainnya") as region, SUM(purchase_orders.total_amount) as revenue')
            ->groupBy('region')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'label' => $r->region,
                'revenue' => (float) $r->revenue,
            ])->toArray();
    }

    /** PO count grouped by status — pie chart. */
    public function poStatusDistribution(?User $viewer = null): array
    {
        $rows = $this->scopePo(PurchaseOrder::query(), $viewer)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $out = [];
        foreach (PurchaseOrder::STATUSES as $status) {
            $out[] = ['label' => $status, 'total' => (int) ($rows[$status] ?? 0)];
        }

        return $out;
    }

    /** HQ vs partner stock per product — inventory bar chart. */
    public function inventoryMonitoring(int $limit = 12): array
    {
        $partner = Inventory::query()
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->orderByDesc('hq_stock')
            ->limit($limit)
            ->get()
            ->map(fn (Product $p) => [
                'label' => $p->name,
                'hq_stock' => (int) $p->hq_stock,
                'partner_stock' => (int) ($partner[$p->id] ?? 0),
            ])->toArray();
    }

    /** Per-driver date bucketing expression. */
    private function dateFormatExpr(string $column, string $granularity, string $driver): string
    {
        if ($driver === 'pgsql') {
            return match ($granularity) {
                'month' => "to_char($column, 'YYYY-MM')",
                'week' => "to_char($column, 'IYYY-IW')",
                default => "to_char($column, 'YYYY-MM-DD')",
            };
        }

        if ($driver === 'sqlite') {
            return match ($granularity) {
                'month' => "strftime('%Y-%m', $column)",
                'week' => "strftime('%Y-%W', $column)",
                default => "strftime('%Y-%m-%d', $column)",
            };
        }

        // mysql / mariadb
        return match ($granularity) {
            'month' => "DATE_FORMAT($column, '%Y-%m')",
            'week' => "DATE_FORMAT($column, '%x-%v')",
            default => "DATE_FORMAT($column, '%Y-%m-%d')",
        };
    }
}
