# Spec Desain — Hirarki Mitra ala MLM (Tahap 1: Pondasi)

**Tanggal:** 2026-08-10
**Status:** Disetujui (brainstorming) → siap ke writing-plans
**Branch:** `feat/hirarki-mitra-mlm` (dari `main`)

## 1. Tujuan & Visi

Ubah struktur mitra SKINKU dari **datar** (matriks role×izin global) menjadi
**bertingkat** (pohon upline–downline) ala MLM, sehingga:

- atasan bisa **lihat & kelola** jaringan bawahannya, dan
- (akhirnya) **komisi/insentif mengalir ke atas** dari pembelian downline.

Dibangun **bertahap**; tiap tahap = software yang jalan & teruji sendiri:

| Tahap | Nama | Deliverable |
|---|---|---|
| **1 (spec ini)** | Pondasi Hirarki | Pohon upline-downline + tier (role) + Member ID/login + halaman Struktur Jaringan |
| 2 | Akses Bertingkat | Atasan lihat/kelola subtree; gating stok per tier (reseller tanpa stok) |
| 3 | Harga & Komisi | Harga spesifik per tier + komisi mengalir ke atas + laporan jaringan |

**Prinsip:** aditif & zero-dependency. Tahap 1 hanya **menambah** pondasi + 1
halaman; **tidak mengubah** kelakuan izin/harga/stok yang sudah jalan. Semua
perubahan kelakuan (akses subtree, gating stok, komisi) dikerjakan di tahap
berikutnya, masing-masing dengan tesnya sendiri.

## 2. Keputusan Ruang Lingkup (hasil brainstorming)

- **Tujuan akhir:** MLM penuh + komisi. Tapi **pondasi dulu**, uang paling akhir.
- **Anggota pohon:** hanya **spine distribusi** — Pusat HQ → Grand Distributor →
  Distributor → Reseller (Bronze/Gold). End-customer ("N user") = **data
  penjualan, BUKAN akun**. Cabang "Online" (Affiliate/KOL/LIVE) **tidak ikut**
  pohon; tetap pakai permission biasa.
- **Bentuk:** **tangga tetap** — induk selalu **tepat 1 tingkat** di atas.
  Reseller = daun (tak punya downline akun).
- **Onboarding:** **admin/HQ** yang set upline + tier lewat form anggota
  (self-service via ID sponsor = kemungkinan nanti, bukan Tahap 1).
- **Pemodelan tier = ROLE** (bukan kolom `tier` terpisah). Tambah role baru;
  pertahankan sistem izin & harga yang **sudah** per-role. + kolom `upline_id`.
- **Data mitra lama:** **kosong dulu** — TIDAK ada migrasi paksa; admin
  menempatkan mitra bertahap lewat halaman.
- **Member ID + login:** tiap mitra dapat `member_id` unik & **tetap** (format
  `SKN-000123`, netral — tak berubah walau naik tier). Login bisa pakai **Member
  ID / username / email** + password. (`uid` = kolom warisan Firebase yang mati,
  tak dipakai.)
- **Region:** **disarankan** (utamakan region sama saat pilih upline), **tidak
  dipaksa**.
- **Model stok:** **campur** — Grand Distributor & Distributor = **stockist**
  (nyetok); Reseller (Bronze/Gold) = **tanpa stok** (jualan diteruskan ke
  distributor induk). Shopee/TikTok = **HQ saja** (sudah begitu; tak berubah).
- **Branch lama `feature/distributor`:** **ditinggalkan** — isinya stub
  `MasterDistributor` (tabel kontak datar, tanpa hirarki/UI, nambah dependency,
  langgar zero-dep). Digantikan desain ini.

## 3. Tahap 1 — Desain Detail

### 3.1 Perubahan data

Migrasi `2026_01_01_000074_add_hierarchy_to_users.php`:

- Tambah `users.upline_id` — `unsignedBigInteger`, **nullable**, **index**, FK
  self-ref → `users.id` **nullOnDelete** (fallback aman; app-guard mencegah hapus
  upline yang masih punya downline).
