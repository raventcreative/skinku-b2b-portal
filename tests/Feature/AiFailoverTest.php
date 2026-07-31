<?php

namespace Tests\Feature;

use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiTurn;
use App\Services\Ai\ConcurrentAiProvider;
use App\Services\Ai\FailoverAiProvider;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFailoverTest extends TestCase
{
    use RefreshDatabase;

    private function failing(string $message, bool $transient = false): ConcurrentAiProvider
    {
        return new class($message, $transient) implements ConcurrentAiProvider
        {
            public function __construct(private string $message, private bool $transient) {}

            public function chat(array $messages, array $tools): AiTurn
            {
                throw new AiException($this->message, transient: $this->transient);
            }

            public function chatMany(array $requests): array
            {
                throw new AiException($this->message, transient: $this->transient);
            }
        };
    }

    private function replying(string $text): ConcurrentAiProvider
    {
        return new class($text) implements ConcurrentAiProvider
        {
            public function __construct(private string $text) {}

            public function chat(array $messages, array $tools): AiTurn
            {
                return new AiTurn(text: $this->text);
            }

            public function chatMany(array $requests): array
            {
                $out = [];
                foreach ($requests as $key => $request) {
                    $out[$key] = new AiTurn(text: $this->text);
                }

                return $out;
            }
        };
    }

    public function test_failover_ke_cadangan_saat_primary_kehabisan_kuota(): void
    {
        $chain = new FailoverAiProvider([
            $this->failing('Kuota/saldo OpenAI tidak tersedia — periksa Billing.'),
            $this->replying('jawaban dari cadangan'),
        ]);

        $this->assertSame('jawaban dari cadangan', $chain->chat([], [])->text);
    }

    public function test_primary_berhasil_cadangan_tidak_dipanggil(): void
    {
        $chain = new FailoverAiProvider([
            $this->replying('jawaban utama'),
            $this->failing('cadangan ini seharusnya TIDAK dipanggil'),
        ]);

        $this->assertSame('jawaban utama', $chain->chat([], [])->text);
    }

    public function test_semua_provider_gagal_melempar_error_terakhir(): void
    {
        $chain = new FailoverAiProvider([
            $this->failing('primary mati'),
            $this->failing('cadangan juga mati'),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('cadangan juga mati');
        $chain->chat([], []);
    }

    public function test_chatmany_ikut_failover_ke_cadangan(): void
    {
        $chain = new FailoverAiProvider([
            $this->failing('primary limit'),
            $this->replying('panel dari cadangan'),
        ]);

        $result = $chain->chatMany([
            'cmo' => ['messages' => [], 'tools' => []],
            'cfo' => ['messages' => [], 'tools' => []],
        ]);

        $this->assertSame('panel dari cadangan', $result['cmo']->text);
        $this->assertSame('panel dari cadangan', $result['cfo']->text);
    }

    public function test_factory_tanpa_backup_kembalikan_provider_tunggal(): void
    {
        config([
            'services.ai.openai.key' => 'sk-primary',
            'services.ai.backup.key' => null,
            'services.ai.backup.model' => null,
        ]);

        $this->assertInstanceOf(OpenAiProvider::class, AiProviderFactory::make());
    }

    public function test_backup_sekuensial_chatmany_tidak_pakai_pool(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok', 'tool_calls' => []]]],
            ], 200),
        ]);

        $provider = new OpenAiProvider('sk-x', 'https://9router.test/v1', 'model-x', 500, sequential: true);
        $result = $provider->chatMany([
            'cmo' => ['messages' => [], 'tools' => []],
            'cfo' => ['messages' => [], 'tools' => []],
            'coo' => ['messages' => [], 'tools' => []],
        ]);

        $this->assertSame(['cmo', 'cfo', 'coo'], array_keys($result));
        Http::assertSentCount(3); // tiga request terpisah, satu per satu
    }

    public function test_factory_dengan_backup_bangun_rantai_failover(): void
    {
        config([
            'services.ai.openai.key' => 'sk-primary',
            'services.ai.backup.key' => 'sk-backup',
            'services.ai.backup.model' => 'openai/gpt-4o-mini',
        ]);

        $provider = AiProviderFactory::make();
        $this->assertInstanceOf(FailoverAiProvider::class, $provider);
        $this->assertInstanceOf(AiProvider::class, $provider);
    }
}
