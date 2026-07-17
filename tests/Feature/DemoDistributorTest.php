<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoDistributorTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'F', 'fullname' => 'Freddie', 'username' => 'freddie', 'email' => 'f@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_membuat_akun_distributor_demo_yang_aktif(): void
    {
        $this->assertSame(0, Artisan::call('demo:distributor'));

        $demo = User::where('username', 'demo_distributor')->first();
        $this->assertNotNull($demo);
        $this->assertSame(User::ROLE_DISTRIBUTOR, $demo->role);
        $this->assertSame(User::STATUS_ACTIVE, $demo->status);
        $this->assertSame('DEMO DISTRIBUTOR', $demo->company_name);
    }

    /**
     * Stok demo akan tercampur ke kolom "Stok Mitra" pada grafik Stok HQ vs
     * Mitra, yang menjumlahkan seluruh baris inventory apa adanya.
     */
    public function test_akun_demo_tidak_membawa_stok_bawaan(): void
    {
        Artisan::call('demo:distributor');
        $demo = User::where('username', 'demo_distributor')->first();

        $this->assertSame(0, Inventory::where('user_id', $demo->id)->count());
    }

    public function test_super_admin_bisa_langsung_masuk_sebagai_akun_demo(): void
    {
        $sa = $this->superAdmin();
        Artisan::call('demo:distributor');
        $demo = User::where('username', 'demo_distributor')->first();

        $this->actingAs($sa)->post("/users/{$demo->id}/impersonate")->assertRedirect(route('dashboard'));

        $this->assertSame($demo->id, auth()->id());
        $this->assertSame($sa->id, session(ImpersonationService::SESSION_KEY));
    }

    public function test_dijalankan_dua_kali_tidak_menggandakan_akun(): void
    {
        Artisan::call('demo:distributor');
        $pertama = User::where('username', 'demo_distributor')->first();
        $passwordPertama = $pertama->password;

        $this->assertSame(0, Artisan::call('demo:distributor'));

        $this->assertSame(1, User::where('username', 'demo_distributor')->count());
        // Tanpa --reset-password, password tak ikut berubah.
        $this->assertSame($passwordPertama, $pertama->fresh()->password);
    }

    public function test_menghidupkan_kembali_akun_demo_yang_dinonaktifkan_atau_dihapus(): void
    {
        Artisan::call('demo:distributor');
        $demo = User::where('username', 'demo_distributor')->first();
        $demo->status = User::STATUS_INACTIVE;
        $demo->save();
        $demo->delete();

        Artisan::call('demo:distributor');

        $lagi = User::where('username', 'demo_distributor')->first();
        $this->assertNotNull($lagi);          // tak lagi ter-soft-delete
        $this->assertSame(User::STATUS_ACTIVE, $lagi->status);
    }

    public function test_reset_password_mengganti_password_dan_menampilkannya_sekali(): void
    {
        Artisan::call('demo:distributor');
        $lama = User::where('username', 'demo_distributor')->first()->password;

        $this->assertSame(0, Artisan::call('demo:distributor', ['--reset-password' => true]));
        $out = Artisan::output();

        $this->assertNotSame($lama, User::where('username', 'demo_distributor')->first()->password);
        $this->assertStringContainsString('Password acak', $out);
        $this->assertSame(1, User::where('username', 'demo_distributor')->count());
    }

    public function test_password_akun_demo_tidak_bisa_ditebak(): void
    {
        Artisan::call('demo:distributor');
        $demo = User::where('username', 'demo_distributor')->first();

        // Akun demo dipakai lewat "Masuk sebagai", bukan login. Password tebakan
        // yang lazim tak boleh membuka pintu.
        foreach (['demo', 'password', 'demo_distributor', 'secret123', '12345678'] as $tebakan) {
            $this->assertFalse(Hash::check($tebakan, $demo->password));
        }
    }
}
