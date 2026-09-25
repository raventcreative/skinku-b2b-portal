# FRD — Portal Content Creator SKINKU
**Functional Requirements Document** · v0.1 draft · 2026-09-25

Dokumen terkait: [BRD](BRD.md) · [PRD](PRD.md) · [TRD](TRD.md)

Prioritas: **M** = Must (Fase 1), **S** = Should (Fase 1 bila sempat), **F2/F3** = fase berikutnya.

---

## 1. Role, Akun & Akses

| ID | Requirement | Prio |
|----|-------------|------|
| FR-01 | Sistem memiliki role `content_creator` (label "Content Creator") di tabel `roles`, bukan role sistem (`is_system=false`). | M |
| FR-02 | Super admin/admin dapat membuat, mengedit, menonaktifkan user `content_creator` melalui menu Kelola Anggota yang sudah ada. | M |
| FR-03 | Tersedia 1 akun demo `creator_demo` (hanya lewat seeder dev, tidak jalan di production). | M |
| FR-04 | `content_creator` **bukan** partner & **bukan** staff: tidak dapat mengakses PO, inventory, harga, komisi, KOL, keuangan, laporan penjualan (HTTP 403 / menu tidak tampil). | M |
| FR-05 | Permission baru di matriks permission (default role dalam kurung):<br>• `content.create` — buat & kelola konten sendiri (content_creator, admin)<br>• `content.review` — lihat semua konten & setujui/tolak (admin)<br>• `content.publish.manage` — retry, tandai terbit manual, lihat log publish (admin)<br>• `social.connect` — hubungkan/putus akun brand (super_admin) | M |
| FR-06 | Sidebar untuk `content_creator` hanya menampilkan: Dashboard Creator, Konten Saya, Kalender, Ganti Password. | M |

## 2. Dashboard Creator

| ID | Requirement | Prio |
|----|-------------|------|
| FR-10 | Setelah login, `content_creator` yang membuka `/dashboard` diarahkan ke dashboard creator. | M |
| FR-11 | Kartu ringkasan (konten milik sendiri): Draft, Menunggu Review, Ditolak, Terjadwal, Terbit bulan ini. | M |
| FR-12 | Tabel 10 konten terbaru: thumbnail, judul, platform (ikon), status per platform, jadwal, link terbit. | M |
| FR-13 | Tombol utama "+ Konten Baru". | M |
| FR-14 | Banner info bila ada konten ditolak (dengan alasan) atau gagal terbit. | S |
| FR-15 | Admin/reviewer melihat widget "Antrean Review" (jumlah + 5 teratas) dan "Gagal Terbit" di dashboard staff. | S |

## 3. Konten & Media

| ID | Requirement | Prio |
|----|-------------|------|
| FR-20 | Creator membuat konten dengan field: judul internal (wajib), tipe (`image`, `video`, `carousel`), media, caption utama, caption override per platform (opsional), platform target (≥1), usulan jadwal (opsional, "secepatnya" bila kosong), catatan untuk reviewer. | M |
| FR-21 | Upload media: gambar JPG/PNG/WEBP; video MP4/MOV. Maks. ukuran default: gambar 8 MB, video 300 MB (dapat dikonfigurasi). Carousel 2–10 item. | M |
| FR-22 | Validasi kecocokan media vs platform target mengikuti tabel §7; bila tidak cocok, form menampilkan pesan per platform dan tidak bisa diajukan. | M |
| FR-23 | Validasi panjang caption per platform (tabel §7), dihitung setelah override. | M |
| FR-24 | Gambar di-resize/konversi ke JPEG (IG hanya menerima JPEG); video disimpan apa adanya. | M |
| FR-25 | Creator dapat mengedit/menghapus konten berstatus `draft` atau `rejected`. Konten `in_review` hanya bisa ditarik kembali ke draft. Konten `approved`/`scheduled`/`published` tidak bisa diedit creator. | M |
| FR-26 | Preview per platform (tampilan caption + media) sebelum mengajukan. | S |
| FR-27 | Tombol download media + salin caption (dipakai untuk mode manual TikTok). | M |

## 4. Workflow Status

Status konten (level konten):

```
draft ──ajukan──▶ in_review ──setujui──▶ approved ──(jadwal)──▶ scheduled ──▶ publishing ──▶ done
  ▲                  │                                                              │
  └──revisi── rejected ◀──tolak                                                     └─▶ partial / failed
  ▲                  │
  └──tarik kembali───┘
```

Status per platform (level target): `pending` → `queued` → `publishing` → `published` | `failed` | `manual_pending` → `published` (manual).

| ID | Requirement | Prio |
|----|-------------|------|
| FR-30 | Transisi status hanya lewat aksi yang sah di diagram; transisi lain ditolak server-side. | M |
| FR-31 | Reviewer dapat mengedit caption & jadwal sebelum menyetujui; perubahan tercatat di audit log. | M |
| FR-32 | Penolakan wajib alasan (min. 5 karakter); alasan tampil ke creator. | M |
| FR-33 | Status konten diturunkan dari status target: semua `published` → `done`; sebagian → `partial`; semua gagal → `failed`. | M |
| FR-34 | Bila OQ-1 diputuskan "tanpa approval", permission `content.review` boleh diberikan ke creator sehingga ia dapat menyetujui kontennya sendiri — tanpa mengubah kode. | S |

## 5. Koneksi Akun Brand

