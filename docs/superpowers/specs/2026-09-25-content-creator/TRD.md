# TRD — Portal Content Creator SKINKU
**Technical Requirements Document** · v0.1 draft · 2026-09-25

Dokumen terkait: [BRD](BRD.md) · [PRD](PRD.md) · [FRD](FRD.md)

> **Status implementasi (2026-09-25): Fase 1 selesai.** Penyimpangan dari rencana di bawah:
> - Publikasi diproses **inline** oleh command `content:publish-due` (bukan `PublishContentTargetJob`) — API call singkat, video diproses async oleh platform. Pindah ke queue job bila volume per menit membesar.
> - Dashboard creator digabung di `ContentPostController@dashboard` (route `creator.dashboard`, URL `/creator`); route koneksi: `social.*` di `/social-connections`.
> - Retry otomatis = 3x setelah percobaan pertama (total 4), jeda 5/15/60 menit.
> - Kolom tambahan `content_post_targets.container_polls` (batas polling container video 30 menit).
> - Belum dikerjakan (prioritas S): widget antrean review di dashboard staff (FR-15), preview per platform (FR-26), notifikasi ke reviewer & Telegram (FR-61/62), kalender (FR-73), hapus media otomatis (FR-74), cek kuota IG (FR-59). Notifikasi creator (FR-60) = banner "Perlu perhatian" di dashboard creator.
> - Tambahan di luar spec: middleware `business` menutup route PO/retur/inventory/komisi untuk role kustom non-staff (kebocoran data yang ditemukan saat implementasi).
>
> **Fase 2 (TikTok) selesai di kode (2026-09-25):** `Social\TikTokContentClient` (Login Kit + Content Posting API Direct Post). Video = FILE_UPLOAD per potongan 10 MB; foto/carousel = PULL_FROM_URL (domain `skinku.id` sudah terverifikasi di developer portal). Mode TikTok `auto`: API bila akun terhubung, selain itu manual. Reviewer wajib memilih privacy + setuju Music Usage Confirmation saat approve (pedoman UX TikTok); opsi disimpan di `content_post_targets.options` (migrasi `000143`). Token akses 24 jam diperbarui on-demand + harian. Halaman publik `/privacy` & `/terms`. Sebelum app lolos audit TikTok, postingan hanya bisa `SELF_ONLY`.

Prinsip: ikuti pola yang sudah ada di repo (role dinamis, matriks permission, `ImageService`,
koneksi OAuth ala TikTok/Shopee, queue via scheduler). Tidak ada dependency baru — HTTP client
Laravel (`Http::`) cukup untuk Graph API.

---

## 1. Stack & Konteks yang Dipakai Ulang

| Kebutuhan | Pakai yang sudah ada |
|-----------|----------------------|
| Role baru | Tabel `roles` + `insertOrIgnore` di migration (pola `database/migrations/2026_01_01_000045_create_kols_table.php`) |
| Permission | `app/Support/Permissions.php` (`DEFINITIONS` + `DEFAULTS`), middleware `permission:` di `routes/web.php`, `$user->canDo()` di sidebar |
| Dashboard terpisah | Pola `KolDashboardController` (`routes/web.php:384`) |
| Redirect dashboard | `DashboardController@index` — cabang `limited` (baris ~46) ditambah redirect untuk `content_creator` |
| Upload & file | `app/Services/ImageService.php::attach()` + model polymorphic `app/Models/File.php` (disk `public`) |
| Koneksi OAuth | Pola `app/Models/TiktokConnection.php` + `TikTokController::connect/callback` |
| API client | Pola `app/Services/TikTokClient.php` |
| Background job | `QUEUE_CONNECTION=database`; worker via scheduler `queue:work --stop-when-empty --tries=1` (`routes/console.php:93`) |
| Audit | `AuditService` |
| Notifikasi Telegram (opsional) | Bot Telegram yang sudah ada |

## 2. Arsitektur

```
Browser (creator/admin)
   │  Blade + Tailwind (CDN)
   ▼
ContentCreatorDashboardController   ContentPostController   ContentReviewController   SocialConnectionController
   │                                  │                        │                          │ OAuth
   ▼                                  ▼                        ▼                          ▼
                       ContentPostService (transisi status, validasi platform)
                                      │
             Scheduler (tiap menit): content:dispatch-due ──▶ PublishContentTargetJob (queue: database)
                                                                   │
                                        ┌──────────────────────────┼─────────────────────────┐
                                        ▼                          ▼                         ▼
                                 MetaGraphClient            ThreadsClient          TikTokContentClient (F2)
                              (FB Page + Instagram)        (graph.threads.net)    (open.tiktokapis.com)
```

## 3. Skema Database (usulan)

