<?php

namespace Tests\Unit;

use App\Support\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

class PdfTextExtractorTest extends TestCase
{
    public function test_teks_pendek_atau_kosong_dianggap_tak_terbaca(): void
    {
        $this->assertTrue(PdfTextExtractor::looksUnreadable(''));
        $this->assertTrue(PdfTextExtractor::looksUnreadable('   '));
        $this->assertTrue(PdfTextExtractor::looksUnreadable('INVOICE'));
    }

    public function test_teks_bersih_walau_sangat_tabular_dianggap_terbaca(): void
    {
        // Ekstraktor menambah "\n" per sel; PDF tabular yang bersih tidak boleh
        // salah dikira sampah hanya karena banyak baris baru.
        $tabular = implode("\n", array_fill(0, 40, 'Beban Iklan'));

        $this->assertFalse(PdfTextExtractor::looksUnreadable($tabular));
    }

    public function test_karakter_private_use_dianggap_tak_terbaca(): void
    {
        // Font CID custom sering decode jadi Private-Use Area, bukan control char.
        $this->assertTrue(PdfTextExtractor::looksUnreadable(str_repeat("\u{E000}", 40)));
    }

    public function test_ekstrak_teks_dari_stream_pdf_terkompresi(): void
    {
        $content = "BT /F1 12 Tf (Beban Iklan Meta) Tj ET\nBT [(Rp) -200 (500.000)] TJ ET";
        $pdf = "%PDF-1.4\nstream\n".gzcompress($content)."\nendstream\n%%EOF";

        $text = PdfTextExtractor::fromBytes($pdf);

        $this->assertStringContainsString('Beban Iklan Meta', $text);
        $this->assertStringContainsString('500.000', $text);
    }

    public function test_pdf_tanpa_stream_balik_kosong(): void
    {
        $this->assertSame('', PdfTextExtractor::fromBytes('%PDF-1.4 tanpa stream'));
        $this->assertSame('', PdfTextExtractor::fromBytes(''));
    }
}
