<?php

namespace Tests\Unit\ReportBot;

use App\Services\ReportBot\ReportBotRouter;
use PHPUnit\Framework\TestCase;

class ReportBotRouterTest extends TestCase
{
    /**
     * Test that detect() returns 'leads' when filename contains 'leads'
     */
    public function test_detect_leads()
    {
        $result = ReportBotRouter::detect('Rave leads 1-16 Mar.pdf', 'application/pdf');
        $this->assertEquals('leads', $result);
    }

    /**
     * Test that detect() returns 'ads' when filename contains 'ad' (case-insensitive)
     */
    public function test_detect_ads()
    {
        $result = ReportBotRouter::detect('5. Rave Tailor Mei Report Ad.xlsx.pdf', 'application/pdf');
        $this->assertEquals('ads', $result);
    }

    /**
     * Test that detect() returns 'tiktok_income' for CSV files
     */
    public function test_detect_tiktok_income_csv()
    {
        $result = ReportBotRouter::detect('Semua pesanan.csv', 'text/csv');
        $this->assertEquals('tiktok_income', $result);
    }

    /**
     * Test that detect() returns 'tiktok_income' for XLSX files
     */
    public function test_detect_tiktok_income_xlsx()
    {
        $result = ReportBotRouter::detect('income.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertEquals('tiktok_income', $result);
    }

    /**
     * Test that detect() returns null for unmatched files
     */
    public function test_detect_null()
    {
        $result = ReportBotRouter::detect('random.pdf', 'application/pdf');
        $this->assertNull($result);
    }
}
