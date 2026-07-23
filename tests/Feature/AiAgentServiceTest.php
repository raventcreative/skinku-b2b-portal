<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiTurn;
use App\Services\Ai\Tools\BaseTool;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Fase 2: loop agent + registry. Pakai FakeAiProvider (tanpa jaringan) + alat
 * dummy (anonymous class).
 */
class AiAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = User::ROLE_SUPER_ADMIN, string $u = 'freddie'): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function readTool(): BaseTool
    {
        return new class extends BaseTool
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'baca_tes';
            }

            public function description(): string
            {
                return 'baca angka tes';
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => (object) []];
            }

            public function run(array $args, User $user): array
            {
                $this->calls++;

                return ['angka' => 42];
            }
        };
    }

    private function writeTool(?string $validateErr = null): BaseTool
    {
        return new class($validateErr) extends BaseTool
        {
            public bool $ran = false;

            public function __construct(private ?string $validateErr) {}

            public function name(): string
            {
                return 'tulis_tes';
            }

            public function description(): string
            {
                return 'bikin sesuatu';
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => ['judul' => ['type' => 'string']]];
            }

            public function isWrite(): bool
            {
                return true;
            }

            public function validate(array $args, User $user): ?string
            {
                return $this->validateErr;
            }

            public function previewText(array $args, User $user): string
            {
                return 'Buat: '.($args['judul'] ?? '?');
            }

            public function run(array $args, User $user): array
            {
                $this->ran = true;

                return ['ok' => true];
            }
        };
    }

    public function test_loop_alat_baca_lalu_jawaban(): void
    {
        $read = $this->readTool();
        $fake = new FakeAiProvider([
            new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'baca_tes', 'arguments' => []]]),
            new AiTurn(text: 'Angkanya 42.'),
        ]);
        $out = (new AiAgentService($fake, new ToolRegistry([$read])))->run($this->user(), [], 'berapa angkanya?');

        $this->assertSame('text', $out['result']->type);
        $this->assertSame('Angkanya 42.', $out['result']->text);
        $this->assertSame(1, $read->calls);
        // Riwayat disimpan teks-saja: user + assistant (round-trip alat tak masuk).
        $this->assertCount(2, $out['history']);
        $this->assertSame('user', $out['history'][0]['role']);
        $this->assertSame('assistant', $out['history'][1]['role']);
    }

    public function test_alat_tulis_minta_konfirmasi_tanpa_eksekusi(): void
    {
        $write = $this->writeTool();
        $fake = new FakeAiProvider([
            new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'tulis_tes', 'arguments' => ['judul' => 'Beli stok']]]),
        ]);
        $out = (new AiAgentService($fake, new ToolRegistry([$write])))->run($this->user(), [], 'buatkan tugas beli stok');

        $this->assertSame('confirm', $out['result']->type);
        $this->assertSame('tulis_tes', $out['result']->pending['tool']);
        $this->assertSame('Beli stok', $out['result']->pending['args']['judul']);
        $this->assertSame('Buat: Beli stok', $out['result']->pending['preview']);
        $this->assertFalse($write->ran);           // TIDAK dieksekusi
        $this->assertCount(1, $out['history']);     // cuma pesan user
    }

    public function test_alat_tulis_argumen_kurang_bikin_ai_tanya_balik(): void
    {
        $write = $this->writeTool('Papan mana? sebutkan dulu.');
        $fake = new FakeAiProvider([
            new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'tulis_tes', 'arguments' => []]]),
            new AiTurn(text: 'Mau ditaruh di papan mana ya?'),
        ]);
        $out = (new AiAgentService($fake, new ToolRegistry([$write])))->run($this->user(), [], 'buatkan tugas');

        $this->assertSame('text', $out['result']->type);
        $this->assertSame('Mau ditaruh di papan mana ya?', $out['result']->text);
        $this->assertFalse($write->ran);
        $last = $this->lastMessage($fake->sent[1]['messages']);
        $this->assertSame('tool', $last['role']);
        $this->assertStringContainsString('perlu_klarifikasi', $last['content']);
    }

    public function test_batas_iterasi_kepegang(): void
    {
        config()->set('services.ai.max_iterations', 3);
        $read = $this->readTool();
        $fake = new FakeAiProvider(array_fill(0, 6, new AiTurn(toolCalls: [['id' => 'c', 'name' => 'baca_tes', 'arguments' => []]])));
        $out = (new AiAgentService($fake, new ToolRegistry([$read])))->run($this->user(), [], 'loop terus');

        $this->assertSame('text', $out['result']->type);
        $this->assertStringContainsString('kepanjangan', $out['result']->text);
        $this->assertSame(3, $read->calls);   // dijalankan tepat batas iterasi
    }

    public function test_alat_tak_dikenal_disuapkan_dan_lanjut(): void
    {
        $fake = new FakeAiProvider([
            new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'ga_ada', 'arguments' => []]]),
            new AiTurn(text: 'Maaf, itu di luar kemampuanku.'),
        ]);
        $out = (new AiAgentService($fake, new ToolRegistry([$this->readTool()])))->run($this->user(), [], 'pakai alat aneh');

        $this->assertSame('text', $out['result']->type);
        $last = $this->lastMessage($fake->sent[1]['messages']);
        $this->assertSame('tool', $last['role']);
        $this->assertStringContainsString('tidak tersedia', $last['content']);
    }

    public function test_alat_disaring_per_izin(): void
    {
        $tool = new class extends BaseTool
        {
            public function name(): string
            {
                return 'rahasia';
            }

            public function description(): string
            {
                return 'butuh izin akuntansi';
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => (object) []];
            }

            public function permission(): ?string
            {
                return 'view_accounting';
            }

            public function run(array $args, User $user): array
            {
                return ['x' => 1];
            }
        };
        $reg = new ToolRegistry([$tool]);

        $dist = $this->user(User::ROLE_DISTRIBUTOR, 'dist');
        $this->assertCount(0, $reg->forUser($dist));
        $this->assertNull($reg->find('rahasia', $dist));
        // super_admin selalu punya semua izin.
        $this->assertCount(1, $reg->forUser($this->user()));
    }

    /** @param  array<int,array<string,mixed>>  $messages */
    private function lastMessage(array $messages): array
    {
        return $messages[array_key_last($messages)];
    }
}
