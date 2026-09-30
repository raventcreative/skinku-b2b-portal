# PRD — Content Pipeline SKINKU

**Product Requirements Document** · v1.0 · 2026-09-30

Dokumen terkait: [BRD](BRD.md) · [FRD](FRD.md) · [Implementation Plan](../../plans/2026-09-30-content-pipeline.md)

## 1. Visi produk

Jadikan Konten sebagai ruang kerja publikasi yang langsung menghubungkan materi creator dengan kalender dan hasil terbit. Antarmuka mengambil pola operasional Pipeline SKINKU: navigasi ringkas, status yang terbaca, tindakan berikutnya jelas, aksen merah, tanpa emoji.

## 2. Pengguna dan pekerjaan utama

- **Creator:** menyiapkan media dan caption, memilih target platform, menyimpan draft, atau menerbitkan/menjadwalkan tanpa meminta approval.
- **Super admin/admin:** melihat pipeline semua creator, menemukan konten bermasalah, membantu menandai posting manual, retry publikasi, mengelola koneksi akun, serta melihat insight.

## 3. Pengalaman utama

Workspace memiliki dua tampilan yang saling terhubung:

1. **Pipeline:** daftar konten terkelompok berdasarkan status; ringkasan jumlah; pencarian; filter status, creator (admin), platform, dan tanggal; kartu/baris menunjukkan media, judul, pemilik, jadwal, target platform, dan tindakan.
2. **Kalender:** tampilan bulan dengan navigasi bulan sebelumnya/berikutnya; item membuka detail; item terjadwal menampilkan waktu dan status. Tombol buat konten tersedia pada kedua tampilan.

Formulir menyimpan draft atau menerbitkan. Jadwal kosong berarti antrekan segera; tanggal masa depan berarti antrekan terjadwal. Koneksi API menentukan publikasi otomatis. Target manual memiliki jalur unggah/salin caption dan pencatatan URL.

## 4. Status produk

- **Draft** — disimpan, belum dimasukkan ke antrean publish.
- **Terjadwal** — target menunggu jadwal atau giliran scheduler.
- **Sedang terbit** — minimal satu target sedang diproses sementara target lain belum selesai.
- **Terbit sebagian** — sebagian target terbit dan sebagian target gagal.
- **Selesai** — semua target selesai, termasuk target manual yang telah dicatat terbit.
- **Gagal / Perlu tindakan** — ada target gagal atau menunggu posting manual; daftar menampilkan platform dan tindakan yang diperlukan.

Status platform tetap menjadi sumber data utama untuk detail. Status kartu adalah ringkasan target dan jadwal.

## 5. Kebutuhan produk

- Menghapus langkah, halaman, label, dan permission khusus approval.
- Membuka daftar creator sendiri; super admin/admin dapat beralih ke semua creator.
- Menyediakan formulir upload media dan caption per platform dengan batas validasi existing.
- Meneruskan publikasi ke scheduler yang sudah ada tanpa menggandakan posting.
- Menyediakan kalender server-rendered yang responsif tanpa library kalender baru.
- Menjaga detail, permalink, retry, posting manual, insight, dan koneksi sosial yang sudah bekerja.
- Menampilkan ikon SVG inline yang bermakna, label yang jelas, fokus keyboard, dan tanpa emoji di komponen yang dibangun ulang.

## 6. Di luar cakupan

Approval/rejection, drag-and-drop lintas status, penjadwalan ulang dengan drag, social inbox, editing media, dan platform baru.

## 7. Kriteria penerimaan

1. Submit publish tidak membuat status `in_review` dan tidak membutuhkan aksi admin.
2. Draft tidak dapat dipublikasikan oleh scheduler.
3. Publish tanpa jadwal masuk antrean scheduler; publish dengan waktu masa depan menunggu waktu tersebut.
4. Tampilan kalender hanya menampilkan konten yang memiliki waktu jadwal pada bulan yang diminta.
5. Creator dibatasi ke konten miliknya; admin/super admin dapat melihat seluruh creator.
6. Target API dan manual ditangani sesuai konfigurasi platform/koneksi yang sudah ada.
7. Kegagalan dan retry tetap terpisah per target; tautan terbit tetap tersedia.
8. Status lama `in_review`/`rejected` menjadi draft melalui migrasi aman tanpa mengantrekan target.
9. Workspace dan formulir beradaptasi pada layar kecil serta memakai kontrol berlabel, fokus terlihat, dan ikon tanpa emoji.
