# SKINKU — OKR berbasis AI
### Objective → Key Result → tugas individu → kartu Kanban

> Fitur ini menyusun OKR bulanan/kuartalan memakai provider AI aktif. AI hanya
> membuat draf; kartu Kanban baru dibuat setelah pratinjau diperiksa dan manusia
> menekan tombol persetujuan.

---

## 1. KEPUTUSAN PRODUK

- Periode mendukung **bulanan** dan **kuartalan**.
- Cakupan mendukung **perusahaan**, **tim/divisi**, dan **individu**.
- Struktur: `OkrCycle → OkrObjective → OkrKeyResult → OkrTask`.
- Generator memakai panel kolaboratif:
  - **CMO AI**: marketing, brand, konten, KOL/affiliate, live commerce, dan penjualan;
  - **CFO AI**: cashflow, margin, biaya, piutang, settlement, dan akuntansi;
  - **COO AI**: stok, produksi, PO, pengiriman, kapasitas, dan SOP.
  - **AI Orchestrator** menyelaraskan ketiga usulan, menghapus duplikasi/konflik,
    lalu membagi pekerjaan ke user nyata.
- CMO/CFO/COO adalah **spesialis AI**, bukan role user baru. Nama BOD/PIC dan
  aturan delegasi tetap berasal dari Pengetahuan AI.
- Satu Key Result boleh dipecah menjadi beberapa tugas spesifik untuk orang yang berbeda.
- AI membaca:
  - seluruh isi **Pengetahuan AI**;
  - anggota internal aktif;
  - papan dan kolom Kanban aktif;
  - arahan awal, periode, cakupan, dan papan pilihan user (opsional).
- AI memilih penerima dan kolom awal dari ID nyata. ID keluaran model tetap
  dinormalisasi server dan terlihat di pratinjau.
- Pratinjau dapat mengubah nama, uraian, owner, metrik, target, penerima,
  kolom Kanban, dan tenggat.
- **Tidak ada kartu sebelum persetujuan.** Tombol persetujuan membuat seluruh
  kartu secara atomik: berhasil semua atau batal semua.
- Progres tidak diisi manual. Sumber tunggal progres adalah `completed_at` kartu
  Kanban: masuk kolom bernama Done/Selesai = selesai; keluar = dibuka kembali.

---

## 2. ALUR PENGGUNA

1. Buka **OKR → Susun OKR dengan AI**.
2. Pilih periode, cakupan, dan tulis arahan bisnis.
3. Opsional: pilih papan Kanban utama. Tanpa pilihan, AI memilih dari konteks.
4. CMO AI, CFO AI, dan COO AI membaca snapshot bidangnya lalu masing-masing
   memanggil structured tool `usulkan_okr_spesialis`.
5. AI Orchestrator menerima ketiga usulan + konteks bersama, memanggil
   `susun_draf_okr`, dan menyimpan hasil sebagai draf.
6. User memeriksa serta mengedit spesialis, Objective, Key Result, dan setiap tugas.
7. User menyimpan koreksi pratinjau.
8. User menekan **Ya, Setujui & Buat Semua Kartu**.
9. Server memvalidasi ulang seluruh penerima/kolom/tanggal, membuat kartu
   `created_via=ai`, menghubungkan kartu ke tugas OKR, lalu mengaktifkan OKR.
10. Halaman OKR menghitung progres Objective/KR dari kartu yang selesai.

---

## 3. DATA & MIGRASI

Migrasi `2026_01_01_000063_create_okr_tables.php`:

- `okr_cycles`: periode, cakupan, arahan, status draf/aktif, pembuat dan penyetuju.
- `okr_objectives`: label spesialis `cmo/cfo/coo`, hasil utama, dan owner.
- `okr_key_results`: metrik, target, owner, tenggat.
- `okr_tasks`: pekerjaan spesifik, penerima, kolom tujuan, tenggat, dan relasi
  satu-ke-satu ke `board_cards`.

