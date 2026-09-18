<?php

namespace Tests\Feature;

use App\Models\EcomChatMessage;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        config()->set('services.shopee.partner_key', 'secretkey');
        $this->app->instance(AiProvider::class, new FakeAiProvider([]));
    }

    public function test_push_pesan_teks_masuk_inbox(): void
    {
        $url = 'http://localhost/webhooks/shopee/push';
        $payload = [
            'shop_id' => 426938728, 'code' => 10, 'timestamp' => 1789000000,
            'data' => ['type' => 'message', 'content' => [
                'conversation_id' => 'C1', 'message_id' => 'M1',
                'from_id' => 555001, 'to_id' => 426938728, 'from_shop_id' => 0, 'to_shop_id' => 426938728,
                'from_user_name' => 'Budi', 'message_type' => 'text',
                'content' => ['text' => 'Barangnya ready kak?'], 'created_timestamp' => 1789000000,
            ]],
        ];
        $body = json_encode($payload);

        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => $this->sign($url, $body), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertDatabaseHas('ecom_chat_messages', ['channel' => 'shopee', 'external_message_id' => 'M1', 'sender' => 'buyer']);
    }

    public function test_tanda_tangan_salah_ditolak(): void
    {
        $body = json_encode(['data' => ['type' => 'message']]);
        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => 'salah', 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);
    }

    public function test_push_non_chat_diabaikan_200(): void
    {
        $url = 'http://localhost/webhooks/shopee/push';
        $body = json_encode(['code' => 3, 'data' => ['ordersn' => 'x']]); // push order, bukan chat
        $this->call('POST', '/webhooks/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => $this->sign($url, $body), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();
        $this->assertSame(0, EcomChatMessage::count());
    }
}
