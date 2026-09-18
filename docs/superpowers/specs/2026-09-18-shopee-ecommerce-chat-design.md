# Chat E-commerce — Channel Shopee (Design Spec)

**Tanggal:** 2026-09-18
**Status:** Disetujui untuk lanjut ke rencana implementasi
**Repo:** `C:\Users\DELL\Downloads\skinku-b2b-php` (SKINKU B2B Distributor Portal, Laravel 13 / PHP 8.3, zero-dependency)

## 1. Tujuan

Menambahkan **Shopee** sebagai channel kedua pada modul Chat E-commerce yang sudah ada
(saat ini hanya TikTok). Pembeli yang chat lewat Shopee masuk ke **inbox yang sama**,
AI membuat **draft balasan** → staf menyetujui/kirim (semi-auto), dengan toggle
**Auto-send** dan **Pengetahuan AI** yang sama. Balasan yang dikirim dari SKINKU sampai
ke pembeli di Shopee, dan balasan yang dibuat di **Shopee Seller Center** ikut tercermin
di SKINKU.

**Non-tujuan:** tidak membangun modul/inbox baru, tidak mengubah alur chat TikTok yang
sudah jalan, tidak menambah paket composer/npm (zero-dependency tetap).

## 2. Keputusan yang sudah dikunci (hasil brainstorming)

| Keputusan | Pilihan |
|---|---|
| Cara pesan masuk | **Push realtime (webhook)** — Shopee `POST` ke callback SKINKU |
| Mode | **Sama persis TikTok** — inbox sama, AI draft + staf kirim, toggle Auto-send sama, Pengetahuan AI sama |
| Cakupan pesan | **Paritas penuh** — teks + gambar + kartu produk + kartu pesanan |

## 3. Konteks kode yang sudah ada (dipakai ulang)

- **Model channel-agnostic:** `app/Models/EcomChatConversation.php` sudah punya kolom
  `channel`, `external_conversation_id`, `buyer_name`, `buyer_id`. `EcomChatMessage`
  dedupe by (`channel`, `external_message_id`).
- **Facade chat:** `app/Services/EcomChatService.php` — `syncIncoming(string $channel, array $msg)`
  sudah channel-agnostic. Tapi `send()` dan pull masih **hardcoded TikTok**
  (`new TikTokClient('tiktok')`, `$this->affiliate->freshToken()`, `$conn->shop_cipher`).
- **UI:** `resources/views/ecom-chat/{index,_thread,_list}.blade.php` — render kartu
  (`order_card`, `logistics_card`, `product_card`, `image`, `video`, `other`, teks)
  sudah channel-agnostic; tak perlu UI baru.
- **Drafter AI:** `app/Services/EcomChatDrafter.php` + `processDraft()` di `EcomChatService`
  — channel-agnostic; dipakai ulang apa adanya.
- **Klien Shopee LIVE:** `app/Services/ShopeeClient.php` — punya `shopCall($method, $path,
  $accessToken, $shopId, $params)` bertanda-tangan (HMAC-SHA256 `partner_key`). Config
  di `services.shopee.partner_id` / `partner_key`. Token toko (~4 jam, auto-refresh)
  sudah dipakai `ShopeeOrderService` dkk.
- **Belum ada:** webhook/push Shopee (integrasi Shopee sekarang **cron-poll**, lihat
  `Schedule::command('shopee:sync')` di `routes/console.php`); API chat di `ShopeeClient`.

## 4. Arsitektur

Shopee = **channel kedua**, bukan modul baru. Komponen baru:

1. **`ShopeeChatClient`** — kelas baru (atau grup metode di `ShopeeClient`) untuk endpoint
   `sellerchat` Shopee. Metode: `getConversationList`, `getOneConversation`, `getMessages`,
   `sendMessage`, `readConversation`. Semua lewat `shopCall()` yang sudah bertanda-tangan.
   > Nama endpoint pasti & bentuk respons **diverifikasi di Fase 0** (lihat §9).
