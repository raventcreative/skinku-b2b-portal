# BRD — Portal Content Creator SKINKU
**Business Requirements Document** · v0.1 draft · 2026-09-25

Dokumen terkait: [PRD](PRD.md) · [FRD](FRD.md) · [TRD](TRD.md)

---

## 1. Latar Belakang

Konten sosial media SKINKU saat ini diproduksi creator internal/freelance lalu dikirim lewat
WhatsApp/Drive ke tim marketing, yang mem-posting manual ke Facebook, Instagram, TikTok, dan
Threads. Akibatnya:

- File dan caption tercecer, versi final sering tertukar.
- Tidak ada jejak siapa membuat apa, kapan disetujui, kapan terbit.
- Posting lintas 4 platform dikerjakan berulang secara manual.
- Tidak ada angka produktivitas creator (berapa konten terbit per minggu).

Portal B2B sudah menjadi pusat operasional SKINKU (PO, stok, KOL, marketplace). Menambahkan
modul content creator di portal yang sama menjaga satu sumber data dan satu login.

## 2. Tujuan Bisnis

| # | Tujuan | Ukuran keberhasilan |
|---|--------|---------------------|
| B1 | Satu tempat untuk menyetor konten | 100% konten brand masuk lewat portal dalam 1 bulan setelah rilis |
| B2 | Mempercepat konten sampai terbit | Lead time upload → terbit rata-rata < 24 jam (di luar jadwal yang disengaja) |
| B3 | Mengurangi kerja posting manual | ≥ 80% posting FB/IG/Threads terbit otomatis via API (Fase 1) |
| B4 | Menjaga kualitas & keamanan brand | 0 konten terbit tanpa lolos review (bila approval diaktifkan) |
| B5 | Visibilitas produktivitas creator | Laporan konten terbit per creator per minggu tersedia |

## 3. Stakeholder

| Stakeholder | Kepentingan |
|-------------|-------------|
| Content Creator (role baru `content_creator`) | Upload konten, caption, jadwal; lihat status & hasil |
| Admin / Tim Marketing | Review, setujui/tolak, kelola jadwal, hubungkan akun brand |
| Super Admin | Kelola role, permission, akun creator, kredensial developer app |
| Owner / Manajemen | KPI produktivitas & konsistensi posting |

## 4. Scope

### In scope
- Role baru `content_creator` dengan dashboard tersendiri (terpisah dari dashboard distributor/mitra).
- Upload konten (gambar, video, carousel) + caption per platform + pilih platform target + jadwal.
- Alur review/approval oleh admin (lihat Open Question OQ-1).
- Publikasi ke **akun brand SKINKU** (bukan akun pribadi creator):
  - **Fase 1:** Facebook Page, Instagram Business, Threads (Meta) — otomatis via API.
  - **Fase 2:** TikTok (Content Posting API) — setelah app TikTok lolos audit.
- Riwayat, status per platform, retry bila gagal, audit log.

### Out of scope (versi ini)
- Posting ke akun pribadi creator.
- Editor video/foto di dalam portal.
- Balas komentar / DM sosial media (inbox sosial).
- Iklan berbayar (Meta Ads / TikTok Ads).
- Pembayaran/fee creator (sudah ada modul KOL & Gapok untuk kebutuhan itu).
- Insight/metrik performa postingan → **Fase 3** (dicatat, belum dikerjakan).

## 5. Asumsi & Dependensi

- SKINKU memiliki: Facebook Page, akun Instagram **Business/Creator** yang terhubung ke Page, akun Threads, dan akun TikTok brand.
- SKINKU membuat **Meta Developer App** (dengan Business verification bila diminta) dan **TikTok Developer App**.
- Portal di-deploy dengan domain **HTTPS publik** (Hostinger) — Instagram & Threads mengambil media dari URL publik; publish tidak bisa diuji dari `localhost` tanpa tunnel.
- Cron `schedule:run` aktif di server (sudah dipakai untuk sync TikTok/Shopee & queue worker).

## 6. Risiko

| Risiko | Dampak | Mitigasi |
|--------|--------|----------|
| TikTok app belum lolos audit | Postingan via API hanya bisa **private** | TikTok dijadikan Fase 2; sementara mode "siap posting manual" (download + salin caption) |
| Review/perizinan Meta App tertunda | Fase 1 mundur | Mulai pengajuan app di hari pertama; uji dengan admin yang punya role di app |
| Token akses kedaluwarsa / dicabut | Posting gagal diam-diam | Refresh terjadwal + peringatan di dashboard admin bila koneksi bermasalah |
| Konten melanggar guideline brand/platform | Reputasi brand | Approval admin sebelum terbit; checklist review |
| File besar membebani hosting | Disk penuh, upload gagal | Batas ukuran per file, pembersihan media setelah N hari terbit |
| Rate limit platform (mis. IG 100 post/24 jam) | Posting tertolak | Validasi kuota sebelum publish, antrean terjadwal |

## 7. Open Questions (butuh keputusan bisnis)

| ID | Pertanyaan | Rekomendasi |
|----|------------|-------------|
| OQ-1 | Apakah konten wajib di-approve admin sebelum terbit? | **DIPUTUSKAN: Ya** (dipakai di implementasi Fase 1). Konten brand resmi → wajib review. Bisa diberi opsi "trusted creator" langsung publish kelak. |
| OQ-2 | Siapa yang boleh approve — admin saja atau role marketing khusus? | Permission `content.approve`, default admin & super_admin; bisa ditambah via matriks permission. |
| OQ-3 | Apakah creator boleh menentukan jadwal sendiri, atau admin yang menjadwalkan? | Creator mengusulkan jadwal; admin boleh mengubah saat approve. |
| OQ-4 | Berapa lama file media disimpan setelah terbit? | 90 hari, lalu file dihapus (metadata & link postingan tetap). |
| OQ-5 | Apakah creator internal ini sama dengan KOL di modul KOL? | Tidak — creator portal adalah user login; KOL tetap CRM eksternal. Bisa ditautkan di Fase 3. |
