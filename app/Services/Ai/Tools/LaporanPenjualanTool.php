<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\ReportService;
use App\Support\PartnerHierarchy;

/**
 * Alat BACA: menu Laporan Penjualan (staf) / Laporan Pembelian (mitra) — angka dari ReportService yang sama dgn
 * halamannya. Izin = view_reports. Mitra hanya PO miliknya; rincian per mitra/wilayah khusus staf; laba kotor
 * (dihitung dari HPP) juga butuh izin Lihat HPP — sama dgn kartu di halaman.
 */
class LaporanPenjualanTool extends BaseTool
{
    public function __construct(private ReportService $reports) {}

    public function name(): string
    {
        return 'laporan_penjualan';
    }

    public function permission(): ?string
    {
        return 'view_reports';
    }

    public function description(): string
    {
        return 'Laporan Penjualan PO HQ (untuk staf) atau Laporan Pembelian (untuk mitra = PO milik akun itu) per bulan: '
            .'total penjualan PO selesai, jumlah PO per status, produk terlaris (unit & rupiah). Staf juga dapat penjualan '
            .'per mitra (= pembelian/belanja tiap distributor & reseller ke HQ, urut terbesar — dasar "mitra omzet terbesar" untuk SKINKU) '
            .'dan per wilayah, plus laba kotor bila berizin Lihat HPP. Hanya PO — untuk '
            .'TikTok/Shopee & perbandingan periode pakai laporan_bisnis.';
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
        $s = $this->reports->summary($user, $bulan);
        $out = [
            'periode' => $this->labelBulan($bulan),
            'jenis' => $user->isPartner()
                ? 'Laporan Pembelian — PO milik akun Anda sendiri'
                : 'Laporan Penjualan HQ — PO mitra ke HQ yang selesai',
            'total_penjualan' => (float) $s['total_sales'],
            'po_total' => $s['total_po'],
            'po_pending' => $s['pending_po'],
            'po_selesai' => $s['completed_po'],
            'po_per_status' => $this->reports->poStatusDistribution($user, $bulan),
            'produk_terlaris' => array_map(fn ($r) => ['produk' => $r['label'], 'unit' => $r['qty'], 'rupiah' => $r['revenue']],
                $this->reports->salesByProduct(10, $user, $bulan)),
        ];
        if ($user->isPartner()) {
            return $out + ['catatan' => 'Hanya data akun Anda. Penjualan Anda ke downline ada di penjualan_downline (khusus stockist).'];
        }

        // ponytail: 20 mitra terbesar biar konteks AI tak meledak; rincian lengkap di halaman Laporan Penjualan.
        $out['per_mitra'] = array_map(fn ($r) => [
            'mitra' => $r['label'], 'tier' => $r['role'] ? PartnerHierarchy::label($r['role']) : '—',
            'po' => $r['orders'], 'rupiah' => $r['revenue'], 'rata_rata_po' => $r['avg'],
        ], array_slice($this->reports->partnerSalesDetail($bulan), 0, 20));
        $out['per_wilayah'] = array_map(fn ($r) => ['wilayah' => $r['label'], 'rupiah' => $r['revenue']], $this->reports->salesByRegion($bulan));

        if (! $this->bolehLihatHpp($user)) {
            return $out + ['catatan_akses' => 'Laba kotor & margin tidak ditampilkan — dihitung dari HPP. '.self::CATATAN_HPP];
        }
        $g = $this->reports->grossProfit($bulan);

        return $out + ['laba_kotor' => ['penjualan' => $g['revenue'], 'hpp' => $g['cogs'], 'laba_kotor' => $g['profit'], 'margin_persen' => $g['margin']]];
    }
}
