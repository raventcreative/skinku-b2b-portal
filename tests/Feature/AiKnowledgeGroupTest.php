<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiKnowledgeGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_sistem_tak_menyertakan_pengetahuan_chat(): void
    {
        AiKnowledge::create(['section' => 'rules', 'content' => 'Aturan sistem X', 'group' => 'sistem']);
        AiKnowledge::create(['section' => 'chat_faq', 'content' => 'FAQ untuk pembeli Y', 'group' => 'chat']);

        $sistem = AiKnowledge::document(); // default 'sistem'
        $this->assertStringContainsString('Aturan sistem X', $sistem);
        $this->assertStringNotContainsString('FAQ untuk pembeli Y', $sistem);
    }

    public function test_document_chat_hanya_pengetahuan_chat(): void
    {
        AiKnowledge::create(['section' => 'rules', 'content' => 'Aturan sistem X', 'group' => 'sistem']);
        AiKnowledge::create(['section' => 'chat_faq', 'content' => 'FAQ untuk pembeli Y', 'group' => 'chat']);

        $chat = AiKnowledge::document('chat');
        $this->assertStringContainsString('FAQ untuk pembeli Y', $chat);
        $this->assertStringNotContainsString('Aturan sistem X', $chat);
    }

    public function test_sections_of_memisahkan_grup(): void
    {
        $this->assertArrayHasKey('rules', AiKnowledge::sectionsOf('sistem'));
        $this->assertArrayNotHasKey('chat_faq', AiKnowledge::sectionsOf('sistem'));
        $this->assertArrayHasKey('chat_faq', AiKnowledge::sectionsOf('chat'));
        $this->assertSame('chat', AiKnowledge::groupOf('chat_faq'));
        $this->assertSame('sistem', AiKnowledge::groupOf('rules'));
    }
}
