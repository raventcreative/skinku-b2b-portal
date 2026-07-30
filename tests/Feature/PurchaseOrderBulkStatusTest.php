<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderBulkStatusTest extends TestCase
{
    use RefreshDatabase;

    private function partner(string $u = 'dist1'): User
    {
        return User::create([
            'name' => 'Dist', 'fullname' => 'Dist', 'username' => $u,
            'email' => $u.'@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Super', 'fullname' => 'Super', 'username' => 'super1',
            'email' => 'super1@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'P', 'sku' => 'SKU-1', 'price_distributor' => 40000, 'price_reseller' => 55000,
            'price_retail' => 75000, 'cogs' => 25000, 'hq_stock' => 100, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function makePo(User $buyer, Product $p): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->createForPartner(
            $buyer, [['product_id' => $p->id, 'qty' => 5]], 'Jl. Test', null
        );
    }

    public function test_partner_cannot_mass_change_status(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner)
            ->post(route('purchase-orders.bulk-status'), ['ids' => [1], 'status' => PurchaseOrder::STATUS_APPROVED])
            ->assertForbidden();
    }

    public function test_mass_approve_moves_pending_pos_to_approved(): void
    {
        $product = $this->product();
        $buyer = $this->partner();
        $po1 = $this->makePo($buyer, $product);
        $po2 = $this->makePo($buyer, $product);

        $this->actingAs($this->admin())
            ->post(route('purchase-orders.bulk-status'), [
                'ids' => [$po1->id, $po2->id],
                'status' => PurchaseOrder::STATUS_APPROVED,
            ])
            ->assertRedirect();

        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $po1->fresh()->status);
        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $po2->fresh()->status);
    }

    public function test_mass_completed_walks_paid_pending_po_through_all_steps(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();
        $product = $this->product();
        $po = $this->makePo($this->partner(), $product);
        $svc->verifyPayment($po->fresh(), true, $admin->id); // lunas → gerbang terbuka

        $this->actingAs($admin)
            ->post(route('purchase-orders.bulk-status'), [
                'ids' => [$po->id],
                'status' => PurchaseOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect();

        // pending → approved → processing → shipped → completed dalam satu aksi.
        $po = $po->fresh();
        $this->assertEquals(PurchaseOrder::STATUS_COMPLETED, $po->status);
        $this->assertNotNull($po->completed_at);
    }

    public function test_mass_completed_stops_at_approved_when_unpaid(): void
    {
        $admin = $this->admin();
        $po = $this->makePo($this->partner(), $this->product()); // belum lunas

        $this->actingAs($admin)
            ->post(route('purchase-orders.bulk-status'), [
                'ids' => [$po->id],
                'status' => PurchaseOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect();

        // Maju sampai approved lalu berhenti di gerbang lunas — tidak completed.
        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $po->fresh()->status);
    }

    public function test_invalid_transition_is_skipped_not_fatal(): void
    {
        $svc = app(PurchaseOrderService::class);
        $product = $this->product();
        $buyer = $this->partner();
        $pending = $this->makePo($buyer, $product);
        $cancelled = $this->makePo($buyer, $product);
        $svc->updateStatus($cancelled, PurchaseOrder::STATUS_CANCELLED); // cancelled -> approved is invalid

        $this->actingAs($this->admin())
            ->post(route('purchase-orders.bulk-status'), [
                'ids' => [$pending->id, $cancelled->id],
                'status' => PurchaseOrder::STATUS_APPROVED,
            ])
            ->assertRedirect();

        // Valid one applied, invalid one left untouched — no exception aborts the batch.
        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $pending->fresh()->status);
        $this->assertEquals(PurchaseOrder::STATUS_CANCELLED, $cancelled->fresh()->status);
    }
}
