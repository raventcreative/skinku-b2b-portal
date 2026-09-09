<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderShowCetakButtonTest extends TestCase
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
            'po_number' => 'PO-BTN-1', 'created_by' => $owner->id, 'user_id' => $owner->id,
            'status' => PurchaseOrder::STATUS_PROCESSING, 'total_amount' => 100_000, 'user_role' => $owner->role,
        ]);
    }

    public function test_staf_lihat_tombol_cetak(): void
    {
        $admin = $this->make(User::ROLE_ADMIN);
        $po = $this->po($admin);

        $this->actingAs($admin)->get(route('purchase-orders.show', $po))
            ->assertOk()->assertSee('Cetak Dokumen');
    }

    public function test_mitra_tidak_lihat_tombol_cetak(): void
    {
        $reseller = $this->make(User::ROLE_RESELLER);
        $po = $this->po($reseller);

        $this->actingAs($reseller)->get(route('purchase-orders.show', $po))
            ->assertOk()->assertDontSee('Cetak Dokumen');
    }

    public function test_po_selesai_sembunyikan_tombol_cetak_dan_form_resi(): void
    {
        // Samakan dengan form Ongkir: begitu PO completed/cancelled/deleted,
        // form Kurir & Resi dan tombol Cetak Dokumen tidak muncul lagi.
        $admin = $this->make(User::ROLE_ADMIN);
        $po = $this->po($admin);
        $po->update(['status' => PurchaseOrder::STATUS_COMPLETED]);

        $this->actingAs($admin)->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertDontSee('Cetak Dokumen')
            ->assertDontSee('Kurir & Resi');
    }
}
