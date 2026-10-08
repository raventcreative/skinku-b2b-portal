<?php

namespace Tests\Feature;

use App\Services\Ai\AiException;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kompatibilitas dengan endpoint OpenAI-compatible pihak ketiga (OpenRouter,
 * 9router, Groq, DeepSeek). Model gratis sering menolak parameter opsional yang
 * diterima OpenAI; kalau itu bikin gagal total, upload dokumen ikut hangus.
 */
class AiCompatibilityTest extends TestCase
{
    private function provider(string $tokenParam = 'max_tokens'): OpenAiProvider
    {
        return new OpenAiProvider('sk-test', 'https://router.test/v1', 'model-gratis', 4000, 10, 5, $tokenParam);
    }

    private function balasanSukses(string $isi = '{"ok":true}'): array
    {
        return ['choices' => [['message' => ['content' => $isi]]]];
    }

    private function tolakParameter(string $param): array
    {
        return ['error' => ['message' => "Unsupported parameter: '{$param}' is not supported by this model."]];
    }

    public function test_default_memakai_max_tokens_bukan_max_completion_tokens(): void
    {
        // Mayoritas endpoint pihak ketiga hanya mengenal max_tokens.
        Http::fake(['*' => Http::response($this->balasanSukses())]);

        $this->provider()->chat([['role' => 'user', 'content' => 'halo']]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['max_tokens']) && ! isset($data['max_completion_tokens']);
        });
    }

    public function test_response_format_ditolak_maka_diulang_tanpa_parameter_itu(): void
    {
        $panggilan = 0;
        Http::fake(function () use (&$panggilan) {
            $panggilan++;

            return $panggilan === 1
                ? Http::response($this->tolakParameter('response_format'), 400)
                : Http::response($this->balasanSukses('{"total":1000}'));
        });

        $turn = $this->provider()->chat([['role' => 'user', 'content' => 'baca']], ['json' => true]);

        $this->assertSame(['total' => 1000], $turn->json());
        $this->assertSame(2, $panggilan, 'Harus dicoba ulang sekali.');

        $requests = Http::recorded();
        $this->assertArrayHasKey('response_format', $requests[0][0]->data());
        $this->assertArrayNotHasKey('response_format', $requests[1][0]->data());
    }

    public function test_parameter_token_ditolak_maka_ditukar_ke_penamaan_satunya(): void
    {
        $panggilan = 0;
        Http::fake(function () use (&$panggilan) {
            $panggilan++;

            return $panggilan === 1
                ? Http::response($this->tolakParameter('max_tokens'), 400)
                : Http::response($this->balasanSukses());
        });

        $this->provider()->chat([['role' => 'user', 'content' => 'halo']]);

        $requests = Http::recorded();
        // Batas token tidak boleh hilang begitu saja — balasan bisa terpotong
        // di tengah JSON kalau tanpa batas.
        $kedua = $requests[1][0]->data();
        $this->assertArrayNotHasKey('max_tokens', $kedua);
        $this->assertSame(4000, $kedua['max_completion_tokens']);
    }

    public function test_dua_parameter_ditolak_berturut_turut_tetap_berhasil(): void
    {
        $panggilan = 0;
        Http::fake(function () use (&$panggilan) {
            $panggilan++;

            return match ($panggilan) {
                1 => Http::response($this->tolakParameter('response_format'), 400),
                2 => Http::response($this->tolakParameter('max_tokens'), 400),
                default => Http::response($this->balasanSukses()),
            };
        });

        $this->provider()->chat([['role' => 'user', 'content' => 'halo']], ['json' => true]);

        $this->assertSame(3, $panggilan);
    }

    public function test_error_400_yang_bukan_soal_parameter_langsung_dilempar(): void
    {
        // Jangan sampai request diulang-ulang untuk error yang memang fatal.
        $panggilan = 0;
        Http::fake(function () use (&$panggilan) {
            $panggilan++;

            return Http::response(['error' => ['message' => 'The model `model-gratis` does not exist.']], 400);
        });

        $this->expectException(AiException::class);

        try {
            $this->provider()->chat([['role' => 'user', 'content' => 'halo']], ['json' => true]);
        } finally {
            $this->assertSame(1, $panggilan, 'Tidak boleh diulang.');
        }
    }

    public function test_parameter_yang_sama_ditolak_lagi_tidak_bikin_loop(): void
    {
        $panggilan = 0;
        Http::fake(function () use (&$panggilan) {
            $panggilan++;

            return Http::response($this->tolakParameter('response_format'), 400);
        });

        try {
            $this->provider()->chat([['role' => 'user', 'content' => 'halo']], ['json' => true]);
            $this->fail('Seharusnya melempar AiException.');
        } catch (AiException) {
            // Panggilan ke-2 sudah tanpa response_format; error yang sama tidak
            // boleh memicu percobaan ke-3.
            $this->assertSame(2, $panggilan);
        }
    }

    public function test_key_ditolak_tetap_pesan_jelas(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/Key API ditolak/');

        $this->provider()->chat([['role' => 'user', 'content' => 'halo']]);
    }

    public function test_gambar_dikirim_sebagai_image_url_ke_endpoint_openai_compatible(): void
    {
        Http::fake(['*' => Http::response($this->balasanSukses())]);

        $this->provider()->chat([[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'baca struk ini'],
                ['type' => 'image', 'mime' => 'image/jpeg', 'data' => base64_encode('xx')],
            ],
        ]]);

        Http::assertSent(function ($request) {
            $parts = $request->data()['messages'][0]['content'];

            return $parts[1]['type'] === 'image_url'
                && str_starts_with($parts[1]['image_url']['url'], 'data:image/jpeg;base64,');
        });
    }
}
