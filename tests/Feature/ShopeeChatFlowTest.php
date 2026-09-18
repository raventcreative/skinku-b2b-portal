<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\ShopeeConnection;
use App\Models\User;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeChatFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'admin1', 'email' => 'a@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function shopeeConn(): void
    {
        config()->set('services.shopee.partner_id', '1');
        config()->set('services.shopee.partner_key', 'k');
        ShopeeConnection::create([
            'shop_id' => '426938728', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20),
        ]);
    }

    public function test_import_shopee_menandai_replied_saat_balasan_toko_terbaru(): void
    {
        $this->shopeeConn();
        Http::fake([
            '*get_conversation_list*' => Http::response(['response' => ['conversations' => [['conversation_id' => 'C1', 'to_name' => 'Budi', 'to_id' => 949588930]]], 'error' => '']),
            '*get_one_conversation*' => Http::response(['response' => ['conversation_id' => 'C1', 'to_name' => 'Budi', 'to_id' => 949588930], 'error' => '']),
            '*get_message*' => Http::response(['response' => ['messages' => [
                // pembeli (from_shop_id != toko kita)
                ['message_id' => 'B1', 'conversation_id' => 'C1', 'from_id' => 949588930, 'from_shop_id' => 949477559, 'message_type' => 'text', 'content' => ['text' => 'halo'], 'created_timestamp' => 1723887400],
                // toko (from_shop_id == toko kita) TERBARU → replied
                ['message_id' => 'S1', 'conversation_id' => 'C1', 'from_id' => 426958305, 'from_shop_id' => 426938728, 'message_type' => 'text', 'content' => ['text' => 'siap kak'], 'created_timestamp' => 1723887500],
            ]], 'error' => '']),
        ]);

        $res = app(EcomChatService::class)->importFromShopee();

        // Ambil percakapan TERBARU → Shopee pakai direction=older (terbalik).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'get_conversation_list') && str_contains($r->url(), 'direction=older'));
        $this->assertSame(1, $res['conversations']);
        $conv = EcomChatConversation::where('channel', 'shopee')->where('external_conversation_id', 'C1')->first();
        $this->assertNotNull($conv);
        $this->assertSame('Budi', $conv->buyer_name);
        $this->assertSame('replied', $conv->status);
        $this->assertDatabaseHas('ecom_chat_messages', ['channel' => 'shopee', 'external_message_id' => 'B1', 'sender' => 'buyer']);
        $this->assertDatabaseHas('ecom_chat_messages', ['channel' => 'shopee', 'external_message_id' => 'S1', 'sender' => 'seller']);
    }

    public function test_percakapan_terbaru_tetap_muncul_walau_get_message_kosong(): void
    {
        // Chat baru (ditangani Asisten AI Shopee) kadang get_message-nya 0 → percakapan
        // tetap harus tampil di posisi benar dari RINGKASAN daftar.
        $this->shopeeConn();
        $tsNano = (string) (1789700000 * 1_000_000_000); // ~2026-09
        Http::fake([
            '*get_conversation_list*' => Http::response(['response' => ['conversations' => [[
                'conversation_id' => 'CN', 'to_name' => 'Mia', 'to_id' => 111,
                'last_message_timestamp' => $tsNano, 'latest_message_from_id' => 111,
                'latest_message_type' => 'text', 'latest_message_content' => ['text' => 'halo kak'],
            ]]], 'error' => '']),
            '*get_message*' => Http::response(['response' => ['messages' => []], 'error' => '']), // 0 pesan
        ]);

        app(EcomChatService::class)->importFromShopee();

        $conv = EcomChatConversation::where('external_conversation_id', 'CN')->first();
        $this->assertNotNull($conv);
        $this->assertSame('Mia', $conv->buyer_name);
        $this->assertNotNull($conv->last_message_at);       // recency dari ringkasan
        $this->assertSame('halo kak', $conv->last_message_preview);
        $this->assertSame('open', $conv->status);            // pembeli pengirim terakhir → perlu dibalas
    }

    public function test_inbox_dipisah_per_channel(): void
    {
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'T1', 'buyer_name' => 'AndiTikTok', 'status' => 'open', 'last_message_at' => now(), 'last_incoming_at' => now()]);
        EcomChatConversation::create(['channel' => 'shopee', 'external_conversation_id' => 'S1', 'buyer_name' => 'BudiShopee', 'status' => 'open', 'last_message_at' => now(), 'last_incoming_at' => now()]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/ecom-chat?channel=shopee&tab=semua')
            ->assertOk()->assertSee('BudiShopee')->assertDontSee('AndiTikTok');

        $this->actingAs($admin)
            ->get('/ecom-chat?channel=tiktok&tab=semua')
            ->assertOk()->assertSee('AndiTikTok')->assertDontSee('BudiShopee');
    }
}
