<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\TiktokAffiliateConnection;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatSyncTest extends TestCase
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

    public function test_import_menyimpan_percakapan_pesan_dan_nama_pembeli(): void
    {
        $this->connect();
        Http::fake([
            // Lebih spesifik dulu: daftar pesan per percakapan.
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'M2', 'content' => json_encode(['content' => 'Ready kak?']), 'sender' => ['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi'], 'create_time' => 1_757_000_100],
                ['id' => 'M1', 'content' => json_encode(['content' => 'Halo kak']), 'sender' => ['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi'], 'create_time' => 1_757_000_000],
            ]]]),
            '*/conversations*' => Http::response(['code' => 0, 'data' => ['conversations' => [
                ['id' => 'CONV1', 'participants' => [
                    ['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi'],
                    ['role' => 'SELLER', 'im_user_id' => 'S1', 'nickname' => 'Toko'],
                ]],
            ]]]),
        ]);

        $res = app(EcomChatService::class)->importFromTikTok();

        $this->assertSame(1, $res['conversations']);
        $this->assertSame(2, $res['messages']);

        $conv = EcomChatConversation::where('external_conversation_id', 'CONV1')->first();
        $this->assertNotNull($conv);
        $this->assertSame('Budi', $conv->buyer_name);
        $this->assertSame(2, $conv->messages()->count());
        // Konten JSON-terbungkus TikTok ter-decode ke teks polos.
        $this->assertSame('Halo kak', $conv->messages()->orderBy('id')->first()->text);
        // Recency dari pesan pembeli terbaru.
        $this->assertNotNull($conv->last_incoming_at);
        $this->assertSame('Ready kak?', $conv->last_message_preview);

        // CS API batasi page_size <= 10 (regresi bug 36009004: sebelumnya 20 → ditolak).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'page_size=10'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'page_size=20'));
    }

    public function test_import_paginasi_tarik_lebih_dari_satu_halaman(): void
    {
        $this->connect();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/messages')) {
                return Http::response(['code' => 0, 'data' => ['messages' => []]]);
            }
            if (str_contains($url, 'page_token=')) {
                // Halaman 2 (terakhir — tanpa next_page_token → loop berhenti).
                return Http::response(['code' => 0, 'data' => ['conversations' => [
                    ['id' => 'CONV2', 'participants' => [['role' => 'BUYER', 'im_user_id' => 'B2', 'nickname' => 'Cici']]],
                ]]]);
            }

            // Halaman 1 (ada next_page_token → lanjut ke halaman berikutnya).
            return Http::response(['code' => 0, 'data' => [
                'conversations' => [['id' => 'CONV1', 'participants' => [['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi']]]],
                'next_page_token' => 'PAGE2',
            ]]);
        });

        $res = app(EcomChatService::class)->importFromTikTok();

        $this->assertSame(2, $res['conversations']);
        $this->assertNotNull(EcomChatConversation::where('external_conversation_id', 'CONV1')->first());
        $this->assertNotNull(EcomChatConversation::where('external_conversation_id', 'CONV2')->first());
    }

    public function test_import_render_tipe_kartu_dan_other_jadi_label_ramah(): void
    {
        $this->connect();
        Http::fake([
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'MT', 'type' => 'TEXT', 'content' => json_encode(['content' => 'Halo kak']), 'sender' => ['role' => 'ROBOT'], 'create_time' => 1_789_346_665],
                ['id' => 'MO', 'type' => 'ORDER_CARD', 'content' => json_encode(['order_id' => '586']), 'sender' => ['role' => 'ROBOT'], 'create_time' => 1_789_346_666],
                ['id' => 'ML', 'type' => 'LOGISTICS_CARD', 'content' => json_encode(['order_id' => '586', 'package_id' => '121']), 'sender' => ['role' => 'ROBOT'], 'create_time' => 1_789_346_667],
                ['id' => 'MX', 'type' => 'OTHER', 'content' => json_encode(['content' => '[Other] Please check this message in seller center of TikTok shop.']), 'sender' => ['role' => 'ROBOT'], 'create_time' => 1_789_346_668],
            ]]]),
            '*/conversations*' => Http::response(['code' => 0, 'data' => ['conversations' => [
                ['id' => 'CONV1', 'participants' => [['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi']]],
            ]]]),
        ]);

        app(EcomChatService::class)->importFromTikTok();
        $byId = EcomChatConversation::where('external_conversation_id', 'CONV1')->first()->messages->keyBy('external_message_id');

        $this->assertSame('text', $byId['MT']->type);
        $this->assertSame('Halo kak', $byId['MT']->text);
        $this->assertSame('order_card', $byId['MO']->type);
        $this->assertStringContainsString('Kartu Pesanan', $byId['MO']->text);
        $this->assertStringContainsString('586', $byId['MO']->text);
        $this->assertSame('logistics_card', $byId['ML']->type);
        $this->assertSame('other', $byId['MX']->type);
        // JSON mentah / placeholder "[Other]" diganti label ramah.
        $this->assertStringNotContainsString('[Other]', $byId['MX']->text);
        // meta kartu tersimpan untuk render kaya (lookup order/produk/harga/status).
        $this->assertSame('586', $byId['MO']->meta['order_id']);
        $this->assertSame('121', $byId['ML']->meta['package_id']);
    }

    public function test_import_balasan_penjual_terbaru_jadi_terbalas(): void
    {
        // Dibalas langsung di Seller Center → pesan penjual jadi TERBARU. Saat sync,
        // status SKINKU harus ikut jadi "Terbalas" (staff), tak nyangkut "Perlu dibalas".
        $this->connect();
        Http::fake([
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'S2', 'content' => json_encode(['content' => 'Ready kak, silakan order']), 'sender' => ['role' => 'SELLER'], 'create_time' => 1_757_000_200],
                ['id' => 'B1msg', 'content' => json_encode(['content' => 'Ready kak?']), 'sender' => ['role' => 'BUYER', 'nickname' => 'Budi'], 'create_time' => 1_757_000_100],
            ]]]),
            '*/conversations*' => Http::response(['code' => 0, 'data' => ['conversations' => [
                ['id' => 'CONV1', 'participants' => [['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi']]],
            ]]]),
        ]);

        app(EcomChatService::class)->importFromTikTok();

        $conv = EcomChatConversation::where('external_conversation_id', 'CONV1')->first();
        $this->assertSame('replied', $conv->status);
        $this->assertSame('staff', $conv->last_reply_via);
    }

    public function test_import_idempoten_tak_gandakan_pesan(): void
    {
        $this->connect();
        Http::fake([
            '*/conversations/*/messages*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'M1', 'content' => json_encode(['content' => 'Halo']), 'sender' => ['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi'], 'create_time' => 1_757_000_000],
            ]]]),
            '*/conversations*' => Http::response(['code' => 0, 'data' => ['conversations' => [
                ['id' => 'CONV1', 'participants' => [['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi']]],
            ]]]),
        ]);

        $svc = app(EcomChatService::class);
        $svc->importFromTikTok();
        $second = $svc->importFromTikTok();

        $this->assertSame(0, $second['messages']); // dedupe by external_message_id
        $this->assertSame(1, EcomChatConversation::where('external_conversation_id', 'CONV1')->first()->messages()->count());
    }
}
