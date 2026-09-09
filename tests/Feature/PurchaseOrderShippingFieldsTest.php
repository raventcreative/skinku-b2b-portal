<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderShippingFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_kurir_dan_no_resi_bisa_disimpan_dan_dibaca(): void
    {
        $admin = User::create([
            'name' => 'adm', 'fullname' => 'ADM', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-SHIP-1', 'created_by' => $admin->id, 'user_id' => $admin->id,
            'status' => PurchaseOrder::STATUS_PENDING, 'total_amount' => 100_000, 'user_role' => 'super_admin',
            'kurir' => 'J&T Express', 'no_resi' => 'JT1234567890',
        ]);

        $fresh = $po->fresh();
        $this->assertSame('J&T Express', $fresh->kurir);
        $this->assertSame('JT1234567890', $fresh->no_resi);
    }
}
