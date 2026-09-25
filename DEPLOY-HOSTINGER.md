# Deploy SKINKU B2B Portal ke Hostinger (jalur SSH) — system.skinku.id

Detail server Anda (dari hPanel → SSH Access):
- Host/IP : `153.92.11.179`
- Port    : `65002`
- User    : `u864765086`
- Login   : `ssh -p 65002 u864765086@153.92.11.179`

> WordPress di `skinku.id` TIDAK terganggu. Portal ini berdiri di subdomain `system.skinku.id` yang terpisah.

---

## TAHAP 0 — Aktifkan SSH (sekali saja)
hPanel → Website `skinku.id` → Tingkat lanjut → **SSH Access** → klik **Aktifkan**.
Jika diminta, set/ubah **password SSH** dan simpan baik-baik (jangan dibagikan).

## TAHAP 1 — Buat database (hPanel)
hPanel → **Databases → MySQL Databases** → buat baru. Catat 3 hal:
- Nama database (mis. `u864765086_skinku`)
- Username database (mis. `u864765086_skinku`)
- Password database

## TAHAP 2 — Buat subdomain (hPanel)
hPanel → **Domain → Subdomain** → buat `system` untuk domain `skinku.id`.
Catat **folder/document root** yang muncul (mis. `public_html/system` atau `domains/system.skinku.id/public_html`).
> Kita akan arahkan document root ini ke folder `public` aplikasi Laravel (lihat Tahap 5).

## TAHAP 3 — Upload file aplikasi
Pakai **File Manager** hPanel ATAU SCP. Upload `skinku-b2b-deploy.zip` ke home folder Anda,
mis. ke `~/laravel/` lalu extract sehingga menjadi `~/laravel/skinku-b2b/`.
(Folder aplikasi sebaiknya DI LUAR `public_html` demi keamanan — hanya `public/` yang diekspos web.)

## TAHAP 4 — Setup via SSH
```bash
ssh -p 65002 u864765086@153.92.11.179
cd ~/laravel/skinku-b2b

# 1. Install dependency (tanpa dev). Pakai PHP 8.3 bila perlu: php8.3 $(which composer) ...
composer install --no-dev --optimize-autoloader

# 2. Siapkan .env produksi
cp .env.production .env
nano .env          # isi DB_DATABASE / DB_USERNAME / DB_PASSWORD + SUPER_ADMIN_PASSWORD + MAIL_*

# 3. Generate app key
php artisan key:generate --force

# 4. Migrasi + seed super admin (HANYA super admin, bukan data demo)
php artisan migrate --force
php artisan db:seed --force

# 5. Symlink storage (foto produk) + cache konfigurasi
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Pastikan folder writable
chmod -R 775 storage bootstrap/cache
```

> Jika `php` di server bukan 8.3, cek `php -v`. Pakai `php8.3 artisan ...` bila tersedia.
> Jika `composer` tak ada di PATH: `php8.3 ~/composer.phar install --no-dev --optimize-autoloader`
> (atau saya kirim paket yang sudah termasuk `vendor/` agar langkah composer dilewati).

## TAHAP 5 — Arahkan document root subdomain ke /public
hPanel → Domain → Subdomain `system.skinku.id` → ubah **Document root** menjadi folder
`public` aplikasi, mis. `laravel/skinku-b2b/public`.
(Kalau hPanel hanya mengizinkan folder di dalam `public_html`, beri tahu saya — ada trik alternatifnya.)

## TAHAP 6 — SSL + DNS
1. hPanel → **SSL** → terbitkan SSL gratis untuk `system.skinku.id`.
2. DNS: record lama `CNAME system → ghs.googlehosted.com` (sisa Google) akan otomatis
   tergantikan saat subdomain dibuat. Jika masih ada, hapus, lalu pastikan `system`
   mengarah ke server Hostinger (`153.92.11.179`).

## TAHAP 7 — Cek
Buka `https://system.skinku.id` → login dengan `SUPER_ADMIN_USERNAME` / password dari `.env`.

---

## Update aplikasi di masa depan
> Aset CSS (Tailwind) sudah ter-build di repo (`public/build`) — server **tidak** butuh Node/npm.

```bash
cd ~/laravel/skinku-b2b
# upload file baru / git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Catatan keamanan produksi
- `APP_DEBUG=false` (sudah di .env.production).
- JANGAN jalankan `DevDataSeeder` di server produksi.
- Ganti `SUPER_ADMIN_PASSWORD` sebelum seed.
- Folder aplikasi di luar `public_html`; hanya `public/` yang diekspos.

## Portal Content Creator (migrasi 000142)
1. `php artisan migrate --force` (tabel konten + role `content_creator`).
2. Isi `.env` server: `META_APP_ID`, `META_APP_SECRET`, `THREADS_APP_ID`, `THREADS_APP_SECRET`
   (lihat `.env.example`), lalu `php artisan config:cache`. `APP_URL` **wajib** `https://system.skinku.id` —
   Instagram & Threads mengambil media dari URL publik ini.
3. Di Meta Developer App daftarkan redirect URI:
   `https://system.skinku.id/social-connections/meta/callback` dan `.../social-connections/threads/callback`.
4. Login super_admin → menu **Konten → Akun Sosial Media** → Hubungkan Meta & Threads.
5. Upload video sampai 300 MB butuh batas PHP yang cukup (hPanel → PHP Options):
   `upload_max_filesize=300M`, `post_max_size=310M`, `max_execution_time=300`.
6. Cron `schedule:run` yang sudah ada otomatis menjalankan `content:publish-due` (tiap menit)
   & `social:refresh-tokens` (harian).
