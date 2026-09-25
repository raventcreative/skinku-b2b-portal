# PRD — Portal Content Creator SKINKU
**Product Requirements Document** · v0.1 draft · 2026-09-25

Dokumen terkait: [BRD](BRD.md) · [FRD](FRD.md) · [TRD](TRD.md)

---

## 1. Ringkasan

Role baru **Content Creator** mendapat dashboard sendiri untuk menyetor konten (foto, video,
carousel) beserta caption dan jadwal. Admin me-review, lalu portal mempublikasikan otomatis ke
akun brand SKINKU di **Facebook Page, Instagram, Threads** (Fase 1) dan **TikTok** (Fase 2).

## 2. Persona

**Rina — Content Creator (22–30 th)**
Membuat 5–15 konten/minggu dari HP & laptop. Butuh: upload cepat, tahu status konten, tahu kenapa
ditolak, lihat link postingan yang sudah terbit. Tidak butuh: angka PO, stok, harga mitra.

**Dimas — Admin Marketing**
Mengatur kalender konten brand. Butuh: antrean review yang jelas, bisa edit caption/jadwal sebelum
approve, tahu posting mana yang gagal dan kenapa, satu klik retry.

**Super Admin**
Membuat akun creator, mengatur permission, menghubungkan akun sosial media brand.

## 3. Perbedaan dengan Dashboard Distributor

| Aspek | Dashboard Distributor | Dashboard Content Creator |
|-------|----------------------|---------------------------|
| Fokus | PO, stok, omzet, downline | Konten, status review, jadwal, hasil terbit |
| Kartu ringkasan | Total belanja, stok, PO aktif | Draft, Menunggu review, Terjadwal, Terbit bulan ini, Ditolak |
| Aksi utama | Buat PO | **+ Konten Baru** |
| Sidebar | PO, Inventory, Komisi, dll. | Dashboard Creator, Konten Saya, Kalender, Ganti Password |
| Data | Milik mitra & downline | Hanya konten milik creator sendiri |

Saat ini role non-staff/non-mitra jatuh ke tampilan "limited" di `DashboardController`. Content
creator akan diarahkan ke dashboard khususnya.

## 4. User Stories

### Content Creator
- US-C1 Sebagai creator, saya bisa login dan langsung melihat dashboard creator (bukan dashboard mitra).
- US-C2 Saya bisa membuat konten baru: upload 1 video, 1 foto, atau carousel (beberapa foto).
- US-C3 Saya bisa menulis caption utama dan (opsional) caption khusus per platform.
- US-C4 Saya bisa memilih platform target (FB, IG, Threads, TikTok) — sistem memberi tahu bila media tidak cocok dengan platform tertentu (mis. carousel video tidak didukung).
- US-C5 Saya bisa mengusulkan tanggal & jam terbit, atau "secepatnya setelah disetujui".
- US-C6 Saya bisa menyimpan sebagai draft lalu mengajukan review.
- US-C7 Saya melihat status konten per platform (menunggu review, disetujui, terjadwal, terbit + link, gagal, ditolak + alasan).
- US-C8 Konten yang ditolak bisa saya revisi dan ajukan ulang.
- US-C9 Saya hanya bisa melihat & mengubah konten milik sendiri; konten yang sudah disetujui tidak bisa saya ubah.

### Admin
- US-A1 Saya melihat antrean konten "Menunggu review" dari semua creator.
- US-A2 Saya bisa preview media + caption per platform, mengedit caption/jadwal, lalu Setujui atau Tolak (alasan wajib).
- US-A3 Saya melihat kalender konten terjadwal & terbit.
- US-A4 Saya melihat postingan gagal beserta pesan error dan bisa retry.
- US-A5 Saya bisa menghubungkan/memutus akun brand (FB Page, IG, Threads, TikTok) dan melihat status token.
- US-A6 Saya mendapat peringatan bila koneksi akun hampir kedaluwarsa atau error.

### Super Admin
- US-S1 Saya bisa membuat user dengan role `content_creator` dari menu Kelola Anggota yang sudah ada.
- US-S2 Saya bisa mengatur permission content di matriks permission yang sudah ada.

## 5. Fitur per Fase

### Fase 1 — MVP + Meta (target rilis pertama)
1. Role `content_creator` + akun demo.
2. Dashboard creator (kartu ringkasan + daftar konten terbaru).
3. CRUD konten + upload media + caption per platform + jadwal.
4. Workflow review/approval (OQ-1).
5. Koneksi akun brand Meta (FB Page, IG Business, Threads) oleh admin.
6. Publish otomatis terjadwal ke FB/IG/Threads + retry + notifikasi status.
7. **TikTok mode manual**: bila TikTok dipilih, konten tampil di daftar "Siap posting manual" (download media + salin caption); admin menempelkan link postingan setelah terbit.
8. Audit log untuk semua aksi (buat, ajukan, setujui, tolak, publish, gagal).

### Fase 2 — TikTok API
1. Koneksi akun TikTok brand (OAuth Content Posting API).
2. Publish video/foto ke TikTok (setelah app lolos audit; sebelum audit → private).
3. Mode manual tetap tersedia sebagai cadangan.

### Fase 3 — Insight (belum dijadwalkan)
- Tarik metrik (views, likes, comments, shares, reach) per postingan.
- Laporan produktivitas & performa per creator.
- Tautan opsional creator ↔ data KOL.

## 6. Alur Utama

```
Creator: Konten Baru → upload media → caption → pilih platform → usul jadwal
       → [Simpan Draft] atau [Ajukan Review]
Admin:   Antrean Review → preview → (edit caption/jadwal) → Setujui | Tolak(alasan)
Sistem:  Disetujui → Terjadwal → pada jam terbit: publish per platform
       → Terbit (simpan link)  |  Gagal (simpan error, retry otomatis 3x → notifikasi admin)
Creator: melihat status & link di dashboard
```

## 7. Acceptance Criteria (tingkat produk)

- AC-1 User `content_creator` yang login diarahkan ke dashboard creator; tidak bisa membuka menu PO, stok, harga, KOL, keuangan (403).
- AC-2 Creator hanya melihat konten miliknya.
- AC-3 Konten tidak pernah terbit tanpa status Disetujui (bila OQ-1 = Ya).
- AC-4 Satu konten dengan 3 platform target menghasilkan 3 status terpisah; gagal di satu platform tidak membatalkan platform lain.
- AC-5 Postingan yang terbit menyimpan ID eksternal + permalink yang bisa diklik.
- AC-6 Media yang tidak memenuhi syarat platform ditolak di form sebelum diajukan, dengan pesan yang jelas.
- AC-7 Token akun brand tidak pernah tampil di UI, log, maupun respons.

## 8. Non-Goals
Lihat BRD §4 Out of scope.

## 9. Open Questions
Lihat BRD §7 (OQ-1 s.d. OQ-5). Tambahan produk:

| ID | Pertanyaan | Rekomendasi |
|----|------------|-------------|
| OQ-6 | Apakah admin juga boleh membuat konten langsung (tanpa creator)? | Ya, admin memiliki permission create + approve sekaligus. |
| OQ-7 | Batas jumlah konten per creator per hari? | Tidak dibatasi di v1; kuota platform dijaga di sisi publish. |
