# SKINKU B2B Distributor Portal (Laravel + SQL)

ERP ringan untuk operasional distribusi SKINKU:

```
Pusat SKINKU → Distributor → Reseller → Konsumen
```

Aplikasi ini adalah hasil migrasi dari prototipe **Vite + React + TypeScript + Firebase (Auth/Firestore/Storage)** ke **Laravel 13 dengan database SQL** sebagai satu-satunya sumber data. **Tidak ada lagi ketergantungan Firebase/Firestore.**

## Fitur

- Autentikasi berbasis SQL (login username/email + password, hashing bcrypt, forgot/reset/change password).
- Role: `super_admin`, `admin`, `gudang`, `distributor`, `reseller` dengan middleware akses per-route.
- Manajemen User (CRUD, enable/disable, reset password, soft delete) + aturan privilege.
- Manajemen Produk (CRUD, upload foto ke storage publik, soft delete).
- Purchase Order (mitra membuat PO; total dihitung di server berdasarkan harga & role; alur status).
- Saat PO `completed` → transaksi DB otomatis: kurangi stok pusat, tambah stok mitra, catat stock movement (`OUT` + `PO_FULFILLMENT`), audit log. Gagal & rollback bila stok pusat kurang.
- Inventory (stok pusat & mitra) + Stock Movement ledger.
- Reporting & dashboard chart berbasis SQL aggregate (tren penjualan, top produk, per distributor, per region, distribusi status PO, HQ vs mitra).
- Audit log untuk semua aksi penting.
- Portal Content Creator (role `content_creator`): kreator membuat konten, saling review (tidak bisa approve konten sendiri), lalu konten terbit ke akun brand SKINKU — Facebook Page, Instagram, Threads (Meta API) dan TikTok (Content Posting API). Detail: `docs/superpowers/specs/2026-09-25-content-creator/`.

## Persyaratan

- PHP **8.3+** (proyek ini dikembangkan & diuji dengan PHP 8.3 di `C:\php83\php.exe`).
- Composer 2.x
- MySQL / MariaDB (default) — XAMPP sudah cukup. PostgreSQL didukung lewat `.env`.
- Ekstensi PHP: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `fileinfo`, `gd`.

> Catatan Windows/XAMPP: PHP default pada PATH mungkin masih 7.4 (terlalu lama untuk Laravel 13).
> Jalankan composer/artisan dengan PHP 8.3 secara eksplisit, mis:
> `& "C:\php83\php.exe" artisan migrate` atau tambahkan `C:\php83` ke depan PATH.

## Setup Lokal

```bash
# 1. Install dependency
composer install

# 2. Copy environment & generate key
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate

# 3. Konfigurasi database di .env (default MySQL):
#    DB_CONNECTION=mysql
#    DB_DATABASE=skinku_b2b
#    DB_USERNAME=root
#    DB_PASSWORD=
#    Buat database-nya:  CREATE DATABASE skinku_b2b;

# 4. Set kredensial super admin di .env (JANGAN hardcode di kode):
#    SUPER_ADMIN_NAME / SUPER_ADMIN_EMAIL / SUPER_ADMIN_USERNAME / SUPER_ADMIN_PASSWORD

# 5. Jalankan migrasi
php artisan migrate

# 6. Seed super admin awal (idempotent — hanya dibuat bila belum ada)
php artisan db:seed

# 7. Symlink storage agar foto produk tampil
php artisan storage:link

# 8. Jalankan server
php artisan serve
# buka http://127.0.0.1:8000/login
```

Login pertama memakai `SUPER_ADMIN_USERNAME` / `SUPER_ADMIN_PASSWORD` dari `.env`.

### Data demo (opsional, hanya development)

```bash
php artisan db:seed --class=Database\\Seeders\\DevDataSeeder
```

Membuat 5 produk + akun demo (`admin`, `gudang`, `dist_bali`, `dist_jkt`, `res_santi`) dengan password `password123`. **Tidak akan jalan di environment `production`.**

## Beralih ke PostgreSQL

Di `.env`:

```env
DB_CONNECTION=pgsql
DB_PORT=5432
```

Aktifkan ekstensi `pdo_pgsql` terlebih dulu. Query aggregate sudah portabel (mysql/pgsql/sqlite).

## Matriks Akses Role

