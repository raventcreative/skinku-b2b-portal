# FRD — Penyegaran UI/UX Portal SKINKU
**Functional Requirements Document** · v0.1 · 2026-09-30

Dokumen terkait: [PRD](PRD.md) · [TRD](TRD.md) · [Design System](../../../../DESIGN.md)

Referensi evaluasi dan pola: [Impeccable](https://impeccable.style/), [Kokonut UI](https://kokonutui.com/), [21st.dev](https://21st.dev/), UI UX Pro Max, dan [shadcn/ui](https://ui.shadcn.com/). Semua contoh wajib tetap memenuhi kebutuhan fungsional berikut dan token di `DESIGN.md`.

Prioritas: **M** = wajib pada halaman yang sedang diperbarui; **S** = diterapkan saat halaman lain masuk cakupan penyegaran.

## 1. Tabel dan data

| ID | Requirement | Prio |
|---|---|---|
| FR-01 | Tabel menggunakan elemen tabel semantik dengan header yang terbaca, jarak baris konsisten, garis pemisah ringan, dan hover baris yang halus. | M |
| FR-02 | Pada viewport sempit, tabel lebar dapat digeser horizontal tanpa membuat seluruh halaman ikut melebar. | M |
| FR-03 | Kolom identitas/status/aksi yang perlu tetap terlihat boleh memakai sticky positioning jika tidak menutupi kolom lain dan bekerja pada breakpoint kecil. | S |
| FR-04 | Data angka dan mata uang tetap mudah dipindai melalui perataan yang konsisten; status memiliki label teks yang eksplisit. | M |
| FR-05 | Aksi baris memiliki area sentuh memadai, label yang jelas, dan tata letak yang tidak saling menimpa. | M |

## 2. Ikon dan kontrol

| ID | Requirement | Prio |
|---|---|---|
| FR-10 | Emoji dihilangkan dari tombol, badge, tab, pesan status, empty state, atribusi pesan, dan kontrol dalam halaman yang diperbarui. | M |
| FR-11 | Tombol yang sebelumnya mengandalkan emoji memakai ikon SVG yang sesuai; label aksi tetap terlihat. | M |
| FR-12 | SVG dekoratif disembunyikan dari pembaca layar (`aria-hidden="true"`); tombol ikon tanpa teks memiliki `aria-label` atau nama aksesibel setara. | M |
| FR-13 | Ikon memakai `currentColor`, ukuran dan stroke konsisten, serta tidak menambah dependency. | M |
| FR-14 | Hover, focus-visible, disabled, dan kontras status tetap terbaca. | M |

## 3. Chat E-commerce dan asisten AI

| ID | Requirement | Prio |
|---|---|---|
| FR-20 | Tab TikTok/Shopee, badge balasan AI/staf, kartu pesanan/pengiriman, label produk, dan empty state menggunakan teks tanpa emoji. | M |
| FR-21 | Tombol tarik chat menampilkan ikon refresh dan label teks; aksi tarik dan keadaan loading tetap berjalan. | M |
| FR-22 | Aksi Tandai, Buat ulang draft AI, Buka lagi, dan Tutup chat ditampilkan sebagai tombol berlabel dengan ikon SVG yang sesuai; grup tombol membungkus rapi pada layar sempit. | M |
| FR-23 | Bubble AI tidak menutupi kontrol chat penting, memiliki tombol buka/tutup yang jelas, dan mendukung fokus keyboard. Di Chat E-commerce pada layar di bawah 1024px, bubble global disembunyikan agar composer tetap bisa dipakai. | M |
| FR-24 | Thread chat dan composer menyesuaikan viewport ponsel; pesan dapat digulir tanpa mendorong composer keluar dari panel. | M |

## 4. Responsivitas dan konsistensi

| ID | Requirement | Prio |
|---|---|---|
| FR-30 | Halaman yang diperbarui diperiksa pada lebar 375, 768, 1024, dan 1440 piksel. | M |
| FR-31 | Sidebar dan header mengikuti pola responsif yang sudah ada di layout portal; tidak ada kontrol penting yang hilang pada ponsel. | S |
| FR-32 | Penyegaran mempertahankan palet merah SKINKU, bahasa Indonesia, route, permission, serta perilaku submit yang ada. | M |
| FR-33 | Pola tabel, badge, tombol, fokus, dan breakpoint yang disepakati dicatat di `DESIGN.md` dan digunakan ulang pada halaman terkait. | S |
| FR-34 | Pola komponen dari Kokonut UI dan 21st.dev boleh menjadi acuan struktur; evaluasi Impeccable dan UI UX Pro Max dipakai untuk meninjau hierarki, konsistensi, aksesibilitas, serta tampilan responsif. Hasil akhir harus memakai Blade/Tailwind portal dan identitas merah SKINKU. | M |
| FR-35 | Aksi Edit, Hapus, Riwayat HPP, Lihat, dan Detail di tabel memakai hierarki tombol shadcn/ui-inspired: outline/secondary/destructive dengan ikon yang sesuai, label jelas, fokus terlihat, serta tata letak responsif. | M |

## 5. Data demo lokal

| ID | Requirement | Prio |
|---|---|---|
| FR-40 | Satu perintah seeder lokal menyiapkan contoh lintas modul utama: pengguna/produk/PO/retur, chat, konten creator, KOL, OKR/Kanban, Academy, mindmap, master marketplace, supplier/produksi, akuntansi, dan pengetahuan AI. | M |
| FR-41 | Semua record buatan diberi penanda `Demo Lokal` atau ID berawalan `DEMO`; seeder memakai kunci stabil agar dapat dijalankan berulang tanpa duplikasi. | M |
| FR-42 | Seeder hanya berjalan bila `APP_ENV=local`. Tidak menghapus data, tidak membuat kredensial/API connection, dan tidak mengirim request ke TikTok, Shopee, Meta, atau layanan eksternal. | M |
| FR-43 | Sebelum menambah sampel yang memengaruhi saldo atau stok, seeder memberi label demo yang terlihat dan menjaga jurnal seimbang; contoh produksi tidak memutasi stok produk. | M |

## 6. Kriteria verifikasi fungsional

- Pindai file Blade dalam cakupan untuk emoji setelah perubahan.
- Pastikan tombol ikon dapat diidentifikasi melalui label terlihat atau nama aksesibel.
- Periksa tabel horizontal, sticky action/status jika digunakan, dan scroll thread pada viewport target.
- Pastikan aksi sinkronisasi, tandai, balas, tutup/buka chat, retur, dan kontrol AI tidak berubah perilakunya.
- Jalankan seeder lokal dua kali dan pastikan jumlah data contoh tidak bertambah pada eksekusi kedua.
