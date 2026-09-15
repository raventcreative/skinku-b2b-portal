<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokConnection;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatServiceTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): void
    {
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        config()->set('services.tiktok.app_key', 'k');
        config()->set('services.tiktok.app_secret', 's');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
    }

    public function test_sync_incoming_dedupe_dan_upsert_percakapan(): void
    {
        $svc = app(EcomChatService::class);
        $payload = [
            'conversation_id' => 'CONV1', 'message_id' => 'M1', 'text' => 'Halo',
            'buyer_name' => 'Budi', 'buyer_id' => 'B1', 'sender' => 'buyer', 'sent_at' => null,
        ];

        $first = $svc->syncIncoming('tiktok', $payload);
        $dup = $svc->syncIncoming('tiktok', $payload);

        $this->assertNotNull($first);
        $this->assertNull($dup); // dedupe
        $this->assertSame(1, EcomChatMessage::count());
        $conv = EcomChatConversation::first();
        $this->assertSame('Budi', $conv->buyer_name);
        $this->assertSame('Halo', $conv->last_message_preview);
    }

    public function test_sync_incoming_abaikan_pesan_penjual(): void
    {
        $svc = app(EcomChatService::class);
        $out = $svc->syncIncoming('tiktok', [
            'conversation_id' => 'CONV1', 'message_id' => 'S1', 'text' => 'balasan toko',
            'sender' => 'seller', 'sent_at' => null,
        ]);
        $this->assertNull($out);
        $this->assertSame(0, EcomChatMessage::count());
    }

    public function test_send_memanggil_api_dan_menyimpan_pesan_seller(): void
    {
        $this->connect();
        Http::fake(['*/customer_service/*' => Http::response(['code' => 0, 'data' => ['message_id' => 'X']])]);

        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1', 'status' => 'open',
        ]);
        $svc = app(EcomChatService::class);
        $msg = $svc->send($conv, 'Terima kasih kak', EcomChatMessage::VIA_STAFF);

        $this->assertSame('seller', $msg->sender);
        $this->assertSame('staff', $msg->via);
        $this->assertSame('replied', $conv->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'CONV1'));
    }

    public function test_autosend_default_mati(): void
    {
        $this->assertFalse(app(EcomChatService::class)->autosendEnabled());
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, '1');
        $this->assertTrue(app(EcomChatService::class)->autosendEnabled());
    }
}
