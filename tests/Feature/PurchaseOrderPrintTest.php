<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role).' Name', 'username' => $role,
            'email' => $role.'@skinku.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function poWithItem(User $mitra): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-PRINT-1', 'created_by' => $mitra->id, 'user_id' => $mitra->id,
            'status' => PurchaseOrder::STATUS_PROCESSING, 'subtotal' => 100_000, 'discount' => 0,
            'shipping_cost' => 5_000, 'total_amount' => 105_000, 'user_role' => $mitra->role,
            'shipping_address' => 'Jl. Melati 2, Malang',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => 1, 'product_name' => 'Serum A', 'sku' => 'SRM-A',
            'qty' => 3, 'unit_price' => 20_000, 'total_price' => 60_000,
        ]);

        return $po;
    }

    public function test_staf_cetak_faktur_dan_label(): void
    {
        AppSetting::put('hq_sender_name', 'SKINKU PUSAT');
        $admin = $this->make(User::ROLE_ADMIN);
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra);

        $this->actingAs($admin)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'label,faktur', 'size' => 'A6']))
            ->assertOk()
            ->assertSee('SKINKU PUSAT')          // pengirim HQ
            ->assertSee('RESELLER Name')          // penerima mitra
            ->assertSee('PO-PRINT-1')             // no PO
            ->assertSee('Serum A')                // item (faktur)
            ->assertSee('105.000');               // total (faktur)
    }

    public function test_docs_ngawur_fallback_label(): void
    {
        $admin = $this->make(User::ROLE_ADMIN);
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra);

        $this->actingAs($admin)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'xxx']))
            ->assertOk()
            ->assertSee('PO-PRINT-1');
    }

    public function test_mitra_tidak_boleh_cetak(): void
    {
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra);

        $this->actingAs($mitra)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'label']))
            ->assertForbidden();
    }

    public function test_label_ada_logo_dan_barcode_dari_resi(): void
    {
        $admin = $this->make(User::ROLE_ADMIN);
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra);
        $po->update(['no_resi' => 'JT0123456789']);

        $this->actingAs($admin)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'label']))
            ->assertOk()
            ->assertSee('skinku-logo.jpg')        // logo terpasang
            ->assertSee('Skinku Official')        // default nama pengirim (hq_sender_name belum diset)
            ->assertSee('JT0123456789')           // teks di bawah barcode = resi
            ->assertDontSee('Resi belum diisi');
    }

    public function test_label_tanpa_resi_tampilkan_placeholder(): void
    {
        // Barcode HANYA dari No. Resi — tak ada fallback ke No. PO.
        $admin = $this->make(User::ROLE_ADMIN);
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra); // no_resi kosong

        $this->actingAs($admin)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'label']))
            ->assertOk()
            ->assertSee('Resi belum diisi');
    }
}
