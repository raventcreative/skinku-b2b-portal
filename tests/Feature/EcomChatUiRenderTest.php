<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatUiRenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'adminui', 'email' => 'adminui@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_inbox_menampilkan_badge_dan_toggle(): void
    {
        EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi',
            'status' => 'needs_staff', 'last_message_preview' => 'Produknya BPOM?',
            'last_incoming_at' => now(), 'last_message_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/ecom-chat')->assertOk()
            ->assertSee('Budi')
            ->assertSee('Perlu staf')
            ->assertSee('Auto-send');
    }

    public function test_detail_menampilkan_draft_dan_tombol(): void
    {
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi', 'status' => 'needs_staff',
            'ai_draft' => 'Sudah BPOM kak', 'ai_decision' => 'to_staff', 'ai_reason' => 'perlu cek',
        ]);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'M1', 'sender' => 'buyer', 'via' => 'buyer', 'text' => 'BPOM ga?',
        ]);

        $this->actingAs($this->admin())->get("/ecom-chat/{$conv->id}")->assertOk()
            ->assertSee('Sudah BPOM kak')
            ->assertSee('Kirim')
            ->assertSee('Buat ulang draft');
    }

    public function test_nav_ada_untuk_staf(): void
    {
        $this->actingAs($this->admin())->get('/ecom-chat')->assertOk()->assertSee('Chat E-commerce');
    }

    public function test_thread_memberi_label_bot_tiktok_ai_dan_staf(): void
    {
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C2', 'buyer_name' => 'Sari', 'status' => 'open']);
        foreach ([['B', 'buyer', 'buyer', 'Halo'], ['A', 'seller', 'ai', 'Jawaban AI'], ['S', 'seller', 'staff', 'Jawaban staf'], ['T', 'seller', 'bot', 'Pesan bot']] as $i => [$id, $sender, $via, $text]) {
            $conv->messages()->create(['channel' => 'tiktok', 'external_message_id' => $id, 'sender' => $sender, 'via' => $via, 'text' => $text, 'sent_at' => now()->addMinutes($i)]);
        }

        $this->actingAs($this->admin())->get('/ecom-chat/'.$conv->id)->assertOk()
            ->assertSee('AI SKINKU')->assertSee('Staf')->assertSee('Bot TikTok')->assertSee('Pesan bot');
    }
}
