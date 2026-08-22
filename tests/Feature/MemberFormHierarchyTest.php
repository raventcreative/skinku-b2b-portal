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

    public function test_region_pakai_dropdown_provinsi_dan_kota(): void
    {
        $sa = $this->superAdmin();

        // Form render: dropdown provinsi + kota ketik-cari (datalist dari cascade JS @json).
        $this->actingAs($sa)->get(route('users.index'))->assertOk()
            ->assertSee('pilih provinsi')->assertSee('Jawa Timur')->assertSee('Papua Pegunungan')
            ->assertSee('Ketik / pilih kota')->assertSee('Surabaya (Kota)');

        // Simpan user dengan provinsi + kota → keduanya tersimpan persis.
        $this->actingAs($sa)->post(route('users.store'), [
            'fullname' => 'Siti Nurkana', 'email' => 'siti@t.test', 'username' => 'siti_dist',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
            'region' => 'Jawa Timur', 'city' => 'Surabaya (Kota)',
        ])->assertRedirect();
        $row = User::where('username', 'siti_dist')->first();
        $this->assertSame('Jawa Timur', $row->region);
        $this->assertSame('Surabaya (Kota)', $row->city);
    }

    public function test_kelola_anggota_bisa_disortir_per_kolom(): void
    {
        $sa = $this->superAdmin();
        foreach (['zulfa', 'brian', 'maya'] as $u) {
            User::create([
                'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
                'password' => Hash::make('secret123'), 'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
            ]);
        }

        $asc = $this->actingAs($sa)->get(route('users.index', ['sort' => 'username', 'dir' => 'asc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($asc, '>maya<'), strpos($asc, '>brian<'), 'ASC: brian sebelum maya');
        $this->assertLessThan(strpos($asc, '>zulfa<'), strpos($asc, '>maya<'), 'ASC: maya sebelum zulfa');

        $desc = $this->actingAs($sa)->get(route('users.index', ['sort' => 'username', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($desc, '>brian<'), strpos($desc, '>zulfa<'), 'DESC: zulfa sebelum brian');

        // Kolom ngawur → jatuh ke default (created_at), tetap 200.
        $this->actingAs($sa)->get(route('users.index', ['sort' => 'hack', 'dir' => 'up']))->assertOk();
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
