# Kanban AI Task Composer

## Problem

Menambah kartu saat ini hanya menerima judul singkat. Pengguna perlu beralih ke Asisten AI terpisah untuk merumuskan tugas, lalu mengisi detail kartu secara manual.

## Goals

- Sediakan toggle **Manual / Agent AI** pada area tambah kartu di setiap kolom.
- Mode Agent AI menanyakan tugas dalam bahasa bebas dan memakai papan serta kolom aktif sebagai konteks.
- Agent mengusulkan judul, deskripsi, penanggung jawab, tenggat, dan prioritas sebagai draft yang bisa diedit.
- Kartu hanya disimpan setelah pengguna menekan **Buat kartu**.
- Mode manual tetap ringkas seperti saat ini.

## Design

Toggle berada di area tambah kartu setiap kolom. Mode manual mempertahankan input judul dan submit Enter. Mode Agent AI menampilkan prompt “Tugas apa yang mau diberikan?” dan tombol **Buat draft**. Hasilnya menjadi form draft yang dapat diedit; daftar penanggung jawab dibatasi ke pengguna internal aktif yang sudah dipakai oleh form detail Kanban.

Agent menerima nama papan, nama kolom, permintaan pengguna, dan daftar penanggung jawab yang diizinkan. Agent hanya mengembalikan data JSON untuk draft; endpoint tidak membuat kartu. Submit draft memakai endpoint simpan kartu Kanban yang sama dan validasi server, sehingga konfirmasi eksplisit tetap menjadi batas penulisan.

Provider AI yang sekarang dipakai aplikasi tetap digunakan, termasuk failover otomatis. Tidak ada pemilih model atau agent persona karena aplikasi belum menyediakan beberapa agent khusus.

## Failure behavior

- Validasi prompt ditampilkan di panel draft tanpa mengubah kartu.
- Error provider atau respons agent yang tidak dapat dibaca menampilkan pesan agar pengguna mencoba lagi atau beralih ke mode manual.
- Penanggung jawab hanya diterima jika ID termasuk pengguna internal aktif.

## Acceptance

- Toggle bekerja terpisah pada tiap kolom dan dapat dioperasikan dengan keyboard.
- Agent menerima konteks board/kolom dari server, bukan input tersembunyi yang dapat dipalsukan.
- Semua nilai draft dapat diedit sebelum disimpan.
- Pengiriman prompt saja tidak membuat atau mengubah kartu.
- Pembuatan manual yang sudah ada tetap berfungsi.
