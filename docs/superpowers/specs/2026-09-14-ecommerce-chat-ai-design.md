# Modul Chat E-commerce (AI Auto-reply) — Desain

**Tanggal:** 2026-09-14
**Repo:** `skinku-b2b-php`
**Status:** Disetujui (design) — siap dibuat rencana implementasi.

## Tujuan

Balas chat pembeli **TikTok Shop** dari dalam SKINKU. AI menyusun balasan otomatis
memakai pengetahuan yang diisi HQ; untuk pertanyaan umum/kebijakan AI boleh **kirim
sendiri (auto-send)**, untuk kasus spesifik/perlu keputusan diteruskan ke staf.
Pengetahuan chat dibuat **channel-agnostic** supaya bisa dipakai ulang untuk Shopee
dll di masa depan.

## Ruang Lingkup

**v1 (spec ini):**
- Channel **TikTok Shop** saja, lewat **Customer Service API** resmi (`seller.customer_service`).
- **Real-time via webhook** (`NEW_MESSAGE`).
- **Auto-draft tiap pesan masuk**; **auto-send** untuk FAQ/kebijakan; **escalate ke staf**
  untuk kasus spesifik/ragu.
- Pengetahuan **statis, satu set bersama** (tab baru di Pengetahuan AI).
- Menu baru untuk lihat percakapan + kelola balasan.

**DI LUAR v1 (nanti, spec sendiri — struktur disiapkan supaya tinggal "colok"):**
- Channel **Shopee** & lainnya (kolom `channel` sudah ada sejak v1).
- AI **melihat data order live** (status/resi order spesifik).
- Auto-send penuh tanpa aturan escalate.

## Keputusan (disetujui user)

1. **Pemicu:** real-time **webhook**, bukan polling.
2. **Draft:** dibuat **otomatis tiap pesan masuk**.
3. **Otomatis kirim (auto-send) dengan guardrail berbasis jenis pertanyaan:**
   - **AI kirim sendiri** = pertanyaan **umum/kebijakan/FAQ** (AI *menjelaskan* informasi):
     cara batal, kebijakan refund/retur, ongkir, cara lacak, jam kirim, info produk.
   - **Diteruskan ke staf (TIDAK dikirim, hanya ditandai)** = **spesifik/perlu tindakan atau
     keputusan / AI ragu / di luar pengetahuan**: "batalin pesanan SAYA #123", "barang saya
     rusak minta refund", komplain spesifik, nego harga.
   - Penilaian "FAQ vs spesifik" dilakukan **oleh AI sendiri** (menilai confidence + apakah
     butuh data order/tindakan). Tak 100% sempurna → dijaring **log + kill switch + aturan
     yang bisa disetel**.
4. **Kill switch + start aman:** auto-send = toggle, **default MATI**. Saat MATI, AI tetap
   **membuat draft** (staf lihat di inbox) → begitu kualitasnya dipercaya, auto-send dinyalakan.
5. **Pengetahuan:** statis, **satu set bersama** semua channel, sebagai **tab baru** di halaman
   Pengetahuan AI.
6. **AI tak pernah** mengirim balasan kosong/rusak (fail-safe: kalau ragu/gagal → ke staf).

## Fakta Kode yang Dipakai (hasil telaah)

- `TikTokClient` punya infrastruktur request bertanda-tangan generik (`request()`, `sign()`) →
  tinggal tambah method IM. **Endpoint IM ada** di TikTok Shop Customer Service API (Get
  Conversations, Get Conversation Messages, Send Message; webhook `NEW_MESSAGE` = event 14).
  **Path & versi persis dikonfirmasi ke dokumentasi TikTok saat implementasi** (verified: ada).
- `TiktokConnection` menyimpan `shop_id`, `shop_cipher`, `access_token`, `refresh_token`,
  `access_expires_at`. Re-auth = jalankan ulang alur **Connect/Callback** (`TikTokController`)
  → token baru mencakup scope baru. Additive (scope order tetap).
- Stack AI matang: `AiProviderFactory` (`OpenAiProvider`, `FailoverAiProvider`) → dipakai
  drafter. Tidak perlu sistem Tools/agent (itu untuk asisten interaktif).
- Pengetahuan disimpan lewat model **`AiKnowledge`** (kolom `section` + `content`,
  `AiKnowledge::SECTIONS`, `::map()`), diedit di `ai/knowledge.blade.php`.
- Izin dinamis lewat `app/Support/Permissions.php` (mis. `update_po_status`).
- **Zero-dependency:** webhook, verifikasi HMAC, AI call — semua pakai bawaan Laravel + HTTP
  client. Tak ada paket baru.