| Fitur                | super_admin | admin | gudang | distributor | reseller |
|----------------------|:-----------:|:-----:|:------:|:-----------:|:--------:|
| Dashboard            | ✅ | ✅ | ✅ | ✅ | ✅ |
| Purchase Orders      | ✅ | ✅ | ✅ | ✅ (sendiri) | ✅ (sendiri) |
| Buat PO              | – | – | – | ✅ | ✅ |
| Update status PO     | ✅ | ✅ | ✅ | – | – |
| Hapus PO             | ✅ | ✅ | – | – | – |
| Manajemen Produk     | ✅ | ✅ | – | – | – |
| Inventory            | ✅ | ✅ | ✅ | ✅ (sendiri) | ✅ (sendiri) |
| Stock Movement       | ✅ | ✅ | ✅ | ✅ (sendiri) | ✅ (sendiri) |
| Laporan              | ✅ | ✅ | ✅ | ✅ | – |
| Kelola Anggota       | ✅ | ✅ | – | – | – |
| Hapus User           | ✅ | – | – | – | – |
| Audit Log            | ✅ | – | – | – | – |
| Pengaturan Sistem    | ✅ | – | – | – | – |

## Testing

```bash
php artisan test
```

Test berjalan di SQLite in-memory (terisolasi dari DB dev). Mencakup: autentikasi SQL, gating role, perhitungan total PO server-side, transaksi penyelesaian PO (stok + movement + audit), guard double-complete, dan rollback saat stok kurang, serta render semua halaman.

## Arsitektur

```
app/
  Models/            User, Product, PurchaseOrder, PurchaseOrderItem, Inventory, StockMovement, AuditLog
  Http/Controllers/  Auth, Dashboard, User, Product, PurchaseOrder, Inventory, StockMovement, Report, AuditLog, Setting
  Http/Middleware/   RoleMiddleware (role:... + enforce status aktif)
  Services/          AuditService, InventoryService, PurchaseOrderService, ReportService
database/migrations/ 7 tabel inti
database/seeders/    SuperAdminSeeder (env), DevDataSeeder (dev)
resources/views/     Blade (layout, auth, dashboard, users, products, purchase_orders, inventory, stock_movements, reports, audit_logs, settings)
routes/web.php       Semua route + middleware role
```

UI memakai Tailwind v4 terkompilasi lewat Vite (`resources/css/app.css` → `public/build`, **ikut di-commit** agar deploy cukup `git pull`). Chart.js dll. masih via CDN. Setelah mengubah kelas di Blade/CSS: `npm run build` lalu commit `public/build`. Dev: `npm run dev` bersamaan dengan `php artisan serve`.

## Keamanan

- Sumber kebenaran user = tabel SQL `users` (tanpa Firestore, tanpa fallback role, tanpa auto-create profile, tanpa dev bypass).
- User `inactive`/`deleted` tidak bisa login dan langsung dikeluarkan dari sesi.
- Password ter-hash (bcrypt), tidak pernah plaintext (termasuk di audit log).
- CSRF aktif di semua form; query lewat Eloquent/Query Builder (anti SQL injection).
- Upload foto divalidasi (mime + ukuran). Soft delete pada user/produk/PO untuk menjaga histori.
- Credential DB & super admin via `.env`, tidak di kode.
- Kredensial app Meta/Threads/TikTok diisi di **Konten → Akun Sosial Media → Kredensial App** (permission `social.connect`) atau di `.env` (`META_APP_ID`, `THREADS_APP_ID`, `TIKTOK_CONTENT_CLIENT_KEY`, dst. — lihat `.env.example`). Isian portal disimpan terenkripsi di `app_settings` dan **menimpa** `.env`; secret tidak pernah ditampilkan, kolom kosong = nilai lama dipertahankan.
- Kolom secret di form itu memakai `autocomplete="new-password"`: Chrome mengabaikan `autocomplete="off"` dan sempat mengisi Meta App ID/Secret dengan username/password login. Setelah menyimpan, pastikan isian bukan hasil autofill browser.

## Catatan Migrasi (lama → baru)

| Lama (React/Firebase)              | Baru (Laravel/SQL)                              |
|------------------------------------|-------------------------------------------------|
| `firebase/auth` signIn             | `AuthController@login` (Hash + session)         |
| Firestore `users` + `usernameIndex`| Tabel `users` (username & email unik)           |
| Firestore `products`               | Tabel `products` + `ProductController`          |
| Firestore `purchaseOrders`         | `purchase_orders` + `purchase_order_items`      |
| Firestore `inventory`              | Tabel `inventory` (unik `user_id`+`product_id`) |
| Firestore `stockMovements`         | Tabel `stock_movements`                         |
| Firestore `auditLogs`              | Tabel `audit_logs` + `AuditService`             |
| Firebase Storage                   | Storage disk `public` (`storage:link`)          |
| Role `admin_gudang`                | Dipetakan ke `gudang`                           |
| Client menghitung total PO         | Total dihitung ulang di server                  |
