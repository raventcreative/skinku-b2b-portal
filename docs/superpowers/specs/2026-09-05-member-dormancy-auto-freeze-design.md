# Dormansi Member — Auto-Freeze Akun Tak Aktif (Desain)

**Tanggal:** 2026-09-05
**Repo:** `skinku-b2b-php`
**Status:** Disetujui (pendekatan A) — siap dibuat rencana implementasi.

## Tujuan

Membekukan **otomatis** akun member yang tidak ada pergerakan selama sekian waktu
(mis. affiliator 3 bulan, Grand Distributor tanpa RO 6 bulan), supaya daftar member
tetap bersih & mencerminkan yang benar-benar aktif. Akun beku **tidak bisa login**;
menghidupkannya kembali **hanya manual dari pusat**. Aturan (role mana, berapa bulan,
sinyal aktifnya apa) **sepenuhnya bisa disetting dari sistem — tidak di-hardcode**.

Termasuk: pelacakan **kapan member terakhir online**.

## Ruang Lingkup

- **Fase 1 (spec ini):** member MLM = akun `users` ber-role partner:
  `distributor`, `reseller`, `reseller_bronze`, `reseller_gold`, `grand_distributor`,
  `sponsor`.
- **Fase berikutnya (BUKAN sekarang):** role `affiliator` & role custom lain.
  Karena aturan disimpan **per role-slug**, role baru cukup ditambah lewat halaman
  Setelan — **tanpa koding**.
- Staff HQ (`super_admin`, `admin`, `gudang`) **tidak pernah** kena auto-freeze.
- **Di luar scope v1:** notifikasi/peringatan otomatis ke member sebelum dibekukan
  (HQ tetap bisa lihat daftar "akan beku" dan bertindak). Dormansi "KOL affiliate"
  (kreator TikTok di modul KOL) — beda model, tidak dibahas di sini.

## Fakta Kode yang Dipakai (hasil telaah)

- **Login sudah menolak akun non-aktif.** `AuthController@store` menolak login bila
  `status !== 'active'` (pesan "Akun mitra ... dinonaktifkan"). → **Beku = set
  `status = 'inactive'`**; blokir login otomatis, tanpa middleware baru.
- **Login sudah dicatat.** Tiap login sukses menulis `audit_logs` (action `login`,
  `target_user_id`, `created_at`) — riwayat login sudah ada. Kita tambah kolom
  `last_login_at` untuk pengecekan cepat + tampilan.
- **Kolom status/disabled sudah ada.** `users.status` (`active`/`inactive`/`deleted`)
  + `users.disabled_at`. Beku memakai keduanya.
- **Role sebagian dinamis.** Ada tabel `roles` (role custom, mis. `affiliator`).
  Karena itu aturan dormansi di-key by **slug role**, bukan enum.
- **Pola setelan.** `AppSetting` (key/value) ada, tapi aturan per-role lebih rapi di
  tabel khusus (lihat pendekatan A).
- **Zero-dependency.** Tidak menambah paket composer/npm. Runner tes:
  `/c/php83/php.exe artisan test`. Pint sebelum commit.
- **Migrasi terakhir:** `2026_01_01_000122` → migrasi baru mulai `000123`.

## Arsitektur (Pendekatan A)

Tabel aturan per-role + service penghitung + command harian + reuse mekanisme
status/login yang sudah ada + kolom last-online. Semua konfigurasi lewat DB (halaman
Setelan), bukan konstanta di kode.

### 1. Data model

**Migrasi `000123_create_member_dormancy_rules`** — tabel `member_dormancy_rules`:

| kolom | tipe | keterangan |
|---|---|---|
| `id` | id | |
| `role` | string(50), unique | slug role (built-in atau custom) |
| `enabled` | boolean, default false | aturan aktif/tidak |
| `inactive_months` | unsignedSmallInteger, default 3 | ambang bulan tanpa aktivitas |
| `basis` | string(20) | sinyal aktif: `order` \| `login` \| `recruit` |
| `activated_at` | dateTime, nullable | kapan aturan di-ON-kan (dasar masa tenggang) |
| `updated_by` | foreignId users, nullable | |
| `timestamps` | | |

Seed baris default untuk 6 role Fase 1 dengan **`enabled = false`** (aman: tak ada
yang langsung beku saat deploy; HQ nyalakan setelah meninjau). Default nilai:

| role | basis | inactive_months |
|---|---|---|
| `grand_distributor` | `order` | 6 |
| `distributor` | `order` | 3 |
| `reseller` | `login` | 3 |
| `reseller_bronze` | `login` | 3 |
| `reseller_gold` | `login` | 3 |
| `sponsor` | `login` | 3 |

**Migrasi `000124_add_last_login_at_to_users`** — `users.last_login_at` dateTime
nullable (setelah `disabled_at`).

**Model `MemberDormancyRule`** — fillable `role, enabled, inactive_months, basis,
activated_at, updated_by`; cast `enabled` bool, `activated_at` datetime,
`inactive_months` integer. Konstanta `BASES = ['order','login','recruit']`.

### 2. Sinyal aktif (`basis`) → tanggal aktivitas terakhir

- `order` — PO/RO terakhir: `max(created_at)` dari `purchase_orders` milik user yang
  **bukan** status `cancelled`. (Join package awal Grand adalah `JoinTransaction`,
  BUKAN PO → tidak dihitung sebagai RO; RO = order ulang setelah join.)
- `login` — `users.last_login_at`.
- `recruit` — member baru yang dibawa: `max(created_at)` dari `users` yang
  `sponsor_id = user.id` ATAU `upline_id = user.id`.

### 3. Aturan "dorman" (dengan masa tenggang aman)

