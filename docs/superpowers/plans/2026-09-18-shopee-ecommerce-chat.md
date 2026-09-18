# Chat E-commerce — Channel Shopee Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menambah Shopee sebagai channel kedua pada modul Chat E-commerce (inbox, AI draft, kirim/terima) — paritas penuh dengan TikTok, via push webhook realtime.

**Architecture:** Shopee jadi channel kedua di data & UI yang sudah channel-agnostic. Metode API `sellerchat` ditambahkan ke `ShopeeClient` (pola sama dengan `getOrderList`). Pengirim balasan di-abstraksi jadi `EcomChatSender` per-channel. Push masuk lewat `ShopeePushController` (verifikasi tanda tangan `partner_key`), dinormalisasi, lalu masuk `EcomChatService::syncIncoming('shopee', …)` dan drafter AI yang sudah ada.

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + vanilla JS, `Illuminate\Support\Facades\Http` untuk API, PHPUnit. Zero-dependency (tanpa paket composer/npm baru).

## Global Constraints

- Zero-dependency: TIDAK menambah paket composer/npm apa pun.
- Runner tes: `/c/php83/php.exe artisan test`. Format: `/c/php83/php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- Claude push `origin/main` dari lokal; user `git pull` + `php artisan migrate --force` (bila ada migrasi) + `php artisan optimize:clear` di prod. Migrasi baru pakai penomoran `2026_01_01_0001XX` (terakhir: `000137`).
- Claude TIDAK login/otorisasi Shopee atas nama user & TIDAK pernah melihat `partner_key` (ada di `.env` server; verifikasi tanda tangan berjalan di server).
- Dedupe pesan masuk by (`channel`, `external_message_id`) — sudah dijamin `syncIncoming`.
- Regresi TikTok NOL: jalur TikTok yang ada tak boleh berubah perilaku.
- Semua endpoint Shopee lewat `ShopeeClient` (private `shopCall()` yang sudah bertanda-tangan) — jangan bikin HTTP client baru.

## Token & koneksi Shopee (dipakai semua task)

```php
$sync = app(\App\Services\ShopeeSyncService::class);
$conn = $sync->connection();            // ShopeeConnection (latest) | null
$access = $sync->freshToken($conn);     // access_token segar (auto-refresh ~4 jam)
$shopId = (string) $conn->shop_id;
```

## Bentuk pesan ternormalisasi (kontrak internal)

`EcomChatService::syncIncoming(string $channel, array $msg)` menerima:

```php
[
  'conversation_id' => string,   // wajib
  'message_id'      => string,   // wajib (dedupe)
  'sender'          => 'buyer',  // hanya 'buyer' yang diproses (anti-loop)
  'sent_at'         => int,      // unix timestamp (opsional)
  'type'            => string,   // text|image|video|product_card|order_card|other
  'text'            => string,   // teks / caption / placeholder
  'meta'            => array|null,// {url}|{product_id,item_name,price,image,shop_id}|{order_id}
  'buyer_name'      => ?string,
  'buyer_id'        => ?string,  // Shopee user_id pembeli (dipakai to_id saat kirim)
]
```

Tipe yang dirender `resources/views/ecom-chat/_thread.blade.php`: `text`, `image`, `video`,
`product_card` (`meta.product_id`), `order_card`/`logistics_card` (`meta.order_id`), `other`.

## File Structure

- **Create** `app/Services/ShopeeChatParser.php` — ubah 1 pesan Shopee (dari API/push) → array ternormalisasi di atas. Satu tanggung jawab: pemetaan format.
- **Create** `app/Contracts/EcomChatSender.php` — antarmuka pengirim balasan per-channel.
- **Create** `app/Services/EcomChat/TikTokChatSender.php` — bungkus jalur kirim TikTok yang ada.
- **Create** `app/Services/EcomChat/ShopeeChatSender.php` — kirim balasan lewat `ShopeeClient::sendChatMessage`.
- **Create** `app/Http/Controllers/ShopeePushController.php` — terima push Shopee, verifikasi tanda tangan, normalisasi, teruskan ke service.
- **Create** `app/Console/Commands/ShopeeChatCheckCommand.php` — smoke test live (Fase 0) + dump fixture.
- **Create** `app/Models/ShopeeProduct.php` + migrasi `2026_01_01_000138_create_shopee_products.php` — cache nama/gambar/harga item (kartu produk).
- **Modify** `app/Services/ShopeeClient.php` — tambah metode chat: `getConversationList`, `getMessages`, `sendChatMessage`, `getItemBaseInfo`.
- **Modify** `app/Services/EcomChatService.php` — `send()` pilih sender per channel; `importFromShopee()`; `syncIncoming` simpan `meta`.
- **Modify** `app/Http/Controllers/EcomChatController.php` — `threadData()` channel-aware (resolve produk/order Shopee).
- **Modify** `resources/views/ecom-chat/_thread.blade.php` — tautan produk per-channel (Shopee vs TikTok).
- **Modify** `app/Console/Commands/EcomChatSyncCommand.php` — ikut tarik Shopee.
- **Modify** `routes/web.php` — rute `POST /webhooks/shopee/push`.
- **Modify** `bootstrap/app.php` — kecualikan `webhooks/shopee/push` dari CSRF.

---

## Task 1: Metode API chat di `ShopeeClient`

**Files:**
- Modify: `app/Services/ShopeeClient.php`
- Test: `tests/Feature/ShopeeChatClientTest.php` (create)

**Interfaces:**
- Consumes: `shopCall(string $method, string $path, string $accessToken, string $shopId, array $params)` (private, sudah ada) yang mengembalikan `array` respons JSON penuh (`['response'=>[...], 'error'=>'', ...]`).
- Produces:
  - `getConversationList(string $accessToken, string $shopId, string $direction='latest', string $type='all', int $pageSize=25): array`
  - `getMessages(string $accessToken, string $shopId, string $conversationId, int $pageSize=25): array`
  - `sendChatMessage(string $accessToken, string $shopId, int $toId, string $text): array`
  - `getItemBaseInfo(string $accessToken, string $shopId, array $itemIds): array`

- [ ] **Step 1: Tulis tes yang gagal**

```php
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
```

- [ ] **Step 2: Jalankan tes — pastikan gagal**

Run: `/c/php83/php.exe artisan test --filter=ShopeeChatClientTest`
Expected: FAIL (`Call to undefined method ShopeeClient::sendChatMessage`).

- [ ] **Step 3: Tambahkan metode di `ShopeeClient`** (setelah `getWalletTransactionList`, ikut pola metode `shopCall` yang ada)

```php
/** Daftar percakapan chat (Seller Chat). */
public function getConversationList(string $accessToken, string $shopId, string $direction = 'latest', string $type = 'all', int $pageSize = 25): array
{
    return $this->shopCall('GET', '/api/v2/sellerchat/get_conversation_list', $accessToken, $shopId, [
        'direction' => $direction,
        'type' => $type,
        'page_size' => $pageSize,
    ]);
}

