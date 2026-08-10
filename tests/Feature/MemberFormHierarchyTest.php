<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberFormHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function grand(): User
    {
        return User::create([
            'name' => 'g', 'fullname' => 'GRAND', 'username' => 'grand', 'email' => 'grand@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'grand_distributor', 'status' => User::STATUS_ACTIVE,
            'member_id' => 'SKN-000001',
        ]);
    }

    public function test_buat_distributor_dengan_upline_dan_member_id(): void
    {
        $sa = $this->superAdmin();
        $grand = $this->grand();

        $this->actingAs($sa)->post(route('users.store'), [
            'fullname' => 'Distri Satu', 'email' => 'dist1@skinku.test', 'username' => 'dist1',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'distributor', 'upline_id' => $grand->id, 'status' => User::STATUS_ACTIVE,
        ])->assertRedirect();

        $dist = User::where('username', 'dist1')->firstOrFail();
        $this->assertSame($grand->id, $dist->upline_id);
        $this->assertNotNull($dist->member_id);
        $this->assertStringStartsWith('SKN-', $dist->member_id);
    }

    public function test_tolak_upline_level_salah_lewat_form(): void
    {
        $sa = $this->superAdmin();
        $grand = $this->grand();

        // reseller_bronze induknya harus distributor, bukan grand → ditolak
        $this->actingAs($sa)->post(route('users.store'), [
            'fullname' => 'Res Satu', 'email' => 'res1@skinku.test', 'username' => 'res1',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'reseller_bronze', 'upline_id' => $grand->id, 'status' => User::STATUS_ACTIVE,
        ])->assertSessionHasErrors('upline_id');

        $this->assertNull(User::where('username', 'res1')->first());
    }

    public function test_member_id_tampil_di_daftar(): void
    {
        $sa = $this->superAdmin();
        $this->grand();
        $this->actingAs($sa)->get(route('users.index'))->assertOk()->assertSee('SKN-000001');
    }

    public function test_tak_bisa_hapus_upline_yang_punya_downline(): void
    {
        $sa = $this->superAdmin();
        $grand = $this->grand();
        User::create([
            'name' => 'd', 'fullname' => 'DIST', 'username' => 'distx', 'email' => 'distx@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'distributor', 'status' => User::STATUS_ACTIVE,
            'upline_id' => $grand->id,
        ]);

        $this->actingAs($sa)->delete(route('users.destroy', $grand))->assertSessionHasErrors('user');
        $this->assertNotNull(User::find($grand->id));
    }
}