- Migrasi terakhir `000126` → migrasi baru mulai `000127`.
- Runner tes `/c/php83/php.exe artisan test`; format `/c/php83/php.exe vendor/bin/pint --dirty`.

## Arsitektur & Alur Data

```
Pembeli chat di TikTok
  → TikTok dorong webhook NEW_MESSAGE ke SKINKU (POST /webhooks/tiktok/chat)
  → Verifikasi tanda tangan (HMAC app_secret). Tak valid → 401. Balas 200 < 3 detik.
  → Simpan/deduped pesan (message_id) ke ecom_chat_messages; upsert ecom_chat_conversations
  → (async job) tarik konteks bila perlu (Get Conversation Messages) → AI Drafter
       → AI hasilkan {reply, decision: auto_send|to_staff, reason}
       → decision=auto_send DAN kill switch ON → kirim via Send Message API, catat log
       → decision=to_staff ATAU kill switch OFF → simpan sbg DRAFT, tandai "perlu staf"
  → Staf buka menu Chat: lihat percakapan + draft + status
       → review/edit/ketik sendiri → Kirim → Send Message API → tercatat
```

## Komponen

### 1. Data model (migrasi `000127`)
- **`ecom_chat_conversations`**: `id`, `channel` ('tiktok'), `external_conversation_id`
  (unik per channel), `buyer_name`/`buyer_id` (nullable), `last_message_at`, `last_message_preview`,
  `status` ('open'|'needs_staff'|'replied'|'closed'), `ai_draft` (text, nullable), `ai_decision`
  (nullable: auto_send|to_staff), `ai_reason` (nullable), timestamps. Index (`channel`,`external_conversation_id`) unik.
- **`ecom_chat_messages`**: `id`, `conversation_id` (fk), `channel`, `external_message_id` (unik
  per channel — dedupe webhook), `sender` ('buyer'|'seller'|'system'), `via` ('ai'|'staff'|'buyer'),
  `text`, `sent_at`, timestamps. Index unik (`channel`,`external_message_id`).
- Model `EcomChatConversation`, `EcomChatMessage`.

### 2. Webhook penerima
- Route **publik** `POST /webhooks/tiktok/chat` (di luar grup auth; **dikecualikan dari CSRF**).
- Controller `TikTokChatWebhookController@handle`: verifikasi tanda tangan (HMAC-SHA256 pakai
  `app_secret`), tolak 401 kalau invalid; dedupe by `external_message_id`; simpan pesan; **balas 200
  segera** (harus < 3 detik).
- **Kerja berat (AI drafter + kemungkinan send) dijalankan SETELAH respon terkirim**, bukan di dalam
  request. Pakai `DraftEcomChatReply::dispatchAfterResponse()` (atau `App::terminating()`), sehingga
  200 balik cepat lalu AI diproses via `fastcgi_finish_request` — **tak butuh queue worker** (aman di
  shared hosting yang queue-nya `sync`). Kalau kelak ada worker, tinggal ganti ke queue biasa.
- Abaikan event non-buyer (pesan penjual sendiri / system) → anti-loop.

### 3. API chat TikTok (`app/Services/TikTokClient.php` + service)
- Tambah method: `getConversations()`, `getConversationMessages()`, `sendMessage()` (pola sama
  seperti order: `request()` + `sign()`, pakai `access_token` + `shop_cipher` dari `TiktokConnection`).
- Service tipis `EcomChatService` (channel-agnostic facade): `syncMessage()`, `send($conversation, $text, $via)`,
  supaya UI & job tak langsung ke `TikTokClient` (memudahkan tambah Shopee nanti).

### 4. AI Drafter (`app/Services/Ai/EcomChatDrafter.php`)
- Input: riwayat percakapan (n pesan terakhir) + pengetahuan Chat E-commerce (dari `AiKnowledge`
  group 'chat') + pesan pembeli terbaru.
- System prompt: peran CS SKINKU + pengetahuan + **instruksi guardrail** (jawab hanya dari
  pengetahuan; kalau butuh data order spesifik / tindakan / ragu → `to_staff`; kalau pembeli minta
  bicara manusia → `to_staff`).
- Output terstruktur (JSON): `{ reply: string, decision: "auto_send"|"to_staff", reason: string }`.
  Parsing aman; kalau gagal parse / kosong / provider error → paksa `to_staff` (fail-safe).
- Pakai `AiProviderFactory::make()` — provider-agnostic (sama seperti asisten).

