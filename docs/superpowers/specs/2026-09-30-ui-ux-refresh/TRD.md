# TRD — Penyegaran UI/UX Portal SKINKU
**Technical Requirements Document** · v0.1 · 2026-09-30

Dokumen terkait: [PRD](PRD.md) · [FRD](FRD.md) · [Design System](../../../../DESIGN.md)

Referensi desain: Impeccable, Kokonut UI, 21st.dev, UI UX Pro Max, dan shadcn/ui. Gunakan sebagai panduan pola/inspeksi; implementasi tetap native pada stack portal.

## 1. Batas implementasi

- Stack tetap Laravel Blade + Tailwind CSS v4 melalui Vite.
- Gunakan token dan konvensi dari `DESIGN.md` serta kelas Tailwind yang sudah terpasang.
- Pertahankan controller, route, policy/permission, nama field, method form, dan alur AJAX.
- Tidak menambah paket ikon atau framework UI.
- Jangan menyalin komponen referensi yang mengharuskan React atau dependency baru; adaptasikan struktur dan perilakunya ke Blade + Tailwind v4.
- Untuk aksi tabel, gunakan pola shadcn/ui button variants secara visual: primary untuk aksi utama, outline untuk aksi biasa, destructive untuk hapus. Implementasikan dengan kelas Tailwind lokal dan inline SVG.
- Gunakan Impeccable/UI UX Pro Max untuk review hirarki, kontras, keadaan kontrol, responsivitas, serta kepadatan; gunakan Kokonut UI/21st.dev untuk pola tabel, badge, tombol, chat, dan navigasi.
- Pertahankan token merah, tipografi, spacing, dan aturan aksesibilitas yang ditetapkan di `DESIGN.md` meski pola referensi memakai gaya berbeda.

## 2. Pola teknis

### Ikon

- Gunakan SVG inline untuk ikon pada tombol yang terlihat; set `viewBox`, ukuran Tailwind yang konsisten, `fill="none"`, dan stroke `currentColor` bila sesuai.
- Tandai SVG dekoratif dengan `aria-hidden="true"` dan `focusable="false"`.
- Jangan mengganti label teks dengan ikon. Untuk tombol ikon saja, berikan `aria-label` dan tooltip bila pola setempat mendukung.
- Pakai ikon yang semantiknya cocok: refresh untuk sinkronisasi, check untuk setujui, x/ban untuk tolak atau batal, trash untuk hapus, star untuk tandai.
- Aksi yang sebelumnya berupa teks polos tetap berupa `<button>` semantik, diberi batas/latar/area sentuh, ikon SVG dekoratif, dan label teks.

### Tabel

- Pertahankan `<table>`, `<thead>`, `<tbody>`, dan heading kolom yang benar.
- Bungkus area tabel dengan `overflow-x-auto` dan tetapkan `min-width` hanya bila kolom memerlukannya.
- Sticky column harus ditempatkan dalam container scroll tabel, memakai latar opaque dan offset yang sesuai; matikan sticky pada breakpoint jika menyebabkan kolom menutupi isi.
- Aksi tabel membungkus dengan `flex-wrap` atau pola setempat agar tidak keluar dari viewport.

### Layout responsif

- Gunakan breakpoint Tailwind standar: `sm` 640px, `md` 768px, `lg` 1024px, `xl` 1280px.
- Pada mobile, kurangi padding luar, jaga ukuran teks, dan batasi overflow ke panel data atau thread yang memang perlu scroll.
- Composer chat tetap berada dalam layout flex kolom dengan area pesan `min-h-0` dan `overflow-y-auto`.
- Bubble AI harus memiliki posisi aman pada layar kecil, tombol dismiss terpisah, dan tidak menutupi composer atau tombol aksi utama.

## 3. Area kode awal

| Area | Lokasi utama |
|---|---|
| Token dan pola global | `resources/css/app.css`, `DESIGN.md` |
| Retur | `resources/views/retur/index.blade.php` |
| Retur TikTok/Shopee | `resources/views/tiktok/returns.blade.php`, `resources/views/shopee/returns.blade.php` |
| Chat E-commerce | `resources/views/ecom-chat/index.blade.php`, `_list.blade.php`, `_thread.blade.php` |
| Bubble AI | `resources/views/partials/ai-widget.blade.php` |
| Build asset | `public/build/` (di-commit sesuai konvensi repo) |

Daftar ini menjadi titik awal, bukan whitelist permanen. Saat satu halaman masuk cakupan, cari juga partial dan script yang merender UI halaman itu.

## 4. Alur perubahan

1. Catat halaman dan elemen yang masuk cakupan pada PR.
2. Reuse kelas, token, dan pola SVG lokal sebelum menambahkan helper.
3. Hilangkan emoji dari teks statis dan string JavaScript yang merender UI; ganti dengan teks, dan tambahkan SVG pada kontrol yang memang memerlukan ikon.
4. Perbaiki layout sesuai FRD tanpa mengubah endpoint atau payload.
5. Jalankan `npm run build` jika Blade/CSS berubah dan `git diff --check`.
6. Preview halaman lokal pada breakpoint FR-30 serta periksa alur kontrol yang disentuh.

## 5. Risiko teknis dan mitigasi

| Risiko | Mitigasi |
|---|---|
| Sticky kolom menutupi isi tabel | Uji lebar scroll dan mobile; gunakan offset tepat atau nonaktifkan sticky pada breakpoint kecil. |
| SVG tidak dapat dibaca teknologi bantu | SVG dekoratif disembunyikan; nama aksi berasal dari label atau `aria-label` tombol. |
| Kelas Tailwind dinamis hilang dari build | Gunakan kelas literal atau safelist `@source inline(...)` sesuai konvensi repo. |
| Perubahan markup merusak handler AJAX/form | Pertahankan atribut `data-*`, action, method, dan struktur target yang dikonsumsi JavaScript. |
| Bubble menutupi composer pada mobile | Uji viewport 375px; geser posisi dan pastikan dismiss mudah dijangkau. |
| Data demo memengaruhi saldo/stok atau terkirim ke integrasi | Seeder wajib guard `local`, memakai penanda demo, tidak memanggil API, dan hanya membuat jurnal seimbang tanpa mutasi stok. |

## 6. Kriteria selesai teknis

- Build Vite sukses dan perubahan tidak menyisakan error format diff.
- Tidak ada emoji pada Blade/JS UI yang termasuk halaman tersentuh.
- Tidak ada dependency UI baru.
- Perilaku route, permission, submit, AJAX, dan keyboard yang sudah ada tetap terjaga.
- Bukti preview mencakup layar sempit dan desktop untuk halaman yang diubah.
- `php artisan db:seed --class=Database\\Seeders\\SkinkuLocalDemoSeeder` berhasil pada DB lokal dan aman dijalankan ulang.