- Tambah `users.member_id` — `string`, **unique**, **nullable**, index (ID member
  tetap, format `SKN-000123`). Nullable + unique: user lama tanpa ID tak
  bertabrakan.
- Seed 3 role baru ke tabel `roles`: `grand_distributor`, `reseller_bronze`,
  `reseller_gold` (agar muncul di matriks Hak Akses; `distributor` & `reseller`
  sudah ada).

Tidak ada kolom `tier` — **role = tier**.

> **Kenapa 000074, bukan 000073?** `000073` dipakai `feat/report-bot-telegram`
> (`create_report_bot_tables`). Meski di branch ini 000073 masih "kosong", pakai
> **000074** supaya tak bentrok saat kedua branch merge ke `main`.

### 3.2 Registry `App\Support\PartnerHierarchy`

Satu tempat terpusat (pola seperti `Permissions`) yang tahu urutan & sifat tier:

| Tier (role) | level | induk sah | `holds_stock` |
|---|---|---|---|
| `grand_distributor` | 1 | — (langsung HQ) | true |
| `distributor` | 2 | `grand_distributor` | true |
| `reseller_bronze` | 3 | `distributor` | false |
| `reseller_gold` | 3 | `distributor` | false |

API:

- `tiers(): array` — daftar tier terurut + metadata (label, level, holds_stock).
- `levelOf(string $role): ?int`
- `allowedParentRoles(string $role): array`
- `isTierRole(string $role): bool`
- `holdsStock(string $role): bool`
- `label(string $role): string`

> `holds_stock` disimpan **sekarang** sebagai metadata. **Pemakaiannya**
> (sembunyikan UI stok untuk reseller, arahkan penjualan reseller ke stok
> distributor induk) = **Tahap 2**. Tahap 1 hanya mendefinisikan.

### 3.3 Update 3 method (agar role baru dikenal)

Semua cek klasifikasi mitra nyalur lewat 3 titik ini, jadi cukup update di sini:

- `User::PARTNER_ROLES` (const baru) = `[distributor, reseller,
  grand_distributor, reseller_bronze, reseller_gold]`; `isPartner()` memakainya.
- `User::priceField()`: `[distributor, grand_distributor]` → `price_distributor`;
  sisanya → `price_reseller`.
- `Product::priceForRole()`: `[distributor, grand_distributor]` →
  `price_distributor`; `[reseller, reseller_bronze, reseller_gold]` →
  `price_reseller`; default → `price_retail`.

**Efek ekonomi Tahap 1 = NOL** (harga belum berubah per tier). **Wajib** tes
regresi: `grand_distributor` TIDAK jatuh ke `price_retail`.

### 3.4 Aturan integritas pohon

Dijaga di service `PartnerHierarchyService::assignUpline()` + form request. Saat
set/ubah role+upline seorang mitra:

- Induk harus **tepat 1 level di atas** (`allowedParentRoles`).
- `upline_id` ≠ id sendiri; **tidak boleh cycle** (upline tak boleh keturunan
  sendiri).
- `grand_distributor`: `upline_id` = null (langsung HQ).
- Hanya tier mitra yang punya upline; staf (admin/gudang/super_admin) tidak.
- **Region disarankan**: saat pilih upline, kandidat region sama **diutamakan**
  di urutan, tapi tidak diblokir.
- **Tak boleh hapus/non-aktifkan** mitra yang masih punya **downline aktif**
  (harus dipindah dulu) — cegah "anak yatim".

### 3.5 UI

**(a) Form Anggota (Kelola Anggota):**

- Dropdown role dapat 3 opsi tier baru.
- Kalau role = tier mitra → muncul pemilih **Upline** (cari-ketik) berisi **hanya
  kandidat induk yang sah** (level tepat di atas), region sama diutamakan (bisa
  ditimpa). Grand Distributor → upline = HQ/none.
- **Member ID** tampil read-only (terisi otomatis setelah dibuat).
- Simpan lewat `PartnerHierarchyService` (aturan 3.4).

**(b) Halaman baru "Struktur Jaringan"** — menu sidebar internal di bawah "Kelola
Anggota", gate `manage_users`:

