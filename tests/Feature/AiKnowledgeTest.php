<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiTurn;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * "Pengetahuan AI" (memori): admin isi konteks bisnis → disuntik ke system-prompt.
 */
class AiKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function super(string $u = 'sa'): User
    {
        return $this->user(User::ROLE_SUPER_ADMIN, $u);
    }

    public function test_gate_dan_render_kotak_terpandu(): void
    {
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dst'))->get(route('ai.knowledge'))->assertForbidden();

        $this->actingAs($this->super())->get(route('ai.knowledge'))->assertOk()
            ->assertSee('Pengetahuan AI')
            ->assertSee('Tentang bisnis')
            ->assertSee('Tim & tanggung jawab')
            ->assertSee('bikin asisten tahu mau delegasi tugas', false);   // pertanyaan pemandu
    }

    public function test_simpan_pengetahuan_per_bagian(): void
    {
        $this->actingAs($this->super())->post(route('ai.knowledge.save'), [
            'content' => ['business' => 'Kami distributor B2B.', 'team' => 'Agatha = konten.', 'notes' => '   '],
        ])->assertRedirect(route('ai.knowledge'));

        $this->assertSame('Kami distributor B2B.', AiKnowledge::where('section', 'business')->value('content'));
        $this->assertSame('Agatha = konten.', AiKnowledge::where('section', 'team')->value('content'));
        $this->assertNull(AiKnowledge::where('section', 'notes')->value('content'));   // whitespace → null
    }

    public function test_pengetahuan_disuntik_ke_system_prompt(): void
    {
        AiKnowledge::updateOrCreate(['section' => 'business'], ['content' => 'SKINKU distributor skincare kode-uji-xyz.']);

        $fake = new FakeAiProvider([new AiTurn(text: 'ok')]);
        (new AiAgentService($fake, new ToolRegistry([])))->run($this->super(), [], 'halo');

        $system = $fake->sent[0]['messages'][0]['content'];
        $this->assertStringContainsString('PENGETAHUAN BISNIS', $system);
        $this->assertStringContainsString('kode-uji-xyz', $system);
    }

    public function test_tanpa_pengetahuan_tak_ada_blok(): void
    {
        $fake = new FakeAiProvider([new AiTurn(text: 'ok')]);
        (new AiAgentService($fake, new ToolRegistry([])))->run($this->super(), [], 'halo');

        $this->assertStringNotContainsString('PENGETAHUAN BISNIS', $fake->sent[0]['messages'][0]['content']);
    }

    public function test_document_kosong_saat_belum_diisi(): void
    {
        $this->assertSame('', AiKnowledge::document());
        AiKnowledge::updateOrCreate(['section' => 'rules'], ['content' => 'Jangan janji diskon.']);
        $this->assertStringContainsString('Jangan janji diskon.', AiKnowledge::document());
    }
}
