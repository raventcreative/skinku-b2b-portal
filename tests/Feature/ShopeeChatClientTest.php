<?php

namespace Tests\Feature;

use App\Services\ShopeeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeChatClientTest extends TestCase
{
    public function test_send_chat_message_memanggil_endpoint_sellerchat_dengan_body_benar(): void
    {
        config()->set('services.shopee.partner_id', '100001');
        config()->set('services.shopee.partner_key', 'secretkey');

        Http::fake([
            '*sellerchat/send_message*' => Http::response(['response' => ['message_id' => 'MSG-1'], 'error' => '']),
        ]);

        $res = app(ShopeeClient::class)->sendChatMessage('ACCESS', '426938728', 555001, 'Halo kak');

        $this->assertSame('MSG-1', $res['response']['message_id']);
        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true) ?: [];

            return str_contains($req->url(), 'sellerchat/send_message')
                && $body['to_id'] === 555001
                && $body['message_type'] === 'text'
                && $body['content']['text'] === 'Halo kak';
        });
    }

    public function test_get_conversation_list_kirim_param_dan_baca_response(): void
    {
        config()->set('services.shopee.partner_id', '100001');
        config()->set('services.shopee.partner_key', 'secretkey');

        Http::fake([
            '*sellerchat/get_conversation_list*' => Http::response([
                'response' => ['conversations' => [['conversation_id' => 'C1', 'to_id' => 555001, 'to_name' => 'Budi']]],
                'error' => '',
            ]),
        ]);

        $res = app(ShopeeClient::class)->getConversationList('ACCESS', '426938728');

        $this->assertSame('C1', $res['response']['conversations'][0]['conversation_id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sellerchat/get_conversation_list')
            && str_contains($req->url(), 'direction=latest'));
    }
}
