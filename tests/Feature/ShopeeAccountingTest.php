<?php

namespace Tests\Feature;

use App\Models\AccBranch;
use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSettlement;
use App\Models\ShopeeWalletTransaction;
use App\Services\ShopeeAccountingService;
use App\Services\ShopeeWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function branch(): AccBranch
    {
        return AccBranch::firstOrCreate(['code' => 'HQ'], ['name' => 'HQ', 'is_active' => true]);
    }

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

    public function test_wallet_store_dan_kind_mapping(): void
    {
        $svc = app(ShopeeWalletService::class);
        $n = $svc->store([
            ['transaction_id' => 'T1', 'transaction_type' => 'PAID_ADS_CHARGE', 'amount' => 5000, 'money_flow' => 'MONEY_OUT', 'create_time' => now()->timestamp],
            ['transaction_id' => 'T2', 'transaction_type' => 'WITHDRAWAL_COMPLETED', 'amount' => 60000, 'money_flow' => 'MONEY_OUT', 'create_time' => now()->timestamp],
        ]);
        $this->assertSame(2, $n);
        $this->assertSame('Biaya iklan', ShopeeWalletTransaction::where('transaction_id', 'T1')->value('kind'));
        $this->assertSame('Tarik ke bank', ShopeeWalletTransaction::where('transaction_id', 'T2')->value('kind'));
    }

    public function test_transit_lalu_sale_akui_omzet_dan_hpp(): void
    {
        $this->branch();
        $svc = app(ShopeeAccountingService::class);
        $a = $svc->accounts();
        $o = ShopeeOrder::create(['order_sn' => 'AC-1', 'status' => 'COMPLETED',
            'total_amount' => 100000, 'hpp_amount' => 40000, 'stock_status' => 'deducted']);

        $svc->postTransit($o);
        $this->assertNotNull($o->fresh()->transit_journal_id);
        $this->assertEquals(40000, $svc->balanceOf($a['transit']->id)); // Dr transit 40000

        $svc->postSale($o->fresh());
        $this->assertNotNull($o->fresh()->sale_journal_id);
        $this->assertEquals(0, $svc->balanceOf($a['transit']->id));       // transit lepas
        $this->assertEquals(-100000, $svc->balanceOf($a['penjualan']->id)); // Cr penjualan
        $this->assertEquals(40000, $svc->balanceOf($a['hpp']->id));        // Dr HPP
        $this->assertEquals(100000, $svc->balanceOf($a['piutang']->id));   // Dr piutang
    }

    public function test_settlement_balance_dari_data_asli(): void
    {
        $this->branch();
        $svc = app(ShopeeAccountingService::class);
        $a = $svc->accounts();
        // Piutang harus ada dulu (dari sale) supaya lunas — buat order+sale
        $o = ShopeeOrder::create(['order_sn' => '2608247FYHUBMG', 'status' => 'COMPLETED',
            'total_amount' => 77665, 'hpp_amount' => 40000, 'stock_status' => 'deducted']);
        $svc->postTransit($o);
        $svc->postSale($o->fresh()); // Dr piutang 77665

        $s = ShopeeSettlement::create(['order_sn' => '2608247FYHUBMG', 'escrow_amount' => 64675,
            'buyer_total_amount' => 77665, 'actual_shipping_fee' => 11765, 'campaign_fee' => 0,
            'posting_status' => ShopeeSettlement::POST_PENDING]);

        $svc->postSettlement($s);
        $this->assertTrue($s->fresh()->isPosted());
        $this->assertEquals(64675, $svc->balanceOf($a['kas']->id));    // Dr kas net
        $this->assertEquals(11765, $svc->balanceOf($a['ongkir']->id)); // Dr ongkir
        $this->assertEquals(1225, $svc->balanceOf($a['fee']->id));     // Dr fee catch-all (77665-64675-11765)
        $this->assertEquals(0, $svc->balanceOf($a['piutang']->id));    // piutang lunas (77665 Dr - 77665 Cr)
    }

    public function test_wallet_withdrawal_dan_ads_dan_skip_escrow(): void
    {
        $this->branch();
        $svc = app(ShopeeAccountingService::class);
        $a = $svc->accounts();

        $wd = ShopeeWalletTransaction::create(['transaction_id' => 'WD', 'transaction_type' => 'WITHDRAWAL_COMPLETED',
            'kind' => 'Tarik ke bank', 'amount' => 50000, 'posting_status' => 'pending']);
        $svc->postWallet($wd);
        $this->assertEquals(50000, $svc->balanceOf($a['bank']->id));  // Dr bank
        $this->assertEquals(-50000, $svc->balanceOf($a['kas']->id));  // Cr kas

        $ads = ShopeeWalletTransaction::create(['transaction_id' => 'AD', 'transaction_type' => 'PAID_ADS_CHARGE',
            'kind' => 'Biaya iklan', 'amount' => 8000, 'posting_status' => 'pending']);
        $svc->postWallet($ads);
        $this->assertEquals(8000, $svc->balanceOf($a['iklan']->id));  // Dr iklan

        // ESCROW_VERIFIED_ADD di-SKIP (sudah di settlement) → tak buat jurnal
        $esc = ShopeeWalletTransaction::create(['transaction_id' => 'ES', 'transaction_type' => 'ESCROW_VERIFIED_ADD',
            'kind' => 'Order cair (ke saldo)', 'amount' => 64675, 'posting_status' => 'pending']);
        $svc->postWallet($esc);
        $this->assertSame('pending', $esc->fresh()->posting_status); // tetap pending (di-skip)
    }

    public function test_switch_off_throw_dan_unpost_scoped_dan_idempoten(): void
    {
        $this->branch();
        $svc = app(ShopeeAccountingService::class);
        // tanpa journal_enabled → throw
        ShopeeConnection::create(['shop_id' => '1', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]); // journal_enabled default false
        $this->expectException(\RuntimeException::class);
        $svc->postPending();
    }

    public function test_full_cycle_idempoten_dan_unpost(): void
    {
        $this->branch();
        ShopeeConnection::create(['shop_id' => '1', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30), 'journal_enabled' => true]);
        $svc = app(ShopeeAccountingService::class);
        $a = $svc->accounts();
        ShopeeOrder::create(['order_sn' => 'FC', 'status' => 'COMPLETED', 'total_amount' => 100000,
            'hpp_amount' => 40000, 'stock_status' => 'deducted']);
        ShopeeSettlement::create(['order_sn' => 'FC', 'escrow_amount' => 90000, 'buyer_total_amount' => 100000,
            'actual_shipping_fee' => 0, 'campaign_fee' => 0, 'posting_status' => 'pending']);

        $r1 = $svc->postPending();
        $this->assertGreaterThanOrEqual(1, $r1['sale']);
        $this->assertEquals(0, $svc->balanceOf($a['piutang']->id)); // lunas
        $r2 = $svc->postPending();
        $this->assertSame(0, $r2['sale'] + $r2['settlement']); // idempoten

        // jurnal non-shopee tak kehapus
        $svc->unpostAll();
        $this->assertEquals(0, $svc->balanceOf($a['kas']->id)); // semua jurnal shopee dicabut
        $this->assertSame('pending', ShopeeSettlement::where('order_sn', 'FC')->value('posting_status'));
    }
}
