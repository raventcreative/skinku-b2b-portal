# Mindmaps Fase 2 — Integrasi Asisten AI (Design Spec)

**Tanggal:** 2026-08-01
**Status:** disetujui (scope + aturan akses), siap implementasi
**Prasyarat:** Mindmaps Fase 1 sudah live (lihat `2026-07-31-mindmaps-design.md`).

## Tujuan
Asisten AI internal SKINKU bisa **membaca**, **membuat**, dan **menambah ke** papan Mindmaps — dengan akses **ketat & role-based** (prinsip sama seperti OKR: tertutup untuk mitra, per-izin), malah lebih ketat karena **per-papan**.

## Arsitektur
Ikuti pola alat AI yang sudah ada persis: kelas extend `App\Services\Ai\Tools\BaseTool`, didaftarkan di `AppServiceProvider` → `ToolRegistry`. `ToolRegistry::forUser($user)` menyaring per `permission()`. Alat tulis pakai gerbang `isWrite()`+`validate()`+`previewText()` (preview → user setuju → `run`). Zero-dependency (murni Eloquent + model Mindmap yang ada). Tidak ada paket/route/migrasi baru.

## Aturan akses (WAJIB)
- **Ketersediaan alat**: ketiga alat `permission() = 'mindmap.view'`. `ToolRegistry::forUser` otomatis menyembunyikannya dari user tanpa izin. Default `mindmap.view` = admin, gudang, super_admin. **Mitra (distributor/reseller) tidak dapat** — alat tak muncul, AI tak bisa baca/buat mindmap untuk mereka.
- **Baca per-papan**: `ringkas_mindmap` hanya mengembalikan papan yang user **`canView`** (owner atau anggota; super_admin = semua). Papan orang lain yang bukan haknya tak pernah bocor.
- **Tambah wajib `canEdit`**: `tambah_mindmap` menolak (via `validate`) bila user bukan owner / bukan anggota ber-`can_edit` di papan tujuan.
- **Buat**: papan baru jadi milik user (`created_by`), jadi otomatis punya akses.
- Semua aksi tulis lewat gerbang konfirmasi (preview → setuju).

## Komponen — 3 alat baru (`app/Services/Ai/Tools/`)

### 1. `RingkasMindmapTool` — BACA
- `name`: `ringkas_mindmap` · `permission`: `mindmap.view` · read (isWrite default false).
- Param: `papan` (opsional string).
- `run`:
  - Kumpulan papan-boleh-akses: super_admin → semua; selain itu `created_by = user` OR anggota (limit 20, urut `updated_at` desc).
  - `papan` kosong → daftar `{judul, pemilik, jumlah_sticky, jumlah_garis}`.
  - `papan` diisi → cocokkan judul (LOWER LIKE) di antara papan-boleh-akses. Kembalikan struktur: `sticky` = daftar `{id, teks, warna}`, `garis` = daftar `{dari_teks, ke_teks, label}`. Tidak ketemu/di luar akses → `{catatan: ...}` ramah.

### 2. `BuatMindmapTool` — TULIS
- `name`: `buat_mindmap` · `permission`: `mindmap.view` · `isWrite`: true.
- Param: `judul` (wajib), `node` (wajib array `{teks, induk?}`; `induk` = indeks 0-based node induk di array yang sama, untuk cabang).
- `validate`: `judul` terisi; `node` array tak kosong; tiap item punya `teks`; `induk` (jika ada) indeks valid (0..n-1) & bukan diri sendiri. Balikin string error atau null.
- `previewText`: `Buat papan "{judul}" berisi {N} sticky` + contoh 3 teks pertama.
- `run`: `Mindmap::create(title, created_by=user)`. Layout via helper (lihat di bawah). Buat `MindmapNode` per item; `MindmapEdge` dari node-induk → node-anak untuk tiap item ber-`induk`. `AuditService::log('create_mindmap_ai')`. Balikin `{ok:true, pesan, papan_id}`.

### 3. `TambahMindmapTool` — TULIS
- `name`: `tambah_mindmap` · `permission`: `mindmap.view` · `isWrite`: true.
- Param: `papan` (wajib nama), `node` (wajib array `{teks, induk?}`), `sambung_dari` (opsional teks sticky lama).
- `validate`: cari papan (LOWER =) di antara papan yang user **`canEdit`**; tak ada/tak boleh edit → error ramah + sebut papan yang bisa diedit. Validasi `node` seperti Buat. `sambung_dari` (jika ada) dicocokkan ke sticky lama; kalau tak ketemu/ambigu → tetap lanjut tanpa menyambung + catatan.
- `previewText`: `Tambah {N} sticky ke papan "{judul}"` (+ `menyambung dari "{teks}"` bila cocok).
- `run`: base X = (max `x` node lama) + 260, atau 100 bila kosong. Layout sub-pohon. Buat node + garis internal. Bila `sambung_dari` cocok satu sticky lama → garis dari sticky itu ke tiap akar sub-pohon baru. Audit log. Balikin `{ok, pesan}`.

## Helper layout (dipakai Buat & Tambah)
Fungsi privat `layout(array $nodes, float $baseX, float $baseY): array` — untuk tiap node hitung `depth` (telusuri rantai `induk`, guard siklus: bila lebih dari n langkah → anggap akar). Posisi: `x = baseX + depth * 260`, `y = baseY + urutan * 144`. Lebar/tinggi pakai default model (200×120). Cukup terbaca; user tinggal geser untuk merapikan.

## Registrasi
`AppServiceProvider` → tambah `new RingkasMindmapTool`, `new BuatMindmapTool`, `new TambahMindmapTool` ke array `ToolRegistry`. Tanpa dependency konstruktor khusus.

## Testing (feature, SQLite in-memory)
`tests/Feature/MindmapAiToolTest.php`:
1. **Ketersediaan per izin**: user `mindmap.view` → 3 alat ada di `ToolRegistry::forUser`; distributor → tidak ada.
2. **Baca per-papan**: A owner papan X + anggota Y, bukan anggota Z. `ringkas_mindmap` (kosong) → sebut X & Y, bukan Z. `papan=X` → struktur node/edge.
3. **Buat**: `buat_mindmap` (judul + 3 node bercabang) → papan milik user + 3 node + edge sesuai `induk`; `previewText` menyebut judul & jumlah; `validate` error saat `node` kosong.
4. **Tambah + canEdit**: anggota `can_edit=false` → `validate` menolak; owner → node bertambah; `sambung_dari` cocok → edge dari sticky lama ke akar baru.

## Di luar cakupan (YAGNI)
- AI mengedit/menghapus sticky/garis yang sudah ada (hanya tambah).
- Tata letak canggih (tree balancing). Sederhana dulu; user geser manual.
- Ekspor/gambar papan oleh AI.
