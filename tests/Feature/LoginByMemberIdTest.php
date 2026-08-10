<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginByMemberIdTest extends TestCase
{
    use RefreshDatabase;

    private function mk(): User
    {
        return User::create([
            'name' => 'mit', 'fullname' => 'MITRA', 'username' => 'mitra1', 'email' => 'mitra1@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'distributor', 'status' => User::STATUS_ACTIVE,
            'member_id' => 'SKN-000123',
        ]);
    }

    public function test_login_pakai_member_id(): void
    {
        $this->mk();
        $this->post(route('login'), ['login' => 'SKN-000123', 'password' => 'secret123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_username_masih_jalan(): void
    {
        $this->mk();
        $this->post(route('login'), ['login' => 'mitra1', 'password' => 'secret123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_member_id_salah_ditolak(): void
    {
        $this->mk();
        $this->post(route('login'), ['login' => 'SKN-999999', 'password' => 'secret123'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_non_aktif_tetap_diblok(): void
    {
        $u = $this->mk();
        $u->update(['status' => User::STATUS_INACTIVE]);
        $this->post(route('login'), ['login' => 'SKN-000123', 'password' => 'secret123'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }
}
