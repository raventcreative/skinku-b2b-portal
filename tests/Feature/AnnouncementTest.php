<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Kelola pengumuman butuh manage_announcements (default: hanya super admin). */
    public function test_hanya_pemegang_izin_bisa_kelola(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN, 'annadm'))->get(route('announcements.manage'))->assertForbidden();
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'annsuper'))->get(route('announcements.manage'))->assertOk();
    }

    public function test_catatan_tampil_di_dashboard_role_sasaran_saja(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'annsuper2');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1',
            'note_title' => 'Promo Juli', 'note_body' => 'Diskon 10% semua produk.',
        ])->assertRedirect();

        // Distributor melihat catatannya.
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'anndist'))->get(route('dashboard'))->assertOk()
            ->assertSee('Promo Juli')->assertSee('Diskon 10% semua produk.');

        // Reseller (role lain) TIDAK melihat catatan distributor.
        $this->actingAs($this->user(User::ROLE_RESELLER, 'annresel'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('Promo Juli');
    }

    /** URL di isi catatan otomatis jadi tautan; tombol link tampil bila diisi. */
    public function test_catatan_url_jadi_tautan_dan_tombol_link(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'annlink');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1',
            'note_body' => 'Aset: https://drive.google.com/drive/folders/ABC',
            'note_link' => 'https://drive.google.com/folder', 'note_link_label' => 'Buka Drive',
        ])->assertRedirect();

        $html = $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'annlinkd'))
            ->get(route('dashboard'))->assertOk()->getContent();

        // URL di dalam isi jadi <a href> (auto-hyperlink).
        $this->assertStringContainsString('href="https://drive.google.com/drive/folders/ABC"', $html);
        // Tombol link terpisah + label kustom.
        $this->assertStringContainsString('https://drive.google.com/folder', $html);
        $this->assertStringContainsString('Buka Drive', $html);
    }

    /** Catatan boleh tampil dengan HANYA tombol link (tanpa isi teks). */
    public function test_catatan_hanya_tombol_link_tetap_tampil(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'annlink2');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_RESELLER, 'note_enabled' => '1', 'note_title' => 'Katalog',
            'note_link' => 'https://skinku.id/katalog',
        ])->assertRedirect();

        $this->actingAs($this->user(User::ROLE_RESELLER, 'annlink2d'))->get(route('dashboard'))->assertOk()
            ->assertSee('Katalog')->assertSee('Klik di sini', false);   // label default
    }

    public function test_catatan_nonaktif_atau_kosong_tak_tampil(): void
    {
        Announcement::create(['role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => false, 'note_body' => 'rahasia']);
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'anndist2'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('rahasia');
    }

    public function test_popup_banner_muncul_sekali_per_sesi_login(): void
    {
        Storage::fake('public');
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'annsuper3');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'banner_enabled' => '1',
            'banner' => UploadedFile::fake()->image('banner.jpg', 1200, 400),
            'banner_link' => 'https://skinku.id/promo',
        ])->assertRedirect();

        $this->assertTrue(Announcement::where('role', User::ROLE_DISTRIBUTOR)->first()->bannerVisible());

        $dist = $this->user(User::ROLE_DISTRIBUTOR, 'anndist3');
        // Pertama kali → popup muncul (banner + link klik).
        $this->actingAs($dist)->get(route('dashboard'))->assertOk()
            ->assertSee('annBanner', false)->assertSee('https://skinku.id/promo', false);
        // Kedua kali di sesi sama → tak muncul lagi.
        $this->get(route('dashboard'))->assertOk()->assertDontSee('annBanner', false);
    }

    public function test_hapus_banner(): void
    {
        Storage::fake('public');
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'annsuper4');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_RESELLER, 'banner_enabled' => '1',
            'banner' => UploadedFile::fake()->image('b.jpg'),
        ])->assertRedirect();
        $ann = Announcement::where('role', User::ROLE_RESELLER)->first();
        $this->assertNotNull($ann->bannerUrl());

        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_RESELLER, 'banner_enabled' => '1', 'remove_banner' => '1',
        ])->assertRedirect();
        $this->assertNull($ann->fresh()->bannerUrl());
    }

    /** super_admin tak bisa dijadikan sasaran pengumuman (dia yang mengatur). */
    public function test_super_admin_bukan_sasaran(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'annsuper5');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_SUPER_ADMIN, 'note_enabled' => '1', 'note_body' => 'x',
        ])->assertSessionHasErrors('role');
    }
}
