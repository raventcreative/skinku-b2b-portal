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

    public function test_po_completed_tetap_tampilkan_cetak_dan_resi(): void
    {
        // PO completed JUSTRU sering perlu cetak faktur/label + koreksi resi,
        // jadi tombol Cetak & form Resi tetap muncul di status completed.
        $admin = $this->make(User::ROLE_ADMIN);
        $po = $this->po($admin);
        $po->update(['status' => PurchaseOrder::STATUS_COMPLETED]);

        // Pakai teks tanpa '&' — assertSee meng-escape '&' jadi '&amp;' sehingga
        // 'Kurir & Resi' (teks statis) tak pernah cocok. 'Simpan Resi' = tombol form resi.
        $this->actingAs($admin)->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('Cetak Dokumen')
            ->assertSee('Simpan Resi');
    }

    public function test_po_batal_sembunyikan_cetak_dan_resi(): void
    {
        // PO batal/hapus = void, tak perlu cetak label/isi resi.
        $admin = $this->make(User::ROLE_ADMIN);
        $po = $this->po($admin);
        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        $this->actingAs($admin)->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertDontSee('Cetak Dokumen')
            ->assertDontSee('Simpan Resi');
    }
}
