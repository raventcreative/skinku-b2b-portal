<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartnerHierarchyDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_dan_role_baru_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'upline_id'));
        $this->assertTrue(Schema::hasColumn('users', 'member_id'));
        foreach (['grand_distributor', 'reseller_bronze', 'reseller_gold'] as $role) {
            $this->assertTrue(Role::where('name', $role)->exists(), "role {$role} harus di-seed");
        }
    }

    public function test_relasi_upline_downline(): void
    {
        $grand = $this->mk('grand', 'grand_distributor');
        $dist = $this->mk('dist', 'distributor', $grand->id);

        $this->assertSame($grand->id, $dist->upline->id);
        $this->assertTrue($grand->downlines->contains('id', $dist->id));
    }

    private function mk(string $u, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }
}