| ID | Requirement | Prio |
|----|-------------|------|
| FR-40 | Halaman "Akun Sosial Media" (permission `social.connect`) menampilkan status koneksi FB Page, IG, Threads, TikTok: nama akun, terhubung oleh, kedaluwarsa token, error terakhir. | M |
| FR-41 | Tombol "Hubungkan Meta" menjalankan OAuth Facebook Login; setelah callback, admin memilih Page, dan sistem mendeteksi akun IG Business yang tertaut ke Page tersebut. | M |
| FR-42 | Tombol "Hubungkan Threads" menjalankan OAuth Threads terpisah. | M |
| FR-43 | Tombol "Hubungkan TikTok" (OAuth Content Posting API). | F2 ✅ |
| FR-44 | Tombol "Putuskan" menghapus token dari database. | M |
| FR-45 | Token di-refresh otomatis sebelum kedaluwarsa; bila gagal, status koneksi `error` dan admin mendapat peringatan di dashboard. | M |
| FR-46 | Hanya satu koneksi aktif per platform (akun brand tunggal). | M |

## 6. Publikasi

| ID | Requirement | Prio |
|----|-------------|------|
| FR-50 | Scheduler tiap menit memilih target `queued` yang jadwalnya ≤ sekarang lalu mempublikasikan lewat antrean (queue). | M |
| FR-51 | Publish ke Facebook Page: foto, video, atau multi-foto. | M |
| FR-52 | Publish ke Instagram: foto, Reels (video), carousel (container → publish). | M |
| FR-53 | Publish ke Threads: teks + foto, video, carousel (container → publish). | M |
| FR-54 | Publish ke TikTok: video/foto via Content Posting API. Sebelum app lolos audit, privacy = `SELF_ONLY`. | F2 ✅ |
| FR-55 | Selama Fase 1, target TikTok berstatus `manual_pending`; admin menandai terbit dengan menempelkan URL postingan. | M |
| FR-56 | Setiap target menyimpan: ID eksternal, permalink, waktu terbit, jumlah percobaan, error terakhir. | M |
| FR-57 | Gagal → retry otomatis maks. 3x dengan jeda bertingkat (5, 15, 60 menit); setelah itu `failed` + notifikasi. Admin dapat retry manual. | M |
| FR-58 | Publish idempoten: target yang sudah punya ID eksternal tidak dipublish ulang. | M |
| FR-59 | Sebelum publish, cek kuota platform (mis. IG `content_publishing_limit`); bila habis, tunda ke slot berikutnya. | S |

## 7. Batasan Platform (acuan validasi FR-22/23)

> Angka mengikuti dokumentasi resmi per 2026-09; **wajib diverifikasi ulang** saat implementasi dan
> disimpan di `config/content.php` agar mudah diubah.

| Platform | Gambar | Video | Carousel | Caption | Kuota |
|----------|--------|-------|----------|---------|-------|
| Facebook Page | JPG/PNG, ≤ 10 MB | MP4/MOV, sampai beberapa GB | multi-foto | ±63.000 karakter | — |
| Instagram (Business) | **JPEG saja**, ≤ 8 MB, rasio 4:5 – 1.91:1 | Reels MP4/MOV, 3 dtk – 15 mnt, ≤ 300 MB, disarankan 9:16 | 2–10 item (gambar/video) | 2.200 karakter, ≤ 30 hashtag | ±100 post / 24 jam |
| Threads | JPG/PNG, ≤ 8 MB | MP4/MOV, ≤ 5 mnt, ≤ 1 GB | 2–20 item | 500 karakter | ±250 post / 24 jam |
| TikTok (F2) | JPG/WEBP (photo post) | MP4/MOV/WEBM, durasi maks. mengikuti `creator_info` akun | foto: sampai 35 | 2.200 karakter | mengikuti `creator_info` |

## 8. Notifikasi

| ID | Requirement | Prio |
|----|-------------|------|
| FR-60 | Creator diberi tahu (notifikasi di portal) saat konten disetujui, ditolak, terbit, atau gagal. | M |
| FR-61 | Reviewer diberi tahu saat ada konten baru diajukan. | S |
| FR-62 | Opsional: notifikasi Telegram ke grup admin untuk kegagalan publish (memakai bot Telegram yang sudah ada). | S |

## 9. Riwayat & Audit

| ID | Requirement | Prio |
|----|-------------|------|
| FR-70 | Semua aksi (buat, edit, ajukan, tarik, setujui, tolak, publish, gagal, retry, connect, disconnect) dicatat di audit log. | M |
| FR-71 | Halaman detail konten menampilkan timeline status + log percobaan publish per platform. | M |
| FR-72 | Halaman "Semua Konten" (reviewer) dengan filter: creator, status, platform, rentang tanggal. | M |
| FR-73 | Kalender bulanan konten terjadwal/terbit (creator: miliknya; reviewer: semua). | S |
| FR-74 | File media dihapus N hari (default 90) setelah semua target terbit; metadata & link tetap. | S |

## 10. Traceability ke PRD

| User Story | FR |
|------------|----|
| US-C1 | FR-10, FR-06 |
| US-C2..C5 | FR-20..FR-24 |
| US-C6, C8 | FR-25, FR-30 |
| US-C7 | FR-11, FR-12, FR-56, FR-71 |
| US-C9 | FR-04, FR-25 |
| US-A1..A2 | FR-15, FR-31, FR-32, FR-72 |
| US-A3 | FR-73 |
| US-A4 | FR-57, FR-71 |
| US-A5..A6 | FR-40..FR-46 |
| US-S1..S2 | FR-02, FR-05 |
