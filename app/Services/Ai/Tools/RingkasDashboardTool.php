<?php

namespace App\Services\Ai\Tools;

use App\Models\Inventory;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: ambil angka dashboard nyata (penjualan, PO, stok, mitra) untuk satu
 * bulan lewat ReportService — supaya AI menganalisa dari data, bukan mengarang.
 */
class RingkasDashboardTool extends BaseTool
{
    public function __construct(private ReportService $reports) {}

    public function name(): string
    {
        return 'ringkas_dashboard';
    }

    public function description(): string
    {
        return 'Ambil ringkasan angka dashboard SKINKU (total penjualan, jumlah & status PO, '
            .'mitra aktif, stok HQ, produk yang stoknya menipis) untuk satu bulan. '
            .'Panggil ini sebelum menjawab pertanyaan soal performa/penjualan/stok.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => [
                    'type' => 'string',
                    'description' => 'Bulan dalam format YYYY-MM. Kosongkan untuk bulan berjalan.',
                ],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $bulan = $this->parseMonth($args['bulan'] ?? null);

        $summary = $this->reports->summary($user, $bulan, allChannels: true);
        $poStatus = $this->reports->poStatusDistribution($user, $bulan);

        $low = Inventory::query()
            ->with('product')
            ->whereColumn('quantity', '<=', 'minimum_stock')
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->limit(10)
            ->get();

        return [
            'bulan' => $bulan->translatedFormat('F Y'),
            'penjualan_total' => $summary['total_sales'],
            'jumlah_po' => $summary['total_po'],
            'po_pending' => $summary['pending_po'],
            'po_selesai' => $summary['completed_po'],
            'mitra_aktif' => $summary['total_partners'],
            'produk_aktif' => $summary['total_products'],
            'stok_hq_unit' => $summary['hq_stock_units'],
            'distribusi_status_po' => $poStatus,
            'stok_menipis' => [
                'jumlah' => $low->count(),
                'contoh' => $low->map(fn (Inventory $i) => [
                    'produk' => $i->product?->name,
                    'sisa' => $i->quantity,
                    'minimum' => $i->minimum_stock,
                ])->values()->all(),
            ],
        ];
    }

    private function parseMonth(?string $v): Carbon
    {
        if ($v && preg_match('/^\d{4}-\d{2}$/', $v)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $v.'-01')->startOfMonth();
            } catch (\Throwable $e) {
                // jatuh ke bulan berjalan
            }
        }

        return Carbon::now();
    }
}
