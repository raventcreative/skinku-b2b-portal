<?php

namespace App\Services;

/**
 * Kalkulator ROI TikTok Shop: dari Harga Jual + Modal + potongan platform,
 * hitung Profit Bersih dan target ROI/ROAS iklan (dgn & tanpa affiliate).
 * compute() murni (tanpa DB) — semua input sudah "efektif" (bukan nullable).
 */
class RoiCalculatorService
{
    public function compute(array $in): array
    {
        $price = (float) ($in['selling_price'] ?? 0);
        $modal = (float) ($in['modal'] ?? 0);
        $packing = (float) ($in['packing'] ?? 0);
        $proses = (float) ($in['proses_order'] ?? 0);

        $pct = fn (string $k) => (float) ($in[$k] ?? 0) / 100 * $price;

        $profit = $price - $modal;
        $admin = $pct('admin_pct');
        $voucher = $pct('voucher_pct');
        $komisiRaw = $pct('komisi_pct');
        $cap = (float) ($in['komisi_cap'] ?? 0);
        $komisi = $cap > 0 ? min($komisiRaw, $cap) : $komisiRaw;
        $mall = $pct('mall_pct');
        $pajak = $pct('pajak_pct');
        $operasional = $pct('operasional_pct');

        // Total Biaya = SUM(G:M): admin+voucher+komisi+proses+mall+pajak+operasional.
        $totalBiaya = $admin + $voucher + $komisi + $proses + $mall + $pajak + $operasional;
        $profitBersih = $profit - $packing - $totalBiaya;
        $affiliate = $pct('affiliate_pct');
        $profitAfterAff = $profitBersih - $affiliate;

        // ROI = harga / penyebut; penyebut <= 0 => null (rugi / target tak tercapai).
        $roi = fn (float $denom): ?float => $denom > 0 ? $price / $denom : null;
        $target = fn (float $base, int $x): ?float => $roi($base - ($x / 100 * $price));

        $targetNoaff = [
            5 => $target($profitBersih, 5), 10 => $target($profitBersih, 10),
            15 => $target($profitBersih, 15), 20 => $target($profitBersih, 20),
        ];
        $targetAff = [
            5 => $target($profitAfterAff, 5), 10 => $target($profitAfterAff, 10),
            15 => $target($profitAfterAff, 15), 20 => $target($profitAfterAff, 20),
        ];

        $avg = fn (?float $a, ?float $b): ?float => ($a !== null && $b !== null) ? ($a + $b) / 2 : null;

        return [
            'profit' => $profit,
            'admin' => $admin,
            'voucher' => $voucher,
            'komisi' => $komisi,
            'mall' => $mall,
            'pajak' => $pajak,
            'operasional' => $operasional,
            'total_biaya' => $totalBiaya,
            'profit_bersih' => $profitBersih,
            'affiliate' => $affiliate,
            'profit_after_aff' => $profitAfterAff,
            'bep_roi' => $roi($profitBersih),
            'bep_roi_aff' => $roi($profitAfterAff),
            'target_noaff' => $targetNoaff,
            'target_aff' => $targetAff,
            'avg_min' => $avg($targetNoaff[10], $targetAff[10]),
            'avg_optimum' => $avg($targetNoaff[20], $targetAff[20]),
        ];
    }
}
