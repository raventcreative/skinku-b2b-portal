<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokAffiliateConnection;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatServiceTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): void
    {
        TiktokAffiliateConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        config()->set('services.tiktok_affiliate.app_key', 'k');
        config()->set('services.tiktok_affiliate.app_secret', 's');
        config()->set('services.tiktok_affiliate.api_base', 'https://open-api.tiktokglobalshop.com');
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
        $svc = app(EcomChatService::class);
        $this->assertFalse($svc->autosendEnabled('tiktok'));
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, '1'); // global lama → fallback semua channel ON
        $this->assertTrue($svc->autosendEnabled('tiktok'));
        $this->assertTrue($svc->autosendEnabled('shopee'));
    }

    public function test_autosend_terpisah_per_channel(): void
    {
        $svc = app(EcomChatService::class);
        $svc->setAutosend('tiktok', true);
        $svc->setAutosend('shopee', false);
        $this->assertTrue($svc->autosendEnabled('tiktok'));
        $this->assertFalse($svc->autosendEnabled('shopee'));
    }
}
