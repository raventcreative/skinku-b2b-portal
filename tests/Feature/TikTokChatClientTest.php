<?php

namespace Tests\Feature;

use App\Services\TikTokClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokChatClientTest extends TestCase
{
    public function test_send_message_memanggil_endpoint_conversation_dengan_teks(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');

        Http::fake([
            '*/customer_service/*' => Http::response(['code' => 0, 'message' => 'ok', 'data' => ['message_id' => 'NEW1']]),
        ]);

        $client = app(TikTokClient::class);
        $data = $client->sendMessage('acc-token', 'cipher-1', 'CONV9', 'Halo kak, terima kasih');

        $this->assertSame('NEW1', $data['message_id']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/customer_service/')
                && str_contains($request->url(), 'CONV9')
                && str_contains($request->body(), 'Halo kak, terima kasih')
                && $request->hasHeader('x-tts-access-token', 'acc-token');
        });
    }

    public function test_get_conversation_messages_mengembalikan_data(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');

        Http::fake([
            '*/customer_service/*' => Http::response(['code' => 0, 'data' => ['messages' => [['id' => 'M1']]]]),
        ]);

        $data = app(TikTokClient::class)->getConversationMessages('t', 'c', 'CONV9', 20);
        $this->assertSame('M1', $data['messages'][0]['id']);
    }
}
