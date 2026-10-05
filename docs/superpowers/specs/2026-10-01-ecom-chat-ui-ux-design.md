# Desain UI/UX Chat E-commerce

## Tujuan
Memudahkan staf membedakan Chat E-commerce dari chat umum, memindai kanal dan status percakapan, serta membaca dan membalas pesan lewat ponsel.

## Desain
- Pakai satu motif ikon percakapan marketplace yang konsisten pada navigasi samping dan tombol chat di header. Pertahankan lencana jumlah pesan belum dibaca.
- Gunakan merah marun SKINKU sebagai warna aksi dan keadaan aktif, dengan warna krem sebagai latar lembut.
- Pertahankan inbox dua kolom di desktop. Perjelas percakapan terpilih, identitas kanal TikTok/Shopee, dan status percakapan dengan label yang terbaca.
- Di layar ponsel, tampilkan inbox terlebih dahulu. Setelah percakapan dipilih, sembunyikan inbox dan tampilkan thread dengan tombol “Kembali ke inbox”. Pertahankan kolom balas di bagian bawah thread.
- Gunakan komponen Blade dan gaya Tailwind yang sudah ada. Tidak mengubah rute, sinkronisasi, status, pengiriman, atau aturan akses chat.

## Arsitektur dan alur
Perubahan terbatas pada layout navigasi dan view `resources/views/ecom-chat`. Ikon menggunakan SVG inline sesuai pola ikon yang sudah dipakai layout. Perilaku inbox/thread tetap menggunakan fetch yang ada; perubahan mobile hanya mengatur panel yang terlihat dan menyediakan aksi kembali.

## Kesalahan dan aksesibilitas
- Kegagalan memuat atau mengirim pesan tetap memakai penanganan yang sudah ada.
- Ikon dekoratif diberi `aria-hidden`; tombol navigasi memiliki nama aksesibel dan status percakapan aktif tetap terlihat tanpa mengandalkan warna saja.
- Tombol kembali hanya mengubah tampilan, tidak mengubah status atau mengirim data.

## Verifikasi
Kompilasi Blade dan build Vite. Pemeriksaan UI manual pada desktop dan lebar ponsel untuk ikon, penanda status, buka percakapan, kembali ke inbox, dan posisi kolom balas.
