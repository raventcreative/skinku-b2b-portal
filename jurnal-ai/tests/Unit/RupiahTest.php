<?php

namespace Tests\Unit;

use App\Support\Rupiah;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RupiahTest extends TestCase
{
    #[DataProvider('angka')]
    public function test_parse_angka_rupiah_segala_rasa(mixed $input, ?float $expected): void
    {
        $this->assertSame($expected, Rupiah::parse($input), "Gagal mem-parse: ".var_export($input, true));
    }

    public static function angka(): array
    {
        return [
            'int polos' => [1250000, 1250000.0],
            'float' => [85000.5, 85000.5],
            'titik ribuan' => ['1.250.000', 1250000.0],
            'koma ribuan' => ['1,250,000', 1250000.0],
            'prefix Rp' => ['Rp 1.250.000', 1250000.0],
            'prefix Rp tanpa spasi' => ['Rp85.000', 85000.0],
            'prefix IDR' => ['IDR 2.500.000', 2500000.0],
            'koma desimal' => ['1.250.000,75', 1250000.75],
            'titik desimal' => ['1,250,000.75', 1250000.75],
            'desimal saja' => ['85000.50', 85000.5],
            // Tiga digit setelah pemisah = ribuan, bukan desimal.
            'ribuan pendek' => ['1.250', 1250.0],
            'negatif minus' => ['-50000', -50000.0],
            'negatif kurung akuntansi' => ['(50.000)', -50000.0],
            'sufiks rb' => ['85rb', 85000.0],
            'sufiks ribu' => ['85 ribu', 85000.0],
            'sufiks k' => ['500k', 500000.0],
            'sufiks jt' => ['3jt', 3000000.0],
            'sufiks jt desimal' => ['1,5jt', 1500000.0],
            'sufiks juta' => ['2 juta', 2000000.0],
            'nol' => ['0', 0.0],
            'kosong' => ['', null],
            'null' => [null, null],
            'bukan angka' => ['tidak ada', null],
            'bool ditolak' => [true, null],
        ];
    }

    public function test_amount_selalu_balik_float(): void
    {
        $this->assertSame(0.0, Rupiah::amount('bukan angka'));
        $this->assertSame(85000.0, Rupiah::amount('85rb'));
    }

    public function test_format_tanpa_desimal_kalau_bulat(): void
    {
        $this->assertSame('1.250.000', Rupiah::format(1250000));
        $this->assertSame('1.250.000,75', Rupiah::format(1250000.75));
        $this->assertSame('1.250.000,00', Rupiah::format(1250000, withDecimals: true));
        $this->assertSame('0', Rupiah::format(null));
    }
}
