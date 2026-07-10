<?php

namespace App\Services;

/**
 * Agregasi laporan untuk perbandingan & tren. "spec" periode = 'YYYY' (setahun,
 * jumlah 12 bulan) atau 'YYYY-MM' (satu bulan). Laba Rugi & Arus Kas dijumlah
 * antar bulan; Neraca diambil per akhir periode (kumulatif).
 */
class ComparativeReportService
{
    private const IS_KEYS = [
        'penjualan_bersih', 'hpp', 'laba_kotor', 'beban_operasional',
        'operating_income', 'pendapatan_lain', 'beban_non_operasional', 'net_income',
    ];

    public function __construct(
        private FinancialReportService $reports,
        private CashFlowService $cash,
    ) {}

    /** 'YYYY' → 12 bulan; 'YYYY-MM' → satu bulan. */
    public static function expand(string $spec): array
    {
        if (preg_match('/^\d{4}$/', $spec)) {
            return array_map(fn ($m) => sprintf('%s-%02d', $spec, $m), range(1, 12));
        }

        return [$spec];
    }

    public static function isYear(string $spec): bool
    {
        return (bool) preg_match('/^\d{4}$/', $spec);
    }

    /** Ringkasan agregat (Laba Rugi + Neraca + Arus Kas) untuk satu spec. */
    public function summary(string $spec): array
    {
        $months = self::expand($spec);

        $is = array_fill_keys(self::IS_KEYS, 0.0);
        $cf = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0, 'net' => 0.0];

        foreach ($months as $m) {
            $r = $this->reports->incomeStatement($m);
            foreach (self::IS_KEYS as $k) {
                $is[$k] += $r[$k];
            }
            $c = $this->cash->directCashFlow($m);
            $cf['operating'] += $c['totals']['operating'];
            $cf['investing'] += $c['totals']['investing'];
            $cf['financing'] += $c['totals']['financing'];
            $cf['net'] += $c['net'];
        }

        $bs = $this->reports->balanceSheet(end($months));   // per akhir periode
        $cf['kas_awal'] = $this->cash->directCashFlow(reset($months))['kas_awal'];
        $cf['kas_akhir'] = $this->cash->directCashFlow(end($months))['kas_akhir'];

        return ['spec' => $spec, 'is' => $is, 'bs' => $bs, 'cf' => $cf];
    }

    /** Laba Rugi + Neraca + Arus Kas per bulan untuk satu tahun (tabel tren). */
    public function monthlyReport(string $year): array
    {
        $out = [];
        foreach (range(1, 12) as $m) {
            $period = sprintf('%s-%02d', $year, $m);
            $r = $this->reports->incomeStatement($period);
            $c = $this->cash->directCashFlow($period);
            $b = $this->reports->balanceSheet($period);
            $out[] = [
                'month' => $m, 'period' => $period,
                'penjualan_bersih' => $r['penjualan_bersih'], 'hpp' => $r['hpp'], 'laba_kotor' => $r['laba_kotor'],
                'beban_operasional' => $r['beban_operasional'], 'operating_income' => $r['operating_income'], 'net_income' => $r['net_income'],
                'arus_operasi' => $c['totals']['operating'], 'arus_investasi' => $c['totals']['investing'],
                'arus_pendanaan' => $c['totals']['financing'], 'arus_kas_bersih' => $c['net'], 'kas_akhir' => $c['kas_akhir'],
                'total_aktiva' => $b['total_aktiva'], 'total_liabilitas' => $b['total_liabilitas'], 'total_ekuitas' => $b['total_ekuitas'],
            ];
        }

        return $out;
    }
}
