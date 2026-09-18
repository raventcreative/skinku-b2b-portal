<?php

namespace Tests\Feature;

use App\Models\EcomChatMessage;
use App\Models\ShopeeConnection;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class ShopeePushTest extends TestCase
{
    use RefreshDatabase;

    private function sign(string $url, string $body): string
    {
        return hash_hmac('sha256', $url.'|'.$body, 'secretkey');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopee.partner_id', '1');
        config()->set('services.shopee.partner_key', 'secretkey');
        $this->app->instance(AiProvider::class, new FakeAiProvider([]));
        ShopeeConnection::create([
            'shop_id' => '426938728', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20),
        ]);
    }

    /** Push chat = notifikasi; isi ditarik dari API get_message. */
    private function fakeShopeeChat(): void
    {
        Http::fake([
            '*sellerchat/get_one_conversation*' => Http::response(['response' => ['conversation_id' => 'C1', 'to_name' => 'Budi', 'to_id' => 949588930], 'error' => '']),
            '*sellerchat/get_message*' => Http::response(['response' => ['messages' => [
                ['message_id' => 'M1', 'conversation_id' => 'C1', 'from_id' => 949588930, 'from_shop_id' => 949477559, 'message_type' => 'text', 'content' => ['text' => 'halo kak'], 'created_timestamp' => 1723887490],
            ]], 'error' => '']),
        ]);
    }

    private function chatPush(): array
    {
        return ['shop_id' => 426938728, 'code' => 10, 'data' => ['type' => 'notification', 'content' => ['conversation_id' => 'C1', 'type' => 'message']]];
    }

    public function test_push_pesan_teks_masuk_inbox(): void
    {
        $this->fakeShopeeChat();
        $url = 'http://localhost/webhooks/shopee/push';
        $body = json_encode($this->chatPush());

        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => $this->sign($url, $body), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseHas('ecom_chat_messages', ['channel' => 'shopee', 'external_message_id' => 'M1', 'sender' => 'buyer']);
        $this->assertDatabaseHas('ecom_chat_conversations', ['channel' => 'shopee', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi']);
    }

    public function test_tanda_tangan_salah_ditolak(): void
    {
        $body = json_encode($this->chatPush());
        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => 'salah', 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);
        $this->assertSame(0, EcomChatMessage::count());
    }

    public function test_push_non_chat_diabaikan_200(): void
    {
        $url = 'http://localhost/webhooks/shopee/push';
        $body = json_encode(['code' => 3, 'data' => ['ordersn' => 'x']]); // push order, tak ada conversation_id
        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => $this->sign($url, $body), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();
        $this->assertSame(0, EcomChatMessage::count());
    }

    public function test_tanda_tangan_verifikasi_pakai_push_partner_key(): void
    {
        config()->set('services.shopee.push_partner_key', 'pushkey');
        $this->fakeShopeeChat();
        $url = 'http://localhost/webhooks/shopee/push';
        $body = json_encode($this->chatPush());
        $pushSig = hash_hmac('sha256', $url.'|'.$body, 'pushkey');

        // Ditandatangani API key (salah utk push) → 401.
        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => $this->sign($url, $body), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);

        // Ditandatangani PUSH key → diterima, pesan ditarik & masuk.
        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => $pushSig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();
        $this->assertDatabaseHas('ecom_chat_messages', ['channel' => 'shopee', 'external_message_id' => 'M1']);
    }

    public function test_mode_debug_terima_tanpa_verifikasi_dan_rekam(): void
    {
        config()->set('services.shopee.push_debug', true);
        $this->fakeShopeeChat();
        $body = json_encode($this->chatPush());

        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => 'apapun', 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();
        $this->assertDatabaseHas('ecom_chat_messages', ['channel' => 'shopee', 'external_message_id' => 'M1']);
    }
}
