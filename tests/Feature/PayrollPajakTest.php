<?php

namespace Tests\Feature;

use App\Models\PayrollItem;
use App\Services\PayrollService;
use App\Support\Payroll\Pajak;
use Tests\TestCase;

/**
 * Hitungan payroll murni (tanpa DB): tarif TER PP 58/2023, Pasal 17, biaya jabatan, iuran BPJS & batas upahnya,
 * PPh 21 bulanan vs hitung ulang setahun (Desember) termasuk lebih potong. Angka contoh dihitung manual.
 */
class PayrollPajakTest extends TestCase
{
    private const SETELAN = ['kes_cap' => 12_000_000, 'jp_cap' => 11_086_300, 'jkk_bps' => 24];

    private function baris(int $gaji, string $ptkp = 'TK/0', bool $bpjs = true, array $extra = []): PayrollItem
    {
        return new PayrollItem($extra + [
            'employee_name' => 'Rina', 'ptkp_status' => $ptkp, 'ter_category' => Pajak::kategori($ptkp), 'cost_group' => 'operasional',
            'bpjs_kesehatan' => $bpjs, 'bpjs_tk' => $bpjs, 'bpjs_jp' => $bpjs, 'base_salary' => $gaji, 'fixed_allowance' => 0,
            'overtime' => 0, 'bonus' => 0, 'kasbon' => 0,
        ]);
    }

    private function svc(): PayrollService
    {
        return app(PayrollService::class);
    }

    public function test_kategori_dan_tarif_ter_di_batas_lapisan(): void
    {
        $this->assertSame(['A', 'A', 'B', 'B', 'C', 'A'], [Pajak::kategori('TK/0'), Pajak::kategori('K/0'), Pajak::kategori('TK/2'),
            Pajak::kategori('K/2'), Pajak::kategori('K/3'), Pajak::kategori(null)]);
        $this->assertSame([44, 40, 41], [count(Pajak::TER['A']), count(Pajak::TER['B']), count(Pajak::TER['C'])]);

        // "di atas X s.d. Y": batas atas ikut lapisan bawah.
        $this->assertSame([0, 25, 250, 1300, 3400], [Pajak::ter('A', 5_400_000), Pajak::ter('A', 5_400_001), Pajak::ter('A', 10_454_000),
            Pajak::ter('A', 30_080_000), Pajak::ter('A', 2_000_000_000)]);
        // Contoh resmi PMK 168/2023: K/0 bruto Rp35.080.000 → 14%, Rp50.080.000 → 18%; penjelasan PP 58: Rp10 jt → 2%.
        $this->assertSame([1400, 1800, 200], [Pajak::ter('A', 35_080_000), Pajak::ter('A', 50_080_000), Pajak::ter('A', 10_000_000)]);
        // Rp9 jt sebulan: A 1,75% · B 1% · C 1,25% (keganjilan memang ada di tabel PP 58).
        $this->assertSame([175, 100, 125], [Pajak::ter('A', 9_000_000), Pajak::ter('B', 9_000_000), Pajak::ter('C', 9_000_000)]);
        $this->assertSame([0, 25, 75], [Pajak::ter('B', 6_200_000), Pajak::ter('B', 6_200_001), Pajak::ter('C', 7_800_000)]);
    }

    public function test_pasal_17_dan_biaya_jabatan(): void
    {
        $this->assertSame(0, Pajak::pasal17(0));
        $this->assertSame(3_000_000, Pajak::pasal17(60_000_000));
        $this->assertSame(3_277_200, Pajak::pasal17(61_848_000));
        $this->assertSame(31_500_000, Pajak::pasal17(250_000_000));
        $this->assertSame(124_000_000, Pajak::pasal17(600_000_000));

        $this->assertSame(6_000_000, Pajak::biayaJabatan(125_448_000, 12));   // 5% = 6,27 jt → maks 6 jt
        $this->assertSame(1_500_000, Pajak::biayaJabatan(31_362_000, 3));     // maks Rp500rb × 3 bulan
        $this->assertSame(3_250_000, Pajak::biayaJabatan(65_000_000, 12));    // 5% di bawah maks
        $this->assertSame([54_000_000, 72_000_000], [Pajak::PTKP['TK/0'], Pajak::PTKP['K/3']]);
    }

