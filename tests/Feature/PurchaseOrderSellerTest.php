<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderSellerTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    public function test_seller_id_bisa_diisi_dan_relasinya_jalan(): void
    {
        $seller = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $po = PurchaseOrder::create([
            'po_number' => 'SKN-PO-TEST-1', 'created_by' => $seller->id, 'user_id' => $seller->id,
            'seller_id' => $seller->id, 'company_name' => 'CV X', 'user_role' => 'distributor',
            'status' => PurchaseOrder::STATUS_PENDING, 'subtotal' => 0, 'total_amount' => 0,
            'payment_status' => PurchaseOrder::PAYMENT_UNPAID,
        ]);

        $this->assertSame($seller->id, (int) $po->fresh()->seller_id);
        $this->assertSame($seller->id, $po->seller->id);
    }

    public function test_seller_id_boleh_null_hq(): void
    {
        $buyer = $this->user(User::ROLE_DISTRIBUTOR);
        $po = PurchaseOrder::create([
            'po_number' => 'SKN-PO-TEST-2', 'created_by' => $buyer->id, 'user_id' => $buyer->id,
            'company_name' => 'CV Y', 'user_role' => 'distributor',
            'status' => PurchaseOrder::STATUS_PENDING, 'subtotal' => 0, 'total_amount' => 0,
            'payment_status' => PurchaseOrder::PAYMENT_UNPAID,
        ]);

        $this->assertNull($po->fresh()->seller_id);
        $this->assertNull($po->seller);
    }
}