/** Pesan-pesan dalam 1 percakapan (terbaru dulu). */
public function getMessages(string $accessToken, string $shopId, string $conversationId, int $pageSize = 25): array
{
    return $this->shopCall('GET', '/api/v2/sellerchat/get_message', $accessToken, $shopId, [
        'conversation_id' => $conversationId,
        'page_size' => $pageSize,
    ]);
}

/** Kirim balasan teks ke pembeli (to_id = user_id pembeli). */
public function sendChatMessage(string $accessToken, string $shopId, int $toId, string $text): array
{
    return $this->shopCall('POST', '/api/v2/sellerchat/send_message', $accessToken, $shopId, [
        'to_id' => $toId,
        'message_type' => 'text',
        'content' => ['text' => $text],
    ]);
}

/** Info dasar item (nama/gambar/harga) untuk kartu produk. */
public function getItemBaseInfo(string $accessToken, string $shopId, array $itemIds): array
{
    return $this->shopCall('GET', '/api/v2/product/get_item_base_info', $accessToken, $shopId, [
        'item_id_list' => implode(',', array_map('intval', $itemIds)),
    ]);
}
```

> **Catatan Fase 0:** nama endpoint & bidang di atas mengikuti dokumentasi Shopee Open
> Platform. Task 2 memverifikasi ke API asli; bila ada beda field, sesuaikan di sini.

- [ ] **Step 4: Jalankan tes — pastikan lulus**

Run: `/c/php83/php.exe artisan test --filter=ShopeeChatClientTest`
Expected: PASS.

- [ ] **Step 5: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/ShopeeClient.php tests/Feature/ShopeeChatClientTest.php
git commit -m "feat(shopee-chat): metode API sellerchat di ShopeeClient"
```

---

## Task 2: Fase 0 — verifikasi live + tangkap fixture (GATE)

**Prasyarat eksternal (aksi user):** modul Chat aktif di Shopee Open Platform, Push URL
`https://system.skinku.id/webhooks/shopee/push` terdaftar + push "message" aktif, toko
diotorisasi ulang bila perlu. **Task ini dijalankan di/oleh prod (SSH), bukan lokal**,
karena butuh token toko asli.

**Files:**
- Create: `app/Console/Commands/ShopeeChatCheckCommand.php`
- Create (hasil): `tests/fixtures/shopee-chat/conversation_list.json`, `.../push_message.json`

**Interfaces:**
- Consumes: `ShopeeClient::getConversationList`, `ShopeeSyncService::connection()/freshToken()`.
- Produces: command `shopee:chat-check` yang mencetak daftar percakapan + menyimpan respons
  mentah ke file fixture; konfirmasi tertulis (di komentar plan/README) untuk: formula
  tanda tangan push, nama field `conversation_id`/`message_id`/`from_shop_id`/`to_shop_id`,
  dan bentuk `content` per `message_type`.

- [ ] **Step 1: Buat command smoke-test**

