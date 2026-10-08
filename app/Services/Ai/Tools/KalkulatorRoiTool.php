<?php

namespace App\Services\Ai\Tools;

use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Models\User;
use App\Services\RoiCalculatorService;

/**
 * Alat BACA: Kalkulator ROI TikTok Shop (menu Kalkulator ROI), izin manage_roi_calculator sama dgn halamannya.
 * Angka dari RoiCalculatorService::rowFor/summary → sama dgn tabel. Modal = HPP produk (bila tak diisi manual), jadi
 * modal, profit, BEP & target ROI hanya utk view_hpp (aturan HPP) — tanpa izin itu hanya setelan biaya & harga jual.
 */
class KalkulatorRoiTool extends BaseTool
{
    public function __construct(private RoiCalculatorService $svc) {}

    public function name(): string
    {
        return 'kalkulator_roi';
    }

    public function permission(): ?string
    {
        return 'manage_roi_calculator';
    }

    public function description(): string
    {
        return 'Kalkulator ROI TikTok Shop (menu Kalkulator ROI): per produk harga jual, modal, total potongan platform, '
            .'profit bersih (tanpa & dgn affiliate), BEP ROI, dan target ROI/ROAS iklan untuk margin 5/10/15/20% (Target Min '
            .'= margin 10%, Optimum = 20%, rata-rata tanpa & dgn affiliate) + rata-rata semua produk sebagai patokan setelan '
            .'iklan, serta setelan potongan global. Isi cari untuk satu produk. Modal/profit/target hanya untuk izin "Lihat HPP".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cari' => ['type' => 'string', 'description' => 'Nama atau SKU produk (opsional).'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $hpp = $this->bolehLihatHpp($user);
        $s = RoiSetting::current();
        $rows = RoiItem::with('product')->get()->map(fn (RoiItem $i) => $this->svc->rowFor($i, $s))
            ->sortBy(fn ($r) => $r['product']?->name ?? '')->values();
        $cari = mb_strtolower(trim((string) ($args['cari'] ?? '')));
        $pilih = $cari === '' ? $rows : $rows->filter(fn ($r) => str_contains(mb_strtolower(($r['product']?->name ?? '').' '.($r['product']?->sku ?? '')), $cari))->values();
        $x = fn (?float $v) => $v === null ? null : round($v, 2); // ROI null = rugi / target tak tercapai

        $out = array_filter([
            'setelan_biaya_global' => [
                'admin_persen' => (float) $s->admin_pct, 'voucher_persen' => (float) $s->voucher_pct, 'komisi_persen' => (float) $s->komisi_pct,
                'komisi_maks_rp' => (int) $s->komisi_cap, 'mall_persen' => (float) $s->mall_pct, 'pajak_persen' => (float) $s->pajak_pct,
                'operasional_persen' => (float) $s->operasional_pct, 'affiliate_persen' => (float) $s->affiliate_pct,
                'packing_rp' => (int) $s->packing_default, 'proses_order_rp' => (int) $s->proses_order_default,
            ],
            'catatan' => 'ROI = harga jual ÷ (profit − margin target); kosong = rugi / target tak tercapai. Setelan per produk bisa menimpa setelan global.',
            'filter_cari' => $cari ?: null,
            'jumlah_produk' => $pilih->count(),
            'produk' => $pilih->map(function ($r) use ($hpp, $x) {
                $res = $r['result'];
                $row = ['nama' => $r['product']?->name ?? '(produk dihapus)', 'sku' => $r['product']?->sku, 'harga_jual' => $r['in']['selling_price']];
                if ($hpp) {
                    $row += [
                        'modal' => (int) $r['in']['modal'],
                        'total_biaya_platform' => (int) round($res['total_biaya']),
                        'packing' => (int) $r['in']['packing'],
                        'profit_bersih' => (int) round($res['profit_bersih']),
                        'profit_setelah_affiliate' => (int) round($res['profit_after_aff']),
                        'bep_roi' => $x($res['bep_roi']),
                        'bep_roi_dgn_affiliate' => $x($res['bep_roi_aff']),
                        'target_min_10' => $x($res['avg_min']),
                        'target_optimum_20' => $x($res['avg_optimum']),
                        'target_roi_tanpa_affiliate' => array_map($x, $res['target_noaff']),
                        'target_roi_dgn_affiliate' => array_map($x, $res['target_aff']),
                    ];
                }

                return array_filter($row, fn ($v) => $v !== null);
            })->all(),
        ], fn ($v) => $v !== null);
        if ($hpp) {
            $sum = $this->svc->summary($rows); // patokan halaman = rata-rata SEMUA produk (bukan hasil cari)
            $out['rata_rata_semua_produk'] = ['target_min' => $x($sum['avg_min_all']), 'target_optimum' => $x($sum['avg_optimum_all'])];
        } else {
            $out['catatan_akses'] = 'Modal, profit, BEP & target ROI tidak ditampilkan karena dihitung dari HPP — khusus izin "Lihat HPP" (default hanya super admin).';
        }

        return $out;
    }
}