### `social_connections`
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint PK | |
| platform | string(20) unique | `facebook`, `instagram`, `threads`, `tiktok` — satu koneksi per platform (FR-46) |
| account_id | string | Page ID / IG user ID / Threads user ID / TikTok open_id |
| account_name | string nullable | |
| access_token | text | cast `encrypted` (lebih ketat dari `TiktokConnection` yang plaintext) · `$hidden` |
| refresh_token | text nullable | cast `encrypted` · `$hidden` |
| access_expires_at | datetime nullable | Page token Meta bisa tanpa kedaluwarsa → null |
| refresh_expires_at | datetime nullable | |
| status | string(20) | `active`, `error` |
| last_error | text nullable | |
| meta | json nullable | mis. `page_id` untuk IG, scope yang diberikan |
| connected_by | FK users nullable | |
| timestamps | | |

### `content_posts`
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint PK | |
| user_id | FK users | pembuat (creator) |
| title | string | judul internal |
| type | string(20) | `image`, `video`, `carousel` |
| caption | text nullable | caption utama |
| status | string(20) index | `draft`, `in_review`, `rejected`, `approved`, `scheduled`, `publishing`, `done`, `partial`, `failed` |
| scheduled_at | datetime nullable index | null = secepatnya setelah disetujui |
| creator_note | text nullable | |
| review_note | text nullable | alasan tolak |
| reviewed_by / reviewed_at | FK users / datetime nullable | |
| submitted_at | datetime nullable | |
| timestamps, softDeletes | | |

Media: relasi `files()` polymorphic ke `files` dengan collection `content_media`; urutan carousel di kolom `sort_order` (tambah kolom bila tabel `files` belum punya).

### `content_post_targets`
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint PK | |
| content_post_id | FK cascade | |
| platform | string(20) | unique bersama `content_post_id` |
| caption_override | text nullable | |
| status | string(20) index | `pending`, `queued`, `publishing`, `published`, `failed`, `manual_pending` |
| external_id | string nullable | kunci idempotensi (FR-58) |
| container_id | string nullable | IG/Threads creation container |
| permalink | string nullable | |
| published_at | datetime nullable | |
| attempts | tinyint default 0 | |
| next_attempt_at | datetime nullable index | backoff (FR-57) |
| last_error | text nullable | |
| timestamps | | |

### Data awal (migration)
- `roles`: `insertOrIgnore(['name'=>'content_creator','label'=>'Content Creator','is_system'=>false,'sort_order'=>…])`.
- Akun demo `creator_demo` di `DevDataSeeder` (tidak jalan di production) — FR-03.

## 4. Permission & Routing

Tambahan di `Permissions::DEFINITIONS` / `DEFAULTS` (FR-05):

```php
'content.create'         => ['content_creator', 'admin'],
'content.review'         => ['admin'],
'content.publish.manage' => ['admin'],
'social.connect'         => ['content_creator'], // revisi 2026-09-25; super_admin selalu lolos
```

Route (di dalam grup `['auth','role']` yang ada):

| Method | URI | Permission | Controller@action |
|--------|-----|------------|-------------------|
| GET | `/creator` | content.create | ContentCreatorDashboardController@index |
| GET/POST | `/content`, `/content/create` | content.create | ContentPostController@index/create/store |
| GET/PUT/DELETE | `/content/{post}` | content.create (+ owner policy) | show/update/destroy |
| POST | `/content/{post}/submit`, `/withdraw` | content.create | submit/withdraw |
| GET | `/content-review` | content.review | ContentReviewController@index |
| POST | `/content/{post}/approve`, `/reject` | content.review | approve/reject |
| POST | `/content-targets/{target}/retry`, `/mark-published` | content.publish.manage | retry/markPublished |
| GET | `/content-calendar` | content.create \| content.review | calendar |
| GET | `/social-connections` | social.connect | SocialConnectionController@index |
| GET | `/social-connections/{platform}/connect`, `/callback` | social.connect | connect/callback |
| DELETE | `/social-connections/{platform}` | social.connect | destroy |

- Kepemilikan: creator hanya boleh `$post->user_id === auth()->id()` kecuali punya `content.review` (Policy `ContentPostPolicy`).
- FR-04: `content_creator` tidak lolos `isStaff()`/`isPartner()`; semua modul lain sudah di-gate permission sehingga otomatis 403 selama `DEFAULTS` tidak memberi key lain. Tambahkan test regresi.
- `DashboardController@index`: `if ($user->canDo('content.create') && ! $user->isStaff() && ! $user->isPartner()) return redirect()->route('creator.dashboard');` (FR-10).

## 5. Media Pipeline

