# Impor Massal KOL (Template)

Impor banyak KOL + screening sekaligus dari file template, ganti input satu-satu.

## Alur
1. Database KOL → **⬆ Impor** → halaman impor.
2. **⬇ Unduh Template** (.xlsx, dibuat XlsxWriter) — 1 sheet, 1 baris header.
3. Paste data ke template → **upload** (.xlsx atau .csv).
4. **Preview**: tiap baris ditandai BARU / +Screening / Dilewati(+alasan). Belum tersimpan.
5. **Konfirmasi Impor** → yang valid masuk, muncul laporan.

## Kolom template
Wajib: `username`, `followers`, `views_1..views_7`
Opsional: `platform` (kosong=tiktok), `ratecard`, `tanggal_listing`, `agency`, `kategori`
Field form: **Tanggal listing default** (default hari ini) → dipakai untuk baris tanpa tanggal.

## Kurasi / dedup (kunci = username + tanggal_listing)
- Username dibandingkan case-insensitive, `@` diabaikan.
- Duplikat dalam file (username+tanggal sama) → baris pertama masuk, sisanya dilewati.
- Username sudah ada di DB → KOL dipakai ulang, followers di-update, + screening.
- Screening tanggal itu sudah ada → dilewati ("sudah ada"). **Re-upload file sama = 0 duplikat.**
- Screening lama tidak ditimpa (tak ada data hilang).

## Validasi (file berantakan itu wajar)
- username & followers wajib; non-angka pada followers/ratecard/views → baris error.
- view kosong = 0; ratecard kosong = null (belum nego); platform kosong = tiktok.
- Baris error DILEWATI + dilaporkan (nomor baris + alasan); baris valid tetap masuk.

## Komponen
- `app/Support/SpreadsheetReader.php` — baca .xlsx (sharedStrings + inlineStr + sel jarang) & .csv (auto delimiter , / ;). Zero-dep, pelengkap XlsxWriter.
- `app/Services/KolService.php` — `upsertScreening()` (buat/pakai-ulang KOL + buat screening). Dipakai bareng form Screening & impor (satu sumber logika).
- `app/Services/KolImportService.php` — template, parse, classify (preview), commit. Delegasi tulis ke KolService.
- `app/Http/Controllers/KolImportController.php` — form / template / preview / commit. File upload disimpan sementara (token) antara preview→commit, dihapus setelah.
- Route di grup `permission:kol.screening.manage`. Tombol **⬆ Impor** di Database KOL.

## Di luar cakupan (sengaja)
Mapping kolom manual, impor multi-platform per file, scheduling/auto-import, update screening yang sudah ada (harus hapus dulu).

## Rollback
Fitur ADITIF (nol migrasi, nol perubahan tabel). `git revert` commit-nya, `optimize:clear`. Refactor `KolScreeningController::store` → `KolService::upsertScreening` ikut ter-revert; perilaku form tak berubah (dijaga KolModuleTest).
