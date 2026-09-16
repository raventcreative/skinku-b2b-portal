<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiPingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lapor_hidup_saat_otak_utama_ok(): void
    {
        config()->set('services.ai.openai.key', 'sk-test');
        config()->set('services.ai.openai.base', 'https://api.openai.com/v1');
        config()->set('services.ai.default_model', 'gpt-4o-mini');
        // Tanpa cadangan → cuma primary yang diuji.
        config()->set('services.ai.backup', []);
        config()->set('services.ai.backup2', []);
        config()->set('services.ai.backup3', []);

        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->artisan('ai:ping')->assertExitCode(0);
    }

    public function test_lapor_mati_saat_semua_gagal(): void
    {
        config()->set('services.ai.openai.key', 'sk-test');
        config()->set('services.ai.openai.base', 'https://api.openai.com/v1');
        config()->set('services.ai.backup', []);
        config()->set('services.ai.backup2', []);
        config()->set('services.ai.backup3', []);

        Http::fake(['*/chat/completions' => Http::response(['error' => 'server'], 500)]);

        $this->artisan('ai:ping')->assertExitCode(1);
    }
}
