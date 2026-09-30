# BRD — Content Pipeline SKINKU

**Business Requirements Document** · v1.0 · 2026-09-30

## Ringkasan bisnis

SKINKU memerlukan satu ruang kerja untuk menyiapkan dan menerbitkan konten ke akun sosial brand. Alur approval membuat unggahan creator berhenti pada antrean admin, padahal proses operasional yang diminta adalah unggah, atur jadwal, lalu pantau hasil publikasi. Modul baru menghilangkan approval dan mengikuti pola kerja Pipeline SKINKU: status terlihat jelas, pekerjaan yang perlu ditindaklanjuti mudah ditemukan, dan jadwal tersedia di kalender.

## Tujuan bisnis

1. Mengurangi langkah antara materi siap dan konten masuk antrean terbit.
2. Memberi creator kendali untuk mengunggah, menjadwalkan, dan memantau kontennya sendiri.
3. Memberi super admin/admin visibilitas lintas creator serta penanganan kegagalan dan posting manual.
4. Menjaga publikasi per platform terpisah agar kegagalan satu kanal tidak menghambat kanal lain.

## Pemangku kepentingan

| Peran | Kebutuhan |
|---|---|
| Creator | Menyiapkan media/caption, memilih platform, menerbitkan sekarang atau menjadwalkan, memantau status sendiri. |
| Super admin/admin | Melihat seluruh konten, membantu menangani target gagal/manual, menghubungkan akun brand, membaca insight. |
| Sistem publikasi | Memproses target yang jatuh tempo, retry sesuai kebijakan, mencatat hasil dan tautan publikasi. |

## Kondisi sekarang

- Konten baru disimpan sebagai draft lalu diajukan untuk persetujuan.
- Admin meninjau konten dan memilih Setujui atau Tolak.
- Hanya setelah disetujui target platform masuk antrean publikasi.
- Jadwal dan status tersebar di dashboard, daftar, detail, dan formulir approval.

## Kondisi yang dituju

- Creator memilih **Simpan draft** atau **Terbitkan/jadwalkan** dari formulir.
- Tanpa jadwal, target API masuk antrean untuk diproses scheduler berikutnya.
- Dengan jadwal di masa depan, target masuk antrean dan scheduler menunggu waktu tersebut.
- Target TikTok yang tidak bisa diterbitkan melalui API ditampilkan sebagai posting manual; creator/admin menempelkan tautan setelah posting.
- Daftar pipeline memperlihatkan Draft, Terjadwal, Sedang terbit, Terbit sebagian, Selesai, dan Perlu tindakan. Kalender menampilkan konten terjadwal berdasarkan tanggal.
- Konten yang gagal dapat di-retry; status serta riwayat tiap platform tetap tercatat.

## Aturan bisnis

1. Tidak ada approval, rejection, atau antrean reviewer pada alur baru.
2. Creator hanya dapat melihat dan mengubah konten miliknya; admin/super admin dapat melihat lintas creator.
3. Konten harus lolos validasi media, caption, platform, dan jadwal sebelum diterbitkan.
4. Publikasi otomatis bergantung pada koneksi akun dan dukungan API yang tersedia.
5. Posting manual tetap memerlukan tautan publikasi untuk menandai target selesai.
6. Target platform memiliki status dan retry sendiri-sendiri.
7. Konten lama berstatus menunggu/ditolak approval dikembalikan ke Draft saat migrasi; perubahan tidak memicu publikasi otomatis.
8. Riwayat dan catatan audit lama dipertahankan untuk keterlacakan.

## Cakupan

**Termasuk:** satu workspace Pipeline Konten, kalender bulanan, buat/edit draft, upload foto/video/carousel, caption per platform, jadwal, penerbitan langsung/terjadwal, status per platform, retry, posting manual, detail, insight yang sudah ada, akun sosial yang sudah terhubung, permission creator/admin.

**Tidak termasuk:** approval/rejection konten, kalender drag-and-drop, penjadwalan ulang massal, editor video, integrasi platform baru, perubahan API publishing yang tidak didukung koneksi saat ini.

## Ukuran keberhasilan

- Creator dapat membuat draft atau mengirim konten ke antrean/jadwal dari satu formulir tanpa langkah persetujuan.
- Jadwal konten dapat ditemukan pada daftar pipeline dan kalender.
- Creator tidak dapat membaca atau mengubah konten creator lain.
- Kegagalan satu platform tidak mengubah hasil target platform lain.
- Konten menunggu approval yang lama tidak diterbitkan otomatis setelah rilis.

## Risiko dan penanganan

| Risiko | Penanganan |
|---|---|
| Creator mengirim konten yang belum siap | Validasi server tetap wajib; jadwal/publish adalah aksi eksplisit; audit log mencatat pelaku dan transisi. |
| Post lama terbit tiba-tiba saat deploy | Migrasi status `in_review`/`rejected` ke `draft`; targetnya tidak masuk antrean. |
| Akun API putus atau menolak media | Status gagal per target, alasan aman ditampilkan, retry manual tetap tersedia. |
| TikTok API memerlukan opsi akun/consent | Ambil Creator Info saat menyiapkan konten dan wajibkan pilihan yang relevan sebelum publish API. |
| Target manual terlupakan | Lane Perlu tindakan dan badge platform manual menunjukkan langkah berikutnya. |