```php
<?php

namespace App\Console\Commands;

use App\Services\ShopeeClient;
use App\Services\ShopeeSyncService;
use Illuminate\Console\Command;

class ShopeeChatCheckCommand extends Command
{
    protected $signature = 'shopee:chat-check {--dump= : path simpan JSON mentah}';

    protected $description = 'Fase 0: cek API chat Shopee reachable & dump bentuk respons.';

    public function handle(ShopeeClient $client, ShopeeSyncService $sync): int
    {
        $conn = $sync->connection();
        if (! $conn) {
            $this->error('Belum ada koneksi Shopee (ShopeeConnection kosong).');

            return self::FAILURE;
        }

        $res = $client->getConversationList($sync->freshToken($conn), (string) $conn->shop_id);
        if (($res['error'] ?? '') !== '') {
            $this->error('API error: '.json_encode($res));

            return self::FAILURE;
        }

        $convs = $res['response']['conversations'] ?? [];
        $this->info(count($convs).' percakapan terbaca. Contoh field: '.implode(', ', array_keys($convs[0] ?? [])));

        if ($path = $this->option('dump')) {
            file_put_contents($path, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Disimpan ke '.$path);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: (prod) jalankan & simpan fixture percakapan**

Run (SSH prod): `php artisan shopee:chat-check --dump=storage/app/shopee_conv.json`
Expected: mencetak jumlah percakapan > 0 dan daftar nama field. Salin `storage/app/shopee_conv.json`
→ `tests/fixtures/shopee-chat/conversation_list.json` (rapikan jadi 1 contoh percakapan + 1 pesan).

- [ ] **Step 3: tangkap 1 push asli**

Sementara, aktifkan log di `ShopeePushController` (Task 4) `Log::info('shopee-push', $request->all())`
ATAU minta user kirim 1 chat uji ke toko; ambil body + header `Authorization` dari
`storage/logs/laravel.log`. Simpan body → `tests/fixtures/shopee-chat/push_message.json`.
Catat nilai header `Authorization` & URL untuk konfirmasi formula tanda tangan.

- [ ] **Step 4: konfirmasi & sesuaikan**

Bandingkan fixture dengan asumsi di Task 1/4/5. Bila beda (nama field/`code`/bentuk `content`),
**perbarui**: metode `ShopeeClient` (Task 1), formula verifikasi (Task 4), pemetaan parser (Task 5).
Tulis ringkasan temuan sebagai komentar di bagian atas `ShopeeChatParser.php` (dibuat Task 5).

- [ ] **Step 5: commit command + fixtures**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Console/Commands/ShopeeChatCheckCommand.php tests/fixtures/shopee-chat/
git commit -m "chore(shopee-chat): Fase 0 smoke-test + fixture payload asli"
```

> **GATE:** jangan lanjut ke Task 5–7 (parser & enrichment) sebelum fixture asli ada.
> Task 3 & 4 (sender & push controller kerangka) boleh jalan paralel karena tak
> bergantung bentuk `content` detail.

---

## Task 3: Abstraksi pengirim per-channel (`EcomChatSender`)

**Files:**
- Create: `app/Contracts/EcomChatSender.php`, `app/Services/EcomChat/TikTokChatSender.php`, `app/Services/EcomChat/ShopeeChatSender.php`
- Modify: `app/Services/EcomChatService.php` (metode `send()` + helper `senderFor()`)
- Test: `tests/Feature/EcomChatSenderRoutingTest.php` (create)

**Interfaces:**
- Consumes: `EcomChatConversation` (`channel`, `external_conversation_id`, `buyer_id`), `ShopeeClient::sendChatMessage`, `ShopeeSyncService`.
- Produces:
  - `interface EcomChatSender { public function send(EcomChatConversation $conv, string $text): array; }` → mengembalikan `['message_id' => string]`.
  - `EcomChatService::send()` memakai `senderFor($conv->channel)`.

- [ ] **Step 1: Tulis tes routing (gagal)**

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Services\EcomChatService;
use App\Services\ShopeeClient;
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
        \App\Models\ShopeeConnection::create(['shop_id' => '426938728', 'access_token' => 'A', 'refresh_token' => 'R', 'expires_at' => now()->addHours(3)]);

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
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `/c/php83/php.exe artisan test --filter=EcomChatSenderRoutingTest`
Expected: FAIL (channel shopee masih dikirim lewat jalur TikTok / error).

- [ ] **Step 3: Buat kontrak + dua sender**

`app/Contracts/EcomChatSender.php`:

```php
<?php

namespace App\Contracts;

use App\Models\EcomChatConversation;

interface EcomChatSender
{
    /** Kirim teks ke pembeli; kembalikan ['message_id' => string]. */
    public function send(EcomChatConversation $conv, string $text): array;
}
```

`app/Services/EcomChat/ShopeeChatSender.php`:

```php
<?php

namespace App\Services\EcomChat;

use App\Contracts\EcomChatSender;
use App\Models\EcomChatConversation;
use App\Services\ShopeeClient;
use App\Services\ShopeeSyncService;
use RuntimeException;

class ShopeeChatSender implements EcomChatSender
{
    public function __construct(private ShopeeClient $client, private ShopeeSyncService $sync) {}

    public function send(EcomChatConversation $conv, string $text): array
    {
        $conn = $this->sync->connection();
        if (! $conn) {
            throw new RuntimeException('Toko Shopee belum terhubung.');
        }
        $res = $this->client->sendChatMessage($this->sync->freshToken($conn), (string) $conn->shop_id, (int) $conv->buyer_id, $text);
        if (($res['error'] ?? '') !== '') {
            throw new RuntimeException('Shopee tolak kirim: '.($res['message'] ?? $res['error']));
        }

        return ['message_id' => (string) ($res['response']['message_id'] ?? ('local-'.uniqid()))];
    }
}
```

`app/Services/EcomChat/TikTokChatSender.php` — pindahkan isi kirim TikTok yang ADA di
`EcomChatService::send()` (baris pemanggilan `chatConn()/chatClient()/affiliate->freshToken/shop_cipher`)
ke sini apa adanya:

```php
<?php

namespace App\Services\EcomChat;

use App\Contracts\EcomChatSender;
use App\Models\EcomChatConversation;
use App\Services\EcomChatService;

class TikTokChatSender implements EcomChatSender
{
    public function __construct(private EcomChatService $service) {}

    public function send(EcomChatConversation $conv, string $text): array
    {
        // Jalur TikTok yang ada: pakai helper existing di EcomChatService (chatConn/chatClient/affiliate).
        return $this->service->sendViaTikTok($conv, $text);
    }
}
```

