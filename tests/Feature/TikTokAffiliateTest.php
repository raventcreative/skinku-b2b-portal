<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TikTokAffiliateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_halaman_render_dan_gate_manage_tiktok(): void
    {
        // gudang tak punya manage_tiktok → forbidden
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gd'))->get(route('tiktok-affiliate.index'))->assertForbidden();

        // admin → OK
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->get(route('tiktok-affiliate.index'))
            ->assertOk()->assertSee('TikTok Affiliate API');
    }

    public function test_client_memakai_kredensial_config_key(): void
    {
        config([
            'services.tiktok.app_key' => 'shopkey', 'services.tiktok.app_secret' => 'shopsecret',
            'services.tiktok_affiliate.app_key' => 'affkey', 'services.tiktok_affiliate.app_secret' => 'affsecret',
        ]);
        $shop = new TikTokClient('tiktok');
        $aff = new TikTokClient('tiktok_affiliate');

        $this->assertTrue($aff->configured());
        // Tanda tangan beda (app_secret beda) → bukti client pakai kredensial affiliate.
        $q = ['app_key' => 'affkey', 'timestamp' => '123'];
        $this->assertNotSame($shop->sign('/x', $q), $aff->sign('/x', $q));
    }

    public function test_probe_tanpa_koneksi_ditolak(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm2'))
            ->post(route('tiktok-affiliate.probe'))->assertStatus(400);
    }
}