2. **`ShopeePushController`** — satu endpoint webhook `POST /webhooks/shopee/push` untuk
   **semua** push code Shopee; hanya bertindak pada push code **chat/message**, sisanya
   balas `200` dan diabaikan (aman untuk masa depan).
3. **Percabangan channel** di `EcomChatService`:
   - Ekstrak antarmuka pengirim jadi mekanisme per-channel. `send()` memilih klien
     berdasarkan `$conv->channel`: `tiktok` → jalur lama; `shopee` → `ShopeeChatClient`.
   - Token: `shopee` pakai koneksi/token Shopee yang sama dengan `ShopeeOrderService`.

### Antarmuka pengirim per-channel

```
interface EcomChatSender {
    // Kirim teks ke pembeli; kembalikan ['message_id' => string]
    public function send(EcomChatConversation $conv, string $text): array;
}
```

- `TikTokChatSender` — bungkus jalur `send()` TikTok yang ada sekarang (tanpa ubah perilaku).
- `ShopeeChatSender` — panggil `ShopeeChatClient::sendMessage`.
- `EcomChatService::send()` memilih sender via `match($conv->channel)`. Bila channel tak
  dikenal → lempar exception yang jelas (jangan diam).

## 5. Setup di sisi Shopee (aksi pemilik toko — di luar kode)

1. **Shopee Open Platform** (app partner): aktifkan izin **modul Chat**.
2. Daftarkan **Push URL** app → `https://system.skinku.id/webhooks/shopee/push`, lalu
   aktifkan **push tipe "message"**.
3. **Otorisasi ulang toko** bila scope chat belum kebawa.
4. `.env` **tidak berubah** (`partner_id`/`partner_key`/token toko sudah ada). Claude
   tidak pernah melihat `partner_key`; verifikasi tanda tangan push berjalan di server.

## 6. Alur MASUK (push → inbox)

```
Shopee POST /webhooks/shopee/push
  → verifikasi tanda tangan (HMAC-SHA256 partner_key atas "url|body", header Authorization) [formula dipastikan Fase 0]
  → tanda tangan invalid → 401, berhenti
  → baca push "code"; bukan chat/message → 200 OK, abaikan
  → ekstrak shop_id, conversation_id, dan payload pesan
  → EcomChatService::syncIncoming('shopee', $normalizedMsg)  // dedupe by (channel, message_id)
  → perkaya untuk kartu bila perlu (ambil detail pesan/produk/order via API)
  → EcomChatService::processDraft($conv)  // drafter AI: auto-send bila toggle ON, else needs_staff
  → 200 OK
```

- **Idempoten:** `syncIncoming` sudah menolak duplikat by (`channel`, `external_message_id`).
  Push Shopee yang dikirim ulang tak menggandakan pesan.
- **Buyer name:** ambil dari payload push / `getOneConversation` bila tersedia; fallback
  "Pembeli" (perilaku sama seperti TikTok).
- **Tahan banting:** seluruh proses dibungkus try/catch; kegagalan enrich/draft tidak
  menggagalkan penerimaan pesan (pesan tetap tersimpan, error dicatat log).

## 7. Alur KELUAR + sinkron 2 arah

- **Kirim:** `EcomChatService::send($conv, $text, $via)` untuk `channel=shopee` memakai
  `ShopeeChatSender` → `ShopeeChatClient::sendMessage(accessToken, shopId, conversationId,
  toId, text)`. Catat pesan seller + set percakapan `replied` + `last_reply_via` (sama
  seperti TikTok). `to_id` (user_id pembeli) diambil dari `buyer_id` percakapan.
- **Sinkron 2 arah:** perluas command `ecom-chat:sync`
  (`app/Console/Commands/EcomChatSyncCommand.php`) agar **juga** menarik Shopee
  (`getConversationList` + `getMessages`) sebagai jaring pengaman: menangkap balasan yang
  dibuat langsung di **Shopee Seller Center** → tandai "Terbalas" di SKINKU, dan menambal
  push yang mungkin meleset. Cron sudah tiap 3 menit (`everyThreeMinutes`).

## 8. Parsing pesan (paritas penuh)

