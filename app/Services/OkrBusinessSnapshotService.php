<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\Product;
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
            // Omzet e-commerce bulan berjalan (TikTok + Shopee) sebagai fakta inti,
            // supaya pemisahan e-commerce vs distributor eksplisit di Fakta server.
            $out['omzet_ecommerce_bulan'] = round((float) collect($this->reports->channelSales($month))
                ->whereIn('key', ['tiktok', 'shopee'])->sum('confirmed'), 2);
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
                        'status_periode' => $this->monthStatus($period),
                        'ecommerce_confirmed' => round((float) $channels
                            ->whereIn('key', ['tiktok', 'shopee'])->sum('confirmed'), 2),
                        'ecommerce_pipeline' => round((float) $channels
                            ->whereIn('key', ['tiktok', 'shopee'])->sum('pipeline'), 2),
                        'distributor_po_confirmed' => round((float) $channels
                            ->where('key', 'reseller')->sum('confirmed'), 2),
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
                'catatan_cakupan' => 'Target produk baru dikerjakan sebagai tugas OKR biasa (kartu Kanban), bukan modul pipeline terpisah. Sistem hanya membuktikan master produk yang sudah tersimpan.',
            ];
        } else {
            $out['penjualan'] = ['akses' => 'ditutup karena user tidak punya view_reports'];
        }

        if ($this->allowed($user, 'kol.view')) {
            // KOL = master ENDORSEMENT saja. Ini BUKAN sumber affiliate.
            $out['kol'] = [
                'total' => Kol::count(),
                'status' => Kol::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
                'deal_status' => KolDeal::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
                'slot_aktif' => (int) KolDeal::where('status', 'berjalan')->sum('jumlah_slot'),
                'catatan_cakupan' => 'Modul KOL = master endorsement (screening, deal, slot, ratecard). Bukan sumber affiliate; jangan pakai angka KOL untuk metrik affiliate.',
            ];
        }

        // Affiliate (kreator affiliate TikTok Shop) TERPISAH TOTAL dari KOL.
        // Sumbernya (TikTok Affiliate API) belum tersambung → status eksplisit
        // "belum tersedia". Jangan diambil dari data KOL, jangan dianggap nol.
        $out['affiliate'] = [
            'status' => 'source_not_available',
            'catatan' => 'Funnel affiliate (terdaftar, onboarding, aktif konten/live, order, GMV, conversion, retention, produktivitas per affiliate) belum punya sumber data tersambung. Perlakukan sebagai kebutuhan validasi — bukan nol, dan bukan data KOL.',
        ];

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
            // laba_rugi/arus_kas di atas = bulan referensi. Untuk OKR periode
            // depan, bulan referensi sering bulan BERJALAN (belum tutup buku) →
            // angkanya MTD dan bisa 0. Baseline yang sah = bulan tutup terakhir.
            // Ini fakta TERSEDIA, bukan "belum tersedia".
            $out['laba_rugi_periode'] = [
                'bulan' => $period,
                'status_periode' => $this->monthStatus($month),
            ];
            $closed = $this->latestClosedMonth($month);
            $closedIncome = $this->financial->incomeStatement($closed->format('Y-m'));
            $closedCash = $this->cashFlow->directCashFlow($closed->format('Y-m'));
            $out['laba_rugi_bulan_tutup_terakhir'] = [
                'bulan' => $closed->format('Y-m'),
                'status_periode' => 'bulan selesai',
                'penjualan_bersih' => $closedIncome['penjualan_bersih'],
                'hpp' => $closedIncome['hpp'],
                'laba_kotor' => $closedIncome['laba_kotor'],
                'beban_operasional' => $closedIncome['beban_operasional'],
                'laba_bersih' => $closedIncome['net_income'],
                'arus_kas_bersih' => $closedCash['net'],
                'catatan' => 'Baseline dari bulan buku terakhir yang sudah tutup. Jangan pakai bulan berjalan (MTD) sebagai baseline.',
            ];
            $out['tren_keuangan_3_bulan'] = collect($this->comparisonMonths($month))
                ->map(function (Carbon $period) {
                    $periodLabel = $period->format('Y-m');
                    $income = $this->financial->incomeStatement($periodLabel);
                    $cash = $this->cashFlow->directCashFlow($periodLabel);

                    return [
                        'bulan' => $periodLabel,
                        'status_periode' => $this->monthStatus($period),
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
                ->whereNull('seller_id')
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
                        'status_periode' => $this->monthStatus($period),
                        'batch' => (clone $production)->count(),
                        'output_unit' => (int) (clone $production)->sum('output_qty'),
                        'total_biaya' => round((float) (clone $production)->sum('total_cost'), 2),
                    ];
                })->values()->all();
        }

        if ($this->allowed($user, 'update_po_status') || $this->allowed($user, 'view_reports')) {
            $out['purchase_order'] = PurchaseOrder::query()
                ->whereNull('seller_id')
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
     * FAKTA INTI server dengan URUTAN TETAP. Tujuannya: panel "Fakta server" di
     * pratinjau OKR selalu menampilkan metrik & urutan yang sama tiap generate —
     * tidak lagi bergantung pada source_path mana yang kebetulan dikutip AI (yang
     * dulu membuat set fakta berubah-ubah antar generate). Metrik yang tak ada di
     * katalog (izin/data) dilewati secara konsisten.
     *
     * @param  array<string,array{source_path:string,label:string,value:mixed,period:?string,specialist:string}>  $catalog
     * @return array<int,array{source_path:string,label:string,value:mixed,period:?string,specialist:string}>
     */
    public function coreFacts(array $catalog): array
    {
        $spec = [
            ['path' => 'cmo.penjualan.total_sales', 'label' => 'Omzet total (semua channel)'],
            ['path' => 'cmo.omzet_ecommerce_bulan', 'label' => 'Omzet e-commerce (bulan berjalan)'],
            ['path' => 'cmo.distributor.omzet_selesai', 'label' => 'Omzet distributor (bulan berjalan)'],
            ['path' => 'cmo.distributor.mencapai_100_juta', 'label' => 'Distributor tembus Rp100 juta'],
            ['path' => 'cmo.distributor.aktif_30_hari', 'label' => 'Distributor aktif (30 hari)'],
            ['path' => 'cmo.distributor.onboarding', 'label' => 'Distributor onboarding (belum PO)'],
            ['path' => 'cmo.portofolio_produk.master_aktif', 'label' => 'Produk master aktif'],
            ['path' => 'cfo.laba_rugi_bulan_tutup_terakhir.penjualan_bersih', 'label' => 'Penjualan bersih (bulan buku tutup)'],
            ['path' => 'cfo.laba_rugi.penjualan_bersih', 'label' => 'Penjualan bersih (bulan berjalan · MTD)'],
            ['path' => 'cfo.laba_rugi_bulan_tutup_terakhir.laba_bersih', 'label' => 'Laba bersih (bulan buku tutup)'],
            ['path' => 'cfo.laba_rugi.net_income', 'label' => 'Laba/rugi bersih (bulan berjalan · MTD)'],
            ['path' => 'cfo.laba_rugi_bulan_tutup_terakhir.arus_kas_bersih', 'label' => 'Arus kas bersih (bulan buku tutup)'],
            ['path' => 'cfo.piutang_tempo.sisa_tagihan', 'label' => 'Piutang tempo (sisa tagihan)'],
            ['path' => 'coo.stok.total_hq', 'label' => 'Stok HQ'],
            ['path' => 'coo.stok.total_partner', 'label' => 'Stok partner'],
        ];

        $facts = [];
        foreach ($spec as $item) {
            $entry = $catalog[$item['path']] ?? null;
            if ($entry === null) {
                continue;
            }
            $facts[] = [
                'source_path' => $entry['source_path'],
                'label' => $item['label'],
                'value' => $entry['value'],
                'period' => $entry['period'] ?? null,
                'specialist' => $entry['specialist'],
            ];
        }

        return $facts;
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

    /** Status periode satu bulan: berjalan (MTD, belum lengkap) atau selesai. */
    private function monthStatus(Carbon $period): string
    {
        return $period->isSameMonth(Carbon::today()) ? 'bulan berjalan (MTD)' : 'bulan selesai';
    }

    /**
     * Bulan buku terakhir yang sudah TUTUP. Bulan berjalan belum tutup buku
     * (angkanya MTD), jadi baseline yang sah = bulan sebelumnya.
     */
    private function latestClosedMonth(Carbon $month): Carbon
    {
        return $month->isSameMonth(Carbon::today())
            ? $month->copy()->subMonth()->startOfMonth()
            : $month->copy()->startOfMonth();
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
            // Omzet HQ murni: buang PO inter-partner (seller_id != null) supaya uang
            // antar-mitra tak ikut terhitung sebagai omzet HQ (spec A4).
            ->whereNull('seller_id')
            ->selectRaw('user_id, company_name, SUM(total_amount) as omzet, COUNT(*) as jumlah_po')
            ->groupBy('user_id', 'company_name')
            ->orderByDesc('omzet')
            ->get();

        // Funnel distributor DIHITUNG dari data PO yang sudah ada (read-only),
        // pakai ambang default yang disetujui — tanpa field manual baru.
        $terdaftar = User::where('role', User::ROLE_DISTRIBUTOR)->count();
        // Onboarding = terdaftar tapi BELUM pernah ada PO sama sekali (kapan pun).
        // engagement: mitra aktif beli dari upline tetap dihitung (spec A4)
        $pernahPo = PurchaseOrder::query()
            ->where('user_role', User::ROLE_DISTRIBUTOR)
            ->whereNotNull('user_id')
            ->distinct()->count('user_id');
        // Aktif = ada PO (committed) dalam 30 hari terakhir (rolling) sampai akhir bulan referensi.
        // engagement: mitra aktif beli dari upline tetap dihitung (spec A4)
        $aktifSejak = $month->copy()->endOfMonth()->subDays(30)->toDateString();
        $aktifSampai = $month->copy()->endOfMonth()->toDateString();
        $aktif30Hari = PurchaseOrder::query()
            ->where('user_role', User::ROLE_DISTRIBUTOR)
            ->whereIn('status', $committedStatuses)
            ->whereNotNull('user_id')
            ->whereRaw('COALESCE(order_date, DATE(created_at)) BETWEEN ? AND ?', [$aktifSejak, $aktifSampai])
            ->distinct()->count('user_id');

        return [
            'terdaftar' => $terdaftar,
            'akun_aktif' => User::where('role', User::ROLE_DISTRIBUTOR)
                ->where('status', User::STATUS_ACTIVE)->count(),
            'onboarding' => max(0, $terdaftar - $pernahPo),
            'aktif_30_hari' => $aktif30Hari,
            // engagement: mitra aktif beli dari upline tetap dihitung (spec A4)
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
                'terdaftar' => 'Punya akun distributor (role distributor).',
                'onboarding' => 'Terdaftar tetapi belum pernah membuat PO sama sekali.',
                'aktif_30_hari' => 'Ada PO (selesai/pipeline) dalam 30 hari terakhir hingga akhir bulan referensi.',
                'aktif_bertransaksi' => 'Distributor dengan minimal satu PO selesai/pipeline pada bulan referensi.',
                'mencapai_100_juta' => 'Omzet PO berstatus selesai minimal Rp100 juta pada bulan referensi.',
            ],
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
