<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class PortalFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $status = User::STATUS_ACTIVE): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "User {$role} {$n}",
            'fullname' => "User {$role} {$n}",
            'username' => "{$role}{$n}",
            'email' => "{$role}{$n}@skinku.test",
            'password' => Hash::make('secret123'),
            'role' => $role,
            'company_name' => "Co {$role} {$n}",
            'status' => $status,
        ]);
    }

    private function makeProduct(int $stock = 100): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => "Produk {$n}",
            'sku' => "SKU-{$n}",
            'category' => 'Serum',
            'price_distributor' => 40000,
            'price_reseller' => 55000,
            'price_retail' => 75000,
            'cogs' => 25000,
            'hq_stock' => $stock,
            'status' => Product::STATUS_ACTIVE,
        ]);
    }

    public function test_active_user_can_login_from_sql(): void
    {
        $this->makeUser(User::ROLE_DISTRIBUTOR);
        $user = User::first();

        $res = $this->post('/login', ['login' => $user->username, 'password' => 'secret123']);
        $res->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_can_use_email(): void
    {
        $user = $this->makeUser(User::ROLE_RESELLER);
        $res = $this->post('/login', ['login' => $user->email, 'password' => 'secret123']);
        $res->assertRedirect(route('dashboard'));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->makeUser(User::ROLE_DISTRIBUTOR, User::STATUS_INACTIVE);
        $res = $this->post('/login', ['login' => $user->username, 'password' => 'secret123']);
        $res->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->makeUser(User::ROLE_DISTRIBUTOR);
        $res = $this->post('/login', ['login' => $user->username, 'password' => 'wrong']);
        $res->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_partner_cannot_access_user_management(): void
    {
        $partner = $this->makeUser(User::ROLE_DISTRIBUTOR);
        $this->actingAs($partner)->get('/users')->assertForbidden();
    }

    public function test_super_admin_can_access_user_management(): void
    {
        $admin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->actingAs($admin)->get('/users')->assertOk();
    }

    public function test_admin_cannot_create_super_admin(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $res = $this->actingAs($admin)->post('/users', [
            'fullname' => 'Hacker', 'email' => 'h@x.test', 'username' => 'hacker',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => User::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $res->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['username' => 'hacker']);
    }

    public function test_po_total_is_computed_server_side_by_role(): void
    {
        $dist = $this->makeUser(User::ROLE_DISTRIBUTOR);
        $p = $this->makeProduct(100);

        $service = app(PurchaseOrderService::class);
        $po = $service->createForPartner($dist, [['product_id' => $p->id, 'qty' => 3]], 'Jl. Test', null);

        // distributor price 40000 * 3 = 120000 (retail/reseller prices ignored)
        $this->assertEquals(120000, (float) $po->total_amount);
        $this->assertEquals(PurchaseOrder::STATUS_PENDING, $po->status);
    }

    public function test_po_completion_runs_full_inventory_transaction(): void
    {
        $dist = $this->makeUser(User::ROLE_DISTRIBUTOR);
        $p = $this->makeProduct(100);
        $service = app(PurchaseOrderService::class);

        $po = $service->createForPartner($dist, [['product_id' => $p->id, 'qty' => 10]], 'Jl. Test', null);
        $po->update(['payment_status' => PurchaseOrder::PAYMENT_PAID]); // payment gate: must be paid to fulfil

        // walk the status chain to completed
        $service->updateStatus($po, PurchaseOrder::STATUS_APPROVED);
        $service->updateStatus($po, PurchaseOrder::STATUS_PROCESSING);
        $service->updateStatus($po, PurchaseOrder::STATUS_SHIPPED);
        $service->updateStatus($po->fresh(), PurchaseOrder::STATUS_COMPLETED);

        $p->refresh();
        $this->assertEquals(90, $p->hq_stock, 'HQ stock should drop by 10');

        $line = Inventory::where('user_id', $dist->id)->where('product_id', $p->id)->first();
        $this->assertNotNull($line);
        $this->assertEquals(10, $line->quantity, 'Partner inventory should gain 10');

        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'movement_type' => StockMovement::TYPE_OUT, 'quantity' => 10]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'user_id' => $dist->id, 'movement_type' => StockMovement::TYPE_PO_FULFILLMENT, 'quantity' => 10]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'complete_po', 'target_id' => $po->id]);
    }

    public function test_po_cannot_complete_twice(): void
    {
        $dist = $this->makeUser(User::ROLE_DISTRIBUTOR);
        $p = $this->makeProduct(100);
        $service = app(PurchaseOrderService::class);

        $po = $service->createForPartner($dist, [['product_id' => $p->id, 'qty' => 5]], null, null);
        $po->update(['status' => PurchaseOrder::STATUS_SHIPPED]);
        $service->complete($po->fresh());

        $this->expectException(RuntimeException::class);
        $service->complete($po->fresh());
    }

    public function test_all_staff_pages_render(): void
    {
        $admin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->makeProduct(50);

        foreach (['/dashboard', '/purchase-orders', '/products', '/inventory', '/reports', '/users', '/audit-logs', '/settings', '/stock-movements', '/account/password'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_all_partner_pages_render(): void
    {
        $partner = $this->makeUser(User::ROLE_DISTRIBUTOR);
        $this->makeProduct(50);

        foreach (['/dashboard', '/purchase-orders', '/purchase-orders/create', '/inventory', '/reports', '/account/password'] as $url) {
            $this->actingAs($partner)->get($url)->assertOk();
        }

        // partners must be blocked from HQ-only pages
        $this->actingAs($partner)->get('/products')->assertForbidden();
        $this->actingAs($partner)->get('/audit-logs')->assertForbidden();
    }

    public function test_completion_fails_when_hq_stock_insufficient(): void
    {
        $dist = $this->makeUser(User::ROLE_DISTRIBUTOR);
        $p = $this->makeProduct(3); // only 3 in HQ
        $service = app(PurchaseOrderService::class);

        $po = $service->createForPartner($dist, [['product_id' => $p->id, 'qty' => 10]], null, null);
        $po->update(['status' => PurchaseOrder::STATUS_SHIPPED]);

        try {
            $service->complete($po->fresh());
            $this->fail('Expected RuntimeException for insufficient stock');
        } catch (RuntimeException $e) {
            // transaction rolled back: stock untouched, PO not completed
            $this->assertEquals(3, $p->fresh()->hq_stock);
            $this->assertNotEquals(PurchaseOrder::STATUS_COMPLETED, $po->fresh()->status);
            $this->assertDatabaseMissing('stock_movements', ['product_id' => $p->id, 'movement_type' => StockMovement::TYPE_OUT]);
        }
    }
}
