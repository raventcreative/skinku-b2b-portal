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