1. Validasi Laravel: `images.*` → `mimes:jpg,jpeg,png,webp|max:8192`; `video` → `mimetypes:video/mp4,video/quicktime|max:307200`. Batas diambil dari `config/content.php`.
2. Gambar → `ImageService::attach($post, $file, 'content_media', 1440)` (hasil sudah JPEG — memenuhi syarat IG).
3. Video → simpan langsung ke disk `public` (`content/videos/…`) + baris `File`; tanpa transcoding (ffmpeg tidak tersedia di shared hosting). Durasi/rasio hanya divalidasi bila `getID3`/ffprobe tersedia — **v1: validasi ukuran & mime saja**, error durasi dari API ditampilkan sebagai `last_error`.
4. **URL publik**: Instagram & Threads mengambil media via URL HTTPS publik (`Storage::disk('public')->url()`), jadi `APP_URL` harus domain HTTPS publik. Untuk uji lokal pakai tunnel (cloudflared/ngrok) atau staging.
5. Pembersihan (FR-74): command `content:prune-media` harian.

## 6. Integrasi Platform

### 6.1 Meta — Facebook Page & Instagram (Fase 1)
- **App**: Meta Developer App tipe Business. Scope: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`, `business_management`.
- **Akses**: karena hanya memposting ke akun milik SKINKU, admin yang login cukup punya role di app (Standard Access). Advanced Access/App Review diperlukan bila kelak dipakai akun di luar app — **verifikasi saat setup**.
- **OAuth**: Facebook Login → code → user token → tukar ke long-lived user token (60 hari) → `GET /me/accounts` → pilih Page → simpan **Page access token** (tidak kedaluwarsa selama user token valid & izin tidak dicabut) → `GET /{page-id}?fields=instagram_business_account` → simpan IG user ID.
- **Publish FB**: foto `POST /{page-id}/photos` (`url`, `caption`); video `POST /{page-id}/videos` (`file_url`, `description`); multi-foto: upload tiap foto `published=false` lalu `POST /{page-id}/feed` dengan `attached_media`.
- **Publish IG**: `POST /{ig-user-id}/media` (`image_url` | `media_type=REELS`+`video_url`, `caption`) → poll `GET /{container-id}?fields=status_code` hingga `FINISHED` (video) → `POST /{ig-user-id}/media_publish` (`creation_id`) → `GET /{media-id}?fields=permalink`. Carousel: container anak `is_carousel_item=true` → container `media_type=CAROUSEL` + `children` → publish.
- **Kuota**: `GET /{ig-user-id}/content_publishing_limit` sebelum publish (FR-59).
- Versi API di `config('services.meta.graph_version')` (mis. `v23.0`).

### 6.2 Threads (Fase 1)
- OAuth terpisah di `threads.net/oauth/authorize`, scope `threads_basic`, `threads_content_publish`. Short-lived → long-lived token (60 hari) → **refresh** via `GET /refresh_access_token` sebelum kedaluwarsa (scheduler harian).
- Publish: `POST /{threads-user-id}/threads` (`media_type=TEXT|IMAGE|VIDEO`, `text`, `image_url|video_url`) → tunggu status (video) → `POST /{threads-user-id}/threads_publish` (`creation_id`). Carousel: item `is_carousel_item=true` → `media_type=CAROUSEL` + `children`.

### 6.3 TikTok Content Posting API (Fase 2)
- App TikTok **terpisah** dari app TikTok Shop yang sudah ada (`services.tiktok`). Scope `user.info.basic`, `video.publish` (Direct Post).
- Wajib `POST /v2/post/publish/creator_info/query/` sebelum posting (privacy options, durasi maks).
- Upload: `FILE_UPLOAD` (chunked dari server) — menghindari verifikasi domain yang diperlukan `PULL_FROM_URL`.
- **Sebelum audit**: hanya `privacy_level=SELF_ONLY` → postingan private; tetap tandai di UI.
- Status via `POST /v2/post/publish/status/fetch/`.
- Access token 24 jam, refresh token 365 hari → refresh terjadwal.
- Pedoman UX TikTok (pilih privacy, toggle komentar/duet/stitch, persetujuan musik) harus ditampilkan di form saat Fase 2.

### 6.4 Konfigurasi
`config/services.php`:
```php
'meta' => [
    'app_id' => env('META_APP_ID'), 'app_secret' => env('META_APP_SECRET'),
    'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
],
'threads' => ['app_id' => env('THREADS_APP_ID'), 'app_secret' => env('THREADS_APP_SECRET')],
'tiktok_content' => ['client_key' => env('TIKTOK_CONTENT_CLIENT_KEY'), 'client_secret' => env('TIKTOK_CONTENT_CLIENT_SECRET')],
```
Tambahkan key kosong ke `.env.example`. Kredensial asli hanya di `.env` server.

## 7. Eksekusi Publish

- **Command** `content:dispatch-due` (scheduler `everyMinute()->withoutOverlapping()`): ambil target `queued` dengan (`content_posts.scheduled_at` ≤ now atau null) dan (`next_attempt_at` null atau ≤ now) → set `publishing` → dispatch `PublishContentTargetJob`.
- **Job** `PublishContentTargetJob($targetId)`:
  1. Lock baris (`lockForUpdate`); lewati bila status bukan `publishing` atau `external_id` sudah terisi (idempotensi FR-58).
  2. Panggil client sesuai platform; simpan `container_id` begitu dibuat (agar retry tidak membuat container ganda).
  3. Video IG/Threads butuh polling status container → bila belum `FINISHED`, set `next_attempt_at = now()+1 menit`, kembalikan ke `queued` **tanpa** menambah `attempts` (maks. 30 menit).
  4. Sukses → `published`, `external_id`, `permalink`, `published_at`; hitung ulang status konten (FR-33).
  5. Gagal → `attempts++`, `last_error`; bila `attempts < 3` → `queued` + backoff 5/15/60 menit, selain itu `failed` + notifikasi (FR-57).
- Retry dikelola sendiri di tabel (bukan `$tries` queue) karena worker global berjalan `--tries=1`.
- Timeout job ≤ 290 dtk (sesuai worker). Upload video TikTok chunked dipecah per langkah bila perlu.
- **Refresh token**: command `social:refresh-tokens` harian (Threads, Meta user token, TikTok); gagal → `status=error` + peringatan (FR-45).

## 8. Keamanan

- Token: cast `encrypted`, `$hidden`, tidak pernah di-log (filter pesan error API sebelum simpan ke `last_error`).
- OAuth `state` acak disimpan di session & diverifikasi di callback (CSRF OAuth).
- Upload: validasi mime + ekstensi + ukuran di server; nama file di-generate (bukan dari user); disimpan di luar path yang dapat dieksekusi.
- Otorisasi: permission middleware + `ContentPostPolicy` (kepemilikan) di setiap aksi; transisi status divalidasi server-side (FR-30).
- Caption tidak di-render sebagai HTML (`{{ }}` Blade escape).
- Audit log via `AuditService` untuk semua aksi FR-70, tanpa token.

## 9. Testing

Pola `tests/Feature/KolDashboardTest.php` (helper privat `user($role)`, SQLite in-memory, `RefreshDatabase`).

| Test | Cakupan |
|------|---------|
| `ContentCreatorAccessTest` | creator diarahkan ke dashboard creator; 403 ke PO/inventory/KOL/produk; sidebar hanya menu creator (FR-04, 06, 10) |
| `ContentPostWorkflowTest` | buat draft, ajukan, tarik, setujui, tolak (alasan wajib), transisi ilegal ditolak, creator tidak bisa lihat konten orang lain (FR-25, 30, 32) |
| `ContentMediaValidationTest` | mime/ukuran, carousel 2–10, caption terlalu panjang per platform, video ke IG carousel campuran (FR-21..23) |
| `PublishContentTargetJobTest` | `Http::fake` Graph/Threads: sukses simpan permalink; gagal → backoff; 3x gagal → `failed`; idempoten saat `external_id` ada; status konten `partial` (FR-33, 56..58) |
| `SocialConnectionTest` | callback dengan state salah ditolak; token terenkripsi di DB & tidak muncul di respons (AC-7) |

Perintah: `php artisan test --filter=Content`.

## 10. Rencana Fase & Estimasi Kasar

| Fase | Isi | Estimasi |
|------|-----|----------|
| 1a | Role + akun demo + permission + dashboard creator + CRUD konten + upload + workflow review + TikTok mode manual | 3–4 hari |
| 1b | Koneksi Meta (FB+IG) + Threads + job publish + retry + refresh token + notifikasi | 4–5 hari (di luar waktu tunggu setup/review Meta App) |
| 2 | TikTok Content Posting API (+ audit app oleh TikTok, 1–4 minggu di pihak TikTok) | 3–4 hari dev |
| 3 | Insight/metrik & laporan creator | TBD |

## 11. Prasyarat dari SKINKU sebelum Fase 1b

1. Meta Developer App (Business) + admin portal diberi role di app; Business verification bila diminta.
2. Instagram brand berupa akun **Business/Creator** dan tertaut ke Facebook Page.
3. Threads diaktifkan untuk akun IG brand; produk "Threads API" ditambahkan di app.
4. Portal live di HTTPS publik (Hostinger) dengan `APP_URL` benar; redirect URI OAuth didaftarkan.
5. (Fase 2) TikTok Developer App + produk Content Posting API + pengajuan audit.
