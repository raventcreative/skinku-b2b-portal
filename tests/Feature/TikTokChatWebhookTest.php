<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokAffiliateConnection;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class TikTokChatWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'topsecret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.tiktok_affiliate.app_key', 'k');
        config()->set('services.tiktok_affiliate.app_secret', $this->secret);
        config()->set('services.tiktok_affiliate.api_base', 'https://open-api.tiktokglobalshop.com');
    }

    private function payload(string $messageId = 'M1', string $conv = 'CONV1'): array
    {
        return [
            'type' => 14,
            'shop_id' => 'S',
            'data' => [
                'conversation_id' => $conv,
                'message_id' => $messageId,
                'content' => 'Produknya BPOM ga kak?',
                'sender' => ['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi'],
                'create_time' => 1757000000,
            ],
        ];
    }

    private function postWebhook(array $payload)
    {
        $body = json_encode($payload);
        $sign = hash_hmac('sha256', 'k'.$body, $this->secret);

        return $this->call('POST', '/webhooks/tiktok/chat', [], [], [], [
            'HTTP_AUTHORIZATION' => $sign,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_tanda_tangan_valid_menyimpan_pesan_dan_membuat_draft(): void
    {
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"Sudah BPOM kak","decision":"auto_send","reason":"FAQ"}'),
        ]));

        $this->postWebhook($this->payload())->assertOk();

        $this->assertSame(1, EcomChatMessage::where('sender', 'buyer')->count());
        $conv = EcomChatConversation::first();
        // Kill switch default MATI → auto_send TIDAK terkirim, hanya draft + needs_staff.
        $this->assertSame('auto_send', $conv->ai_decision);
        $this->assertSame('needs_staff', $conv->status);
        $this->assertSame(0, EcomChatMessage::where('sender', 'seller')->count());
    }

    public function test_kill_switch_nyala_auto_send_terkirim(): void
    {
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, '1');
        TiktokAffiliateConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake(['*/customer_service/*' => Http::response(['code' => 0, 'data' => ['message_id' => 'OUT1']])]);
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"Sudah BPOM kak","decision":"auto_send","reason":"FAQ"}'),
        ]));

        $this->postWebhook($this->payload())->assertOk();

        $this->assertSame(1, EcomChatMessage::where('sender', 'seller')->where('via', 'ai')->count());
        $this->assertSame('replied', EcomChatConversation::first()->status);
    }

    public function test_tanda_tangan_salah_ditolak_401(): void
    {
        $body = json_encode($this->payload());

        $this->call('POST', '/webhooks/tiktok/chat', [], [], [], [
            'HTTP_AUTHORIZATION' => 'salah', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(0, EcomChatMessage::count());
    }

    public function test_webhook_tanpa_content_menarik_isi_pesan_dari_api(): void
    {
        // Payload NEW_MESSAGE TikTok TIDAK membawa teks pesan (hanya metadata) →
        // isi asli harus ditarik dari getConversationMessages, bukan tersimpan kosong.
        TiktokAffiliateConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake([
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'M1', 'type' => 'TEXT', 'content' => json_encode(['content' => 'Halo kak, ready stock?']), 'sender' => ['role' => 'BUYER'], 'create_time' => 1757000000],
            ]]]),
        ]);
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"Ready kak","decision":"to_staff","reason":"x"}'),
        ]));

        // Payload TANPA field content.
        $payload = [
            'type' => 14, 'shop_id' => 'S',
            'data' => [
                'conversation_id' => 'CONV1', 'message_id' => 'M1', 'type' => 'text',
                'sender' => ['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi'],
                'create_time' => 1757000000,
            ],
        ];
        $this->postWebhook($payload)->assertOk();

        $msg = EcomChatMessage::where('external_message_id', 'M1')->first();
        $this->assertNotNull($msg);
        $this->assertSame('Halo kak, ready stock?', $msg->text); // terisi dari API, bukan kosong
        $this->assertSame('Halo kak, ready stock?', EcomChatConversation::first()->last_message_preview);
    }

    public function test_nama_pembeli_diambil_dari_pengirim_pesan(): void
    {
        // Payload webhook sering TANPA nama pengirim → nama diambil dari nickname
        // pada pesan yang ditarik dari API (bukan tetap "Pembeli").
        TiktokAffiliateConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake([
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'NM1', 'type' => 'TEXT', 'content' => json_encode(['content' => 'Halo kak']), 'sender' => ['role' => 'BUYER', 'nickname' => 'ratukatapang', 'im_user_id' => 'B99'], 'create_time' => 1757000000],
            ]]]),
        ]);
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"x","decision":"to_staff","reason":"y"}'),
        ]));

        // Payload webhook TANPA nickname pengirim.
        $payload = ['type' => 14, 'shop_id' => 'S', 'data' => [
            'conversation_id' => 'CONV1', 'message_id' => 'NM1', 'sender' => ['role' => 'BUYER'], 'create_time' => 1757000000,
        ]];
        $this->postWebhook($payload)->assertOk();

        $conv = EcomChatConversation::first();
        $this->assertSame('ratukatapang', $conv->buyer_name);
        $this->assertSame('B99', $conv->buyer_id);
    }

    public function test_pesan_foto_disimpan_sebagai_tipe_image(): void
    {
        // Pesan foto dari pembeli = type IMAGE, content JSON {url,width,height}.
        // Harus jadi tipe 'image' + meta.url, BUKAN teks JSON mentah.
        TiktokAffiliateConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake([
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'IMG1', 'type' => 'IMAGE', 'content' => json_encode(['url' => 'https://cdn.example/x.jpg', 'width' => 460, 'height' => 1024]), 'sender' => ['role' => 'BUYER'], 'create_time' => 1757000000],
            ]]]),
        ]);
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"x","decision":"to_staff","reason":"y"}'),
        ]));

        $payload = ['type' => 14, 'shop_id' => 'S', 'data' => [
            'conversation_id' => 'CONV1', 'message_id' => 'IMG1',
            'sender' => ['role' => 'BUYER', 'nickname' => 'Budi'], 'create_time' => 1757000000,
        ]];
        $this->postWebhook($payload)->assertOk();

        $msg = EcomChatMessage::where('external_message_id', 'IMG1')->first();
        $this->assertNotNull($msg);
        $this->assertSame('image', $msg->type);
        $this->assertSame('https://cdn.example/x.jpg', $msg->meta['url']);
    }

    public function test_pesan_kartu_produk_disimpan_sebagai_product_card(): void
    {
        // Pembeli kirim kartu produk = content {"product_id":"..."} → tipe
        // 'product_card' + meta.product_id, BUKAN teks JSON mentah.
        TiktokAffiliateConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake([
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'PC1', 'type' => 'PRODUCT_CARD', 'content' => json_encode(['product_id' => '1735591701567080362']), 'sender' => ['role' => 'BUYER'], 'create_time' => 1757000000],
            ]]]),
        ]);
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"x","decision":"to_staff","reason":"y"}'),
        ]));

        $payload = ['type' => 14, 'shop_id' => 'S', 'data' => [
            'conversation_id' => 'CONV1', 'message_id' => 'PC1', 'sender' => ['role' => 'BUYER'], 'create_time' => 1757000000,
        ]];
        $this->postWebhook($payload)->assertOk();

        $msg = EcomChatMessage::where('external_message_id', 'PC1')->first();
        $this->assertNotNull($msg);
        $this->assertSame('product_card', $msg->type);
        $this->assertSame('1735591701567080362', $msg->meta['product_id']);
    }

    public function test_tanpa_header_authorization_ditolak_401(): void
    {
        // Apache membuang header Authorization (public/.htaccess tanpa aturannya) → 401, bukan diproses.
        $body = json_encode($this->payload());

        $this->call('POST', '/webhooks/tiktok/chat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(0, EcomChatMessage::count());
    }

    public function test_message_id_dobel_didedupe(): void
    {
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"x","decision":"to_staff","reason":"y"}'),
            new AiTurn(text: '{"reply":"x","decision":"to_staff","reason":"y"}'),
        ]));

        $this->postWebhook($this->payload('DUP'))->assertOk();
        $this->postWebhook($this->payload('DUP'))->assertOk();

        $this->assertSame(1, EcomChatMessage::where('external_message_id', 'DUP')->count());
    }

    public function test_auto_send_gagal_turun_ke_staf_bukan_diam_diam_open(): void
    {
        // Kill switch nyala + keputusan auto_send, TAPI tidak ada TiktokConnection
        // sama sekali → send() lempar RuntimeException. Percakapan HARUS turun ke
        // needs_staff (bukan diam-diam tetap "open" keluar dari antrean staf).
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, '1');
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"Sudah BPOM kak","decision":"auto_send","reason":"FAQ"}'),
        ]));

        $this->postWebhook($this->payload())->assertOk();

        $this->assertSame(0, EcomChatMessage::where('sender', 'seller')->count());
        $conv = EcomChatConversation::first();
        $this->assertSame('needs_staff', $conv->status);
    }
}
