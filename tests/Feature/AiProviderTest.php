<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 1: pondasi provider. Semua diuji lewat Http::fake — TANPA nyentuh API asli.
 */
class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): OpenAiProvider
    {
        return new OpenAiProvider('sk-test', 'https://api.openai.com/v1', 'gpt-4o-mini', 1500);
    }

    private function fakeReply(array $message): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => $message]]], 200)]);
    }

    public function test_kirim_request_benar_dan_baca_teks(): void
    {
        $this->fakeReply(['content' => 'Halo, ini ringkasannya.']);

        $turn = $this->provider()->chat(
            [['role' => 'system', 'content' => 'kamu asisten'], ['role' => 'user', 'content' => 'hai']],
            [['name' => 'ringkas_dashboard', 'description' => 'baca', 'parameters' => ['type' => 'object', 'properties' => (object) []]]],
        );

        $this->assertFalse($turn->wantsTools());
        $this->assertSame('Halo, ini ringkasannya.', $turn->text);

        Http::assertSent(function ($req) {
            $b = $req->data();

            return $req->url() === 'https://api.openai.com/v1/chat/completions'
                && $b['model'] === 'gpt-4o-mini'
                && $b['max_tokens'] === 1500
                && $b['tool_choice'] === 'auto'
                && $b['messages'][0]['role'] === 'system'
                && $b['tools'][0]['type'] === 'function'
                && $b['tools'][0]['function']['name'] === 'ringkas_dashboard'
                && $req->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    public function test_baca_permintaan_panggil_alat(): void
    {
        $this->fakeReply(['tool_calls' => [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'buat_kartu_kanban', 'arguments' => '{"judul":"Tes","kolom":"To Do"}'],
        ]]]);

        $turn = $this->provider()->chat([['role' => 'user', 'content' => 'buatkan kartu']], []);

        $this->assertTrue($turn->wantsTools());
        $this->assertCount(1, $turn->toolCalls);
        $this->assertSame('buat_kartu_kanban', $turn->toolCalls[0]['name']);
        $this->assertSame('Tes', $turn->toolCalls[0]['arguments']['judul']);
        $this->assertSame('call_1', $turn->toolCalls[0]['id']);
    }

    public function test_petakan_riwayat_toolcall_dan_hasil_alat(): void
    {
        $this->fakeReply(['content' => 'oke']);

        $this->provider()->chat([
            ['role' => 'user', 'content' => 'x'],
            ['role' => 'assistant', 'tool_calls' => [['id' => 'call_9', 'name' => 'ringkas_dashboard', 'arguments' => ['bulan' => '2026-06']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => '{"omzet":100}'],
        ], []);

        Http::assertSent(function ($req) {
            $m = $req->data()['messages'];

            return $m[1]['role'] === 'assistant'
                && $m[1]['tool_calls'][0]['id'] === 'call_9'
                && $m[1]['tool_calls'][0]['function']['name'] === 'ringkas_dashboard'
                && $m[1]['tool_calls'][0]['function']['arguments'] === '{"bulan":"2026-06"}'
                && $m[2]['role'] === 'tool'
                && $m[2]['tool_call_id'] === 'call_9'
                && $m[2]['content'] === '{"omzet":100}';
        });
    }

    public function test_error_401_jadi_pesan_ramah(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        $this->provider()->chat([['role' => 'user', 'content' => 'hai']], []);
    }

    public function test_factory_pilih_openai_dan_daftar_tersedia(): void
    {
        config()->set('services.ai.openai.key', 'sk-test');
        config()->set('services.ai.anthropic.key', null);
        config()->set('services.ai.default_model', 'gpt-4o-mini');

        $this->assertInstanceOf(OpenAiProvider::class, AiProviderFactory::make());
        $this->assertSame(['openai' => 'OpenAI'], AiProviderFactory::available());
    }

    public function test_factory_tolak_kalau_key_kosong(): void
    {
        config()->set('services.ai.openai.key', null);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        AiProviderFactory::make();
    }

    public function test_factory_hormati_appsetting_anthropic_belum_didukung(): void
    {
        config()->set('services.ai.openai.key', 'sk-test');
        AppSetting::put('ai_provider', 'anthropic');

        $this->expectException(AiException::class);
        AiProviderFactory::make();
    }
}
