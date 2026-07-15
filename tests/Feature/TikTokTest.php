<?php

namespace Tests\Feature;

use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\Product;
use App\Models\TiktokConnection;
use App\Models\TiktokOrder;
use App\Models\TiktokReturn;
use App\Models\TiktokSettlement;
use App\Models\TiktokSkuMap;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\TikTokAccountingService;
use App\Services\TikTokClient;
use App\Services\TikTokOrderService;
use App\Services\TikTokSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U{$n}", 'fullname' => "U{$n}", 'username' => "{$role}{$n}",
            'email' => "{$role}{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Nyalakan saklar pembukuan (default MATI). */
    private function enableJournal(): TiktokConnection
    {
        $c = TiktokConnection::latest('id')->first();
        if ($c) {
            $c->update(['journal_enabled' => true]);

            return $c;
        }

        return TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'journal_enabled' => true,
        ]);
    }

    public function test_journal_off_by_default_never_touches_the_books(): void
    {
        $acc = app(TikTokAccountingService::class);
        AccBranch::create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);
        // terhubung, TAPI pembukuan belum dinyalakan
        TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a',
            'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        TiktokSettlement::create([
            'tiktok_statement_id' => 'OFF1', 'kind' => 'Iklan TikTok', 'currency' => 'IDR',
            'revenue_amount' => 0, 'fee_amount' => 0, 'adjustment_amount' => -100178,
            'settlement_amount' => -100178, 'statement_time' => '2026-07-20',
        ]);

        $this->assertFalse($acc->enabled());
        $this->expectException(\RuntimeException::class);
        $acc->postPending();                                   // ditolak
    }

    public function test_unpost_removes_only_tiktok_journals(): void
    {
        $acc = app(TikTokAccountingService::class);
        $branch = AccBranch::create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);
        $this->enableJournal();
        TiktokSettlement::create([
            'tiktok_statement_id' => 'UP1', 'kind' => 'Iklan TikTok', 'currency' => 'IDR',
            'revenue_amount' => 0, 'fee_amount' => 0, 'adjustment_amount' => -100178,
            'settlement_amount' => -100178, 'statement_time' => '2026-07-20',
        ]);

        // jurnal NON-TikTok (mis. impor Excel) — tidak boleh ikut terhapus
        $a = $acc->accounts();
        app(AccountingService::class)->record(
            ['branch_id' => $branch->id, 'date' => '2026-07-01', 'reference' => 'EXCEL', 'source_type' => 'excel_import'],
            [['account_id' => $a['kas']->id, 'debit' => 5000], ['account_id' => $a['penjualan']->id, 'credit' => 5000]],
        );

        $acc->postPending();
        $this->assertSame(2, AccJournal::count());   // 1 tiktok + 1 excel

        $r = $acc->unpostAll();

        $this->assertSame(1, $r['journals']);
        $this->assertSame(1, AccJournal::count());   // excel selamat
        $this->assertSame('excel_import', AccJournal::first()->source_type);
        $this->assertEquals(0, app(AccountingService::class)->balanceOf($a['iklan']->id)); // buku bersih
        $this->assertSame('pending', TiktokSettlement::first()->posting_status);
    }

    private function configureTikTok(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.service_id', '123');
    }

    public function test_signature_is_stable_order_independent_and_excludes_token(): void
    {
        $this->configureTikTok();
        $c = app(TikTokClient::class);

        $a = $c->sign('/order/202309/orders/search', ['timestamp' => '100', 'app_key' => 'testkey'], '{"page_size":20}');
        $b = $c->sign('/order/202309/orders/search', ['app_key' => 'testkey', 'timestamp' => '100'], '{"page_size":20}');
        $this->assertSame($a, $b);                    // urutan param tidak ngaruh (di-sort)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a); // HMAC-SHA256 hex

        // sign & access_token dikecualikan dari perhitungan
        $withNoise = $c->sign('/x', ['app_key' => 'k', 'sign' => 'zzz', 'access_token' => 'ttt'], '');
        $clean = $c->sign('/x', ['app_key' => 'k'], '');
        $this->assertSame($clean, $withNoise);

        // body & param ikut mengubah tanda tangan
        $this->assertNotSame($a, $c->sign('/order/202309/orders/search', ['timestamp' => '101', 'app_key' => 'testkey'], '{"page_size":20}'));
    }

    public function test_callback_exchanges_code_and_stores_connection(): void
    {
        $this->configureTikTok();
        Http::fake([
            '*/api/v2/token/get*' => Http::response(['code' => 0, 'message' => 'success', 'data' => [
                'access_token' => 'acc-123', 'refresh_token' => 'ref-123',
                // refresh bisa jauh ke depan (mis. epoch th 2125) — harus muat di kolom datetime
                'access_token_expire_in' => time() + 604800, 'refresh_token_expire_in' => 4_900_000_000,
                'seller_name' => 'SKINKU',
            ]]),
            '*/authorization/202309/shops*' => Http::response(['code' => 0, 'data' => [
                'shops' => [['id' => 'SHOP1', 'cipher' => 'CIPHER1', 'name' => 'SKINKU Store', 'region' => 'ID']],
            ]]),
        ]);

        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/tiktok/callback?code=authcode123')
            ->assertRedirect(route('tiktok.index'));

        $this->assertDatabaseHas('tiktok_connections', [
            'shop_id' => 'SHOP1', 'shop_cipher' => 'CIPHER1', 'shop_name' => 'SKINKU Store', 'region' => 'ID',
        ]);
        $conn = TiktokConnection::first();
        $this->assertNotNull($conn->access_expires_at);
    }

    public function test_callback_without_code_shows_error(): void
    {
        $this->configureTikTok();
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/tiktok/callback')
            ->assertRedirect(route('tiktok.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('tiktok_connections', 0);
    }

    public function test_search_orders_sends_json_object_body(): void
    {
        $this->configureTikTok();
        Http::fake(['*/order/202309/orders/search*' => Http::response(['code' => 0, 'data' => ['orders' => []]])]);
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'acc', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/tiktok/sync-orders')->assertRedirect();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/order/202309/orders/search')
                && $request->body() === '{}'                 // object {}, BUKAN array []
                && $request->hasHeader('x-tts-access-token');
        });
    }

    public function test_sync_paginates_and_sorts_newest_first(): void
    {
        $this->configureTikTok();
        Http::fake(['*/order/202309/orders/search*' => Http::sequence()
            ->push(['code' => 0, 'data' => ['orders' => [['id' => 'P1A'], ['id' => 'P1B']], 'next_page_token' => 'TOK2']])
            ->push(['code' => 0, 'data' => ['orders' => [['id' => 'P2A']], 'next_page_token' => '']]),
        ]);
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'acc', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/tiktok/sync-orders')->assertRedirect();

        // dua halaman ke-gabung
        $this->assertDatabaseHas('tiktok_orders', ['tiktok_order_id' => 'P1A']);
        $this->assertDatabaseHas('tiktok_orders', ['tiktok_order_id' => 'P2A']);
        // urut terbaru dulu
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sort_order=DESC') && str_contains($r->url(), 'sort_field=create_time'));
    }

    public function test_index_renders_and_reseller_forbidden(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/tiktok')->assertOk();
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/tiktok')->assertForbidden();
    }

    public function test_stock_funnel_buckets_by_status(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 300, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        // deducted + IN_TRANSIT (×2) → dalam perjalanan
        TiktokOrder::create(['tiktok_order_id' => 'T1', 'status' => 'IN_TRANSIT', 'stock_status' => TiktokOrder::STATUS_DEDUCTED, 'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 2]]]);
        // deducted + COMPLETED (×5) → terkirim
        TiktokOrder::create(['tiktok_order_id' => 'T2', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED, 'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 5]]]);
        // belum dipotong → tidak dihitung
        TiktokOrder::create(['tiktok_order_id' => 'T3', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_PENDING, 'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 99]]]);

        $rows = app(TikTokOrderService::class)->stockFunnel();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertEquals(2, $row['transit']);
        $this->assertEquals(5, $row['delivered']);
        $this->assertEquals(300, $row['sisa']);
        $this->assertEquals(307, $row['total']); // 300 + 2 + 5

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/tiktok/stok')->assertOk()->assertSee('Sabun');
    }

    public function test_return_restock_adds_stock_reject_does_not_reverse_pulls_back(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        $mk = fn ($id) => TiktokReturn::create([
            'tiktok_return_id' => $id, 'tiktok_order_id' => 'O'.$id, 'status' => 'RETURN',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 2]],
            'review_status' => TiktokReturn::REVIEW_PENDING,
        ]);
        $admin = $this->user(User::ROLE_ADMIN);

        // TERIMA (layak jual) → stok +2 = 102
        $r1 = $mk('R1');
        $this->actingAs($admin)->post(route('tiktok.returns.restock', $r1))->assertRedirect();
        $this->assertEquals(102, $p->fresh()->hq_stock);
        $this->assertEquals(TiktokReturn::REVIEW_RESTOCKED, $r1->fresh()->review_status);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'movement_type' => 'IN', 'quantity' => 2, 'reference_type' => 'tiktok_return']);

        // TOLAK (cacat) → stok tetap
        $r2 = $mk('R2');
        $this->actingAs($admin)->post(route('tiktok.returns.reject', $r2))->assertRedirect();
        $this->assertEquals(102, $p->fresh()->hq_stock);
        $this->assertEquals(TiktokReturn::REVIEW_REJECTED, $r2->fresh()->review_status);

        // BATALKAN yang tadi diterima → stok balik 100
        $this->actingAs($admin)->post(route('tiktok.returns.reset', $r1))->assertRedirect();
        $this->assertEquals(100, $p->fresh()->hq_stock);
        $this->assertEquals(TiktokReturn::REVIEW_PENDING, $r1->fresh()->review_status);
    }

    public function test_return_only_sku_appears_in_recipe_panel(): void
    {
        // SKU yang cuma muncul di retur (kode beda dari order) harus bisa dipetakan
        TiktokReturn::create([
            'tiktok_return_id' => 'RX', 'status' => 'RETURN', 'review_status' => TiktokReturn::REVIEW_PENDING,
            'line_items' => [['sku' => 'SK-MZ-BW-500ml', 'name' => 'Mizu', 'qty' => 1]],
        ]);
        $skus = app(TikTokOrderService::class)->skusNeedingMap();
        $this->assertArrayHasKey('SK-MZ-BW-500ml', $skus);
    }

    public function test_return_sync_stores_and_reseller_forbidden(): void
    {
        $this->configureTikTok();
        Http::fake(['*/return_refund/202309/returns/search*' => Http::response(['code' => 0, 'data' => ['return_orders' => [
            ['return_id' => 'RET1', 'order_id' => 'ORD1', 'return_status' => 'RETURN', 'create_time' => 1750000000,
                'return_line_items' => [['seller_sku' => 'SKU-A', 'quantity' => 1]]],
        ]]])]);
        TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/tiktok/returns/sync')->assertRedirect(route('tiktok.returns'));
        $this->assertDatabaseHas('tiktok_returns', ['tiktok_return_id' => 'RET1', 'tiktok_order_id' => 'ORD1']);

        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/tiktok/returns')->assertForbidden();
    }

    public function test_settlement_sync_stores_and_maps_amounts(): void
    {
        $this->configureTikTok();
        Http::fake(['*/finance/202309/statements*' => Http::response(['code' => 0, 'data' => ['statements' => [
            [
                'id' => 'STM1', 'statement_time' => 1750000000, 'payment_status' => 'PAID', 'currency' => 'IDR',
                'revenue_amount' => '10000000', 'fee_amount' => '-800000', 'adjustment_amount' => '0',
                'settlement_amount' => '9200000',
            ],
        ]]])]);
        TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/tiktok/settlements/sync')->assertRedirect(route('tiktok.settlements'));

        $this->assertDatabaseHas('tiktok_settlements', [
            'tiktok_statement_id' => 'STM1', 'payment_status' => 'PAID',
            'revenue_amount' => '10000000.00', 'settlement_amount' => '9200000.00',
        ]);
        // fee disimpan positif (dari -800000)
        $this->assertEquals(800000.0, (float) TiktokSettlement::first()->fee_amount);
        $this->assertEquals('pending', TiktokSettlement::first()->posting_status);
    }

    public function test_settlement_detail_pulls_transactions(): void
    {
        $this->configureTikTok();
        Http::fake(['*/statement_transactions*' => Http::response(['code' => 0, 'data' => ['statement_transactions' => [
            ['type' => 'AFFILIATE_AD', 'order_id' => 'O1', 'settlement_amount' => '-100178'],
        ]]])]);
        TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        $s = TiktokSettlement::create([
            'tiktok_statement_id' => 'STM7', 'payment_status' => 'SETTLED', 'currency' => 'IDR',
            'revenue_amount' => 0, 'fee_amount' => 0, 'adjustment_amount' => -100178, 'settlement_amount' => -100178,
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get(route('tiktok.settlements.detail', $s))
            ->assertOk()
            ->assertSee('Iklan afiliasi');   // keterangan hasil terjemahan AFFILIATE_AD
    }

    public function test_describe_settlements_fills_kind_from_transactions(): void
    {
        $this->configureTikTok();
        Http::fake(['*/statement_transactions*' => Http::response(['code' => 0, 'data' => ['statement_transactions' => [
            ['type' => 'AFFILIATE_AD', 'settlement_amount' => '-100178'],
        ]]])]);
        TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        $s = TiktokSettlement::create([
            'tiktok_statement_id' => 'STM-POT', 'payment_status' => 'SETTLED', 'currency' => 'IDR',
            'revenue_amount' => 0, 'fee_amount' => 0, 'adjustment_amount' => -100178, 'settlement_amount' => -100178,
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/tiktok/settlements/describe')->assertRedirect();
        $this->assertSame('Iklan afiliasi', $s->fresh()->kind);
    }

    public function test_sales_settlement_labeled_penjualan_on_sync(): void
    {
        $n = app(TikTokSettlementService::class)->store([
            ['id' => 'SLS1', 'revenue_amount' => '5000', 'fee_amount' => '-500', 'settlement_amount' => '4500', 'payment_status' => 'SETTLED'],
        ]);
        $this->assertSame(1, $n);
        $this->assertSame('Penjualan', TiktokSettlement::where('tiktok_statement_id', 'SLS1')->first()->kind);
    }

    public function test_cutoff_blocks_pre_opname_orders_from_deduction(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'deduct_from' => '2026-07-15',
        ]);

        $mk = fn ($id, $date) => TiktokOrder::create([
            'tiktok_order_id' => $id, 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_PENDING,
            'order_created_at' => $date, 'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 3]],
        ]);
        $old = $mk('OLD', '2026-07-14 09:00');   // pra-opname → jangan dipotong
        $new = $mk('NEW', '2026-07-15 09:00');   // sesudah → boleh

        $svc = app(TikTokOrderService::class);
        $this->assertTrue($svc->isBeforeCutoff($old));
        $this->assertFalse($svc->isBeforeCutoff($new));

        // potong manual order lama → ditolak
        $this->expectException(\RuntimeException::class);
        $svc->deduct($old, 1);
    }

    public function test_deduct_all_only_takes_orders_from_cutoff(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'deduct_from' => '2026-07-15',
        ]);
        $mk = fn ($id, $date) => TiktokOrder::create([
            'tiktok_order_id' => $id, 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_PENDING,
            'order_created_at' => $date, 'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 3]],
        ]);
        $mk('OLD', '2026-07-14 09:00');
        $mk('NEW', '2026-07-15 09:00');

        $r = app(TikTokOrderService::class)->deductAllReady($this->user(User::ROLE_ADMIN)->id);

        $this->assertSame(1, $r['done']);                       // cuma yang 15 Jul
        $this->assertEquals(97, $p->fresh()->hq_stock);         // 100 − 3 (bukan −6)
        $this->assertSame(TiktokOrder::STATUS_PENDING, TiktokOrder::where('tiktok_order_id', 'OLD')->first()->stock_status);
    }

    public function test_sync_command_pulls_orders_and_auto_deducts(): void
    {
        $this->configureTikTok();
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        Http::fake(['*/order/202309/orders/search*' => Http::response(['code' => 0, 'data' => ['orders' => [
            ['id' => 'CRON1', 'status' => 'COMPLETED', 'create_time' => Carbon::parse('2026-07-16')->timestamp,
                'payment' => ['total_amount' => '1000'],
                'line_items' => [['seller_sku' => 'SKU-A', 'product_name' => 'Sabun', 'quantity' => 4]]],
        ]]])]);
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'auto_deduct' => true, 'deduct_from' => '2026-07-15',
        ]);

        $this->artisan('tiktok:sync')->assertExitCode(0);

        $this->assertDatabaseHas('tiktok_orders', ['tiktok_order_id' => 'CRON1', 'stock_status' => TiktokOrder::STATUS_DEDUCTED]);
        $this->assertEquals(96, $p->fresh()->hq_stock);   // 100 − 4, tanpa user login
    }

    public function test_sync_command_is_quiet_when_not_connected(): void
    {
        $this->artisan('tiktok:sync')->assertExitCode(0);   // tidak error walau belum terhubung
    }

    public function test_option_c_full_cycle_transit_clears_and_piutang_settles(): void
    {
        $acc = app(TikTokAccountingService::class);
        $inv = app(TikTokOrderService::class);
        AccBranch::create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);

        // produk HPP 50rb/pcs
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active',
            'cogs' => 50000, 'price_distributor' => 1, 'price_reseller' => 1]);
        TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'deduct_from' => '2026-07-15', 'journal_enabled' => true]);

        $o = TiktokOrder::create([
            'tiktok_order_id' => 'C1', 'status' => 'IN_TRANSIT', 'stock_status' => TiktokOrder::STATUS_PENDING,
            'total_amount' => 100000, 'order_created_at' => '2026-07-15 09:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 1]],
        ]);

        // 1. Barang keluar → HPP terkunci, transit dijurnal (nol dampak laba)
        $inv->deduct($o, $this->user(User::ROLE_ADMIN)->id);
        $this->assertEquals(50000, (float) $o->fresh()->hpp_amount);
        $acc->postPending();
        $this->assertNotNull($o->fresh()->transit_journal_id);
        $this->assertNull($o->fresh()->sale_journal_id);              // belum sampai → belum diakui
        $a = $acc->accounts();
        $svc = app(AccountingService::class);
        $this->assertEquals(50000, $svc->balanceOf($a['transit']->id));   // nangkring di perjalanan
        $this->assertEquals(0, $svc->balanceOf($a['penjualan']->id));     // BELUM ada omzet
        $this->assertEquals(0, $svc->balanceOf($a['hpp']->id));           // BELUM ada beban HPP

        // 2. Order sampai → omzet + HPP diakui bareng, transit bersih
        $o->update(['status' => 'DELIVERED']);
        $acc->postPending();
        $this->assertNotNull($o->fresh()->sale_journal_id);
        $this->assertEquals(0, $svc->balanceOf($a['transit']->id));       // transit kembali nol
        $this->assertEquals(-100000, $svc->balanceOf($a['penjualan']->id)); // kredit = omzet
        $this->assertEquals(50000, $svc->balanceOf($a['hpp']->id));       // HPP diakui
        $this->assertEquals(100000, $svc->balanceOf($a['piutang']->id));  // TikTok ngutang ke kita

        // 3. Dana cair → piutang lunas, kas + fee masuk
        TiktokSettlement::create([
            'tiktok_statement_id' => 'STC', 'kind' => 'Penjualan', 'currency' => 'IDR',
            'revenue_amount' => 100000, 'fee_amount' => 8000, 'adjustment_amount' => 0,
            'settlement_amount' => 92000, 'statement_time' => '2026-07-20',
        ]);
        $acc->postPending();
        $this->assertEquals(0, $svc->balanceOf($a['piutang']->id));       // piutang LUNAS
        $this->assertEquals(92000, $svc->balanceOf($a['kas']->id));
        $this->assertEquals(8000, $svc->balanceOf($a['fee']->id));
    }

    public function test_backfills_hpp_for_orders_deducted_before_column_existed(): void
    {
        $acc = app(TikTokAccountingService::class);
        AccBranch::create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active',
            'cogs' => 50000, 'price_distributor' => 1, 'price_reseller' => 1]);
        TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'deduct_from' => '2026-07-15', 'journal_enabled' => true]);

        // Order sudah dipotong TAPI hpp_amount 0 (dipotong sebelum kolomnya ada)
        $o = TiktokOrder::create([
            'tiktok_order_id' => 'OLDDEDUCT', 'status' => 'DELIVERED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'total_amount' => 100000, 'hpp_amount' => 0, 'order_created_at' => '2026-07-15 09:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 1]],
        ]);

        $acc->postPending();

        $this->assertEquals(50000, (float) $o->fresh()->hpp_amount);   // HPP dihitung ulang
        $this->assertNotNull($o->fresh()->transit_journal_id);
        $this->assertNotNull($o->fresh()->sale_journal_id);
        $svc = app(AccountingService::class);
        $a = $acc->accounts();
        $this->assertEquals(-100000, $svc->balanceOf($a['penjualan']->id));  // omzet muncul
        $this->assertEquals(50000, $svc->balanceOf($a['hpp']->id));
        $this->assertEquals(0, $svc->balanceOf($a['transit']->id));          // transit tetap bersih
    }

    public function test_sale_posts_even_when_hpp_unknown(): void
    {
        $acc = app(TikTokAccountingService::class);
        AccBranch::create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);
        $this->enableJournal();
        // produk tanpa HPP (cogs 0) → omzet tetap harus diakui
        Product::create(['name' => 'X', 'sku' => 'SKU-X', 'hq_stock' => 10, 'status' => 'active',
            'cogs' => 0, 'price_distributor' => 1, 'price_reseller' => 1]);
        TiktokOrder::create([
            'tiktok_order_id' => 'NOHPP', 'status' => 'DELIVERED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'total_amount' => 75000, 'hpp_amount' => 0, 'order_created_at' => '2026-07-16 09:00',
            'line_items' => [['sku' => 'SKU-X', 'name' => 'X', 'qty' => 1]],
        ]);

        $acc->postPending();

        $svc = app(AccountingService::class);
        $this->assertEquals(-75000, $svc->balanceOf($acc->accounts()['penjualan']->id)); // omzet tidak tersandera
        $this->assertEquals(0, $svc->balanceOf($acc->accounts()['transit']->id));        // transit tidak minus
    }

    public function test_posting_is_idempotent(): void
    {
        $acc = app(TikTokAccountingService::class);
        AccBranch::create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);
        $this->enableJournal();
        TiktokSettlement::create([
            'tiktok_statement_id' => 'IDEM', 'kind' => 'Iklan TikTok', 'currency' => 'IDR',
            'revenue_amount' => 0, 'fee_amount' => 0, 'adjustment_amount' => -100178,
            'settlement_amount' => -100178, 'statement_time' => '2026-07-20',
        ]);

        $r1 = $acc->postPending();
        $r2 = $acc->postPending();   // jalan lagi → tidak boleh dobel

        $this->assertSame(1, $r1['settlement']);
        $this->assertSame(0, $r2['settlement']);
        $this->assertEquals(100178, app(AccountingService::class)->balanceOf($acc->accounts()['iklan']->id));
    }

    public function test_journal_preview_balances_for_sales_and_ads(): void
    {
        $svc = app(TikTokAccountingService::class);

        // Penjualan: bruto 10jt, fee 800rb, cair 9.2jt
        $sale = TiktokSettlement::create([
            'tiktok_statement_id' => 'JS', 'kind' => 'Penjualan', 'currency' => 'IDR',
            'revenue_amount' => 10000000, 'fee_amount' => 800000, 'adjustment_amount' => 0, 'settlement_amount' => 9200000,
        ]);
        $p = $svc->preview($sale);
        $this->assertTrue($p['balanced']);
        // Opsi C: pencairan menutup PIUTANG (bukan mengakui omzet baru)
        $this->assertSame(10000000.0, collect($p['lines'])->firstWhere('account.code', '1103')['credit']);
        $this->assertNull(collect($p['lines'])->firstWhere('account.code', '4001'));   // omzet TIDAK di sini
        $this->assertSame(9200000.0, collect($p['lines'])->firstWhere('account.code', '1003')['debit']);
        $this->assertSame(800000.0, collect($p['lines'])->firstWhere('account.code', '6005')['debit']);

        // Iklan: cair −100178
        $ads = TiktokSettlement::create([
            'tiktok_statement_id' => 'JA', 'kind' => 'Iklan TikTok', 'currency' => 'IDR',
            'revenue_amount' => 0, 'fee_amount' => 0, 'adjustment_amount' => -100178, 'settlement_amount' => -100178,
        ]);
        $pa = $svc->preview($ads);
        $this->assertTrue($pa['balanced']);
        $this->assertSame(100178.0, collect($pa['lines'])->firstWhere('account.code', '6001')['debit']); // Beban Iklan
        $this->assertSame(100178.0, collect($pa['lines'])->firstWhere('account.code', '1003')['credit']); // Kas keluar
    }

    public function test_settlement_page_renders_and_reseller_forbidden(): void
    {
        TiktokSettlement::create([
            'tiktok_statement_id' => 'STM9', 'payment_status' => 'PAID', 'currency' => 'IDR',
            'revenue_amount' => 100, 'fee_amount' => 10, 'settlement_amount' => 90,
        ]);
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/tiktok/settlements')->assertOk()->assertSee('STM9');
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/tiktok/settlements')->assertForbidden();
    }

    public function test_sync_stores_orders_and_matches_sku_to_product(): void
    {
        $this->configureTikTok();
        $product = Product::create([
            'name' => 'Sabun Batang', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active',
            'price_distributor' => 10000, 'price_reseller' => 12000,
        ]);
        Http::fake(['*/order/202309/orders/search*' => Http::response(['code' => 0, 'data' => ['orders' => [
            ['id' => 'TT1', 'status' => 'COMPLETED', 'create_time' => 1750000000,
                'payment' => ['total_amount' => '50000', 'currency' => 'IDR'],
                'line_items' => [['seller_sku' => 'SKU-A', 'product_name' => 'Sabun', 'quantity' => 2]]],
            ['id' => 'TT2', 'status' => 'AWAITING_SHIPMENT', 'create_time' => 1750000100,
                'payment' => ['total_amount' => '9000'],
                'line_items' => [['seller_sku' => 'SKU-UNKNOWN', 'product_name' => 'X', 'quantity' => 1]]],
        ]]])]);
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'acc', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDay(),
        ]);

        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->post('/tiktok/sync-orders')->assertRedirect(route('tiktok.orders'));

        $this->assertDatabaseHas('tiktok_orders', ['tiktok_order_id' => 'TT1', 'status' => 'COMPLETED', 'total_amount' => 50000]);

        $svc = app(TikTokOrderService::class);
        $tt1 = TiktokOrder::where('tiktok_order_id', 'TT1')->first();
        $pv1 = $svc->preview($tt1);
        $this->assertTrue($tt1->isShipped());
        $this->assertTrue($pv1['all_matched']);
        $this->assertSame($product->id, $pv1['lines'][0]['components'][0]['product']->id);
        $this->assertSame(2, $pv1['lines'][0]['components'][0]['deduct']); // resep ×1 × order 2

        // TT2: SKU tak dikenal → tidak cocok, dan belum dikirim
        $tt2 = TiktokOrder::where('tiktok_order_id', 'TT2')->first();
        $this->assertFalse($tt2->isShipped());
        $this->assertFalse($svc->preview($tt2)['all_matched']);

        $this->actingAs($admin)->get('/tiktok/orders')->assertOk()->assertSee('SKU-A');
    }

    public function test_deduct_reduces_stock_idempotently_and_reverses(): void
    {
        $product = Product::create([
            'name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active',
            'price_distributor' => 10000, 'price_reseller' => 12000,
        ]);
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT1', 'status' => 'COMPLETED', 'total_amount' => 50000,
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 3]],
            'stock_status' => TiktokOrder::STATUS_PENDING,
        ]);
        $admin = $this->user(User::ROLE_ADMIN);

        // potong → stok 100 - 3 = 97, order jadi 'deducted'
        $this->actingAs($admin)->post(route('tiktok.deduct', $order))->assertRedirect();
        $this->assertEquals(97, $product->fresh()->hq_stock);
        $this->assertEquals(TiktokOrder::STATUS_DEDUCTED, $order->fresh()->stock_status);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'OUT', 'quantity' => 3, 'reference_type' => 'tiktok_order']);

        // klik lagi → idempoten, stok tetap 97 (tidak dobel)
        $this->actingAs($admin)->post(route('tiktok.deduct', $order))->assertRedirect();
        $this->assertEquals(97, $product->fresh()->hq_stock);

        // batalkan → stok balik 100
        $this->actingAs($admin)->post(route('tiktok.reverse', $order))->assertRedirect();
        $this->assertEquals(100, $product->fresh()->hq_stock);
        $this->assertEquals(TiktokOrder::STATUS_PENDING, $order->fresh()->stock_status);
    }

    public function test_deduct_all_only_processes_shipped_and_matched(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SKU-A', 'hq_stock' => 100, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        // siap (dikirim + cocok)
        $s1 = TiktokOrder::create(['tiktok_order_id' => 'S1', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_PENDING, 'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 2]]]);
        // dikirim tapi SKU tak dikenal
        $s2 = TiktokOrder::create(['tiktok_order_id' => 'S2', 'status' => 'IN_TRANSIT', 'stock_status' => TiktokOrder::STATUS_PENDING, 'line_items' => [['sku' => 'NOPE', 'name' => 'x', 'qty' => 1]]]);
        // belum dikirim
        $s3 = TiktokOrder::create(['tiktok_order_id' => 'S3', 'status' => 'AWAITING_SHIPMENT', 'stock_status' => TiktokOrder::STATUS_PENDING, 'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 5]]]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post(route('tiktok.deduct-all'))->assertRedirect();

        $this->assertEquals(98, $p->fresh()->hq_stock); // hanya S1 (−2)
        $this->assertEquals(TiktokOrder::STATUS_DEDUCTED, $s1->fresh()->stock_status);
        $this->assertEquals(TiktokOrder::STATUS_PENDING, $s2->fresh()->stock_status);
        $this->assertEquals(TiktokOrder::STATUS_PENDING, $s3->fresh()->stock_status);
    }

    public function test_toggle_auto_deduct(): void
    {
        $conn = TiktokConnection::create(['shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        $admin = $this->user(User::ROLE_ADMIN);
        $this->assertFalse($conn->fresh()->auto_deduct); // default MATI

        $this->actingAs($admin)->post(route('tiktok.toggle-auto'), ['auto_deduct' => '1'])->assertRedirect();
        $this->assertTrue($conn->fresh()->auto_deduct);
        $this->actingAs($admin)->post(route('tiktok.toggle-auto'), ['auto_deduct' => '0'])->assertRedirect();
        $this->assertFalse($conn->fresh()->auto_deduct);
    }

    public function test_cannot_deduct_when_not_shipped_or_unmapped(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        // belum dikirim
        $unshipped = TiktokOrder::create(['tiktok_order_id' => 'A', 'status' => 'AWAITING_SHIPMENT',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 1]], 'stock_status' => TiktokOrder::STATUS_PENDING]);
        $this->actingAs($admin)->post(route('tiktok.deduct', $unshipped))->assertRedirect()->assertSessionHas('error');
        $this->assertEquals(TiktokOrder::STATUS_PENDING, $unshipped->fresh()->stock_status);

        // dikirim tapi SKU tak ada produknya
        $unmapped = TiktokOrder::create(['tiktok_order_id' => 'B', 'status' => 'COMPLETED',
            'line_items' => [['sku' => 'NOPE', 'name' => 'x', 'qty' => 1]], 'stock_status' => TiktokOrder::STATUS_PENDING]);
        $this->actingAs($admin)->post(route('tiktok.deduct', $unmapped))->assertSessionHas('error');
        $this->assertEquals(TiktokOrder::STATUS_PENDING, $unmapped->fresh()->stock_status);
    }

    public function test_sku_map_makes_order_matchable(): void
    {
        $product = Product::create([
            'name' => 'Scrub', 'sku' => 'INTERNAL-SCRUB', 'hq_stock' => 50, 'status' => 'active',
            'price_distributor' => 10000, 'price_reseller' => 12000,
        ]);
        $order = TiktokOrder::create(['tiktok_order_id' => 'TTX', 'status' => 'COMPLETED',
            'line_items' => [['sku' => 'Scrub-1', 'name' => 'Scrub', 'qty' => 2]], 'stock_status' => TiktokOrder::STATUS_PENDING]);
        $svc = app(TikTokOrderService::class);
        $this->assertFalse($svc->preview($order)['all_matched']);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post(route('tiktok.sku-map'), [
            'tiktok_sku' => 'Scrub-1', 'product_id' => $product->id, 'qty' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('tiktok_sku_maps', ['tiktok_sku' => 'Scrub-1', 'product_id' => $product->id, 'qty' => 1]);
        $this->assertTrue($svc->preview($order->fresh())['all_matched']);
    }

    public function test_recipe_multiplies_qty_and_handles_bundle(): void
    {
        $soap = Product::create(['name' => 'Body Soap', 'sku' => 'BODYSOAP', 'hq_stock' => 100, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        $lotion = Product::create(['name' => 'Body Lotion', 'sku' => 'BODYLOTION', 'hq_stock' => 50, 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        // Soap-3 = Body Soap ×3 (multiplier); BUND = soap ×1 + lotion ×1 (bundle)
        TiktokSkuMap::create(['tiktok_sku' => 'Soap-3', 'product_id' => $soap->id, 'qty' => 3]);
        TiktokSkuMap::create(['tiktok_sku' => 'BUND', 'product_id' => $soap->id, 'qty' => 1]);
        TiktokSkuMap::create(['tiktok_sku' => 'BUND', 'product_id' => $lotion->id, 'qty' => 1]);

        // order: Soap-3 ×2 (→ soap 3×2=6) + BUND ×1 (→ soap 1, lotion 1)
        $order = TiktokOrder::create(['tiktok_order_id' => 'R', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_PENDING,
            'line_items' => [['sku' => 'Soap-3', 'name' => '', 'qty' => 2], ['sku' => 'BUND', 'name' => '', 'qty' => 1]]]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post(route('tiktok.deduct', $order))->assertRedirect();

        $this->assertEquals(100 - (6 + 1), $soap->fresh()->hq_stock);  // 93
        $this->assertEquals(50 - 1, $lotion->fresh()->hq_stock);       // 49

        // batalkan → balik
        $this->actingAs($this->user(User::ROLE_ADMIN))->post(route('tiktok.reverse', $order))->assertRedirect();
        $this->assertEquals(100, $soap->fresh()->hq_stock);
        $this->assertEquals(50, $lotion->fresh()->hq_stock);
    }
}
