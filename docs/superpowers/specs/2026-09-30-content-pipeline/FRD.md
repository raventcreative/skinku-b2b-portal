# FRD — Content Pipeline SKINKU

**Functional Requirements Document** · v1.0 · 2026-09-30

## 1. Navigasi dan akses

| ID | Persyaratan | Prioritas |
|---|---|---|
| FR-01 | Menu Konten membuka workspace Pipeline; tautan terpisah menuju Kalender, Insight Konten, dan Akun Sosial Media sesuai permission. | M |
| FR-02 | Creator melihat dan mengubah konten sendiri. Admin/super admin melihat seluruh konten dan filter creator. | M |
| FR-03 | Tidak ada menu, route, aksi, atau permission approval/rejection pada workflow baru. | M |

## 2. Workspace Pipeline

| ID | Persyaratan | Prioritas |
|---|---|---|
| FR-10 | Tampilkan kelompok Draft, Terjadwal, Sedang terbit, Selesai, dan Perlu tindakan; hitung jumlah sesuai cakupan akses pengguna. | M |
| FR-11 | Perlu tindakan mencakup target `failed` dan `manual_pending`, serta menunjukkan platform yang perlu ditangani. | M |
| FR-12 | Setiap item menampilkan thumbnail/jenis media, judul, creator bila admin, tanggal jadwal, status, target platform, serta tautan ke detail. | M |
| FR-13 | Filter status, platform, tanggal, pencarian judul; filter creator hanya untuk admin/super admin. Filter tersimpan dalam query string. | M |
| FR-14 | Tersedia aksi Buat Konten dengan ikon dan label pada desktop maupun ponsel. Empty state memberi tombol tindakan yang relevan. | M |

## 3. Kalender

| ID | Persyaratan | Prioritas |
|---|---|---|
| FR-20 | Kalender bulanan menampilkan tanggal lengkap Senin–Minggu dan navigasi bulan. | M |
| FR-21 | Tampilkan konten dengan `scheduled_at` dalam bulan aktif; klik judul membuka detail konten. | M |
| FR-22 | Item kalender menampilkan waktu, judul ringkas, platform, status ringkas, dan gaya visual untuk kegagalan/manual. | M |
| FR-23 | Bulan, tahun, dan filter creator (admin) tervalidasi; tanggal di luar bulan tidak bocor ke tampilan. | M |
| FR-24 | Pada layar sempit grid tetap terbaca; isi sel dapat ditumpuk dan kalender tidak memperlebar viewport. | M |

## 4. Formulir konten

| ID | Persyaratan | Prioritas |
|---|---|---|
| FR-30 | Form mendukung judul, tipe Foto/Video/Carousel, media, caption utama, caption override, satu atau lebih platform, dan jadwal. | M |
| FR-31 | Aksi **Simpan draft** hanya menyimpan; aksi **Terbitkan/jadwalkan** menjalankan validasi penuh dan menyiapkan target platform. | M |
| FR-32 | Jadwal kosong berarti publish segera; jadwal masa depan menahan target sampai waktunya. | M |
| FR-33 | Validasi MIME/ukuran/jumlah media, kecocokan tipe-platform, panjang caption, hashtag, dan jadwal dilakukan server-side. | M |
| FR-34 | Saat TikTok API aktif dan dipilih, form menampilkan akun tujuan, pilihan privacy yang tersedia, opsi komentar/duet/stitch sesuai batas akun, dan consent yang diwajibkan. | M |
| FR-35 | Bila TikTok dalam mode manual, form menjelaskan bahwa creator/admin perlu memposting secara manual dan menempelkan permalink. | M |
| FR-36 | Creator boleh mengedit/menghapus draft; konten antrean hanya dapat diubah selama tidak ada target yang sedang terbit atau sudah terbit. | M |
| FR-37 | Publish langsung dan jadwal merupakan aksi eksplisit; kegagalan validasi mempertahankan nilai form. | M |

## 5. Publikasi dan status

| ID | Persyaratan | Prioritas |
|---|---|---|
| FR-40 | Simpan draft membuat target pending yang tidak dapat diproses scheduler. | M |
| FR-41 | Publish mengubah target API ke queued dan target manual ke manual_pending; konfigurasi koneksi menentukan mode per platform. | M |
| FR-42 | Scheduler hanya memproses queued target dari post yang scheduled/publishing dan jadwalnya sudah jatuh tempo. | M |
| FR-43 | Status ringkasan post diturunkan dari status target tanpa menimpa target platform lain. | M |
| FR-44 | Target failed dapat di-retry sesuai aturan keamanan/idempotensi existing; target manual dapat ditandai terbit memakai URL HTTPS. | M |
| FR-45 | Detail menampilkan status per platform, caption, media, permalink, alasan gagal, percobaan, snapshot insight, dan riwayat audit. | M |

## 6. Permission dan data lama

| ID | Persyaratan | Prioritas |
|---|---|---|
| FR-50 | Ganti `content.review` dengan `content.manage` untuk daftar lintas creator, insight dan pengelolaan seluruh konten; default admin dan super admin. | M |
| FR-51 | Creator dapat publish, retry, atau menandai terbit manual hanya untuk kontennya sendiri. Admin/super admin dapat menangani lintas creator. | M |
| FR-52 | Migrasi mengubah post `in_review` dan `rejected` menjadi `draft`; target tidak diubah ke queued; data reviewer/catatan/audit lama tetap disimpan. | M |
| FR-53 | Route approve/reject/submit/withdraw dan controller approval tidak lagi menjadi jalur aktif. | M |
| FR-54 | Koneksi sosial dan insight existing tetap dapat dibuka dengan permission yang sesuai. | M |

## 7. Aksesibilitas dan responsivitas

| ID | Persyaratan | Prioritas |
|---|---|---|
| FR-60 | Semua aksi memakai label teks atau nama aksesibel, ikon SVG ber-`aria-hidden`, focus-visible, serta warna status dengan teks eksplisit. | M |
| FR-61 | Tidak menambahkan dependency UI/kalender; gunakan Blade, Tailwind, dan CSS yang sudah tersedia. | M |
| FR-62 | Konten tidak menyebabkan viewport melebar pada lebar 375, 768, 1024, dan 1440 piksel. | M |
