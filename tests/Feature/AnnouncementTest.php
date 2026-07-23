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

    private function super(string $u): User
    {
        return $this->user(User::ROLE_SUPER_ADMIN, $u);
    }

    public function test_hanya_pemegang_izin_bisa_kelola(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN, 'annadm'))->get(route('announcements.manage'))->assertForbidden();
        $this->actingAs($this->super('annsuper'))->get(route('announcements.manage'))->assertOk();
    }

    public function test_tambah_catatan_tampil_di_dashboard_role_sasaran_saja(): void
    {
        $this->actingAs($this->super('s1'))->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1', 'note_title' => 'Promo Juli', 'note_body' => 'Diskon 10%.',
        ])->assertRedirect();
        $this->assertSame(1, Announcement::count());

        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'd1'))->get(route('dashboard'))->assertOk()
            ->assertSee('Promo Juli')->assertSee('Diskon 10%.');
        $this->actingAs($this->user(User::ROLE_RESELLER, 'r1'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('Promo Juli');
    }

    /** BANYAK box per role menumpuk di dashboard (inti rework). */
    public function test_beberapa_box_per_role_menumpuk(): void
    {
        $super = $this->super('s2');
        foreach (['Pengumuman A', 'Pengumuman B'] as $i => $t) {
            $this->actingAs($super)->post(route('announcements.save'), [
                'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1', 'note_title' => $t, 'note_body' => 'isi', 'sort_order' => $i,
            ])->assertRedirect();
        }
        $this->assertSame(2, Announcement::where('role', User::ROLE_DISTRIBUTOR)->count());

        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'd2'))->get(route('dashboard'))->assertOk()
            ->assertSee('Pengumuman A')->assertSee('Pengumuman B');
    }

    /** Simpan dengan id = EDIT item yang sama, bukan bikin baru. */
    public function test_edit_pakai_id_tidak_bikin_baru(): void
    {
        $super = $this->super('s3');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1', 'note_title' => 'Awal',
        ])->assertRedirect();
        $ann = Announcement::first();

        $this->actingAs($super)->post(route('announcements.save'), [
            'id' => $ann->id, 'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1', 'note_title' => 'Diedit',
        ])->assertRedirect();

        $this->assertSame(1, Announcement::count());          // tetap 1, bukan 2
        $this->assertSame('Diedit', $ann->fresh()->note_title);
    }

    public function test_hapus_pengumuman(): void
    {
        $super = $this->super('s4');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1', 'note_title' => 'X',
        ])->assertRedirect();
        $ann = Announcement::first();

        $this->actingAs($super)->delete(route('announcements.destroy', $ann))->assertRedirect();
        $this->assertNull(Announcement::find($ann->id));
    }

    public function test_url_jadi_tautan_dan_tombol_link(): void
    {
        $this->actingAs($this->super('s5'))->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1',
            'note_body' => 'Aset: https://drive.google.com/drive/folders/ABC',
            'note_link' => 'https://drive.google.com/folder', 'note_link_label' => 'Buka Drive',
        ])->assertRedirect();

        $html = $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'd5'))->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('href="https://drive.google.com/drive/folders/ABC"', $html);
        $this->assertStringContainsString('https://drive.google.com/folder', $html);
        $this->assertStringContainsString('Buka Drive', $html);
    }

    public function test_catatan_hanya_tombol_link_tetap_tampil(): void
    {
        $this->actingAs($this->super('s6'))->post(route('announcements.save'), [
            'role' => User::ROLE_RESELLER, 'note_enabled' => '1', 'note_title' => 'Katalog',
            'note_link' => 'https://skinku.id/katalog',
        ])->assertRedirect();

        $this->actingAs($this->user(User::ROLE_RESELLER, 'r6'))->get(route('dashboard'))->assertOk()
            ->assertSee('Katalog')->assertSee('Klik di sini', false);   // label default
    }

    public function test_popup_banner_sekali_per_sesi_login(): void
    {
        Storage::fake('public');
        $this->actingAs($this->super('s7'))->post(route('announcements.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'banner_enabled' => '1',
            'banner' => UploadedFile::fake()->image('banner.jpg', 1200, 400),
            'banner_link' => 'https://skinku.id/promo',
        ])->assertRedirect();

        $dist = $this->user(User::ROLE_DISTRIBUTOR, 'd7');
        $this->actingAs($dist)->get(route('dashboard'))->assertOk()
            ->assertSee('annBanner', false)->assertSee('https://skinku.id/promo', false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('annBanner', false);   // sesi sama → tak lagi
    }

    public function test_hapus_banner_lewat_edit(): void
    {
        Storage::fake('public');
        $super = $this->super('s8');
        $this->actingAs($super)->post(route('announcements.save'), [
            'role' => User::ROLE_RESELLER, 'banner_enabled' => '1', 'banner' => UploadedFile::fake()->image('b.jpg'),
        ])->assertRedirect();
        $ann = Announcement::first();
        $this->assertNotNull($ann->bannerUrl());

        $this->actingAs($super)->post(route('announcements.save'), [
            'id' => $ann->id, 'role' => User::ROLE_RESELLER, 'banner_enabled' => '1', 'remove_banner' => '1',
        ])->assertRedirect();
        $this->assertNull($ann->fresh()->bannerUrl());
    }

    public function test_super_admin_bukan_sasaran(): void
    {
        $this->actingAs($this->super('s9'))->post(route('announcements.save'), [
            'role' => User::ROLE_SUPER_ADMIN, 'note_enabled' => '1', 'note_body' => 'x',
        ])->assertSessionHasErrors('role');
    }

    /** Satu layar menampilkan SEMUA role; filter menyaring. */
    public function test_daftar_semua_role_dan_filter(): void
    {
        $super = $this->super('s10');
        $this->actingAs($super)->post(route('announcements.save'), ['role' => User::ROLE_DISTRIBUTOR, 'note_enabled' => '1', 'note_title' => 'Buat Distributor'])->assertRedirect();
        $this->actingAs($super)->post(route('announcements.save'), ['role' => User::ROLE_RESELLER, 'note_enabled' => '1', 'note_title' => 'Buat Reseller'])->assertRedirect();

        $this->actingAs($super)->get(route('announcements.manage'))->assertOk()
            ->assertSee('Buat Distributor')->assertSee('Buat Reseller');
        $this->actingAs($super)->get(route('announcements.manage', ['role' => User::ROLE_DISTRIBUTOR]))->assertOk()
            ->assertSee('Buat Distributor')->assertDontSee('Buat Reseller');
    }
}
