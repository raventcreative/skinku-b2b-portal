<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Tiru destroy(): set status deleted + soft delete. */
    private function softDeleted(string $role, string $u): User
    {
        $x = $this->user($role, $u);
        $x->status = User::STATUS_DELETED;
        $x->save();
        $x->delete();

        return $x;
    }

    public function test_super_admin_bisa_pulihkan_user_terhapus(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $t = $this->softDeleted(User::ROLE_DISTRIBUTOR, 'del1');
        $this->assertSoftDeleted('users', ['id' => $t->id]);

        $this->actingAs($super)->post(route('users.restore', $t->id))->assertRedirect();

        $fresh = User::withTrashed()->find($t->id);
        $this->assertFalse($fresh->trashed());
        $this->assertSame(User::STATUS_ACTIVE, $fresh->status);
    }

    public function test_tanpa_izin_delete_users_ditolak(): void
    {
        // admin default TIDAK punya delete_users (hanya super_admin) → route diblokir.
        $admin = $this->user(User::ROLE_ADMIN, 'adm');
        $t = $this->softDeleted(User::ROLE_DISTRIBUTOR, 'del2');

        $this->actingAs($admin)->post(route('users.restore', $t->id))->assertForbidden();
        $this->assertSoftDeleted('users', ['id' => $t->id]); // tetap terhapus
    }

    public function test_filter_terhapus_tampilkan_user_soft_deleted(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'sa2');
        $this->softDeleted(User::ROLE_DISTRIBUTOR, 'zombieuser');

        // Tampil di view "Terhapus", tak tampil di view "active".
        $this->actingAs($super)->get(route('users.index', ['status' => 'deleted']))
            ->assertOk()->assertSee('zombieuser');
        $this->actingAs($super)->get(route('users.index', ['status' => 'active']))
            ->assertOk()->assertDontSee('zombieuser');
    }
}