    public function test_bpjs_dan_pph21_ter_bulanan(): void
    {
        $i = $this->svc()->hitung($this->baris(10_000_000), self::SETELAN);

        $this->assertSame([400_000, 100_000, 370_000, 200_000, 200_000, 100_000, 24_000, 30_000],
            [$i->kes_company, $i->kes_employee, $i->jht_company, $i->jht_employee, $i->jp_company, $i->jp_employee, $i->jkk, $i->jkm]);
        // Bruto = gaji + BPJS Kesehatan 4% + JKK + JKM (JHT & JP perusahaan bukan penghasilan).
        $this->assertSame(10_454_000, $i->bruto);
        $this->assertSame([250, 261_350, 261_350, false], [$i->ter_rate, $i->pph21_auto, $i->pph21, $i->annual]);
        $this->assertSame(9_338_650, $i->net_pay);   // 10 jt − BPJS 400rb − PPh 261.350
        $this->assertSame(1_024_000, $i->bpjsPerusahaan());

        // Tanpa BPJS, kategori B (K/1): Rp7 jt → 0,75%.
        $b = $this->svc()->hitung($this->baris(7_000_000, 'K/1', false), self::SETELAN);
        $this->assertSame([7_000_000, 75, 52_500, 6_947_500], [$b->bruto, $b->ter_rate, $b->pph21, $b->net_pay]);
    }

    public function test_batas_upah_bpjs_kesehatan_dan_jp(): void
    {
        $i = $this->svc()->hitung($this->baris(20_000_000), self::SETELAN);

        $this->assertSame([480_000, 120_000], [$i->kes_company, $i->kes_employee]);       // upah dibatasi Rp12 jt
        $this->assertSame([221_726, 110_863], [$i->jp_company, $i->jp_employee]);         // upah dibatasi Rp11.086.300
        $this->assertSame([740_000, 400_000, 48_000, 60_000], [$i->jht_company, $i->jht_employee, $i->jkk, $i->jkm]); // tanpa batas
        $this->assertSame([20_588_000, 900, 1_852_920], [$i->bruto, $i->ter_rate, $i->pph21]);

        // Tarif JKK risiko tinggi 1,27% & tunjangan tetap ikut dasar upah.
        $j = $this->svc()->hitung($this->baris(8_000_000, extra: ['fixed_allowance' => 2_000_000]), ['jkk_bps' => 127] + self::SETELAN);
        $this->assertSame([127_000, 200_000], [$j->jkk, $j->jht_employee]);
    }

    public function test_hitung_ulang_setahun_desember(): void
    {
        // 11 bulan sebelumnya sama persis (bruto 10.454.000, iuran 300rb, PPh 261.350 per bulan).
        $lalu = ['bulan' => 11, 'bruto' => 114_994_000, 'iuran' => 3_300_000, 'pph21' => 2_874_850];
        $i = $this->svc()->hitung($this->baris(10_000_000), self::SETELAN, $lalu);

        $this->assertTrue($i->annual);
        $this->assertSame([
            'bulan' => 12, 'bruto_setahun' => 125_448_000, 'biaya_jabatan' => 6_000_000, 'iuran_pensiun' => 3_600_000,
            'neto' => 115_848_000, 'ptkp' => 54_000_000, 'pkp' => 61_848_000, 'pph21_setahun' => 3_277_200, 'pph21_sebelumnya' => 2_874_850,
        ], $i->tax_detail);
        $this->assertSame([402_350, 9_197_650], [$i->pph21, $i->net_pay]);
    }

    public function test_lebih_potong_desember_dikembalikan_lewat_gaji(): void
    {
        $lalu = ['bulan' => 11, 'bruto' => 60_000_000, 'iuran' => 0, 'pph21' => 2_000_000];
        $i = $this->svc()->hitung($this->baris(5_000_000, bpjs: false), self::SETELAN, $lalu);

        $this->assertSame([7_750_000, 387_500], [$i->tax_detail['pkp'], $i->tax_detail['pph21_setahun']]);
        $this->assertSame(-1_612_500, $i->pph21);
        $this->assertSame(6_612_500, $i->net_pay);   // gaji + kelebihan PPh dikembalikan
    }

    public function test_koreksi_manual_pph21_dan_potongan(): void
    {
        $i = $this->svc()->hitung($this->baris(10_000_000, extra: ['pph21_override' => 300_000, 'overtime' => 500_000, 'bonus' => 1_000_000, 'kasbon' => 250_000]), self::SETELAN);

        // Lembur & bonus menaikkan bruto (TER naik), BPJS tetap dari gaji pokok + tunjangan.
        $this->assertSame([11_954_000, 400, 478_160], [$i->bruto, $i->ter_rate, $i->pph21_auto]);
        $this->assertSame(300_000, $i->pph21);
        $this->assertSame(11_500_000 - 400_000 - 300_000 - 250_000, $i->net_pay);
    }
}