> Ekstrak baris pengiriman TikTok lama menjadi `public function sendViaTikTok(EcomChatConversation $conv, string $text): array`
> di `EcomChatService` yang mengembalikan `['message_id' => $externalId]` (pindahan murni, tanpa ubah perilaku).

- [ ] **Step 4: Ubah `EcomChatService::send()` agar route by channel**

```php
public function send(EcomChatConversation $conv, string $text, string $via): EcomChatMessage
{
    $res = $this->senderFor($conv->channel)->send($conv, $text);
    $externalId = (string) ($res['message_id'] ?? ('local-'.uniqid()));

    $msg = $conv->messages()->create([
        'channel' => $conv->channel,
        'external_message_id' => $externalId,
        'sender' => EcomChatMessage::SENDER_SELLER,
        'via' => $via,
        'text' => $text,
        'sent_at' => now(),
    ]);

    $conv->update([
        'status' => EcomChatConversation::STATUS_REPLIED,
        'last_reply_via' => $via,
        'last_message_at' => now(),
        'last_message_preview' => mb_substr($text, 0, 255),
    ]);

    return $msg;
}

private function senderFor(string $channel): \App\Contracts\EcomChatSender
{
    return match ($channel) {
        'shopee' => app(\App\Services\EcomChat\ShopeeChatSender::class),
        'tiktok' => app(\App\Services\EcomChat\TikTokChatSender::class),
        default => throw new \RuntimeException("Channel chat tak dikenal: {$channel}"),
    };
}
```

- [ ] **Step 5: Jalankan tes rute + regresi TikTok**

Run: `/c/php83/php.exe artisan test --filter='EcomChatSenderRoutingTest|EcomChatServiceTest|EcomChatControllerTest'`
Expected: PASS semua (TikTok tak berubah perilaku).

- [ ] **Step 6: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Contracts/EcomChatSender.php app/Services/EcomChat/ app/Services/EcomChatService.php tests/Feature/EcomChatSenderRoutingTest.php
git commit -m "refactor(ecom-chat): pengirim balasan per-channel + rute Shopee"
```

---

## Task 4: `ShopeePushController` + rute + verifikasi tanda tangan (teks E2E)

**Files:**
- Create: `app/Http/Controllers/ShopeePushController.php`
- Modify: `routes/web.php`, `bootstrap/app.php`
- Test: `tests/Feature/ShopeePushTest.php` (create)

**Interfaces:**
- Consumes: `EcomChatService::syncIncoming('shopee', $msg)`, `EcomChatService::processDraft($conv)`, `config('services.shopee.partner_key')`.
- Produces: `POST /webhooks/shopee/push` → 200 (diproses/diabaikan) / 401 (tanda tangan salah).

- [ ] **Step 1: Tulis tes push (gagal)**

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `/c/php83/php.exe artisan test --filter=ShopeePushTest`
Expected: FAIL (rute belum ada → 404).

- [ ] **Step 3: Buat controller**

```php
<?php

namespace App\Http\Controllers;

use App\Services\EcomChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopeePushController extends Controller
{
    public function __construct(private EcomChatService $chat) {}

    public function handle(Request $request)
    {
        $raw = $request->getContent();
        if (! $this->validSignature($request, $raw)) {
            return response('invalid signature', 401);
        }

        $payload = json_decode($raw, true) ?: [];
        $data = $payload['data'] ?? [];

        // Hanya push chat/message; sisanya (order dll) diabaikan dgn 200.
        if (($data['type'] ?? null) !== 'message' || empty($data['content'])) {
            return response('ignored', 200);
        }

        try {
            $c = $data['content'];
            $shopId = (int) ($payload['shop_id'] ?? 0);
            $fromShop = (int) ($c['from_shop_id'] ?? 0);
            // Pesan dari TOKO sendiri (echo) → biar syncIncoming yang abaikan (sender != buyer).
            $sender = ($fromShop !== 0 && $fromShop === $shopId) ? 'seller' : 'buyer';

            $conv = $this->chat->syncIncoming('shopee', [
                'conversation_id' => (string) ($c['conversation_id'] ?? ''),
                'message_id' => (string) ($c['message_id'] ?? ''),
                'sender' => $sender,
                'sent_at' => (int) ($c['created_timestamp'] ?? 0),
                'type' => 'text',
                'text' => (string) ($c['content']['text'] ?? ''),
                'buyer_name' => $c['from_user_name'] ?? null,
                'buyer_id' => isset($c['from_id']) ? (string) $c['from_id'] : null,
            ]);

            if ($conv) {
                $this->chat->processDraft($conv->conversation);
            }
        } catch (\Throwable $e) {
            Log::error('shopee push gagal', ['e' => $e->getMessage()]);
        }

        return response('ok', 200);
    }

    private function validSignature(Request $request, string $raw): bool
    {
        $key = (string) config('services.shopee.partner_key');
        if ($key === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $request->url().'|'.$raw, $key);

        return hash_equals($expected, (string) $request->header('Authorization', ''));
    }
}
```

> `syncIncoming` mengembalikan `EcomChatMessage` (punya relasi `->conversation`), atau `null`
> bila diabaikan/dobel. Pastikan `processDraft` menerima `EcomChatConversation` — pakai
> `$conv->conversation`.

- [ ] **Step 4: Daftarkan rute + kecualikan CSRF**

`routes/web.php` (dekat rute `webhooks/tiktok/chat`, di luar grup auth):

```php
use App\Http\Controllers\ShopeePushController;
Route::post('/webhooks/shopee/push', [ShopeePushController::class, 'handle'])->name('webhooks.shopee.push');
```

`bootstrap/app.php` (di array `validateCsrfTokens(except: [...])`, tambah baris):

```php
'webhooks/shopee/push',
```

- [ ] **Step 5: Jalankan — pastikan lulus**

Run: `/c/php83/php.exe artisan test --filter=ShopeePushTest`
Expected: PASS.

- [ ] **Step 6: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/ShopeePushController.php routes/web.php bootstrap/app.php tests/Feature/ShopeePushTest.php
git commit -m "feat(shopee-chat): push webhook + verifikasi tanda tangan (teks E2E)"
```

