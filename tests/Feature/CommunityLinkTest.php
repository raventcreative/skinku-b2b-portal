<?php

namespace Tests\Feature;

use App\Models\CommunityLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommunityLinkTest extends TestCase
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

    private const WA = 'https://chat.whatsapp.com/ABCDEF';

    public function test_hanya_pemegang_izin_bisa_simpan(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN, 'cadm'))
            ->post(route('announcements.community.save'), ['role' => User::ROLE_DISTRIBUTOR, 'link' => self::WA])
            ->assertForbidden();

        $this->actingAs($this->super('csuper'))
            ->post(route('announcements.community.save'), ['role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1', 'link' => self::WA])
            ->assertRedirect();
        $this->assertSame(1, CommunityLink::count());
    }

    public function test_simpan_upsert_per_role_tidak_gandakan(): void
    {
        $super = $this->super('c2');
        $this->actingAs($super)->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1', 'link' => self::WA,
        ])->assertRedirect();

        $this->actingAs($super)->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1', 'link' => 'https://chat.whatsapp.com/ZZZ',
        ])->assertRedirect();

        $this->assertSame(1, CommunityLink::where('role', User::ROLE_DISTRIBUTOR)->count());
        $this->assertSame('https://chat.whatsapp.com/ZZZ', CommunityLink::first()->link);
    }

    public function test_tombol_muncul_di_sidebar_role_sasaran_saja(): void
    {
        $this->actingAs($this->super('c3'))->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1', 'link' => self::WA, 'label' => 'Gabung Grup Distributor',
        ])->assertRedirect();

        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'cd3'))->get(route('dashboard'))->assertOk()
            ->assertSee('Gabung Grup Distributor')->assertSee(self::WA);
        $this->actingAs($this->user(User::ROLE_RESELLER, 'cr3'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('Gabung Grup Distributor');
        // Super admin bukan target -> tak ada tombol.
        $this->actingAs($this->super('cs3'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('Gabung Grup Distributor');
    }

    public function test_nonaktif_tidak_muncul(): void
    {
        $this->actingAs($this->super('c4'))->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'link' => self::WA,   // enabled tidak dicentang
        ])->assertRedirect();

        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'cd4'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('Gabung Komunitas WA');
    }

    /** Logika visible() di level model: aktif tapi tanpa link tetap tersembunyi. */
    public function test_visible_butuh_aktif_dan_link(): void
    {
        $this->assertFalse((new CommunityLink(['enabled' => true, 'link' => null]))->visible());
        $this->assertFalse((new CommunityLink(['enabled' => false, 'link' => self::WA]))->visible());
        $this->assertTrue((new CommunityLink(['enabled' => true, 'link' => self::WA]))->visible());
    }

    public function test_qr_popup_saat_ada_link_langsung_saat_tidak(): void
    {
        Storage::fake('public');
        $super = $this->super('c5');

        // Tanpa QR -> tombol berupa link langsung, tanpa dialog.
        $this->actingAs($super)->post(route('announcements.community.save'), [
            'role' => User::ROLE_RESELLER, 'enabled' => '1', 'link' => self::WA,
        ])->assertRedirect();
        $this->actingAs($this->user(User::ROLE_RESELLER, 'cr5'))->get(route('dashboard'))->assertOk()
            ->assertSee('href="'.self::WA.'"', false)->assertDontSee('communityQr', false);

        // Dengan QR -> ada dialog popup + tombol Buka WhatsApp.
        $this->actingAs($super)->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1', 'link' => self::WA,
            'qr' => UploadedFile::fake()->image('qr.png', 300, 300),
        ])->assertRedirect();
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'cd5'))->get(route('dashboard'))->assertOk()
            ->assertSee('communityQr', false)->assertSee('Buka WhatsApp');
    }

    public function test_hapus_qr_lewat_simpan(): void
    {
        Storage::fake('public');
        $super = $this->super('c6');
        $this->actingAs($super)->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1', 'link' => self::WA,
            'qr' => UploadedFile::fake()->image('qr.png'),
        ])->assertRedirect();
        $c = CommunityLink::first();
        $this->assertNotNull($c->qrUrl());

        $this->actingAs($super)->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1', 'link' => self::WA, 'remove_qr' => '1',
        ])->assertRedirect();
        $this->assertNull($c->fresh()->qrUrl());
    }

    public function test_super_admin_bukan_target(): void
    {
        $this->actingAs($this->super('c7'))->post(route('announcements.community.save'), [
            'role' => User::ROLE_SUPER_ADMIN, 'enabled' => '1', 'link' => self::WA,
        ])->assertSessionHasErrors('role');
    }

    public function test_link_wajib_bila_aktif(): void
    {
        $this->actingAs($this->super('c8'))->post(route('announcements.community.save'), [
            'role' => User::ROLE_DISTRIBUTOR, 'enabled' => '1',   // aktif tapi link kosong
        ])->assertSessionHasErrors('link');
    }

    public function test_halaman_pengumuman_render_panel_komunitas(): void
    {
        $this->actingAs($this->super('c9'))->get(route('announcements.manage'))->assertOk()
            ->assertSee('Komunitas WA per Role');
    }
}
