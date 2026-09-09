<?php

namespace Tests\Unit;

use App\Support\Barcode;
use PHPUnit\Framework\TestCase;

class BarcodeTest extends TestCase
{
    public function test_menghasilkan_svg(): void
    {
        $svg = Barcode::code128('PO-123');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
    }

    public function test_deterministik_untuk_input_sama(): void
    {
        $this->assertSame(Barcode::code128('ABC123'), Barcode::code128('ABC123'));
    }

    public function test_input_berbeda_hasil_berbeda(): void
    {
        $this->assertNotSame(Barcode::code128('ABC123'), Barcode::code128('XYZ789'));
    }

    public function test_input_kosong_tidak_error(): void
    {
        $svg = Barcode::code128('');
        $this->assertStringStartsWith('<svg', $svg);
    }
}