> **Setelah Task 4:** teks E2E jalan. Fase 1 selesai. Verifikasi live (Task 2 Step 3)
> memakai controller ini untuk menangkap payload asli sebelum Task 5.

---

## Task 5: Parser paritas penuh + simpan `meta`

**Files:**
- Create: `app/Services/ShopeeChatParser.php`
- Modify: `app/Services/EcomChatService.php` (`syncIncoming` simpan `meta`), `app/Http/Controllers/ShopeePushController.php` (pakai parser)
- Test: `tests/Feature/ShopeeChatParserTest.php` (create)

**Interfaces:**
- Consumes: satu pesan Shopee mentah (`content` push atau `messages[]` API) — bentuk dari fixture Task 2.
- Produces: `ShopeeChatParser::normalize(array $raw, int $shopId): array` → array ternormalisasi (lihat kontrak internal di atas), termasuk `type` & `meta`.

- [ ] **Step 1: Tulis tes parser (gagal)** — pakai fixture Task 2 sebagai acuan bentuk `content`

```php
<?php

namespace Tests\Feature;

use App\Services\ShopeeChatParser;
use Tests\TestCase;

class ShopeeChatParserTest extends TestCase
{
    private function raw(string $type, array $content): array
    {
        return ['conversation_id' => 'C1', 'message_id' => 'M', 'from_id' => 555001,
            'from_shop_id' => 0, 'from_user_name' => 'Budi', 'message_type' => $type,
            'content' => $content, 'created_timestamp' => 1789000000];
    }

    public function test_teks(): void
    {
        $n = app(ShopeeChatParser::class)->normalize($this->raw('text', ['text' => 'Halo']), 426938728);
        $this->assertSame('text', $n['type']);
        $this->assertSame('Halo', $n['text']);
    }

    public function test_gambar(): void
    {
        $n = app(ShopeeChatParser::class)->normalize($this->raw('image', ['url' => 'https://x/i.jpg']), 426938728);
        $this->assertSame('image', $n['type']);
        $this->assertSame('https://x/i.jpg', $n['meta']['url']);
    }

    public function test_produk(): void
    {
        $n = app(ShopeeChatParser::class)->normalize($this->raw('item', ['item_id' => 987, 'shop_id' => 426938728]), 426938728);
        $this->assertSame('product_card', $n['type']);
        $this->assertSame('987', $n['meta']['product_id']);
    }

    public function test_order(): void
    {
        $n = app(ShopeeChatParser::class)->normalize($this->raw('order', ['order_sn' => '2609ABC']), 426938728);
        $this->assertSame('order_card', $n['type']);
        $this->assertSame('2609ABC', $n['meta']['order_id']);
    }

    public function test_tak_dikenal_jadi_other(): void
    {
        $n = app(ShopeeChatParser::class)->normalize($this->raw('sticker', ['sticker_id' => 1]), 426938728);
        $this->assertSame('other', $n['type']);
    }
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `/c/php83/php.exe artisan test --filter=ShopeeChatParserTest`
Expected: FAIL (kelas belum ada).

- [ ] **Step 3: Buat parser** (sesuaikan nama field ke fixture Task 2)

```php
<?php

namespace App\Services;

/**
 * Peta 1 pesan Shopee (dari push/get_message) → array ternormalisasi untuk
 * EcomChatService::syncIncoming. Bentuk `content` per tipe DIVERIFIKASI di Fase 0
 * (tests/fixtures/shopee-chat/*.json). Sesuaikan bila field berbeda.
 */
