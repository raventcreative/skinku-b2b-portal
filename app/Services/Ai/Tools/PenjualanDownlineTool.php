<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\ReportService;
use App\Support\PartnerHierarchy;

/**
 * Alat BACA: menu Penjualan Downline (mitra stockist: Grand Distributor/Distributor) — HANYA penjualan akun itu ke
 * downline-nya (PO di mana dia penjual), dari ReportService::downlineSalesReport() yang sama dgn halamannya.
 */
class PenjualanDownlineTool extends BaseTool
{
    public function __construct(private ReportService $reports) {}

    public function name(): string
    {
        return 'penjualan_downline';
    }

    public function permission(): ?string
    {
        return 'view_reports';
    }

    public function availableFor(User $user): bool
    {
        return $user->isPartner() && PartnerHierarchy::holdsStock($user->role);
    }

    public function description(): string
    {
        return 'Penjualan akun Anda (mitra stockist) ke downline per bulan (menu Penjualan Downline): penjualan bersih '
            .'(setelah retur), jumlah PO masuk/pending/selesai, rincian per pembeli downline dan per produk. Hanya data akun Anda.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM, atau "semua" untuk semua periode. Kosongkan = bulan ini.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $bulan = $this->bulanLaporan($args['bulan'] ?? null);
        $r = $this->reports->downlineSalesReport($user, $bulan);

        return [
            'periode' => $this->labelBulan($bulan),
            'catatan' => 'Khusus penjualan akun Anda ke downline.',
            'penjualan_bersih' => $r['net'],
            'po_masuk' => $r['po_count'],
            'po_pending' => $r['pending'],
            'po_selesai' => $r['completed'],
            'per_downline' => array_map(fn ($b) => [
                'downline' => $b['nama'], 'tier' => PartnerHierarchy::label($b['role']), 'po' => $b['po_count'], 'rupiah' => $b['total'],
            ], array_slice($r['per_buyer'], 0, 30)),
            'per_produk' => array_map(fn ($p) => ['produk' => $p['nama'], 'unit' => $p['qty'], 'rupiah' => $p['total']], $r['per_product']),
        ];
    }
}
