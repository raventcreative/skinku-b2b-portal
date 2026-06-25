<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PaymentShippingTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): User
    {
        return User::create([
            'name' => 'Dist', 'fullname' => 'Dist', 'username' => 'dist1',
            'email' => 'dist1@skinku.test', 'password' => Hash::make('secret123'),
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

    private function product(int $stock = 100): Product
    {
        return Product::create([
            'name' => 'P', 'sku' => 'SKU-1', 'price_distributor' => 40000, 'price_reseller' => 55000,
            'price_retail' => 75000, 'cogs' => 25000, 'hq_stock' => $stock, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function makePo(User $buyer, Product $p, int $qty = 5): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->createForPartner(
            $buyer, [['product_id' => $p->id, 'qty' => $qty]], 'Jl. Test', null
        );
    }

    public function test_new_po_is_unpaid_with_zero_shipping(): void
    {
        $po = $this->makePo($this->partner(), $this->product());
        $this->assertEquals(PurchaseOrder::PAYMENT_UNPAID, $po->payment_status);
        $this->assertEquals(0, (float) $po->shipping_cost);
        $this->assertEquals(200000, (float) $po->total_amount); // 40000 * 5
    }

    public function test_admin_shipping_recomputes_total(): void
    {
        $svc = app(PurchaseOrderService::class);
        $po = $this->makePo($this->partner(), $this->product());

        $svc->setShipping($po, 25000, 10000); // +ongkir -diskon
        $po->refresh();

        $this->assertEquals(25000, (float) $po->shipping_cost);
        $this->assertEquals(10000, (float) $po->discount);
        $this->assertEquals(215000, (float) $po->total_amount); // 200000 - 10000 + 25000
    }

    public function test_cannot_process_until_paid(): void
    {
        $svc = app(PurchaseOrderService::class);
        $po = $this->makePo($this->partner(), $this->product());
        $svc->updateStatus($po, PurchaseOrder::STATUS_APPROVED); // allowed before payment

        $this->expectException(RuntimeException::class);
        $svc->updateStatus($po->fresh(), PurchaseOrder::STATUS_PROCESSING); // blocked: unpaid
    }

    public function test_paid_po_can_be_processed(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();
        $po = $this->makePo($this->partner(), $this->product());

        $svc->updateStatus($po, PurchaseOrder::STATUS_APPROVED);
        $svc->verifyPayment($po->fresh(), true, $admin->id);

        $processed = $svc->updateStatus($po->fresh(), PurchaseOrder::STATUS_PROCESSING);
        $this->assertEquals(PurchaseOrder::STATUS_PROCESSING, $processed->status);
    }

    public function test_buyer_uploads_payment_proof(): void
    {
        Storage::fake('public');
        $buyer = $this->partner();
        $p = $this->product();
        $po = $this->makePo($buyer, $p);
        app(PurchaseOrderService::class)->setShipping($po, 20000);

        $this->actingAs($buyer)->post(route('purchase-orders.payment-proof', $po), [
            'proof' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertRedirect();

        $po->refresh();
        $this->assertEquals(PurchaseOrder::PAYMENT_AWAITING, $po->payment_status);
        $this->assertNotNull($po->payment_proof_path);
        Storage::disk('public')->assertExists($po->payment_proof_path);
    }
}
