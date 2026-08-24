<?php

namespace Tests\Feature;

use App\Models\ShopeeReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_menyimpan_dan_cast_line_items(): void
    {
        $r = ShopeeReturn::create([
            'shopee_return_sn' => 'R-1',
            'shopee_order_sn' => 'S-1',
            'status' => 'ACCEPTED',
            'return_reason' => 'Rusak',
            'line_items' => [['sku' => 'A', 'name' => 'Produk A', 'qty' => 2]],
            'review_status' => ShopeeReturn::REVIEW_PENDING,
        ]);

        $this->assertSame('R-1', $r->shopee_return_sn);
        $this->assertSame('pending', $r->review_status);
        $this->assertIsArray($r->fresh()->line_items);
        $this->assertSame(2, $r->fresh()->line_items[0]['qty']);
    }
}
