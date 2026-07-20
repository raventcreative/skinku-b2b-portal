# Rollback Modul KOL Fase 1

Titik mundur: tag **`pre-kol`** (commit `ceb5ca2`, sudah di remote) — keadaan
persis sebelum satu baris pun modul KOL ditulis.

Modul ini sengaja dibangun **aditif**: 3 tabel baru, 1 role baru, 5 permission
baru, nol perubahan pada tabel/modul lain. Karena itu rollback-nya bersih —
tidak ada data lama yang tersentuh.

## Kapan pakai ini
Setelah deploy ternyata tidak sesuai dan Freddie memutuskan mundur total.
Untuk sekadar menyembunyikan menunya TANPA mundur, cukup cabut permission
`kol.view` dari role `kol_specialist` di Manajemen Hak Akses — tak perlu
rollback sama sekali.

## Urutan WAJIB: migrasi dulu, baru kode

Migrasi di-rollback SEBELUM kode di-revert — perintah rollback butuh file
migrasinya masih ada di disk. Kalau kodenya keburu di-revert, file migrasinya
ikut hilang dan tabelnya tertinggal jadi yatim.

### 1. Di server — cabut migrasi (urutan terbalik)

```bash
cd ~/domains/skinku.id/laravel-b2b
/opt/alt/php83/usr/bin/php artisan migrate:rollback --path=database/migrations/2026_01_01_000047_create_kol_deals_table.php
/opt/alt/php83/usr/bin/php artisan migrate:rollback --path=database/migrations/2026_01_01_000046_create_kol_screenings_table.php
/opt/alt/php83/usr/bin/php artisan migrate:rollback --path=database/migrations/2026_01_01_000045_create_kols_table.php
```

⚠️ Ini MENGHAPUS semua data KOL/screening/deal yang sudah diinput, plus role
`kol_specialist` beserta override permission-nya (down() migrasi 000045).
Kalau datanya mau diselamatkan dulu: `artisan db:backup` sebelum langkah ini.

### 2. Di lokal — revert kode, push

```bash
git revert --no-edit pre-kol..HEAD
git push origin main
```

`git revert` (bukan reset/force-push): riwayat tetap utuh, server tinggal pull
biasa, dan modulnya bisa dihidupkan lagi nanti dengan me-revert si revert.

### 3. Di server — tarik & bersihkan

```bash
git pull
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

### 4. Verifikasi

```bash
/opt/alt/php83/usr/bin/php artisan route:list | grep -i kol   # harus kosong
```
Login → menu "KOL" hilang, 328-an test existing tetap lulus.

## Darurat (situs error parah, butuh mundur detik ini juga)

```bash
cd ~/domains/skinku.id/laravel-b2b
git checkout pre-kol
/opt/alt/php83/usr/bin/php artisan optimize:clear
```
Ini detached HEAD — HANYA penahan sementara. Setelah tenang, jalankan prosedur
normal di atas lalu `git checkout main && git pull`. Jangan tinggalkan server
di detached HEAD: `git pull` berikutnya tidak akan jalan.
