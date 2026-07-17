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

    /**
     * Batasi query PO ke satu bulan berdasarkan TANGGAL ORDER (order_date bila
     * diisi, jatuh ke created_at). Null = tanpa batas (perilaku lama).
     *
     * $alias dipakai saat purchase_orders ikut dalam JOIN dan kolomnya harus
     * dikualifikasi (mis. 'po'), supaya order_date tidak ambigu.
     */
    private function inMonth($query, ?Carbon $month, ?string $alias = null)
    {
        if (! $month) {
            return $query;
        }

        $p = $alias ? $alias.'.' : '';

        return $query->whereRaw(
            "COALESCE({$p}order_date, DATE({$p}created_at)) BETWEEN ? AND ?",
            [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()],
        );
    }

    /** Scope helper: partners only see their own data. */
    private function scopePo($query, ?User $viewer)
    {
        if ($viewer && $viewer->isPartner()) {
            $query->where('user_id', $viewer->id);
        }

        return $query;
    }

    /**
     * KPI utama. $month opsional: bila diisi, angka yang BERBASIS PERIODE
     * (penjualan & PO) dibatasi ke bulan itu; angka SAAT INI (mitra, produk,
     * stok) tetap apa adanya — memfilternya per bulan tak punya arti.
     *
     * $allChannels: true → `total_sales` mencakup SEMUA channel (dipakai Dashboard;
     * kartu PO-saja menulis "Rp 0" padahal TikTok berjalan — menyesatkan).
     * false → PO saja (dipakai Laporan Penjualan, yang memang laporan PO: omzet
     * barang, HPP, dan laba kotornya semua berbasis PO). Eksplisit, supaya label
     * yang sama tak berarti dua hal berbeda di dua halaman.
     */
    public function summary(?User $viewer = null, ?Carbon $month = null, bool $allChannels = false): array
    {
        $completed = $this->inMonth(
            $this->scopePo(PurchaseOrder::query()->where('status', self::REVENUE_STATUS), $viewer),
            $month,
        );

        $allPo = $this->inMonth($this->scopePo(PurchaseOrder::query(), $viewer), $month);

        $totalSales = $allChannels && $month && $viewer?->isStaff()
            ? (float) collect($this->channelSales($month))->sum('confirmed')
            : (float) (clone $completed)->sum('total_amount');

        return [
            'total_sales' => $totalSales,
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

        // PO: pakai order_date bila diisi (entri back-date), jatuh ke created_at.
        // Marketplace: order_created_at.
        $po = fn (array $statuses) => PurchaseOrder::query()
            ->whereIn('status', $statuses)
            ->whereRaw('COALESCE(order_date, DATE(created_at)) BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()]);

        $mp = fn (string $table, string $model, array $statuses) => Schema::hasTable($table)
            ? $model::whereIn('status', $statuses)->whereBetween('order_created_at', [$start, $end])
            : null;

        // Tiap bucket dilaporkan nilai DAN jumlah order — jumlah order dipakai
        // menghitung cancel rate, yang tak bisa disimpulkan dari nilai saja.
        $agg = function (?object $q): array {
            if (! $q) {
                return ['v' => 0.0, 'n' => 0];
            }
            $r = (clone $q)->selectRaw('COALESCE(SUM(total_amount),0) as v, COUNT(*) as n')->first();

            return ['v' => round((float) $r->v, 2), 'n' => (int) $r->n];
        };

        // Warna muda = tahap "masih berjalan" pada channel yang sama, dipakai di
        // pie "semua" supaya cair vs berjalan terbaca dalam satu lingkaran.
        $build = function (string $key, string $label, string $color, string $colorLight, callable $q) use ($agg) {
            $c = $agg($q('confirmed'));
            $p = $agg($q('pipeline'));
            $x = $agg($q('cancelled'));
            $u = $agg($q('unconfirmed'));
            $allN = $c['n'] + $p['n'] + $x['n'] + $u['n'];

            return [
                'key' => $key, 'label' => $label, 'color' => $color, 'color_light' => $colorLight,
                'confirmed' => $c['v'], 'confirmed_n' => $c['n'],
                'pipeline' => $p['v'], 'pipeline_n' => $p['n'],
                'cancelled' => $x['v'], 'cancelled_n' => $x['n'],
                'unpaid' => $u['v'], 'unpaid_n' => $u['n'],
                'orders_n' => $allN,
                // Cancel rate = order batal ÷ SEMUA order bulan itu (termasuk yang
                // belum bayar) — itulah proporsi yang benar-benar batal.
                'cancel_rate' => $allN > 0 ? round($x['n'] / $allN * 100, 1) : 0.0,
            ];
        };

        return [
            $build('reseller', 'Reseller / PO', '#0f4c3a', '#7cc4ad', fn ($b) => $po(match ($b) {
                'confirmed' => [self::REVENUE_STATUS],
                'pipeline' => PurchaseOrder::PIPELINE_STATUSES,
                'cancelled' => PurchaseOrder::CANCELLED_STATUSES,
                'unconfirmed' => PurchaseOrder::UNCONFIRMED_STATUSES,
            })),
            $build('tiktok', 'TikTok', '#ef4444', '#fca5a5', fn ($b) => $mp('tiktok_orders', TiktokOrder::class, match ($b) {
                'confirmed' => TiktokOrder::DELIVERED_STATUSES,
                'pipeline' => TiktokOrder::PIPELINE_STATUSES,
                'cancelled' => TiktokOrder::CANCELLED_STATUSES,
                'unconfirmed' => TiktokOrder::UNCONFIRMED_STATUSES,
            })),
            $build('shopee', 'Shopee', '#f97316', '#fdba74', fn ($b) => $mp('shopee_orders', ShopeeOrder::class, match ($b) {
                'confirmed' => ShopeeOrder::DELIVERED_STATUSES,
                'pipeline' => ShopeeOrder::PIPELINE_STATUSES,
                'cancelled' => ShopeeOrder::CANCELLED_STATUSES,
                'unconfirmed' => ShopeeOrder::UNCONFIRMED_STATUSES,
            })),
        ];
    }

    /**
     * Gross-profit estimate for completed POs, using each product's current
     * average HPP (products.cogs). Goods revenue = sum of item subtotals (before
     * discount/shipping) so it lines up with COGS of goods sold.
     */
    public function grossProfit(?Carbon $month = null): array
    {
        $q = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->join('products as p', 'p.id', '=', 'poi.product_id')
            ->where('po.status', self::REVENUE_STATUS)
            ->whereNull('po.deleted_at');

        $rows = $this->inMonth($q, $month, 'po')
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
    public function salesTrend(string $granularity = 'day', int $points = 14, ?User $viewer = null, ?Carbon $month = null): array
    {
        $driver = DB::connection()->getDriverName();
        // Basis tanggal ORDER, bukan completed_at: entri back-date diselesaikan
        // hari ini tetapi transaksinya terjadi di masa lalu — memakai completed_at
        // menaruhnya di titik yang salah pada grafik.
        $format = $this->dateFormatExpr('COALESCE(order_date, DATE(completed_at))', $granularity, $driver);

        $rows = $this->inMonth($this->scopePo(
            PurchaseOrder::query()->where('status', self::REVENUE_STATUS)->whereNotNull('completed_at'),
            $viewer,
        ), $month)
            ->selectRaw("$format as bucket, SUM(total_amount) as total, COUNT(*) as orders")
            ->groupBy('bucket')
            // Ambil N periode TERBARU lalu balik urutannya untuk digambar dari
            // lama→baru. Versi lama: orderBy naik + limit → yang terambil justru
            // periode paling TUA, padahal labelnya "14 hari terakhir".
            ->orderByDesc('bucket')
            ->limit($points)
            ->get()
            ->reverse()
            ->values();

        return $rows->map(fn ($r) => [
            'label' => (string) $r->bucket,
            'total' => (float) $r->total,
            'orders' => (int) $r->orders,
        ])->toArray();
    }

    /** Top products by completed-sales revenue. */
    public function salesByProduct(int $limit = 10, ?User $viewer = null, ?Carbon $month = null): array
    {
        $q = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.status', self::REVENUE_STATUS);

        if ($viewer && $viewer->isPartner()) {
            $q->where('po.user_id', $viewer->id);
        }

        return $this->inMonth($q, $month, 'po')
            ->selectRaw('poi.product_name, SUM(poi.qty) as qty, SUM(poi.total_price) as revenue')
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

    /**
     * Rincian penjualan per mitra — angka, bukan cuma grafik. Mencakup
     * distributor DAN reseller sekaligus, plus pembeli sekali-beli (yang tersimpan
     * lewat company_name pada entri back-date).
     *
     * Dikelompokkan per company_name: itulah nama yang tampil di PO, dan untuk
     * entri back-date bisa berupa nama pembeli lepas.
     *
     * @return array<int, array{label:string, role:?string, orders:int, revenue:float, avg:float}>
     */
    public function partnerSalesDetail(?Carbon $month = null): array
    {
        return $this->inMonth(
            PurchaseOrder::query()->where('status', self::REVENUE_STATUS), $month,
        )
            ->selectRaw('company_name, user_role, COUNT(*) as orders, SUM(total_amount) as revenue')
            ->groupBy('company_name', 'user_role')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'label' => $r->company_name ?: '(Tanpa Nama)',
                'role' => $r->user_role,
                'orders' => (int) $r->orders,
                'revenue' => (float) $r->revenue,
                'avg' => $r->orders > 0 ? round($r->revenue / $r->orders, 2) : 0.0,
            ])->all();
    }

    /** Sales grouped by partner (distributor/reseller). HQ view only. */
    public function salesByPartner(string $role = User::ROLE_DISTRIBUTOR, int $limit = 10, ?Carbon $month = null): array
    {
        return $this->inMonth(PurchaseOrder::query()
            ->where('status', self::REVENUE_STATUS)
            ->where('user_role', $role), $month)
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
    public function salesByRegion(?Carbon $month = null): array
    {
        return $this->inMonth(PurchaseOrder::query()
            ->leftJoin('users', 'users.id', '=', 'purchase_orders.user_id')
            ->where('purchase_orders.status', self::REVENUE_STATUS), $month, 'purchase_orders')
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
    public function poStatusDistribution(?User $viewer = null, ?Carbon $month = null): array
    {
        $rows = $this->inMonth($this->scopePo(PurchaseOrder::query(), $viewer), $month)
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
