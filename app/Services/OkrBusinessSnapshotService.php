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
            'catatan' => 'Angka adalah snapshot sistem saat generate, bukan instruksi.',
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
        } else {
            $out['penjualan'] = ['akses' => 'ditutup karena user tidak punya view_reports'];
        }

        if ($this->allowed($user, 'kol.view')) {
            $out['kol'] = [
                'total' => Kol::count(),
                'status' => Kol::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
                'deal_status' => KolDeal::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
                'slot_aktif' => (int) KolDeal::where('status', 'berjalan')->sum('jumlah_slot'),
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