### 5. Menu "Chat E-commerce" (grup Integrasi)
- Route `GET /ecom-chat` (inbox) + `GET /ecom-chat/{conversation}` (detail) + `POST
  /ecom-chat/{conversation}/send` (kirim) + `POST /ecom-chat/{conversation}/redraft` (buat ulang
  draft) — semua gate `permission:manage_ecommerce_chat`.
- UI: kiri daftar percakapan (nama pembeli, cuplikan, badge status "perlu staf" / "auto terkirim");
  kanan bubble masuk/keluar + kotak **draft** (editable) + tombol **Kirim** & **Buat ulang draft**.
  Badge menandai balasan yang **AI kirim otomatis** vs **dikirim staf**.
- Vanilla JS + fetch (pola sama seperti fitur lain), zero-dep.

### 6. Pengetahuan Chat E-commerce (tab di Pengetahuan AI)
- Tambah kolom **`group`** ke tabel `ai_knowledge` (migrasi `000128`; default `'sistem'` untuk
  baris lama). `AiKnowledge::SECTIONS` jadi bergrup: grup `sistem` (yang ada) + grup `chat`.
- Grup `chat` section: **Produk (untuk customer)**, **Kebijakan kirim/retur/batal**, **FAQ**,
  **Gaya bahasa ke pembeli**.
- View `ai/knowledge.blade.php` diberi **tab switcher** (Sistem | Chat E-commerce); simpan per grup.
- Drafter baca grup `chat`. Channel-agnostic → dipakai Shopee nanti tanpa ubah pengetahuan.

### 7. Kill switch & izin
- **Auto-send toggle** = `AppSetting` key `ecom_chat_autosend` (default `'0'` = MATI). Diatur di
  halaman Chat (atau Setelan). Saat MATI → semua hasil AI jadi draft (tak ada yang terkirim otomatis).
- Izin baru **`manage_ecommerce_chat`** di `Permissions.php` (default: admin). Menu + aksi digembok ini.

## Setup (dilakukan user, sekali)
1. Deploy (pull + migrate + optimize:clear).
2. **Connect TikTok** ulang di SKINKU (dapat token dgn scope Customer Service; sync order tetap jalan).
3. Daftarkan **URL webhook** `https://system.skinku.id/webhooks/tiktok/chat` + subscribe event
   `NEW_MESSAGE` di TikTok Partner Center (dipandu).
4. Isi tab **Pengetahuan AI → Chat E-commerce**.
5. Pantau inbox beberapa hari dengan auto-send **MATI** (lihat draft AI) → kalau puas, **nyalakan
   auto-send**.

## Penanganan Error / Kasus Batas (fail-safe: ragu = JANGAN kirim)
- AI gagal/timeout/parse-error → `to_staff`, tak kirim apa pun.
- Send API gagal → retry terbatas; tetap gagal → tandai gagal, tampil ke staf, tak loop.
- Webhook tanda tangan invalid → 401; payload dobel → dedupe by `external_message_id`.
- Token kedaluwarsa → refresh; gagal → tandai "perlu re-auth" + auto-send berhenti.
- Anti-loop: abaikan pesan `sender=seller`; tak balas ulang tanpa pesan buyer baru.
- Rate limit TikTok → backoff (pola seperti sync order).
- Kill switch MATI → tak ada auto-send sama sekali (hanya draft).

## Rencana Tes
- **Unit `EcomChatDrafter`** (AI provider tiruan/deterministik): FAQ → `auto_send` + reply berisi;
  kasus spesifik/"batalin order saya" → `to_staff`; provider error / JSON rusak → dipaksa `to_staff`
  (fail-safe); "mau bicara CS" → `to_staff`.
- **Feature webhook**: tanda tangan valid → pesan tersimpan + job ter-dispatch; tanda tangan salah →
  401; `external_message_id` dobel → 1 baris saja (dedupe).
- **Feature kirim**: staf POST send → panggil `EcomChatService::send` (TikTok API di-`Http::fake`),
  pesan seller tersimpan, status `replied`; auto-send path saat kill switch ON + decision auto_send →
  terkirim; kill switch OFF → tak terkirim (jadi draft).
- **Feature izin**: non-staf → 403 di menu & aksi Chat.
- **Feature pengetahuan**: simpan grup `chat` → kebaca oleh drafter; grup `sistem` tak berubah.
- Semua panggilan API TikTok **di-mock** (`Http::fake`), tak ada panggilan nyata di tes.

## Migrasi & Deploy
- `000127_create_ecom_chat_tables` (conversations + messages).
- `000128_add_group_to_ai_knowledge` (kolom `group`, default `'sistem'`).
- Deploy: `git pull && php artisan migrate --force && php artisan optimize:clear` + langkah Setup di atas.
