<?php

namespace Tests\Feature;

use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Models\ShopeeWalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_model_dan_kolom_jurnal_order(): void
    {
        $w = ShopeeWalletTransaction::create([
            'transaction_id' => 'W-1', 'transaction_type' => 'WITHDRAWAL_COMPLETED',
            'kind' => 'Tarik ke bank', 'amount' => 50000, 'money_flow' => 'MONEY_OUT',
            'posting_status' => ShopeeWalletTransaction::POST_PENDING,
        ]);
        $this->assertFalse($w->isPosted());
        $this->assertEquals('50000.00', $w->fresh()->amount);

        // kolom jurnal order ada
        $o = ShopeeOrder::create([
            'order_sn' => 'O-1', 'status' => 'COMPLETED', 'total_amount' => 100, 'hpp_amount' => 40,
            'stock_status' => 'deducted',
        ]);
        $o->update(['transit_journal_id' => 5, 'sale_journal_id' => 6]);
        $this->assertEquals(5, $o->fresh()->transit_journal_id);

        // journal_enabled di connection
        $c = ShopeeConnection::create(['shop_id' => '1', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30), 'journal_enabled' => true]);
        $this->assertTrue($c->fresh()->journal_enabled);
    }
}