- **Pohon indentasi bisa dilipat** (Blade rekursif + JS vanilla, zero-dep):
  Grand Distributor → Distributor → Reseller. Tiap node: nama, **Member ID**,
  badge tier (warna), region, jumlah downline, badge **stockist / non-stok**.
- Panel **"Mitra belum ditempatkan"** (semua distributor/reseller generik) untuk
  penempatan cepat. **Kosong di awal** (sesuai keputusan data lama).

### 3.6 Izin role baru

Default izin role baru = **sama dengan basisnya** (grand & distributor seperti
`distributor`; bronze & gold seperti `reseller`) — tambahkan role baru ke array
`Permissions::DEFAULTS` di tiap izin tempat `distributor`/`reseller` muncul.
Pembedaan izin per tier = **Tahap 2**.

### 3.7 Member ID & Login

- **Member ID** (`users.member_id`): kode unik & **tetap**, format `SKN-000123`
  (prefix `SKN-` + urut 6 digit, zero-pad). **Tidak** meng-encode tier (aman buat
  login; tier tampil sebagai badge terpisah).
- **Generate**: saat mitra dibuat/ditempatkan (punya tier), kalau `member_id`
  kosong → isi `SKN-` + `str_pad(seq, 6, '0')`, `seq` = (suffix numerik terbesar
  yang ada) + 1, dijalankan **dalam transaksi**. Hanya mitra (grand/dist/reseller);
  staf tak perlu (bisa ditambah bila diinginkan). Aditif — user lama tanpa
  member_id tetap login seperti biasa.
- **Login** (`AuthController::login`): kolom `login` yang kini menerima
  username/email **diperluas** → **Member ID / username / email**. Non-email
  di-resolve ke user via `username` ATAU `member_id`; cek password & status aktif
  **tak berubah**. Label form: "Member ID / Username / Email".
- **Tampil**: member_id di form Anggota (read-only), node Struktur Jaringan, dan
  dashboard/profil mitra (biar mitra tahu ID login-nya).

### 3.8 Rencana Tes

- **Unit `PartnerHierarchy`**: `levelOf`, `allowedParentRoles`, `isTierRole`,
  `holdsStock`.
- **Unit `User`/`Product`**: `isPartner()` mencakup role baru; `priceField`/
  `priceForRole` benar (⚠️ grand ≠ retail — regresi).
- **Feature form**: set upline valid OK; tolak level salah / self / cycle; tolak
  hapus jika punya downline; region diutamakan di kandidat.
- **Feature Struktur Jaringan**: render pohon + panel belum-ditempatkan; gate
  (mitra kena 403 di Tahap 1).
- **Feature Member ID**: mitra baru dapat `member_id` unik & berurutan
  (`SKN-000123`); **tetap sama** walau tier berubah.
- **Feature Login**: berhasil via `member_id` (dan tetap via username/email);
  member_id/password salah ditolak; akun non-aktif tetap diblok.
- **Regresi**: harga PO distributor/reseller lama tak berubah.

## 4. Di Luar Lingkup Tahap 1 (untuk tahap berikut)

- **Tahap 2:** query subtree (atasan lihat downline-nya); **pemakaian**
  `holds_stock` (sembunyikan fitur stok untuk reseller, arahkan penjualan
  reseller ke stok distributor induk); izin per tier lewat matriks.
- **Tahap 3:** aturan & tabel komisi/insentif; hitung komisi **naik** pohon;
  laporan jaringan; harga spesifik per tier (Grand 8% off, Bronze/Gold beda) —
  perluas `priceForRole`.
- Self-service onboarding via **ID sponsor** (pakai `member_id` upline).
- (Opsional, terpisah) direktori "perusahaan distributor" jika suatu saat perlu.

## 5. Catatan Teknis

- **Zero-dependency**: Blade + Eloquent + vanilla JS. Migrasi `000074`.
- Tes lokal `C:\php83\php.exe artisan test`; `Pint --dirty` sebelum commit.
- Branch `feat/hirarki-mitra-mlm` dari `main`. Merge **terpisah** dari
  `feat/report-bot-telegram` — satu-satunya file bareng = `routes/web.php`
  (konflik sepele).
