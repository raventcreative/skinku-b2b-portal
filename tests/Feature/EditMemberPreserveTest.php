<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EditMemberPreserveTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Payload LENGKAP (seperti modal yang pre-fill) → phone/address harus TETAP. */
    public function test_ganti_role_dengan_payload_lengkap_pertahankan_phone_address(): void
    {
        $sa = $this->superAdmin();
        $dist = User::create([
            'name' => 'D', 'fullname' => 'DIST', 'username' => 'distx', 'email' => 'distx@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'distributor', 'status' => User::STATUS_ACTIVE,
            'phone' => '081234567890', 'address' => 'Jl. Melati No. 5', 'region' => 'Jabar',
        ]);

        $this->actingAs($sa)->put(route('users.update', $dist), [
            'fullname' => 'DIST', 'email' => 'distx@skinku.test', 'username' => 'distx',
            'role' => 'grand_distributor',
            'phone' => '081234567890', 'address' => 'Jl. Melati No. 5', 'region' => 'Jabar',
            'status' => 'active',
        ])->assertRedirect();

        $dist->refresh();
        $this->assertSame('grand_distributor', $dist->role);
        $this->assertSame('081234567890', $dist->phone);
        $this->assertSame('Jl. Melati No. 5', $dist->address);
    }

    /** Payload TANPA phone/address (form tak submit) → ke-wipe? ini yang bikin "hilang". */
    public function test_ganti_role_tanpa_phone_address_ke_wipe(): void
    {
        $sa = $this->superAdmin();
        $dist = User::create([
            'name' => 'D2', 'fullname' => 'DIST2', 'username' => 'dist2', 'email' => 'dist2@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'distributor', 'status' => User::STATUS_ACTIVE,
            'phone' => '081200000000', 'address' => 'Jl. Lama',
        ]);

        $this->actingAs($sa)->put(route('users.update', $dist), [
            'fullname' => 'DIST2', 'email' => 'dist2@skinku.test', 'username' => 'dist2',
            'role' => 'grand_distributor', 'status' => 'active',
            // sengaja TANPA phone & address
        ])->assertRedirect();

        $dist->refresh();
        // Dokumentasi perilaku sekarang:
        $this->assertNull($dist->phone, 'phone ke-wipe jika payload tak mengirimnya');
        $this->assertNull($dist->address, 'address ke-wipe jika payload tak mengirimnya');
    }
}
