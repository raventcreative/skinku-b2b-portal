# KOL Deal Enhancements (Design Spec)

**Tanggal:** 2026-08-01 · **Status:** disetujui, siap implementasi.

## Tujuan
Percepat alur kerja KOL: buat deal cepat dari tabel kurasi, otorisasi cepat di daftar deal (indikator + Acc/Tolak massal), dan laporan hasil endorse dengan verdict yang menyesuaikan tujuan. Zero-dependency (Blade + native `<dialog>` + Eloquent). Aksi deal gated izin `kol.deal.manage`.

## Bagian A — Modal deal cepat dari tabel Database KOL
- `resources/views/kols/index.blade.php`: kolom **Penilaian** (rata-rata & median) jadi bisa diklik → buka **satu** modal `<dialog id="dealModal">` (zero-dep). JS isi `kol_id`, nama, ratecard dari `data-*` baris.
- Modal = form ringkas POST ke `kol-deals.store` (yang sudah ada): `kol_id` (hidden), `jenis`, `jumlah_slot`, `ratecard_deal` (prefill), `periode_mulai/selesai`, `pic_user_id`. Submit biasa (redirect balik) — tak perlu AJAX.
- Hanya muncul bila `kol.deal.manage`.

## Bagian B — Indikator KOL di daftar deal
- `resources/views/kol_deals/index.blade.php`: di samping username KOL, badge dari **screening terakhir**: **Rank** (#), **CPV median**, **Verdict median** (Worth It/Kemahalan/…), **Level** (Makro/Middle/Mikro dari followers).
- `KolDealController::index` eager-load `kol.latestScreening` (relasi yang sudah dipakai di daftar KOL). Level = `$kol->level` (accessor turunan followers). Tak ada query N+1.

## Bagian C — Status: badge warna + filter + Acc/Tolak/Selesai cepat & massal
- **Badge warna** ganti teks status polos: draft=abu, berjalan=biru, **selesai=hijau**, **batal=merah**.
- **Filter status** (dropdown Semua/draft/berjalan/selesai/batal) di atas daftar → `?status=`.
- **Checkbox per baris** + **bar aksi massal** (muncul saat ada yang dicentang): **Acc**, **Tolak**, **Selesai**. Plus **tombol cepat per baris** (Acc/Tolak/Selesai) untuk 1 deal.
- Endpoint baru `KolDealController::bulkStatus` (POST `/kol-deals/bulk-status`, gated `kol.deal.manage`): `ids[]` + `status` (Rule::in STATUSES). Set langsung status terpilih; audit-log; redirect balik dengan ringkasan "N deal → status". Mapping tombol: Acc→`berjalan`, Tolak→`batal`, Selesai→`selesai`. (Set langsung, tanpa aturan transisi rumit — 4 status datar.)
- Route di grup yang sama dengan kol-deals lain, **sebelum** route `{kolDeal}` bila perlu.

## Bagian D — Laporan Hasil Endorse (Evaluasi Kinerja)
Ikuti sheet "KOL Overview". Satu laporan per deal (1:1) → **kolom di `kol_deals`**, verdict/CPM/ROMI **dihitung (accessor)**, bukan disimpan.

**Migrasi `2026_01_01_000072_add_hasil_to_kol_deals`** — tambah nullable:
`hasil_tujuan` (string: 'penjualan'|'awareness'), `hasil_video_upload` (int), `hasil_video_fyp` (int), `hasil_views` (bigint), `hasil_revenue` (bigint), `hasil_catatan` (text), `hasil_diisi_at` (timestamp).

**Model `KolDeal`** — tambah ke `$fillable` (kecuali `hasil_diisi_at`), cast int/bigint, + accessor:
- `getHasilCpmAttribute()`: `hasil_views>0 ? round(total_biaya / hasil_views * 1000) : null`.
- `getHasilRomiAttribute()`: `total_biaya>0 && hasil_revenue!==null ? round(hasil_revenue / total_biaya, 2) : null`.
- `getHasilAvgViewsAttribute()`: `hasil_video_upload>0 ? round(hasil_views / hasil_video_upload) : null`.
- `getHasilVerdictAttribute()`: konstanta `VERDICT_BAGUS/CUKUP/JELEK/BELUM`.
  - `hasil_tujuan==='penjualan'` → dari ROMI: `>=2` Bagus · `>=1` Cukup · `<1` Jelek · (romi null → Belum).
  - `hasil_tujuan==='awareness'` → dari CPM pakai `config('kol.median_worth')` (60rb) & `config('kol.median_masih_oke')` (120rb): `<worth` Bagus · `<masih` Cukup · else Jelek · (cpm null → Belum).
  - tujuan/ data kosong → Belum.

**Form**: bagian "Laporan Hasil" di `kol_deals/form.blade.php` (dan/atau tampil di daftar via modal detail). Input tujuan + 4 angka + catatan. `KolDealController::update` (yang sudah ada) terima field `hasil_*`, set `hasil_diisi_at=now()` bila ada isian. Tampilkan CPM, ROMI, rata-rata views, dan **verdict** (badge).
- Gated `kol.deal.manage`.

## Testing (feature, SQLite)
`tests/Feature/KolDealEnhancementTest.php`:
1. **bulkStatus**: 3 deal draft → bulkStatus ids +'berjalan' → semua berjalan; +'batal' → batal. Non-`kol.deal.manage` → 403.
2. **filter**: `?status=selesai` hanya tampilkan yang selesai.
3. **verdict penjualan**: deal biaya 1jt, hasil_revenue 3jt, tujuan penjualan → ROMI 3 → Bagus. revenue 500rb → Jelek.
4. **verdict awareness**: biaya 1jt, views 50rb → CPM 20rb (<60rb) tujuan awareness → Bagus. views 5rb → CPM 200rb → Jelek. revenue 0 tetap dapat verdict (jawaban "tanpa revenue").
5. **modal render**: `kols.index` memuat `id="dealModal"` untuk user `kol.deal.manage`.

## Di luar cakupan (YAGNI)
- Multi-laporan per deal / per-video (agregat cukup).
- Grafik tren bulanan (sheet Overview) — nanti.
- Aturan transisi status ketat.