Jika kartu dihapus secara soft-delete, tugas OKR tetap tersimpan tetapi progresnya
tidak dianggap selesai dan UI menandai kartu tidak tersedia.

---

## 4. AI & GUARDRAIL

- Tetap provider-agnostic lewat `AiProvider`.
- Tanpa SDK/paket baru; mengikuti arsitektur AI zero-dependency yang sudah ada.
- Satu generate memakai **4 giliran AI**: CMO, CFO, COO, lalu Orchestrator.
- `OkrBusinessSnapshotService` memberi data aktual read-only:
  - CMO: penjualan/channel, produk, KOL/deal, status TikTok;
  - CFO: laba-rugi, neraca, arus kas, margin, piutang tempo, settlement TikTok;
  - COO: stok HQ/partner, produksi, status PO, dan operasional TikTok.
- Tiap sumber data disaring dengan izin user (`view_reports`, `view_accounting`,
  `kol.view`, `manage_tiktok`, `manage_hq_stock`, dan izin terkait). Panel tidak
  mendapat akses data tambahan hanya karena memakai AI.
- Provider baru di-resolve hanya saat tombol generate ditekan. Halaman daftar,
  detail, dan progres tetap bisa dibuka jika key/provider sedang bermasalah.
- Structured tool hanya menghasilkan data draf; tool tersebut tidak punya jalur
  untuk menulis kartu.
- Maksimum server: 6 Objective, 30 Key Result, dan 60 tugas per generasi.
- Anggota partner tidak boleh menjadi penerima.
- Tugas tidak boleh dimulai di kolom Done/Selesai.
- Tenggat wajib berada di dalam periode OKR.
- Klik persetujuan berulang ditolak dan tidak menduplikasi kartu.
- Generate, edit draf, approve, dan hapus draf masuk Audit Log.

---

## 5. PENGETAHUAN AI

Bagian baru **Strategi & aturan OKR** ditambahkan ke `AiKnowledge::SECTIONS`.
Gunakan untuk menyimpan arah tahunan, baseline angka, kapasitas tim, batasan,
dan aturan pembagian target. Bagian Tim, Workflow Kanban, dan Fokus/Target yang
sudah ada juga otomatis ikut dibaca. Struktur BOD (mis. Freddie/CMO,
Billy/CFO, Devrina/COO) dan delegasi ke tim cukup ditulis di sini; tidak ada
pengaturan mapping jabatan terpisah.

---

## 6. IZIN & ROUTE

- `okr.view`: melihat daftar/detail/progres OKR. Default untuk tim internal.
- `okr.manage`: generate, edit draf, approve, dan hapus draf. Default hanya
  super admin karena super admin selalu memiliki semua izin.
- Semua route diblokir middleware `internal` agar role mitra tetap tidak bisa masuk.
- Route utama: `okr.index`, `okr.create`, `okr.generate`, `okr.show`,
  `okr.update`, `okr.approve`, `okr.destroy`.

---

## 7. UJI

`tests/Feature/OkrTest.php` memakai `FakeAiProvider` sehingga tidak menyentuh API:

- gate izin + render halaman;
- AI membaca pengetahuan, anggota, papan, dan kolom;
- empat giliran panel berjalan dalam urutan CMO → CFO → COO → Orchestrator;
- snapshot berisi angka sistem aktual dan menutup bagian tanpa izin;
- generate menghasilkan draf tanpa kartu;
- edit pratinjau;
- approve menghasilkan kartu berlabel AI;
- double approval tidak menduplikasi kartu;
- progres 0% → 100% → 0% mengikuti perpindahan kartu;
- ID palsu dari AI dinormalisasi defensif.

Baseline setelah implementasi panel spesialis: **505 test lulus, 2224 assertions**.

---

## 8. DEPLOY

```bash
git pull
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

Setelah deploy, isi/periksa **Pengetahuan AI → Strategi & aturan OKR**, lalu uji
satu draf kecil sebelum membuat OKR tim yang besar.
