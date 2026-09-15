# Notifikasi Chat E-commerce (Badge Unread Per-Staf + Suara) — Desain

**Tanggal:** 2026-09-15
**Repo:** `skinku-b2b-php`
**Status:** Disetujui (design) — siap dibuat rencana implementasi.

## Tujuan

Tombol Chat E-commerce di **kanan header** portal (mirip "Pesan pembeli" di TikTok Seller
Center) dengan **badge merah jumlah percakapan belum dibaca — per staf** (badge Budi beda
dari Tiar) + **notifikasi suara** saat ada chat pembeli baru. Tujuan: staf tahu ada chat
masuk tanpa harus buka menu Chat.

## Ruang Lingkup

**Termasuk:**
- Pelacakan "sudah dibaca" **per user per percakapan**.
- Tombol header + badge unread (render awal server-side) untuk staf ber-izin `manage_ecommerce_chat`.
- Endpoint hitung unread + **poller JS** (±25 dtk) untuk update badge live.
- **Suara** "ding" saat count naik (Web Audio API, disintesis — tanpa file audio); **toggle mute** (localStorage).
- Buka **detail** percakapan menandai percakapan itu "read" untuk user tsb.

**DI LUAR scope (YAGNI):**
- Indikator unread per-baris di daftar inbox.
- Browser push notification (Notification API) / websocket real-time.
- Menandai read saat membuka **daftar** inbox (hanya detail yang menandai).

## Keputusan (disetujui user)

1. Badge = **unread per-staf** (jumlah percakapan yang punya pesan pembeli belum dilihat user itu).
2. Poll **25 detik**.
3. Suara **on, bisa di-mute** (per-browser, localStorage).
4. **Detail** percakapan yang menandai read (bukan daftar inbox).

## Fakta Kode (hasil telaah)

- `EcomChatConversation` (tabel `ecom_chat_conversations`): fillable ada `last_message_at`
  (di-update saat pembeli masuk **dan** saat staf balas) + `status` (`open`/`needs_staff`/`replied`/`closed`).
- `EcomChatService::syncIncoming()` (baris ~50-59): `firstOrNew` percakapan, set
  `last_message_at = $sentAt`, `status = STATUS_OPEN`, `save()`, lalu buat pesan pembeli.
- `EcomChatController::show(EcomChatConversation $conversation)` — memuat pesan; tempat pas
  menandai read.
- Route Chat berada dalam grup `permission:manage_ecommerce_chat` di dalam grup `['auth','role']`.
- Header layout: `resources/views/layouts/app.blade.php` baris ~490-498 — `<header>` dengan
  kiri (heading) & kanan (`{{ config('app.name') }}`). Tombol chat masuk di sisi kanan.
- Migrasi terakhir `000128` → migrasi baru `000129`.
- Zero-dependency: Blade + vanilla JS + Web Audio API bawaan browser. Tak ada paket baru.

## Arsitektur & Alur Data

```
Pembeli chat → syncIncoming set last_incoming_at (waktu pesan PEMBELI) → percakapan jadi
  unread bagi SEMUA staf yang last_read_at-nya < last_incoming_at
Staf buka halaman apa pun → header render badge = unreadCountFor(user) (server-side)
  → poller JS tiap 25 dtk → GET /ecom-chat/unread-count → {count}
       → update badge; kalau count > terakhir (localStorage) → bunyikan "ding" (bila tak mute)
Staf klik tombol → /ecom-chat → buka detail → markRead(user, percakapan) → last_read_at=now()
  → percakapan itu tak lagi unread bagi user tsb → badge turun di poll berikutnya
Pesan pembeli BARU sesudah dibaca → last_incoming_at maju > last_read_at → unread lagi
```

Kunci: **`last_incoming_at`** (khusus pesan pembeli) dipisah dari `last_message_at` (yang
juga bergerak saat staf membalas) supaya balasan staf tak menandai percakapan jadi "unread".

## Komponen

### 1. Data model (migrasi `000129`)
- Tabel **`ecom_chat_reads`**: `id`, `user_id` (fk users, cascade), `conversation_id` (fk
  ecom_chat_conversations, cascade), `last_read_at` (timestamp), timestamps. **Unik**
  (`user_id`, `conversation_id`).
- Kolom **`last_incoming_at`** (timestamp, nullable) di `ecom_chat_conversations`.
- Model **`EcomChatRead`** (fillable `user_id`, `conversation_id`, `last_read_at`; cast
  `last_read_at` datetime).
- `EcomChatConversation`: tambah `last_incoming_at` ke fillable + cast datetime; relasi
  `reads(): HasMany`.

### 2. Set `last_incoming_at` saat pesan masuk
- Di `EcomChatService::syncIncoming()`, saat meng-upsert percakapan, set
  `$conv->last_incoming_at = $sentAt;` (di samping `last_message_at`). Hanya jalur pesan
  **pembeli** (syncIncoming sudah anti-loop: non-buyer di-skip).

### 3. Hitung unread & tandai read (`EcomChatService`)
- `unreadCountFor(User $user): int` — hitung percakapan yang: `last_incoming_at` **tidak
  null**, `status != 'closed'`, dan `last_incoming_at >` `last_read_at` user itu untuk
  percakapan tsb (atau tak ada baris read). Implementasi: `leftJoin ecom_chat_reads` on
  `conversation_id` + `user_id = $user->id`, `whereNotNull('ecom_chat_conversations.last_incoming_at')`,
  `where('status','!=','closed')`, lalu `whereRaw("ecom_chat_conversations.last_incoming_at >
  COALESCE(ecom_chat_reads.last_read_at, '1970-01-01 00:00:00')")` (banding datetime vs datetime —
  jangan pakai `0`). `->count()` (distinct percakapan). Nama tabel di-kualifikasi penuh agar tak ambigu.
