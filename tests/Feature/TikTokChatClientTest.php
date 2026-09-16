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
            $body = json_decode($request->body(), true);
            // content WAJIB string JSON {"content":"..."} (bukan teks polos) — cegah regresi 45101001.
            $inner = json_decode((string) ($body['content'] ?? ''), true);

            return str_contains($request->url(), '/customer_service/')
                && str_contains($request->url(), 'CONV9')
                && ($body['type'] ?? null) === 'TEXT'
                && is_array($inner)
                && ($inner['content'] ?? null) === 'Halo kak, terima kasih'
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

    public function test_get_webhooks_memanggil_events_api(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');

        Http::fake([
            '*/event/202309/webhooks*' => Http::response(['code' => 0, 'data' => ['webhooks' => [['event_type' => 'NEW_MESSAGE', 'address' => 'https://x/w']]]]),
        ]);

        $data = app(TikTokClient::class)->getWebhooks('acc', 'cipher');
        $this->assertSame('NEW_MESSAGE', $data['webhooks'][0]['event_type']);
    }

    public function test_update_webhook_mengirim_put_dengan_event_dan_address(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');

        Http::fake([
            '*/event/202309/webhooks*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        app(TikTokClient::class)->updateWebhook('acc', 'cipher', 'NEW_MESSAGE', 'https://system.skinku.id/webhooks/tiktok/chat');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->method() === 'PUT'
                && str_contains($request->url(), '/event/202309/webhooks')
                && ($body['event_type'] ?? null) === 'NEW_MESSAGE'
                && ($body['address'] ?? null) === 'https://system.skinku.id/webhooks/tiktok/chat'
                && $request->hasHeader('x-tts-access-token', 'acc');
        });
    }
}
