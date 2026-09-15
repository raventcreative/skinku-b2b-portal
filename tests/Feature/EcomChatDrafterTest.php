<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use App\Models\EcomChatConversation;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use App\Services\Ai\EcomChatDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class EcomChatDrafterTest extends TestCase
{
    use RefreshDatabase;

    private function conv(string $buyerText): EcomChatConversation
    {
        $c = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1', 'status' => 'open',
        ]);
        $c->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'M'.uniqid(),
            'sender' => 'buyer', 'via' => 'buyer', 'text' => $buyerText,
        ]);

        return $c;
    }

    private function fakeAi(string $text): void
    {
        $this->app->instance(AiProvider::class, new FakeAiProvider([new AiTurn(text: $text)]));
    }

    public function test_faq_menghasilkan_auto_send(): void
    {
        AiKnowledge::create(['section' => 'chat_faq', 'content' => 'BPOM: semua terdaftar', 'group' => 'chat']);
        $this->fakeAi('{"reply":"Semua produk kami sudah BPOM kak 😊","decision":"auto_send","reason":"FAQ umum"}');

        $out = app(EcomChatDrafter::class)->draft($this->conv('Produknya BPOM ga kak?'));

        $this->assertSame('auto_send', $out['decision']);
        $this->assertStringContainsString('BPOM', $out['reply']);
    }

    public function test_kasus_spesifik_ke_staf(): void
    {
        $this->fakeAi('{"reply":"","decision":"to_staff","reason":"minta batal order spesifik"}');
        $out = app(EcomChatDrafter::class)->draft($this->conv('Tolong batalin pesanan saya #12345'));
        $this->assertSame('to_staff', $out['decision']);
    }

    public function test_json_rusak_dipaksa_ke_staf(): void
    {
        $this->fakeAi('maaf ini bukan json sama sekali');
        $out = app(EcomChatDrafter::class)->draft($this->conv('halo'));
        $this->assertSame('to_staff', $out['decision']);
        $this->assertSame('', $out['reply']);
    }

    public function test_reply_dibungkus_code_fence_tetap_terparse(): void
    {
        $this->fakeAi("```json\n{\"reply\":\"Halo kak\",\"decision\":\"auto_send\",\"reason\":\"sapaan\"}\n```");
        $out = app(EcomChatDrafter::class)->draft($this->conv('halo'));
        $this->assertSame('auto_send', $out['decision']);
        $this->assertSame('Halo kak', $out['reply']);
    }

    public function test_decision_tak_dikenal_dipaksa_ke_staf(): void
    {
        $this->fakeAi('{"reply":"apa saja","decision":"kirim_langsung","reason":"x"}');
        $out = app(EcomChatDrafter::class)->draft($this->conv('halo'));
        $this->assertSame('to_staff', $out['decision']);
    }
}
