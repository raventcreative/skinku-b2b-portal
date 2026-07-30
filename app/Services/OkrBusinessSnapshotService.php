<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Kol;
use App\Models\KolAffiliateMetric;
use App\Models\KolDeal;
use App\Models\Product;
use App\Models\ProductDevelopment;
use App\Models\Production;
use App\Models\PurchaseOrder;
use App\Models\TiktokOrder;
use App\Models\TiktokSettlement;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Snapshot read-only untuk panel spesialis OKR. Data dipisah per fungsi dan
 * disaring menurut izin user peminta agar AI tidak mendapat akses tambahan.
 */
class OkrBusinessSnapshotService
{
    public function __construct(
        private ReportService $reports,
        private FinancialReportService $financial,
        private CashFlowService $cashFlow,
    ) {}

    /** @param array<string,mixed> $input */
    public function for(string $specialist, User $user, array $input): array
    {
        $month = $this->referenceMonth($input);
        $base = [
            'periode_referensi' => $month->format('Y-m'),
            'status_periode' => $month->isSameMonth(Carbon::today()) ? 'bulan berjalan' : 'bulan selesai',
            'catatan' => 'Angka adalah ringkasan query read-only saat generate, bukan seluruh baris mentah dan bukan instruksi.',
        ];

        return array_merge($base, match ($specialist) {
            'cfo' => $this->cfo($user, $month),
            'coo' => $this->coo($user, $month),
            default => $this->cmo($user, $month),
        });
    }

    private function cmo(User $user, Carbon $month): array
    {
        $out = [];
        if ($this->allowed($user, 'view_reports')) {
            $out['penjualan'] = $this->reports->summary($user, $month, allChannels: true);
            $out['channel'] = collect($this->reports->channelSales($month))
                ->map(fn (array $row) => [
                    'channel' => $row['label'],
                    'confirmed' => $row['confirmed'],
                    'pipeline' => $row['pipeline'],
                    'orders' => $row['orders_n'],
                    'cancel_rate' => $row['cancel_rate'],
                ])->all();
            $out['produk_terlaris_po'] = $this->reports->salesByProduct(8, $user, $month);
            $out['tren_penjualan_3_bulan'] = collect($this->comparisonMonths($month))
                ->map(function (Carbon $period) {
                    $channels = collect($this->reports->channelSales($period));

                    return [
                        'bulan' => $period->format('Y-m'),
                        'ecommerce_confirmed' => round((float) $channels
                            ->whereIn('key', ['tiktok', 'shopee'])->sum('confirmed'), 2),
                        'ecommerce_pipeline' => round((float) $channels
                            ->whereIn('key', ['tiktok', 'shopee'])->sum('pipeline'), 2),
                        'distributor_po_confirmed' => $this->distributorRevenue($period),
                        'semua_channel_confirmed' => round((float) $channels->sum('confirmed'), 2),
                        'jumlah_order' => (int) $channels->sum('orders_n'),
                    ];
                })->values()->all();
            $out['distributor'] = $this->distributorSnapshot($month);
            $out['portofolio_produk'] = [
                'master_aktif' => Product::where('status', Product::STATUS_ACTIVE)->count(),
                'kategori' => Product::query()
                    ->where('status', Product::STATUS_ACTIVE)
                    ->selectRaw('category, COUNT(*) as total')
                    ->groupBy('category')
                    ->pluck('total', 'category')
                    ->all(),
                'catatan_cakupan' => 'Produk master aktif dipisahkan dari calon produk pada pipeline pengembangan.',
            ];
            $out['pipeline_produk_baru'] = $this->productDevelopmentSnapshot();
        } else {
            $out['penjualan'] = ['akses' => 'ditutup karena user tidak punya view_reports'];
        }

        if ($this->allowed($user, 'kol.view')) {
            $out['kol'] = [
                'total' => Kol::count(),
                'status' => Kol::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
                'deal_status' => KolDeal::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
                'slot_aktif' => (int) KolDeal::where('status', 'berjalan')->sum('jumlah_slot'),
                'affiliate' => $this->affiliateSnapshot($month),
            ];
        }

        if ($this->allowed($user, 'manage_tiktok')) {
            $out['tiktok'] = [
                'status_order' => TiktokOrder::query()
                    ->whereBetween('order_created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->all(),
            ];
        }

        return $out;
    }

    private function cfo(User $user, Carbon $month): array
    {
        $period = $month->format('Y-m');
        $out = [];

        if ($this->allowed($user, 'view_accounting')) {
            $income = $this->financial->incomeStatement($period);
            $balance = $this->financial->balanceSheet($period);
            $cash = $this->cashFlow->directCashFlow($period);
            $out['laba_rugi'] = collect($income)->only([
                'penjualan_bersih', 'hpp', 'laba_kotor', 'beban_operasional',
                'operating_income', 'net_income',
            ])->all();
            $out['neraca'] = collect($balance)->only([
                'total_aktiva', 'total_liabilitas', 'total_ekuitas', 'laba_berjalan', 'balanced',
            ])->all();
            $out['arus_kas'] = [
                'operasi' => $cash['totals']['operating'],
                'investasi' => $cash['totals']['investing'],
                'pendanaan' => $cash['totals']['financing'],
                'bersih' => $cash['net'],
                'kas_akhir' => $cash['kas_akhir'],
            ];
            $out['tren_keuangan_3_bulan'] = collect($this->comparisonMonths($month))
                ->map(function (Carbon $period) {
                    $periodLabel = $period->format('Y-m');
                    $income = $this->financial->incomeStatement($periodLabel);
                    $cash = $this->cashFlow->directCashFlow($periodLabel);

                    return [
                        'bulan' => $periodLabel,
                        'penjualan_bersih' => $income['penjualan_bersih'],
                        'hpp' => $income['hpp'],
                        'laba_kotor' => $income['laba_kotor'],
                        'beban_operasional' => $income['beban_operasional'],
                        'laba_bersih' => $income['net_income'],
                        'arus_kas_bersih' => $cash['net'],
                        'kas_akhir' => $cash['kas_akhir'],
                    ];
                })->values()->all();
        } else {
            $out['akuntansi'] = ['akses' => 'ditutup karena user tidak punya view_accounting'];
        }

        if ($this->allowed($user, 'view_reports')) {
            $out['estimasi_margin_po'] = $this->reports->grossProfit($month);
        }

        if ($this->allowed($user, 'update_po_status')) {
            $tempo = PurchaseOrder::query()
                ->where('is_tempo', true)
                ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DELETED])
                ->withSum('payments', 'amount')
                ->get();
            $out['piutang_tempo'] = [
                'jumlah_po' => $tempo->count(),
                'sisa_tagihan' => round((float) $tempo->sum(
                    fn (PurchaseOrder $po) => max(0, (float) $po->total_amount - (float) ($po->payments_sum_amount ?? 0))
                ), 2),
                'jatuh_tempo' => $tempo->filter(fn (PurchaseOrder $po) => $po->tempo_due_date?->isPast())->count(),
            ];
        }

