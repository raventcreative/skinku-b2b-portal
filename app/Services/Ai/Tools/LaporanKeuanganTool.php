<?php

namespace App\Services\Ai\Tools;

use App\Models\AccJournal;
use App\Models\User;
use App\Services\CashFlowService;
use App\Services\FinancialReportService;

/**
 * Alat BACA: laporan keuangan menu Akuntansi (Laba Rugi, Neraca, Arus Kas) satu bulan buku — service yang sama dgn
 * halamannya. Izin = view_accounting + Lihat HPP: laporan hasil hitungan memuat HPP & laba, aturan sama dgn halaman
 * (keputusan user 2026-10-08). Jurnal/COA (cukup view_accounting) sengaja tak dibuatkan alat.
 */
class LaporanKeuanganTool extends BaseTool
{
    public function __construct(private FinancialReportService $keuangan, private CashFlowService $arusKas) {}

    public function name(): string
    {
        return 'laporan_keuangan';
    }

    public function permission(): ?string
    {
        return 'view_accounting';
    }

    public function availableFor(User $user): bool
    {
        return $this->bolehLihatHpp($user);
    }

    public function description(): string
    {
        return 'Laporan keuangan akuntansi satu bulan buku (menu Akuntansi): Laba Rugi (penjualan bersih, HPP, laba kotor, '
            .'beban operasional, laba bersih, margin, akun terbesar), Neraca per akhir bulan (aktiva, liabilitas, ekuitas, laba '
            .'berjalan) dan Arus Kas (operasi/investasi/pendanaan, kas awal & akhir). Sumber: jurnal yang sudah diposting.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'periode' => ['type' => 'string', 'description' => 'Bulan buku YYYY-MM. Kosongkan = bulan buku terakhir yang ada jurnalnya.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $tersedia = AccJournal::periodeBuku();
        $minta = is_string($args['periode'] ?? null) ? $args['periode'] : '';
        $periode = in_array($minta, $tersedia, true) ? $minta : $tersedia[0];
        $is = $this->keuangan->incomeStatement($periode);
        $bs = $this->keuangan->balanceSheet($periode);
        $cf = $this->arusKas->directCashFlow($periode);

        $terbesar = fn (array $baris, int $n) => collect($baris)->sortByDesc(fn ($l) => abs($l['amount']))->take($n)
            ->map(fn ($l) => ['akun' => $l['code'].' · '.$l['name'], 'rupiah' => $l['amount']])->values()->all();
        $persen = fn (float $x) => $is['penjualan_bersih'] > 0 ? round($x / $is['penjualan_bersih'] * 100, 1) : null;

        return [
            'periode' => $periode,
            'periode_tersedia' => array_slice($tersedia, 0, 12),
            'laba_rugi' => [
                'penjualan_bruto' => $is['penjualan_bruto'], 'retur_potongan' => $is['retur_potongan'],
                'penjualan_bersih' => $is['penjualan_bersih'], 'hpp' => $is['hpp'],
                'laba_kotor' => $is['laba_kotor'], 'margin_kotor_persen' => $persen($is['laba_kotor']),
                'beban_operasional' => $is['beban_operasional'], 'laba_operasional' => $is['operating_income'],
                'pendapatan_lain' => $is['pendapatan_lain'], 'beban_non_operasional' => $is['beban_non_operasional'],
                'laba_bersih' => $is['net_income'], 'margin_bersih_persen' => $persen($is['net_income']),
                'beban_operasional_terbesar' => $terbesar($is['lines']['beban_operasional'], 10),
                'rincian_hpp' => $terbesar($is['lines']['hpp'], 5),
                'rincian_penjualan' => $terbesar($is['lines']['penjualan'], 5),
            ],
            'neraca' => [
                'per_akhir' => $periode, 'total_aktiva' => $bs['total_aktiva'], 'total_liabilitas' => $bs['total_liabilitas'],
                'modal' => $bs['modal'], 'laba_berjalan' => $bs['laba_berjalan'], 'total_ekuitas' => $bs['total_ekuitas'],
                'total_pasiva' => $bs['total_pasiva'], 'seimbang' => $bs['balanced'],
                'aktiva_terbesar' => $terbesar($bs['aktiva'], 8), 'liabilitas_terbesar' => $terbesar($bs['liabilitas'], 5),
            ],
            'arus_kas' => [
                'operasi' => $cf['totals']['operating'], 'investasi' => $cf['totals']['investing'], 'pendanaan' => $cf['totals']['financing'],
                'arus_bersih' => $cf['net'], 'kas_awal' => $cf['kas_awal'], 'kas_akhir' => $cf['kas_akhir'],
                'cocok_dengan_saldo_kas' => $cf['reconciled'],
            ],
        ] + ($minta !== '' && $minta !== $periode
            ? ['catatan' => "Bulan buku {$minta} belum punya jurnal — yang ditampilkan {$periode}. Pilih dari periode_tersedia."]
            : []);
    }
}
