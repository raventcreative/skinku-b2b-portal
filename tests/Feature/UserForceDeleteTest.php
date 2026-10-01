<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Hapus permanen akun terhapus (salah input): hanya Super Admin, hanya akun soft-deleted, ditolak bila punya riwayat
 * bisnis / downline; berhasil → baris hilang, email & username bisa dipakai lagi, tercatat di audit.
 */
class UserForceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u, bool $hapus = false): User
    {
        $x = User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
        if ($hapus) {
            $x->status = User::STATUS_DELETED;
            $x->save();
            $x->delete();
        }

        return $x;
    }

    public function test_super_admin_hapus_permanen_akun_salah_input_lalu_email_username_bisa_dipakai_lagi(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $salah = $this->user(User::ROLE_RESELLER_BRONZE, 'dewi', true);

        $this->actingAs($super)->delete(route('users.force-destroy', $salah->id))
            ->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'dihapus permanen'));

        $this->assertNull(User::withTrashed()->find($salah->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'force_delete_user', 'target_id' => $salah->id]);
        $this->user(User::ROLE_RESELLER_BRONZE, 'dewi'); // email & username bebas lagi (tak bentrok unique)
        $this->assertSame(1, User::where('username', 'dewi')->count());
    }

    public function test_ditolak_bila_belum_dihapus_bukan_super_admin_atau_punya_riwayat(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $admin = $this->user(User::ROLE_ADMIN, 'adm');
        $aktif = $this->user(User::ROLE_RESELLER, 'aktif');
        $this->actingAs($super)->delete(route('users.force-destroy', $aktif->id))->assertSessionHasErrors('user');
        $this->assertNotNull(User::find($aktif->id));

        $del = $this->user(User::ROLE_RESELLER, 'del', true);
        $this->actingAs($admin)->delete(route('users.force-destroy', $del->id))->assertForbidden();

        // Punya riwayat PO → ditolak, tetap ada sbg akun terhapus.
        DB::table('purchase_orders')->insert(['user_id' => $del->id, 'po_number' => 'PO-1', 'status' => 'pending', 'total_amount' => 0, 'created_by' => $super->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($super)->delete(route('users.force-destroy', $del->id))->assertSessionHasErrors('user');
        $this->assertStringContainsString('purchase order', session('errors')->first('user'));
        $this->assertNotNull(User::withTrashed()->find($del->id));

        // Masih jadi upline → ditolak.
        $upline = $this->user(User::ROLE_DISTRIBUTOR, 'up', true);
        $anak = $this->user(User::ROLE_RESELLER, 'anak');
        $anak->update(['upline_id' => $upline->id]);
        $this->actingAs($super)->delete(route('users.force-destroy', $upline->id))->assertSessionHasErrors('user');
        $this->assertStringContainsString('downline', session('errors')->first('user'));
    }
}
