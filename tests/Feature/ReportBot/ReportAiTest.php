<?php

namespace Tests\Feature\ReportBot;

use App\Services\ReportBot\ReportAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 7: ReportAi — wrapper tipis di atas AiProviderFactory (multimodal +
 * balasan JSON). Http::fake TANPA nyentuh API asli, sama pola dengan
 * AiProviderTest.
 */
class ReportAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.ai.openai.key', 'sk-test');
        config()->set('services.ai.provider', 'openai');
        config()->set('services.ai.default_model', 'gpt-4o-mini');
        // Pastikan tak ada failover ke cadangan — satu request, deterministik.
        config()->set('services.ai.backup.key', null);
        config()->set('services.ai.backup.model', null);
    }

    private function fakeReply(string $content): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => $content]]],
        ], 200)]);
    }

    public function test_baca_file_kirim_konten_multimodal_dan_decode_json(): void
    {
        $this->fakeReply('{"ok":true}');

        $result = app(ReportAi::class)->readFile('x', 'application/pdf', 'ambil data');

        $this->assertSame(['ok' => true], $result);

        Http::assertSent(function ($req) {
            $content = $req->data()['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            foreach ($content as $part) {
                if (($part['type'] ?? null) === 'file') {
                    return $part['file']['file_data'] === 'data:application/pdf;base64,'.base64_encode('x')
                        && $part['file']['filename'] === 'doc';
                }
            }

            return false;
        });
    }

    public function test_baca_file_konten_pertama_tetap_instruksi_teks(): void
    {
        $this->fakeReply('{"ok":true}');

        app(ReportAi::class)->readFile('x', 'application/pdf', 'ambil data penting');

        Http::assertSent(function ($req) {
            $content = $req->data()['messages'][0]['content'];

            return $req->data()['messages'][0]['role'] === 'user'
                && $content[0]['type'] === 'text'
                && $content[0]['text'] === 'ambil data penting';
        });
    }

    public function test_baca_file_lepas_pagar_kode_json_dari_balasan(): void
    {
        $this->fakeReply("```json\n{\"ok\":true}\n```");

        $result = app(ReportAi::class)->readFile('x', 'application/pdf', 'ambil data');

        $this->assertSame(['ok' => true], $result);
    }

    public function test_baca_file_balasan_bukan_json_kembalikan_array_kosong(): void
    {
        $this->fakeReply('maaf saya tidak mengerti');

        $result = app(ReportAi::class)->readFile('x', 'application/pdf', 'ambil data');

        $this->assertSame([], $result);
    }

    public function test_analyze_kirim_system_dan_user_json_balikin_teks(): void
    {
        $this->fakeReply('Ringkasan: omzet naik.');

        $teks = app(ReportAi::class)->analyze('kamu analis', ['omzet' => 100]);

        $this->assertSame('Ringkasan: omzet naik.', $teks);

        Http::assertSent(function ($req) {
            $m = $req->data()['messages'];

            return $m[0]['role'] === 'system'
                && $m[0]['content'] === 'kamu analis'
                && $m[1]['role'] === 'user'
                && $m[1]['content'] === json_encode(['omzet' => 100], JSON_UNESCAPED_UNICODE);
        });
    }
}
