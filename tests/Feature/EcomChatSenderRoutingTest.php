<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\ShopeeConnection;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatSenderRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_kirim_channel_shopee_lewat_shopee_client(): void
    {
        config()->set('services.shopee.partner_id', '100001');
        config()->set('services.shopee.partner_key', 'k');
        ShopeeConnection::create([
            'shop_id' => '426938728', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20),
        ]);

        $conv = EcomChatConversation::create([
            'channel' => 'shopee', 'external_conversation_id' => 'C1',
            'buyer_name' => 'Budi', 'buyer_id' => '555001', 'status' => 'needs_staff',
        ]);

        Http::fake(['*sellerchat/send_message*' => Http::response(['response' => ['message_id' => 'S-9'], 'error' => ''])]);

        $msg = app(EcomChatService::class)->send($conv, 'Halo', 'staff');

        $this->assertSame('S-9', $msg->external_message_id);
        $this->assertSame('replied', $conv->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sellerchat/send_message'));
    }
}
