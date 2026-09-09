<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderResiTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role), 'username' => $role,
            'email' => $role.'@skinku.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function po(User $owner): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-RESI-1', 'created_by' => $owner->id, 'user_id' => $owner->id,
            'status' => PurchaseOrder::STATUS_PROCESSING, 'total_amount' => 100_000, 'user_role' => $owner->role,
        ]);
    }

    public function test_staf_simpan_resi(): void
    {
        $admin = $this->make(User::ROLE_ADMIN);
        $po = $this->po($admin);

        $this->actingAs($admin)
            ->post(route('purchase-orders.resi', $po), ['kurir' => 'J&T Express', 'no_resi' => 'JT999'])
            ->assertRedirect();

        $fresh = $po->fresh();
        $this->assertSame('J&T Express', $fresh->kurir);
        $this->assertSame('JT999', $fresh->no_resi);
    }

    public function test_mitra_tidak_boleh_simpan_resi(): void
    {
        $reseller = $this->make(User::ROLE_RESELLER);
        $po = $this->po($reseller);

        $this->actingAs($reseller)
            ->post(route('purchase-orders.resi', $po), ['kurir' => 'X', 'no_resi' => 'Y'])
            ->assertForbidden();
    }
}
