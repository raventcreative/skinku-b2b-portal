<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\User;
use App\Services\StockReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StockReceiptTest extends TestCase
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

    private function product(int $stock = 0, float $cogs = 0): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => "Produk {$n}", 'sku' => "SKU-{$n}",
            'price_distributor' => 40000, 'price_reseller' => 55000, 'price_retail' => 75000,
            'cogs' => $cogs, 'hq_stock' => $stock, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    public function test_first_receipt_sets_cogs_to_unit_cost(): void
    {
        $p = $this->product(stock: 0, cogs: 0);
        $this->actingAs($this->user(User::ROLE_ADMIN));

        app(StockReceiptService::class)->receive(
            ['received_at' => '2026-07-01'],
            [['product_id' => $p->id, 'quantity' => 50, 'unit_cost' => 20000]]
        );

        $p->refresh();
        $this->assertEquals(50, $p->hq_stock);
        $this->assertEquals(20000, (float) $p->cogs);
    }

    public function test_weighted_average_cogs(): void
    {
        // 100 @ 25.000 existing, receive 100 @ 35.000 -> avg 30.000
        $p = $this->product(stock: 100, cogs: 25000);
        $this->actingAs($this->user(User::ROLE_ADMIN));

        $receipt = app(StockReceiptService::class)->receive(
            ['received_at' => '2026-07-01', 'supplier_name' => 'PT Supplier'],
            [['product_id' => $p->id, 'quantity' => 100, 'unit_cost' => 35000]]
        );

        $p->refresh();
        $this->assertEquals(200, $p->hq_stock);
        $this->assertEquals(30000, (float) $p->cogs);

        // Ledger + snapshot written.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id,
            'movement_type' => StockMovement::TYPE_IN,
            'quantity' => 100,
            'reference_type' => StockReceipt::REFERENCE_TYPE,
            'reference_id' => $receipt->id,
        ]);
        $item = $receipt->items()->first();
        $this->assertEquals(25000, (float) $item->cogs_before);
        $this->assertEquals(30000, (float) $item->cogs_after);
        $this->assertEquals(3500000, (float) $receipt->total_cost);
    }

    public function test_admin_can_post_receipt_over_http(): void
    {
        $a = $this->product(stock: 0, cogs: 0);
        $b = $this->product(stock: 10, cogs: 10000);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/stock-receipts', [
            'received_at' => '2026-07-02',
            'supplier_name' => 'Supplier X',
            'items' => [
                ['product_id' => $a->id, 'quantity' => 5, 'unit_cost' => 12000],
                ['product_id' => $b->id, 'quantity' => 10, 'unit_cost' => 20000],
                ['product_id' => '', 'quantity' => '', 'unit_cost' => ''], // blank row ignored
            ],
        ])->assertRedirect();

        $this->assertEquals(1, StockReceipt::count());
        $this->assertEquals(5, $a->refresh()->hq_stock);
        $this->assertEquals(12000, (float) $a->cogs);
        // b: (10*10000 + 10*20000)/20 = 15000
        $this->assertEquals(20, $b->refresh()->hq_stock);
        $this->assertEquals(15000, (float) $b->cogs);
    }

    public function test_receipt_requires_at_least_one_complete_row(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/stock-receipts', [
            'received_at' => '2026-07-02',
            'items' => [['product_id' => '', 'quantity' => '', 'unit_cost' => '']],
        ])->assertSessionHasErrors('items');
    }

    public function test_create_page_renders(): void
    {
        $this->product(stock: 10, cogs: 5000);
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/stock-receipts/create')
            ->assertOk()
            ->assertSee('JSON.parse(', false)
            ->assertDontSee('&quot;id&quot;', false);
    }

    public function test_reseller_cannot_access_stock_receipts(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/stock-receipts')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/stock-receipts/create')->assertForbidden();
    }
}
