# AGENTS.md — SKINKU B2B Portal

Aturan kerja portable untuk semua agent (Claude Code, Codex, Gemini, OpenCode, dll.).
`CLAUDE.md` hanya meng-import file ini — ubah aturan **di sini**.

## Proyek
- Laravel 13 + PHP 8.3+ + MySQL/MariaDB. UI Blade + **Tailwind v4 via Vite** (bukan CDN); Chart.js dll. via CDN.
- Detail sistem: `README.md`, `docs/SISTEM.md`, `docs/PETA-SISTEM.md`, `SESSION_HANDOFF.md`.
- Bahasa: kode & komentar mengikuti gaya file sekitar (komentar umumnya Bahasa Indonesia).

## Perintah
```bash
composer install
php artisan migrate            # hanya migration pending, tidak menyentuh data
php artisan serve --host=127.0.0.1 --port=8000   # http://127.0.0.1:8000/login
php artisan test               # SQLite in-memory
php -d memory_limit=-1 vendor/bin/phpunit   # suite penuh (default 128M bisa crash di PHP 8.5)
php artisan test --filter=Content
npm install && npm run dev     # Vite dev server (HMR) — jalankan bersama artisan serve
npm run build                  # WAJIB sebelum commit bila kelas Blade/CSS berubah
```
Lokal (Mac, MariaDB Homebrew): `.env` pakai `DB_USERNAME=<user OS>`, `DB_PASSWORD=` kosong,
`DB_SOCKET=/tmp/mysql.sock` (root tanpa password ditolak).

## Konvensi yang wajib diikuti
- **CSS**: Tailwind v4, entry `resources/css/app.css` (dimuat `@vite('resources/css/app.css')`).
  `public/build` **di-commit** (server Hostinger tanpa Node; deploy = `git pull`). Palet warna
  dikunci ke hex v3 di `@theme`. Jangan rakit nama kelas dinamis (`bg-{{ $x }}-500`) — kalau
  terpaksa, daftarkan di `@source inline(...)`. Jangan kembali ke `cdn.tailwindcss.com`.
- **Role**: kolom string `users.role` + tabel `roles`. Role non-sistem ditambah via migration
  `insertOrIgnore` (contoh `database/migrations/2026_01_01_000045_create_kols_table.php`).
- **Akses**: pakai permission, bukan cek nama role. Key di `app/Support/Permissions.php`
  (`DEFINITIONS` + `DEFAULTS`), route pakai middleware `permission:key`, view pakai `$user->canDo()`.
- **Upload**: `app/Services/ImageService.php::attach()` + model polymorphic `app/Models/File.php`.
- **OAuth/API eksternal**: pola `app/Models/TiktokConnection.php` + `app/Services/TikTokClient.php`.
  Token baru wajib cast `encrypted` + `$hidden`.
- **Background job**: queue `database`, worker lewat scheduler (`routes/console.php`, `--tries=1`)
  → retry dikelola sendiri di tabel, bukan `$tries`.
- **Audit**: aksi penting dicatat lewat `AuditService`.
- **Test**: `tests/Feature/*Test.php`, `RefreshDatabase`, helper privat `user($role)`
  (contoh `tests/Feature/KolDashboardTest.php`). Nama test snake_case Bahasa Indonesia.
- Dokumen: spec di `docs/superpowers/specs/`, plan di `docs/superpowers/plans/YYYY-MM-DD-*.md`.
  Setelah modul baru selesai, catat di `docs/SISTEM.md` + `docs/PETA-SISTEM.md`.

## Pekerjaan aktif: Portal Content Creator
Spec: `docs/superpowers/specs/2026-09-25-content-creator/` — `BRD.md`, `PRD.md`, `FRD.md`, `TRD.md`.
- Role `content_creator`, dashboard terpisah dari distributor, posting ke **akun brand SKINKU**.
- **Fase 1 SELESAI** (FB Page + Instagram + Threads via Meta API, TikTok mode manual) — ringkasan
  & penyimpangan di kepala `TRD.md`, peta kode di `docs/SISTEM.md` §9c. Berikutnya: Fase 2 TikTok API.
- Akun demo lokal: `creator_demo` / `password123` (`ContentCreatorDemoSeeder`, dev only).
- Setiap perubahan harus bisa ditelusuri ke ID `FR-xx` di FRD; test di `tests/Feature/ContentCreatorTest.php`.
- Route bisnis (PO/retur/inventory/komisi) wajib middleware `business` (staff & mitra saja).

## Tooling & alur kerja
Urutan untuk fitur baru: **grill-me → graphify → ponytail → verifikasi**.

1. **grill-me** (`/grill-me`) — interogasi spec/plan sebelum implementasi. Pakai untuk menguji
   BRD/PRD/FRD/TRD: open question, edge case, asumsi platform (kuota, token, audit TikTok).
   Hasil keputusan ditulis balik ke dokumen spec, bukan hanya di chat.
2. **graphify** — knowledge graph codebase. Bila `graphify-out/graph.json` ada, jawab pertanyaan
   struktur kode lewat `graphify query "<pertanyaan>"`, `graphify path "A" "B"`,
   `graphify explain "X"` **sebelum** grep mentah. Setelah mengubah kode: `graphify update .`
   (AST-only, tanpa biaya API). Build awal: `/graphify`. `graphify-out/` tidak di-commit.
3. **ponytail** — mode default saat coding: YAGNI → reuse kode repo → stdlib → fitur native →
   dependency terpasang → kode minimal. Bug fix di akar (fungsi bersama). Jalan pintas sengaja
   ditandai komentar `// ponytail: ...` beserta batas & jalur upgrade-nya. Audit: `/ponytail-review`.
4. **caveman** — gaya komunikasi ringkas (hemat token): jawaban singkat, tanpa basa-basi, poin
   inti saja. Hanya mengatur cara bicara, **bukan** kualitas kode, dokumen spec, atau pesan ke
   pihak lain. Belum terpasang di mesin ini — pasang plugin caveman bila ingin mode otomatis.

## Git & akhir sesi (WAJIB)
- **Jangan cantumkan Claude/AI di commit, PR, atau dokumen repo**: tanpa `Co-Authored-By: Claude…`,
  tanpa "Generated with Claude Code", tanpa menyebut agent sebagai pembuat. Author = user.
- **Setiap percakapan/sesi yang mengubah kode diakhiri dengan `graphify update .`** (AST-only, tanpa
  biaya API) supaya knowledge graph `graphify-out/` selalu mutakhir untuk sesi berikutnya.

## Batasan
- Jangan pernah commit `.env`, token, atau kredensial Meta/TikTok; hanya key kosong di `.env.example`.
- Jangan hapus validasi, cek permission, audit log, atau proteksi data demi diff pendek.
- Migration hanya additive; jangan `migrate:fresh` di DB lokal (berisi data nyata).
- Setiap logika non-trivial (transisi status, publish, retry, token) wajib punya test.