        if ($this->allowed($user, 'manage_tiktok')) {
            $settlements = TiktokSettlement::query()
                ->whereBetween('statement_time', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
            $out['settlement_tiktok'] = [
                'revenue' => round((float) (clone $settlements)->sum('revenue_amount'), 2),
                'fee' => round((float) (clone $settlements)->sum('fee_amount'), 2),
                'net_cair' => round((float) (clone $settlements)->sum('settlement_amount'), 2),
                'belum_posting' => (clone $settlements)->where('posting_status', TiktokSettlement::POST_PENDING)->count(),
            ];
        }

        return $out;
    }

    private function coo(User $user, Carbon $month): array
    {
        $out = [];

        if ($this->allowed($user, 'manage_hq_stock')) {
            $out['stok'] = [
                'total_hq' => (int) Product::where('status', Product::STATUS_ACTIVE)->sum('hq_stock'),
                'total_partner' => (int) Inventory::sum('quantity'),
                'stok_terendah' => Product::query()
                    ->where('status', Product::STATUS_ACTIVE)
                    ->orderBy('hq_stock')
                    ->limit(10)
                    ->get(['name', 'sku', 'category', 'hq_stock'])
                    ->toArray(),
            ];
            $out['kesiapan_produk'] = Product::query()
                ->where('status', Product::STATUS_ACTIVE)
                ->orderBy('category')
                ->orderBy('name')
                ->limit(50)
                ->get([
                    'name', 'sku', 'category', 'price_distributor',
                    'price_retail', 'cogs', 'hq_stock',
                ])
                ->map(fn (Product $product) => [
                    'nama' => $product->name,
                    'sku' => $product->sku,
                    'kategori' => $product->category,
                    'harga_distributor' => (float) $product->price_distributor,
                    'harga_retail' => (float) $product->price_retail,
                    'hpp' => (float) $product->cogs,
                    'stok_hq' => (int) $product->hq_stock,
                ])->all();
            $out['pipeline_produk_baru'] = $this->productDevelopmentSnapshot();
        } else {
            $out['stok'] = ['akses' => 'ditutup karena user tidak punya manage_hq_stock'];
        }

        if ($this->allowed($user, 'manage_production')) {
            $production = Production::query()
                ->whereBetween('produced_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
            $out['produksi'] = [
                'batch' => (clone $production)->count(),
                'output_unit' => (int) (clone $production)->sum('output_qty'),
                'total_biaya' => round((float) (clone $production)->sum('total_cost'), 2),
            ];
            $out['tren_produksi_3_bulan'] = collect($this->comparisonMonths($month))
                ->map(function (Carbon $period) {
                    $production = Production::query()->whereBetween(
                        'produced_at',
                        [$period->copy()->startOfMonth(), $period->copy()->endOfMonth()],
                    );

                    return [
                        'bulan' => $period->format('Y-m'),
                        'batch' => (clone $production)->count(),
                        'output_unit' => (int) (clone $production)->sum('output_qty'),
                        'total_biaya' => round((float) (clone $production)->sum('total_cost'), 2),
                    ];
                })->values()->all();
        }

        if ($this->allowed($user, 'update_po_status') || $this->allowed($user, 'view_reports')) {
            $out['purchase_order'] = PurchaseOrder::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();
        }

        if ($this->allowed($user, 'manage_tiktok')) {
            $out['operasional_tiktok'] = [
                'status_stok' => TiktokOrder::query()
                    ->selectRaw('stock_status, COUNT(*) as total')
                    ->groupBy('stock_status')
                    ->pluck('total', 'stock_status')
                    ->all(),
                'order_pipeline' => TiktokOrder::whereIn('status', TiktokOrder::PIPELINE_STATUSES)->count(),
            ];
        }

        return $out;
    }

    /**
     * Daftar bukti scalar yang boleh dirujuk model. Nilainya nanti tetap diambil
     * ulang dari katalog server, sehingga model tidak bisa memalsukan angka.
     *
     * @param  array<string,array<string,mixed>>  $snapshots
     * @return array<string,array{source_path:string,label:string,value:mixed,period:?string,specialist:string}>
     */
    public function evidenceCatalog(array $snapshots): array
    {
        $catalog = [];
        foreach ($snapshots as $specialist => $snapshot) {
            $period = is_array($snapshot) ? ($snapshot['periode_referensi'] ?? null) : null;
            $this->flattenEvidence(
                value: $snapshot,
                path: $specialist,
                specialist: $specialist,
                period: is_string($period) ? $period : null,
                catalog: $catalog,
            );
        }

        return $catalog;
    }

    /**
     * @param  array<string,array<string,mixed>>  $snapshots
     * @return array<int,array{specialist:string,sources:array<int,string>,closed:array<int,string>}>
     */
    public function coverage(array $snapshots): array
    {
        return collect($snapshots)->map(function (array $snapshot, string $specialist) {
            $sources = [];
            $closed = [];
            foreach ($snapshot as $key => $value) {
                if (in_array($key, ['periode_referensi', 'status_periode', 'catatan'], true)) {
                    continue;
                }
                if (is_array($value) && isset($value['akses'])) {
                    $closed[] = $key;
                } else {
                    $sources[] = $key;
                }
            }

            return [
                'specialist' => strtoupper($specialist),
                'sources' => array_values($sources),
                'closed' => array_values($closed),
            ];
        })->values()->all();
    }

    /** @return array<int,Carbon> */
    private function comparisonMonths(Carbon $month): array
    {
        return [
            $month->copy()->subMonths(2)->startOfMonth(),
            $month->copy()->subMonth()->startOfMonth(),
            $month->copy()->startOfMonth(),
        ];
    }

    /** @return array<string,mixed> */
    private function distributorSnapshot(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();
        $base = PurchaseOrder::query()
            ->where('user_role', User::ROLE_DISTRIBUTOR)
            ->whereRaw('COALESCE(order_date, DATE(created_at)) BETWEEN ? AND ?', [$start, $end]);
        $committedStatuses = array_merge([PurchaseOrder::STATUS_COMPLETED], PurchaseOrder::PIPELINE_STATUSES);
        $revenueByDistributor = (clone $base)
            ->where('status', PurchaseOrder::STATUS_COMPLETED)
            ->selectRaw('user_id, company_name, SUM(total_amount) as omzet, COUNT(*) as jumlah_po')
            ->groupBy('user_id', 'company_name')
            ->orderByDesc('omzet')
            ->get();

        return [
            'terdaftar' => User::where('role', User::ROLE_DISTRIBUTOR)->count(),
            'tahap_lifecycle_tersimpan' => User::query()
                ->where('role', User::ROLE_DISTRIBUTOR)
                ->selectRaw('COALESCE(distributor_stage, ?) as tahap, COUNT(*) as total', [User::DISTRIBUTOR_STAGE_REGISTERED])
                ->groupBy('tahap')
                ->pluck('total', 'tahap')
                ->all(),
            'sudah_masuk_onboarding' => User::query()
                ->where('role', User::ROLE_DISTRIBUTOR)
                ->whereIn('distributor_stage', [
                    User::DISTRIBUTOR_STAGE_ONBOARDING,
                    User::DISTRIBUTOR_STAGE_TRANSACTION_ACTIVE,
                    User::DISTRIBUTOR_STAGE_TARGET_100M,
                ])->count(),
            'akun_aktif' => User::where('role', User::ROLE_DISTRIBUTOR)
                ->where('status', User::STATUS_ACTIVE)->count(),
            'aktif_bertransaksi' => (clone $base)->whereIn('status', $committedStatuses)
                ->whereNotNull('user_id')->distinct()->count('user_id'),
            'mencapai_100_juta' => $revenueByDistributor->where('omzet', '>=', 100_000_000)->count(),
            'omzet_selesai' => round((float) $revenueByDistributor->sum('omzet'), 2),
            'top_distributor' => $revenueByDistributor->take(10)->map(fn ($row) => [
                'nama' => $row->company_name ?: 'Tanpa nama',
                'jumlah_po' => (int) $row->jumlah_po,
                'omzet' => (float) $row->omzet,
            ])->values()->all(),
            'definisi' => [
                'onboarding' => 'Tahap lifecycle eksplisit pada profil distributor; akun aktif tidak lagi dipakai sebagai proxy onboarding.',
                'aktif_bertransaksi' => 'Distributor dengan minimal satu PO selesai atau masih pipeline pada bulan referensi.',
                'mencapai_100_juta' => 'Omzet PO berstatus selesai minimal Rp100 juta pada bulan referensi.',
            ],
        ];
    }

    private function distributorRevenue(Carbon $month): float
    {
        return round((float) PurchaseOrder::query()
            ->where('user_role', User::ROLE_DISTRIBUTOR)
            ->where('status', PurchaseOrder::STATUS_COMPLETED)
            ->whereRaw(
                'COALESCE(order_date, DATE(created_at)) BETWEEN ? AND ?',
                [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()],
            )
            ->sum('total_amount'), 2);
    }

    /** @return array<string,mixed> */
    private function affiliateSnapshot(Carbon $month): array
    {
        $metrics = KolAffiliateMetric::query()
            ->whereDate('period_month', $month->copy()->startOfMonth()->toDateString())
            ->get();
        $withOrder = $metrics->where('order_count', '>', 0);
        $contentCount = (int) $metrics->sum('content_count');

        return [
            'periode' => $month->format('Y-m'),
            'tercatat' => $metrics->count(),
            'tahap' => $metrics->countBy('stage')->all(),
            'onboarding_atau_lanjut' => $metrics->whereIn('stage', [
                KolAffiliateMetric::STAGE_ONBOARDING,
                KolAffiliateMetric::STAGE_CONTENT_ACTIVE,
                KolAffiliateMetric::STAGE_ORDER_ACTIVE,
                KolAffiliateMetric::STAGE_RETAINED,
            ])->count(),
            'aktif_konten' => $metrics->where('content_count', '>', 0)->count(),
            'aktif_live' => $metrics->where('live_count', '>', 0)->count(),
            'menghasilkan_order' => $withOrder->count(),
            'jumlah_konten' => $contentCount,
            'jumlah_live' => (int) $metrics->sum('live_count'),
            'jumlah_order' => (int) $metrics->sum('order_count'),
            'gmv' => round((float) $metrics->sum('gmv'), 2),
            'conversion_rata_rata_persen' => $metrics->whereNotNull('conversion_rate')->isNotEmpty()
                ? round((float) $metrics->whereNotNull('conversion_rate')->avg('conversion_rate'), 4)
                : null,
            'retention_rata_rata_persen' => $metrics->whereNotNull('retention_rate')->isNotEmpty()
                ? round((float) $metrics->whereNotNull('retention_rate')->avg('retention_rate'), 4)
                : null,
            'gmv_per_affiliate_berorder' => $withOrder->isNotEmpty()
                ? round((float) $withOrder->sum('gmv') / $withOrder->count(), 2)
                : null,
            'gmv_per_konten' => $contentCount > 0
                ? round((float) $metrics->sum('gmv') / $contentCount, 2)
                : null,
            'catatan_cakupan' => $metrics->isEmpty()
                ? 'Belum ada metrik affiliate pada periode ini; target wajib ditandai perlu validasi.'
                : 'Metrik berasal dari input bulanan per affiliate/KOL dan dapat ditelusuri ke profilnya.',
        ];
    }

    /** @return array<string,mixed> */
    private function productDevelopmentSnapshot(): array
    {
        $projects = ProductDevelopment::query()->get(['name', 'category', 'stage', 'target_launch_date', 'product_id']);
        $outsideLegacy = $projects->reject(function (ProductDevelopment $project) {
            $text = Str::lower($project->name.' '.$project->category);

            return str_contains($text, 'perfume') || str_contains($text, 'acne');
        });

        return [
            'total_item' => $projects->count(),
            'item_di_luar_perfume_acne' => $outsideLegacy->count(),
            'tahap' => $projects->countBy('stage')->all(),
            'sudah_launch' => $projects->whereIn('stage', ['launch', 'evaluation'])->count(),
            'sudah_tertaut_master_produk' => $projects->whereNotNull('product_id')->count(),
            'target_launch_terisi' => $projects->whereNotNull('target_launch_date')->count(),
            'item' => $projects->take(30)->map(fn (ProductDevelopment $project) => [
                'nama' => $project->name,
                'kategori' => $project->category,
                'tahap' => $project->stage,
                'target_launch' => $project->target_launch_date?->toDateString(),
                'sudah_menjadi_master_produk' => $project->product_id !== null,
            ])->values()->all(),
            'definisi_tahap' => ProductDevelopment::STAGES,
        ];
    }

    /**
     * @param  array<string,array{source_path:string,label:string,value:mixed,period:?string,specialist:string}>  $catalog
     */
    private function flattenEvidence(
        mixed $value,
        string $path,
        string $specialist,
        ?string $period,
        array &$catalog,
    ): void {
        if (is_array($value)) {
            if (isset($value['bulan'])
                && is_string($value['bulan'])
                && preg_match('/^\d{4}-\d{2}$/', $value['bulan'])) {
                $period = $value['bulan'];
            }
            foreach ($value as $key => $child) {
                $this->flattenEvidence($child, $path.'.'.$key, $specialist, $period, $catalog);
            }

            return;
        }
        if (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            return;
        }

        $label = Str::of($path)
            ->replaceMatches('/\.\d+\./', ' · ')
            ->replace(['.', '_'], [' · ', ' '])
            ->headline()
            ->toString();
        $catalog[$path] = [
            'source_path' => $path,
            'label' => $label,
            'value' => $value,
            'period' => $period,
            'specialist' => strtoupper($specialist),
        ];
    }

    /** @param array<string,mixed> $input */
    private function referenceMonth(array $input): Carbon
    {
        $today = Carbon::today();
        $start = Carbon::parse($input['start_date'])->startOfDay();
        $end = Carbon::parse($input['end_date'])->endOfDay();

        if ($today->lt($start)) {
            return $today->startOfMonth();
        }
        if ($today->gt($end)) {
            return $end->startOfMonth();
        }

        return $today->startOfMonth();
    }

    private function allowed(User $user, string $permission): bool
    {
        return Permissions::roleHas($user->role, $permission);
    }
}