- `markRead(User $user, EcomChatConversation $conv): void` — `EcomChatRead::updateOrCreate(['user_id'=>…,'conversation_id'=>…], ['last_read_at'=>now()])`.

### 4. Controller & route
- `EcomChatController::show()` panggil `$this->chat->markRead($request->user(), $conversation)`
  sebelum render.
- `EcomChatController::unreadCount(Request): JsonResponse` → `['count' => $this->chat->unreadCountFor($request->user())]`.
- Route baru `GET /ecom-chat/unread-count` → `ecom-chat.unread-count`, dalam grup
  `permission:manage_ecommerce_chat`. **Daftarkan SEBELUM** `/ecom-chat/{conversation}`
  (biar literal `unread-count` tak ketangkap route-model-binding).

### 5. Tombol header + badge + poller + suara (layout)
- Di `resources/views/layouts/app.blade.php`, sisi kanan `<header>` (bungkus `config('app.name')`
  jadi `flex items-center gap-3`): tambahkan — **hanya bila** `$u->canDo('manage_ecommerce_chat')` —
  tombol `<a href="{{ route('ecom-chat.index') }}">` ikon chat + `<span>` badge merah
  (absolute, `hidden` saat 0) berisi `EcomChatService::unreadCountFor` awal, + ikon **mute** toggle kecil.
- **JS (vanilla, di layout, hanya saat tombol ada):**
  - `poll()` tiap 25 dtk: `fetch('{{ route('ecom-chat.unread-count') }}')` → `{count}` →
    set teks badge + show/hide; kalau `count > last` (last dari `localStorage['ecomChatUnread']`)
    dan **tak mute** → `beep()`. Simpan `last = count`.
  - `beep()`: Web Audio API (OscillatorNode pendek ~150ms). AudioContext dibuat/di-`resume()`
    saat interaksi user pertama (listener sekali pakai) demi kebijakan autoplay; gagal → diam
    (badge tetap jalan).
  - Mute: `localStorage['ecomChatMute']` '1'/'0'; ikon toggle memperbaruinya; default tak mute.
  - Semua akses `localStorage`/AudioContext dibungkus try/catch (mode privat/diblokir → degradasi mulus).
- Nilai awal `unreadCount` untuk badge server-side dihitung sekali di layout via
  `auth()->user()` (helper yang sama). Bila user tak login / tak ber-izin → tombol tak dirender.

## Penanganan Error / Kasus Batas

- User belum pernah buka percakapan (tak ada baris read) → dianggap unread bila ada pesan pembeli.
- `last_incoming_at` null (percakapan tanpa pesan pembeli — mustahil di alur normal, tapi aman) → tak dihitung.
- Percakapan `closed` → tak dihitung (dianggap selesai).
- Poll gagal (jaringan) → badge tak berubah, tak error di UI (fetch dibungkus `.catch`).
- Autoplay diblokir / localStorage tak tersedia → badge tetap, suara/mute degradasi mulus.
- Dua staf independen: `unreadCountFor` selalu difilter `user_id` login → tak bocor antar staf.
- Endpoint digembok `manage_ecommerce_chat` (non-staf → 403).

## Rencana Tes

- **`unreadCountFor()`**: percakapan dgn pesan pembeli, user belum baca → 1; sesudah
  `markRead` → 0; pesan pembeli BARU sesudah baca (`last_incoming_at` maju) → 1 lagi; dua user
  independen (U1 sudah baca=0, U2 belum=1); percakapan `closed` → tak dihitung.
- **`markRead()`**: upsert baris read (idempoten — buka 2x tak dobel baris).
- **`syncIncoming` set `last_incoming_at`**: sesudah pesan pembeli masuk, `last_incoming_at`
  terisi = waktu pesan.
- **`show()` menandai read**: staf GET `/ecom-chat/{id}` → `unreadCountFor` staf itu turun.
- **Endpoint `unread-count`**: JSON `{count}` per user login; non-staf → 403.
- **Render header**: staf ber-izin → tombol + badge tampil (dan angka benar); user tanpa izin
  → tombol TIDAK ada.
- Suara/poller JS = sisi klien, diverifikasi manual (didokumentasikan di plan) — tak di-unit-test.

## File yang Disentuh

- `database/migrations/2026_01_01_000129_*` — tabel `ecom_chat_reads` + kolom `last_incoming_at`.
- `app/Models/EcomChatRead.php` (baru); `app/Models/EcomChatConversation.php` (fillable/cast/relasi).
- `app/Services/EcomChatService.php` — `syncIncoming` (+last_incoming_at), `unreadCountFor`, `markRead`.
- `app/Http/Controllers/EcomChatController.php` — `show()` markRead, `unreadCount()`.
- `routes/web.php` — route `ecom-chat.unread-count`.
- `resources/views/layouts/app.blade.php` — tombol header + badge + JS poller + beep + mute.
- `tests/Feature/EcomChatUnreadTest.php` (baru) — service + endpoint + show + render.

## Deploy

Zero-dependency. Pengguna: `git pull && php artisan migrate --force && php artisan optimize:clear`.
Tanpa data baru pun aman (kolom nullable, tabel read kosong = semua percakapan berpesan-pembeli
dianggap unread sampai dibuka).
