# PRD — Penyegaran UI/UX Portal SKINKU
**Product Requirements Document** · v0.1 · 2026-09-30

Dokumen terkait: [FRD](FRD.md) · [TRD](TRD.md) · [Design System](../../../../DESIGN.md)

Referensi desain: **Impeccable**, **Kokonut UI**, **21st.dev**, **UI UX Pro Max**, dan **shadcn/ui**. Gunakan sebagai sumber pola dan evaluasi kualitas; sesuaikan hasilnya dengan identitas SKINKU serta stack portal.

## 1. Ringkasan

Rapikan antarmuka portal SKINKU agar data mudah dibaca dan tindakan mudah ditemukan di desktop maupun ponsel. Pertahankan identitas merah SKINKU dan perilaku bisnis yang ada. Hapus emoji dari antarmuka; gunakan ikon SVG yang konsisten untuk kontrol yang membutuhkan ikon, dengan label teks tetap jelas.

## 2. Masalah

- Tabel padat dan susah dipindai, terutama saat banyak kolom atau dibuka di layar sempit.
- Beberapa kontrol memakai emoji sebagai ikon sehingga gaya dan keterbacaannya tidak konsisten.
- Layout tertentu memakai ruang layar secara kurang efektif dan perlu menyesuaikan lebar perangkat.
- Chat E-commerce dan bubble asisten AI perlu tetap mudah dipakai tanpa menghalangi percakapan.

## 3. Tujuan dan ukuran keberhasilan

- Pengguna dapat membaca tabel, mengenali status, dan menemukan aksi utama tanpa menebak arti ikon.
- Semua halaman dan komponen yang disentuh dapat digunakan pada lebar 375, 768, 1024, dan 1440 piksel.
- Tidak ada emoji di teks, badge, tombol, status, empty state, atau komponen chat yang termasuk cakupan.
- Tombol berikon tetap memiliki label terlihat atau nama aksesibel yang menjelaskan aksinya.
- Alur, route, permission, dan hasil bisnis yang ada tetap bekerja.

## 4. Pengguna

- Super admin dan staf yang mengelola data operasional melalui tabel.
- Pengelola toko yang membaca dan membalas chat E-commerce.
- Pengguna portal yang membuka asisten AI dari halaman kerja.
- Pengguna ponsel yang perlu menyelesaikan tugas tanpa layout desktop.

## 5. Ruang lingkup

- Penyegaran presentasi tabel, badge status, tombol, kontrol chat, bubble asisten AI, serta layout responsif pada halaman yang dipilih.
- Pertahankan palet merah SKINKU dan bahasa antarmuka Indonesia.
- Ganti emoji pada kontrol dengan ikon SVG inline yang sudah sesuai pola repo; teks label tetap dipertahankan.
- Perbaikan dilakukan bertahap pada halaman operasional yang diprioritaskan, dimulai dari Retur dan Chat E-commerce.
- Sediakan satu seeder demo lokal idempotent yang membuat data pratinjau lintas modul utama SKINKU tanpa menimpa data pengguna.

## 6. Di luar ruang lingkup

- Perubahan proses approval, aturan retur, sinkronisasi marketplace, logika balasan AI, route, atau permission.
- Penggantian Tailwind/Blade atau penambahan pustaka ikon baru.
- Perubahan brand selain penyelarasan visual yang diperlukan untuk konsistensi.
- Menghubungkan API marketplace atau mengirim data demo ke layanan eksternal.

## 7. Prinsip pengalaman

- Merah tetap menjadi aksen identitas dan aksi utama; warna status tetap memakai makna yang sudah ada.
- Tabel mempertahankan struktur tabel semantik dan dapat digeser horizontal ketika ruang tidak cukup.
- Status memakai teks; warna bukan satu-satunya pembeda.
- Aksi penting menampilkan kata kerja yang jelas. Ikon bersifat pendamping.
- Gunakan Impeccable dan UI UX Pro Max untuk mengevaluasi kualitas visual, konsistensi, hierarki, responsivitas, dan aksesibilitas; gunakan Kokonut UI dan 21st.dev untuk mencari pola komponen yang relevan.
- Referensi tersebut bukan alasan untuk menyalin tampilan mentah atau menambah dependency: adaptasikan pola ke Blade, Tailwind yang sudah terpasang, dan merah SKINKU.
- Pada ponsel, konten tidak terpotong di luar viewport; area data yang lebar boleh memiliki scroll horizontal sendiri.
- Fokus keyboard, nama aksesibel, dan keadaan disabled tetap terlihat.

## 8. Kriteria penerimaan produk

1. Tidak ditemukan emoji pada elemen UI dalam halaman yang sudah diperbarui.
2. Ikon pengganti memiliki arti yang sesuai dan tidak menggantikan label aksi.
3. Tabel tetap dapat dipahami pada layar sempit melalui scroll yang terlokalisasi.
4. Form dan tombol tetap menjalankan aksi yang sama seperti sebelum penyegaran.
5. Chat E-commerce dan bubble AI tetap dapat dibuka, ditutup, dibaca, serta dioperasikan dengan keyboard dan sentuhan.
6. Warna merah SKINKU tetap terlihat sebagai warna aksen utama.
7. Seeder demo lokal dapat dijalankan ulang tanpa menggandakan contoh atau menyentuh data production.