class ShopeeChatParser
{
    public function normalize(array $raw, int $shopId): array
    {
        $type = (string) ($raw['message_type'] ?? 'text');
        $c = $raw['content'] ?? [];
        $fromShop = (int) ($raw['from_shop_id'] ?? 0);

        $out = [
            'conversation_id' => (string) ($raw['conversation_id'] ?? ''),
            'message_id' => (string) ($raw['message_id'] ?? ''),
            'sender' => ($fromShop !== 0 && $fromShop === $shopId) ? 'seller' : 'buyer',
            'sent_at' => (int) ($raw['created_timestamp'] ?? 0),
            'buyer_name' => $raw['from_user_name'] ?? null,
            'buyer_id' => isset($raw['from_id']) ? (string) $raw['from_id'] : null,
            'type' => 'text', 'text' => '', 'meta' => null,
        ];

        switch ($type) {
            case 'text':
                $out['text'] = (string) ($c['text'] ?? '');
                break;
            case 'image':
                $out['type'] = 'image';
                $out['meta'] = ['url' => (string) ($c['url'] ?? $c['thumb_url'] ?? '')];
                $out['text'] = '[Foto]';
                break;
            case 'video':
                $out['type'] = 'video';
                $out['meta'] = ['url' => (string) ($c['url'] ?? '')];
                $out['text'] = '[Video]';
                break;
            case 'item':
                $out['type'] = 'product_card';
                $out['meta'] = array_filter([
                    'product_id' => (string) ($c['item_id'] ?? ''),
                    'shop_id' => (string) ($c['shop_id'] ?? $shopId),
                    'item_name' => $c['item_name'] ?? null,
                    'price' => $c['price'] ?? null,
                    'image' => $c['item_image'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                $out['text'] = '[Produk]';
                break;
            case 'order':
                $out['type'] = 'order_card';
                $out['meta'] = ['order_id' => (string) ($c['order_sn'] ?? '')];
                $out['text'] = '[Pesanan]';
                break;
            default:
                $out['type'] = 'other';
                $out['text'] = '[Pesan '.$type.']';
        }

        return $out;
    }
}
```

- [ ] **Step 4: `syncIncoming` simpan `meta`** — di `EcomChatService::syncIncoming`, pada `messages()->create([...])` tambah baris:

```php
'meta' => $msg['meta'] ?? null,
```

Pastikan `App\Models\EcomChatMessage` meng-cast `meta` → `array` (cek `$casts`; migrasi
`000131` menambah kolom `meta`). Bila belum ada cast, tambahkan `'meta' => 'array'`.

- [ ] **Step 5: Pakai parser di push controller** — ganti blok normalisasi manual di
`ShopeePushController::handle` menjadi:

```php
$normalized = app(\App\Services\ShopeeChatParser::class)->normalize($data['content'], (int) ($payload['shop_id'] ?? 0));
$msg = $this->chat->syncIncoming('shopee', $normalized);
if ($msg) {
    $this->chat->processDraft($msg->conversation);
}
```

- [ ] **Step 6: Jalankan tes parser + push + regresi**

Run: `/c/php83/php.exe artisan test --filter='ShopeeChatParserTest|ShopeePushTest|EcomChat'`
Expected: PASS.

- [ ] **Step 7: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/ShopeeChatParser.php app/Services/EcomChatService.php app/Http/Controllers/ShopeePushController.php app/Models/EcomChatMessage.php tests/Feature/ShopeeChatParserTest.php
git commit -m "feat(shopee-chat): parser paritas penuh (gambar/produk/order) + simpan meta"
```

---

## Task 6: Enrichment kartu produk/pesanan Shopee di thread

**Files:**
- Create: `app/Models/ShopeeProduct.php`, `database/migrations/2026_01_01_000138_create_shopee_products.php`
- Modify: `app/Http/Controllers/EcomChatController.php` (`threadData()` channel-aware), `resources/views/ecom-chat/_thread.blade.php` (tautan produk per-channel)
- Test: `tests/Feature/EcomChatShopeeThreadTest.php` (create)

**Interfaces:**
- Consumes: `EcomChatMessage.meta` (`product_id`/`order_id`), `ShopeeOrder` (`order_sn`, `status`, `total_amount`, `line_items`), `ShopeeClient::getItemBaseInfo`.
- Produces: view thread merender kartu produk & pesanan Shopee; `$products` keyed by `product_id` punya `->title/->image_url/->price`; `$orders` keyed by `order_id`.

- [ ] **Step 1: Migrasi + model cache produk Shopee**

`database/migrations/2026_01_01_000138_create_shopee_products.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_products', function (Blueprint $table) {
            $table->id();
            $table->string('item_id')->unique();
            $table->string('title')->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency', 8)->default('IDR');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_products');
    }
};
```

`app/Models/ShopeeProduct.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProduct extends Model
{
    protected $fillable = ['item_id', 'title', 'image_url', 'price', 'currency'];

    protected $casts = ['price' => 'decimal:2'];
}
```

- [ ] **Step 2: Tulis tes thread Shopee (gagal)**

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\ShopeeOrder;
use App\Models\ShopeeProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcomChatShopeeThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_render_kartu_produk_dan_pesanan_shopee(): void
    {
        $admin = User::factory()->create(['role' => 'admin']); // sesuaikan helper user peran admin bila beda
        ShopeeProduct::create(['item_id' => '987', 'title' => 'Sabun Mizu', 'image_url' => 'https://x/p.jpg', 'price' => 25000]);
        ShopeeOrder::create(['order_sn' => '2609ABC', 'status' => 'COMPLETED', 'total_amount' => 50000, 'currency' => 'IDR', 'line_items' => [['name' => 'Sabun', 'qty' => 2]]]);

        $conv = EcomChatConversation::create(['channel' => 'shopee', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi', 'status' => 'needs_staff']);
        $conv->messages()->create(['channel' => 'shopee', 'external_message_id' => 'P', 'sender' => 'buyer', 'via' => 'buyer', 'type' => 'product_card', 'text' => '[Produk]', 'meta' => ['product_id' => '987'], 'sent_at' => now()]);
        $conv->messages()->create(['channel' => 'shopee', 'external_message_id' => 'O', 'sender' => 'buyer', 'via' => 'buyer', 'type' => 'order_card', 'text' => '[Pesanan]', 'meta' => ['order_id' => '2609ABC'], 'sent_at' => now()]);

        $this->actingAs($admin)->get('/ecom-chat/'.$conv->id.'/thread')
            ->assertOk()->assertSee('Sabun Mizu')->assertSee('COMPLETED');
    }
}
```

- [ ] **Step 3: `threadData()` channel-aware** — di `EcomChatController::threadData` (yang sudah
membangun `$products` & `$orders` untuk TikTok), cabangkan by `$conversation->channel`:

```php
if ($conversation->channel === 'shopee') {
    $productIds = $messages->where('type', 'product_card')->pluck('meta.product_id')->filter()->unique();
    $products = \App\Models\ShopeeProduct::whereIn('item_id', $productIds)->get()->keyBy('item_id');

    $orderSns = $messages->where('type', 'order_card')->pluck('meta.order_id')->filter()->unique();
    $orders = \App\Models\ShopeeOrder::whereIn('order_sn', $orderSns)->get()->keyBy('order_sn');
} else {
    // ... jalur TikTok yang sudah ada (tak diubah) ...
}
```

> `ShopeeOrder` sudah punya `order_sn`, `status`, `total_amount`, `line_items` — kompatibel
> dengan yang dibaca view (`->line_items`, `->total_amount`, `->status`). `ShopeeProduct`
> menyediakan `->title/->image_url/->price` sesuai yang dibaca view.

- [ ] **Step 4: Tautan produk per-channel di `_thread.blade.php`** — ganti URL produk yang
hardcoded TikTok:

```blade
@php($prodUrl = $conversation->channel === 'shopee'
    ? 'https://shopee.co.id/product/'.($productMeta['shop_id'] ?? '').'/'.$productId
    : 'https://shop-id.tokopedia.com/view/product/'.$productId)
<a href="{{ $prodUrl }}" target="_blank" rel="noopener" class="text-[11px] text-sky-600 underline">Buka produk ↗</a>
```

(ambil `$productMeta = $m->meta ?? []` di sekitar blok product_card).

- [ ] **Step 5: (opsional) isi cache produk saat parsing** — bila `meta.item_name` kosong,
`ShopeeChatParser`/enrichment memanggil `ShopeeClient::getItemBaseInfo` sekali & `updateOrCreate`
`ShopeeProduct`. Bila API gagal → kartu tetap tampil pakai ID (degradasi anggun; view sudah
menangani `title` null → tampilkan ID + tautan).

- [ ] **Step 6: Jalankan tes + migrasi**

Run: `/c/php83/php.exe artisan test --filter='EcomChatShopeeThreadTest|EcomChatUiRenderTest'`
Expected: PASS.

- [ ] **Step 7: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Models/ShopeeProduct.php database/migrations/2026_01_01_000138_create_shopee_products.php app/Http/Controllers/EcomChatController.php resources/views/ecom-chat/_thread.blade.php tests/Feature/EcomChatShopeeThreadTest.php
git commit -m "feat(shopee-chat): kartu produk & pesanan Shopee di thread"
```

---

## Task 7: Sinkron 2 arah Shopee di `ecom-chat:sync`

**Files:**
- Modify: `app/Services/EcomChatService.php` (tambah `importFromShopee(int $max, int $msgPer)`), `app/Console/Commands/EcomChatSyncCommand.php`
- Test: `tests/Feature/EcomChatShopeeSyncTest.php` (create)

**Interfaces:**
- Consumes: `ShopeeClient::getConversationList/getMessages`, `ShopeeChatParser::normalize`, `ShopeeSyncService`.
- Produces: `EcomChatService::importFromShopee(int $max = 100, int $msgPer = 10): array{conversations:int,messages:int}`; command ikut memanggilnya.

- [ ] **Step 1: Tulis tes sync (gagal)**

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\ShopeeConnection;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatShopeeSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_shopee_menandai_balasan_seller_center(): void
    {
        config()->set('services.shopee.partner_id', '1');
        config()->set('services.shopee.partner_key', 'k');
        ShopeeConnection::create(['shop_id' => '426938728', 'access_token' => 'A', 'refresh_token' => 'R', 'expires_at' => now()->addHours(3)]);

        Http::fake([
            '*get_conversation_list*' => Http::response(['response' => ['conversations' => [['conversation_id' => 'C1', 'to_id' => 555001, 'to_name' => 'Budi']]], 'error' => '']),
            '*get_message*' => Http::response(['response' => ['messages' => [
                ['conversation_id' => 'C1', 'message_id' => 'B1', 'from_id' => 555001, 'from_shop_id' => 0, 'message_type' => 'text', 'content' => ['text' => 'Halo'], 'created_timestamp' => 1789000000],
                ['conversation_id' => 'C1', 'message_id' => 'S1', 'from_id' => 426938728, 'from_shop_id' => 426938728, 'message_type' => 'text', 'content' => ['text' => 'Siap kak'], 'created_timestamp' => 1789000100],
            ]], 'error' => '']),
        ]);

        app(EcomChatService::class)->importFromShopee();

        $conv = EcomChatConversation::where('channel', 'shopee')->where('external_conversation_id', 'C1')->first();
        $this->assertNotNull($conv);
        $this->assertDatabaseHas('ecom_chat_messages', ['channel' => 'shopee', 'external_message_id' => 'B1']);
        $this->assertSame('replied', $conv->status); // pesan terbaru dari seller → sudah dibalas
    }
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `/c/php83/php.exe artisan test --filter=EcomChatShopeeSyncTest`
Expected: FAIL (`importFromShopee` belum ada).

- [ ] **Step 3: Implement `importFromShopee`** di `EcomChatService` — pola sama dengan
`importFromTikTok` yang ada (loop percakapan → tarik pesan → `storeSyncedMessage`/`syncIncoming`
→ set status by pesan terbaru: buyer-terbaru → `open`, seller-terbaru → `replied` + `last_reply_via='staff'`).
Gunakan `ShopeeChatParser::normalize` untuk tiap pesan; simpan lewat jalur yang sama dengan
webhook (idempoten by channel+message_id). Kembalikan `['conversations'=>N, 'messages'=>M]`.

```php
public function importFromShopee(int $max = 100, int $msgPer = 10): array
{
    $sync = app(\App\Services\ShopeeSyncService::class);
    $conn = $sync->connection();
    if (! $conn) {
        return ['conversations' => 0, 'messages' => 0];
    }
    $client = app(\App\Services\ShopeeClient::class);
    $parser = app(\App\Services\ShopeeChatParser::class);
    $access = $sync->freshToken($conn);
    $shopId = (int) $conn->shop_id;

    $convs = $client->getConversationList($access, (string) $shopId, 'latest', 'all', $max)['response']['conversations'] ?? [];
    $nConv = 0;
    $nMsg = 0;
    foreach ($convs as $c) {
        $cid = (string) ($c['conversation_id'] ?? '');
        if ($cid === '') {
            continue;
        }
        $nConv++;
        $raws = $client->getMessages($access, (string) $shopId, $cid, $msgPer)['response']['messages'] ?? [];
        // urut lama→baru agar status akhir mencerminkan pesan terbaru
        usort($raws, fn ($a, $b) => ($a['created_timestamp'] ?? 0) <=> ($b['created_timestamp'] ?? 0));
        foreach ($raws as $raw) {
            $norm = $parser->normalize($raw, $shopId);
            $norm['buyer_name'] = $norm['buyer_name'] ?: ($c['to_name'] ?? null);
            $norm['buyer_id'] = $norm['buyer_id'] ?: (isset($c['to_id']) ? (string) $c['to_id'] : null);
            if ($this->storeSyncedMessage('shopee', $cid, $norm)) {
                $nMsg++;
            }
        }
    }

    return ['conversations' => $nConv, 'messages' => $nMsg];
}
```

> `storeSyncedMessage('shopee', $cid, $norm)` = jalur bersama yang menyimpan pesan (buyer via
> `syncIncoming`; seller-terbaru → set `replied`+`last_reply_via='staff'`). Bila `storeSyncedMessage`
> saat ini TikTok-spesifik, generalisasikan tanda tangannya jadi `(string $channel, string $convId, array $normalized)`
> sambil menjaga perilaku TikTok (tes `EcomChatSyncTest` harus tetap hijau).

- [ ] **Step 4: Command ikut tarik Shopee** — di `EcomChatSyncCommand::handle`, setelah
`importFromTikTok`, panggil `importFromShopee` (bungkus try/catch masing-masing agar satu
channel gagal tak menggagalkan yang lain) dan gabung ringkasannya di output.

- [ ] **Step 5: Jalankan tes sync + regresi TikTok**

Run: `/c/php83/php.exe artisan test --filter='EcomChatShopeeSyncTest|EcomChatSyncTest'`
Expected: PASS.

- [ ] **Step 6: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/EcomChatService.php app/Console/Commands/EcomChatSyncCommand.php tests/Feature/EcomChatShopeeSyncTest.php
git commit -m "feat(shopee-chat): sinkron 2 arah Shopee di cron ecom-chat:sync"
```

---

## Penutup: verifikasi penuh + deploy

- [ ] Jalankan seluruh suite: `/c/php83/php.exe artisan test` → semua hijau.
- [ ] Push `origin/main`. User deploy: `git pull origin main && php artisan migrate --force && php artisan optimize:clear` (migrasi `000138` untuk `shopee_products`).
- [ ] User pastikan di **Shopee Open Platform**: modul Chat aktif, Push URL terdaftar, push "message" aktif, toko diotorisasi ulang.
- [ ] Uji live: kirim 1 chat dari akun pembeli ke toko Shopee → cek masuk inbox SKINKU (realtime), balas dari SKINKU → cek sampai di Shopee, balas dari Seller Center → cek jadi "Terbalas" (≤3 menit lewat cron).

## Self-Review (diisi penulis plan)

**1. Spec coverage:** §4 arsitektur → Task 1/3/4; §5 setup → Penutup + Task 2 prasyarat; §6 alur masuk → Task 4/5; §7 keluar+sync → Task 3/7; §8 parsing → Task 5/6; §9 fase → Task 2 (Fase 0), Task 1/3/4 (Fase 1), Task 5/6/7 (Fase 2); §10 tes → tiap task; §11 batas → Global Constraints. Tidak ada requirement tanpa task.

**2. Placeholder scan:** tak ada "TODO/nanti". Bentuk `content` Shopee yang belum pasti dikunci di Task 2 (Fase 0) dengan artefak fixture nyata — bukan placeholder, tapi langkah verifikasi berartefak.

**3. Type consistency:** `EcomChatSender::send(): array{message_id}` konsisten (Task 3). `ShopeeChatParser::normalize(array,int): array` dipakai sama di Task 4/5/7. Kontrak `syncIncoming $msg` (incl. `meta`) konsisten Task 4/5. `ShopeeProduct` (`item_id/title/image_url/price`) & `ShopeeOrder` (`order_sn/status/total_amount/line_items`) cocok dengan pembacaan view.
