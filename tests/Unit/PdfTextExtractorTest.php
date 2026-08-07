<?php

namespace Tests\Unit;

use App\Support\PdfTextExtractor;
use Tests\TestCase;

class PdfTextExtractorTest extends TestCase
{
    public function test_ekstrak_pdf_rapi(): void
    {
        $t = PdfTextExtractor::extract(base_path('tests/fixtures/report_bot/creator_list.pdf'));
        $this->assertStringContainsString('Creator name', $t);
        $this->assertStringContainsString('GMV', $t);
        $this->assertFalse(PdfTextExtractor::looksUnreadable($t));
    }

    public function test_deteksi_teks_tak_terbaca(): void
    {
        $this->assertTrue(PdfTextExtractor::looksUnreadable(''));
        $this->assertTrue(PdfTextExtractor::looksUnreadable("\x01\x02\x03\xff\xfe garble"));
    }

    /**
     * Regresi (fix round 1): PDF ber-font CID asli (laporan Ads TikTok) harus
     * kena flag looksUnreadable() — bukan cuma string sampah sintetis. Sebelum fix,
     * rasio dihitung dari SELURUH \p{C} termasuk "\n" struktural yang ditambahkan
     * extract() sendiri, jadi PDF bersih-tapi-tabular (Creator List) nyaris kena
     * flag salah (rasio 0.28, cuma 0.02 di bawah cutoff 0.3).
     */
    public function test_deteksi_pdf_cid_font_tak_terbaca(): void
    {
        $t = PdfTextExtractor::extract(base_path('tests/fixtures/report_bot/ads_cid.pdf'));
        $this->assertTrue(PdfTextExtractor::looksUnreadable($t));
    }

    /**
     * Regresi (fix round 1): token "[] TJ" (array TJ kosong) sebelumnya bikin
     * TypeError tak tertangani — regex-nya match cabang TJ dengan grup 1 = ''
     * (bukan absen), jadi kode salah masuk cabang Tj dan baca $token[2] yang
     * memang tak ada di hasil match itu (grup trailing tak ikut match dihilangkan
     * PCRE). Kontrak kelas ini: input rusak -> degradasi ke '', bukan throw.
     */
    public function test_tj_array_kosong_tidak_crash(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf_edge_');
        file_put_contents($path, "stream\n".gzcompress("(Hello) Tj\n[] TJ\n(World) Tj\n")."\nendstream");

        try {
            $t = PdfTextExtractor::extract($path);
        } finally {
            unlink($path);
        }

        $this->assertStringContainsString('Hello', $t);
        $this->assertStringContainsString('World', $t);
    }
}
