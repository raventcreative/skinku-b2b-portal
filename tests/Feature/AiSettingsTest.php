<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 6: pilih provider/model Asisten AI di Pengaturan Sistem. Key tetap di
 * .env — halaman ini cuma memilih.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_panel_ai_tampil_saat_ada_key(): void
    {
        config()->set('services.ai.openai.key', 'sk-test');

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))->get(route('settings.index'))->assertOk()
            ->assertSee('Asisten AI')->assertSee('OpenAI')->assertSee('gpt-4o-mini');
    }

    public function test_peringatan_saat_tanpa_key(): void
    {
        config()->set('services.ai.openai.key', null);
        config()->set('services.ai.anthropic.key', null);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))->get(route('settings.index'))->assertOk()
            ->assertSee('Belum ada provider');
    }

    public function test_simpan_provider_dan_model(): void
    {
        config()->set('services.ai.openai.key', 'sk-test');

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))
            ->post(route('settings.ai.save'), ['ai_provider' => 'openai', 'ai_model' => 'gpt-4o'])
            ->assertRedirect();

        $this->assertSame('openai', AppSetting::get('ai_provider'));
        $this->assertSame('gpt-4o', AppSetting::get('ai_model'));
    }

    public function test_tolak_provider_tanpa_key(): void
    {
        config()->set('services.ai.openai.key', 'sk-test');
        config()->set('services.ai.anthropic.key', null);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))
            ->post(route('settings.ai.save'), ['ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-5'])
            ->assertSessionHasErrors('ai_provider');
        $this->assertNull(AppSetting::get('ai_provider'));
    }

    public function test_tanpa_key_sama_sekali_tolak_simpan(): void
    {
        config()->set('services.ai.openai.key', null);
        config()->set('services.ai.anthropic.key', null);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))
            ->post(route('settings.ai.save'), ['ai_provider' => 'openai', 'ai_model' => 'gpt-4o-mini'])
            ->assertRedirect();
        $this->assertNull(AppSetting::get('ai_provider'));
    }

    public function test_bukan_pemegang_system_settings_ditolak(): void
    {
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dist'))
            ->post(route('settings.ai.save'), ['ai_provider' => 'openai', 'ai_model' => 'gpt-4o-mini'])
            ->assertForbidden();
    }
}
