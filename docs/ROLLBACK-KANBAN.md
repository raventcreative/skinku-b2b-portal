# Rollback Modul Kanban

Titik mundur: tag **`pre-kanban`** (commit `2e1282a`, sudah di remote) — keadaan
persis sebelum satu baris pun modul Kanban ditulis.

Modul sepenuhnya ADITIF: 4 tabel baru (boards, board_columns, board_cards,
board_card_comments),
1 permission baru (kanban.view), nol perubahan pada tabel/modul lain.

Untuk sekadar MENYEMBUNYIKAN tanpa mundur: cabut `kanban.view` dari role di
Manajemen Hak Akses — menu hilang, data tetap, tak perlu rollback.

## Urutan WAJIB: migrasi dulu, baru kode

### 1. Di server — cabut migrasi

```bash
cd ~/domains/skinku.id/laravel-b2b
/opt/alt/php83/usr/bin/php artisan migrate:rollback --path=database/migrations/2026_01_01_000051_create_board_card_comments_table.php
/opt/alt/php83/usr/bin/php artisan migrate:rollback --path=database/migrations/2026_01_01_000050_create_kanban_tables.php
```

⚠️ Menghapus SEMUA papan/kolom/kartu + override permission kanban.
Mau selamatkan datanya dulu: `artisan db:backup` sebelum langkah ini.

### 2. Di lokal — revert kode, push

```bash
git revert --no-edit pre-kanban..HEAD
git push origin main
```

(Bila setelah kanban ada commit fitur LAIN yang mau dipertahankan, revert
per-commit kanban saja — daftar commitnya: `git log --oneline pre-kanban..HEAD`.)

### 3. Di server — tarik & bersihkan

```bash
git pull
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan route:list | grep -i kanban   # harus kosong
```