Peta tipe pesan Shopee → tipe internal (yang sudah dirender `_thread.blade.php`):

| Shopee message_type | Tipe internal SKINKU | Render |
|---|---|---|
| `text` | `text` | bubble teks |
| `image` | `image` | thumbnail + link |
| `item` / product | `product_card` | kartu produk (nama/foto/harga) |
| `order` | `order_card` | kartu pesanan (item/total/status) |
| `sticker`/lainnya | `other` | teks placeholder italic |

- Enrichment kartu **produk**: bila payload hanya membawa `item_id`, ambil detail produk
  Shopee (nama/gambar/harga) — pola sama seperti `TiktokProduct` cache untuk TikTok. Bila
  gagal, tampilkan ID + tautan produk (degradasi anggun).
- Enrichment kartu **pesanan**: cocokkan `order_sn` dengan order Shopee yang sudah
  tersinkron (`ShopeeOrderService`); bila belum ada → tampilkan "Order belum tersinkron".
- **Bentuk payload persis dikonfirmasi Fase 0** sebelum parser final ditulis.

## 9. Rencana bertahap & risiko

**Fase 0 — Verifikasi (WAJIB sebelum bangun banyak).** Setelah pemilik toko mengaktifkan
modul Chat + push:
- Uji **1 panggilan** `get_conversation_list` (buktikan API chat Shopee reachable & scope OK).
- Tangkap **payload push asli** dari 1 chat uji (bentuk `code`, tanda tangan, isi pesan).
- Hasilnya mengunci: nama endpoint pasti, formula tanda tangan push, dan bentuk tiap
  `message_type`. Ini persis pelajaran dari TikTok (webhook cuma bawa metadata → perlu
  tarik isi via API).

**Fase 1 — Teks end-to-end.** `ShopeeChatClient` (get list/messages/send) + `ShopeePushController`
(verifikasi tanda tangan + terima pesan teks) + percabangan `send()` per-channel + rute.
Kirim & terima **teks** jalan penuh.

**Fase 2 — Paritas penuh + sinkron.** Parser gambar/produk/order + enrichment kartu +
perluasan `ecom-chat:sync` untuk Shopee (sinkron 2 arah).

**Risiko utama:**
- **Akses modul Chat** di app Shopee belum tentu disetujui — Fase 0 memfilter risiko ini
  sebelum banyak kode ditulis.
- **Push Shopee bersifat partner-level** (1 URL untuk semua event). Controller harus
  mengabaikan push non-chat dengan aman.
- **Format `sellerchat` bisa beda dari asumsi** — dikunci di Fase 0, bukan ditebak.

## 10. Pengujian

- **Push controller:** tanda tangan valid → pesan tersimpan + draft jalan; tanda tangan
  invalid → 401; push code non-chat → 200 tanpa efek; push dobel → tak menggandakan.
- **`ShopeeChatClient`:** `Http::fake()` untuk `sellerchat` (get list/messages/send);
  assert tanda tangan & parameter benar.
- **Parser:** tiap `message_type` (text/image/item/order/unknown) → tipe internal benar.
- **Routing channel:** `send()` untuk `shopee` memakai `ShopeeChatSender` (tak menyentuh
  `TikTokClient`); `tiktok` tetap jalur lama (regresi TikTok nol).
- **Sinkron 2 arah:** balasan Seller Center (di-fake) → percakapan jadi `replied`.
- Semua tes lewat runner `/c/php83/php.exe artisan test`; format `pint --dirty`.

## 11. Batas & konvensi

- Zero-dependency: tanpa paket composer/npm baru.
- Deploy: Claude push `origin/main` dari lokal; user `git pull` + `migrate` (bila ada
  migrasi) + `optimize:clear` di prod. Migrasi baru (jika ada) mengikuti penomoran
  `2026_01_01_0001XX`.
- Claude **tidak** login/otorisasi Shopee atas nama user; semua aksi Shopee Open Platform
  dilakukan pemilik toko. Claude tak pernah melihat `partner_key` (ada di `.env` server).
