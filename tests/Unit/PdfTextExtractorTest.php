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
}