`MemberDormancyService`:

- `lastActivityDate(User, basis): ?Carbon` — sesuai sumber di atas.
- `effectiveActivityDate(User, MemberDormancyRule): Carbon`
  = **paling baru** dari: `lastActivityDate` (bila ada), `rule.activated_at`,
  `user.created_at`.
  Alasan: melindungi (a) member lama saat fitur baru dinyalakan — `activated_at`
  memberi jendela penuh; (b) member baru — `created_at` masih segar; (c) `login`
  yang `last_login_at`-nya masih NULL (baru ditambah) supaya tidak dianggap "tak
  pernah aktif".
- `isDormant(User, rule, now): bool` = `effectiveActivityDate < now->subMonths(inactive_months)`.
- `atRiskDays(User, rule, now): int` — sisa hari sebelum beku (untuk daftar "akan beku").

### 4. Mesin beku — command `members:auto-freeze` (cron harian)

Untuk tiap `MemberDormancyRule` yang `enabled`: ambil user `status = active` ber-role
tsb (kecuali staff), lewati yang `isDormant` = false; yang dorman → `freeze()`:
`status='inactive'` + `disabled_at=now()` + `AuditService::log('auto_freeze', target
user, after: {basis, inactive_months, last_activity})`. Idempoten (yang sudah inactive
otomatis terlewati). Opsi `--dry-run` (lapor tanpa mengubah) & `--limit`. Dijadwalkan
`dailyAt('03:00')` `withoutOverlapping`.

### 5. Last-online (poin 5)

Stempel `last_login_at = now()` di `AuthController@store` **setelah** `Auth::login`
(hanya login asli). **Tidak** distempel di `ImpersonationService` (staff menyamar ≠
login member). Ditampilkan di daftar member ("terakhir online: N hari lalu / belum
pernah"). Riwayat detail tetap ada di `audit_logs` (action `login`).

### 6. Panel HQ — `MemberDormancyController`

Satu halaman **Setelan → Dormansi Member** (gate `manage_member_dormancy`, default
diberikan ke management di `Permissions`):

- **Editor aturan:** tabel semua role Fase 1 — toggle `enabled`, `inactive_months`,
  pilih `basis`. Simpan (`saveRules`, POST) meng-`updateOrCreate` per role;
  saat `enabled` berubah `false→true`, set `activated_at = now()` (reset masa
  tenggang). Set `updated_by`.
- **Daftar pantau:** member **beku** (status inactive via disabled_at) + member
  **akan beku** (`atRiskDays` ≤ 14) — tampil role, terakhir aktif, sisa hari, tombol
  **Aktifkan lagi**.
- **Reaktivasi** (`reactivate`, POST, gate sama): `status='active'` +
  `disabled_at=null` + `AuditService::log('reactivate_member')`. Satu-satunya jalan
  menghidupkan kembali (manual).

Navigasi: item di grup Setelan/Sistem (ikut pola menu yang ada). Reuse tombol
enable/disable member di Kelola Anggota bila sudah ada; kalau belum, reaktivasi cukup
dari halaman ini.

### 7. Izin

Tambah permission `manage_member_dormancy` di `app/Support/Permissions.php`
(label "Kelola Dormansi Member"), default ke role management (`super_admin`,`admin`).
Bisa diatur ulang lewat halaman Permissions yang ada.

## Alur Data

1. Member login → `last_login_at` diperbarui + audit `login`.
2. Cron `members:auto-freeze` (harian) → per role ber-aturan aktif, cek
   `effectiveActivityDate` vs ambang → yang lewat dibekukan (status inactive).
3. Member beku mencoba login → ditolak `AuthController` (mekanisme lama).
4. HQ buka Dormansi Member → lihat beku/akan-beku → **Aktifkan lagi** (manual) →
   member bisa login lagi; window dihitung ulang dari aktivitas berikutnya.

## Penanganan Error / Kasus Batas

- **`last_login_at` NULL massal saat rilis** → tertangani `activated_at`+`created_at`
  di `effectiveActivityDate` (tak ada beku massal mendadak).
- **Member baru** → `created_at` segar → aman.
- **Aturan `enabled=false`** → role itu dilewati sepenuhnya.
- **Deploy pertama** → semua rule `enabled=false` → nol perubahan sampai HQ nyalakan.
- **Impersonasi** → tak menstempel `last_login_at`.
- **Role custom tanpa baris rule** → tidak diproses (aman); ditambah lewat Setelan.

## Rencana Tes

- **Unit `MemberDormancyService`:** `lastActivityDate` tiap basis (order/login/recruit);
  `effectiveActivityDate` ambil yang terbaru (lindungi member baru + masa tenggang
  activated_at + last_login NULL); `isDormant` di batas (tepat < vs ≥).
- **Feature command:** bekukan partner dorman; lewati yang aktif; lewati staff;
  hormati `enabled`; hormati masa tenggang `activated_at`; `--dry-run` tak mengubah.
- **Feature login:** user beku (`inactive`) ditolak login; `last_login_at` terstempel
  saat login asli; TIDAK terstempel saat impersonasi.
- **Feature HQ:** simpan aturan (set `activated_at` saat ON); reaktivasi
  (active + disabled_at null); render halaman + gate izin (non-izin → 403).

## Migrasi & Deploy

- Migrasi baru: `000123_create_member_dormancy_rules` (+ seed 6 role, enabled=false),
  `000124_add_last_login_at_to_users`.
- Deploy: `git pull && php artisan migrate --force && php artisan optimize:clear`.
- Setelah deploy: HQ buka Dormansi Member, tinjau daftar, **nyalakan** aturan per role
  saat siap.
