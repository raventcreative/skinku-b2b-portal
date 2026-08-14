<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberIdPopupTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_buat_mitra_member_id_muncul_di_pesan_dan_tersimpan(): void
    {
        $sa = $this->superAdmin();

        $response = $this->actingAs($sa)->post(route('users.store'), [
            'fullname' => 'Distri Baru', 'email' => 'distbaru@skinku.test', 'username' => 'distbaru',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);

        $response->assertRedirect();

        $user = User::where('username', 'distbaru')->firstOrFail();
        $this->assertMatchesRegularExpression('/^SKN-\d{6}$/', $user->member_id);

        $response->assertSessionHas('status', function ($value) {
            return str_contains($value, 'Member ID:');
        });
    }

    public function test_index_mengirim_next_member_id_dan_dirender_di_form(): void
    {
        $sa = $this->superAdmin();

        $response = $this->actingAs($sa)->get(route('users.index'));

        $response->assertOk();
        $response->assertViewHas('nextMemberId');

        $nextMemberId = $response->viewData('nextMemberId');
        $this->assertMatchesRegularExpression('/^SKN-\d{6}$/', $nextMemberId);
        $response->assertSee($nextMemberId);
    }

    public function test_buat_non_mitra_member_id_tetap_null(): void
    {
        $sa = $this->superAdmin();

        $this->actingAs($sa)->post(route('users.store'), [
            'fullname' => 'Gudang Baru', 'email' => 'gudangbaru@skinku.test', 'username' => 'gudangbaru',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => User::ROLE_GUDANG, 'status' => User::STATUS_ACTIVE,
        ])->assertRedirect();

        $user = User::where('username', 'gudangbaru')->firstOrFail();
        $this->assertNull($user->member_id);
    }
}
