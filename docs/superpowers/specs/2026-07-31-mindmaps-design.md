# Mindmaps (kanvas kolaboratif ala Miro) — Desain

> Fitur baru untuk SKINKU B2B portal: kanvas serbaguna untuk mindmap, diagram
> alur/organisasi, dan papan visual campaign. Dibangun zero-dependency (vanilla
> JS: DOM + SVG), mengikuti pola Kanban yang sudah ada. Kolaborasi tim secara
> asinkron (tersimpan di server, auto-refresh — TANPA kursor live). Akses AI
> menyusul sebagai fase kedua.

## 1. Tujuan & keputusan (hasil brainstorming)

- **Guna:** serbaguna — mindmap (node + cabang), diagram organisasi/alur/SOP
  (node + panah), papan campaign (sticky). Satu kanvas melayani ketiganya.
- **Kolaborasi:** berbagi tim, **asinkron**. Papan tersimpan di server; perubahan
  orang lain terlihat lewat auto-refresh. **Tanpa kursor live** (websocket dihindari
  karena shared hosting; bisa ditambah kelak).
- **Akses:** tim **internal saja**. Per-papan pilih anggota: owner mengundang staf
  dengan hak edit atau lihat.
- **Akses AI:** asisten bisa membaca papan + membuat draf mindmap dari prompt
  (dengan konfirmasi). Dibangun di **Fase 2**.
- **Batasan teknis:** zero-dependency (tanpa paket composer/npm; JS inline, CDN
  hanya yang sudah dipakai). Shared hosting (LiteSpeed, cron + database queue).
  Render = HTML DOM (node) + SVG (garis), pan/zoom via CSS transform.

## 2. Pendekatan terpilih

**DOM + SVG, simpan per-elemen via AJAX** (seperti Kanban). Node = `<div>`
contenteditable; garis = `<svg>` overlay; pan/zoom = CSS transform pada "dunia".
Tiap mutasi (buat/geser/edit/warna/hapus) disimpan sendiri via AJAX → dua orang
bisa kerja di papan yang sama tanpa saling menimpa satu blob. Ditolak: satu blob
JSON per papan (rawan saling menimpa saat bentrok), library Miro via CDN (langgar zero-dep).

## 3. Data model

Migrasi baru `2026_01_01_000071_create_mindmap_tables.php` — 4 tabel:

**mindmaps** (papan)
- `id`, `title` (string 255), `created_by` (FK users — owner), `timestamps`.

**mindmap_members** (akses per-papan; owner otomatis full akses, tak perlu baris)
- `id`, `mindmap_id` (FK, cascade delete), `user_id` (FK), `can_edit` (bool,
  default true — false = lihat saja). Unique (`mindmap_id`, `user_id`).

**mindmap_nodes** (kotak/sticky/teks)
- `id`, `mindmap_id` (FK cascade), `type` (string: `sticky`|`text`; extensible),
  `x` (float), `y` (float), `width` (float, default 200), `height` (float, default 120),
  `text` (text, nullable), `color` (string 20, palet: kuning/hijau/biru/rose/stone/putih),
  `created_by` (FK), `timestamps`.

**mindmap_edges** (garis penghubung)
- `id`, `mindmap_id` (FK cascade), `from_node_id` (FK mindmap_nodes cascade),
  `to_node_id` (FK mindmap_nodes cascade), `label` (string 255, nullable),
  `timestamps`.

Menghapus node → hapus garis yang menyentuhnya (cascade FK atau event model).

## 4. Akses & izin

- Izin baru **`mindmap.view`** (Support/Permissions) → default tim internal
  (`ROLE_ADMIN`, `ROLE_GUDANG`; super_admin selalu). Bisa diatur di Manajemen Hak
  Akses.
- Semua route grup **`permission:mindmap.view` + `internal`** (mitra terblokir keras).
- Cek per-papan (helper di controller, ala policy):
  - **Lihat papan / state** → owner ATAU anggota (edit/lihat).
  - **Edit isi** (node & garis CRUD) → owner ATAU anggota `can_edit`.
  - **Kelola** (rename, hapus papan, tambah/hapus anggota) → **owner** (super_admin boleh).
- Halaman daftar hanya menampilkan papan di mana user = owner atau anggota.
- Semua aksi tulis masuk Audit Log (pola yang sudah ada).

## 5. Routes / endpoint

Nama `mindmaps.*`, controller `MindmapController` (+ mungkin `MindmapElementController`
untuk node/edge agar file fokus). Gerbang `permission:mindmap.view` + `internal`.

```
GET    /mindmaps                     index (daftar papan milik/diikuti)
POST   /mindmaps                     buat papan (judul)
GET    /mindmaps/{mindmap}           buka papan (halaman kanvas)
PATCH  /mindmaps/{mindmap}           rename (owner)
DELETE /mindmaps/{mindmap}           hapus papan (owner)
GET    /mindmaps/{mindmap}/state     JSON { nodes, edges, updated_at } — load & poll
POST   /mindmaps/{mindmap}/members   tambah anggota (owner)  { user_id, can_edit }
DELETE /mindmaps/{mindmap}/members/{user}   hapus anggota (owner)
POST   /mindmaps/{mindmap}/nodes             buat node        { type, x, y, ... }
PATCH  /mindmaps/{mindmap}/nodes/{node}      update (posisi/teks/warna/ukuran)
DELETE /mindmaps/{mindmap}/nodes/{node}      hapus node (+ garisnya)
POST   /mindmaps/{mindmap}/edges             buat garis       { from_node_id, to_node_id }
PATCH  /mindmaps/{mindmap}/edges/{edge}      update label
DELETE /mindmaps/{mindmap}/edges/{edge}      hapus garis
```

Endpoint CRUD balas JSON saat `wantsJson` (dipakai fetch dari kanvas), redirect
saat form biasa (buat/rename/hapus papan).

## 6. UI kanvas & interaksi

Halaman papan = viewport `overflow:hidden` berisi "dunia" (`transform: translate scale`).
Node = div absolut di dunia; garis = satu `<svg>` menutupi dunia.

- **Pan:** seret area kosong → translate dunia.
- **Zoom:** scroll mouse + tombol `+ / − / fit` di pojok (skala 0.2–3).
- **Toolbar:** + Sticky · + Teks · palet warna · hapus · zoom · **Anggota** · ← daftar.
- **Node:**
  - Buat: double-click kanvas kosong → sticky di titik itu (langsung editable);
    atau tombol toolbar → node di tengah viewport.
  - Edit: klik → contenteditable/textarea; blur → PATCH text.
  - Geser: drag → PATCH x,y saat dilepas (debounce saat drag; final on drop).
  - Ukur ulang: tarik pojok → PATCH width,height.
  - Warna: pilih node → palet → PATCH color.
  - Hapus: pilih + tombol/tombol Delete → DELETE node (garis ikut terhapus).
- **Garis:** hover node → titik sambung → tarik ke node lain → POST edge (panah).
  Klik garis → beri label / hapus. Garis dihitung ulang saat node bergerak.

## 7. Simpan & sinkron (tanpa kursor live)

- Tiap mutasi → AJAX per-elemen (endpoint di §5), balas JSON. `updated_at` papan
  ikut disentuh tiap ada perubahan.
- **Auto-refresh:** poll `GET /state` tiap ~10 dtk (ambil `updated_at` + versi ringkas).
  - Jika papan lebih baru (diedit orang lain) **dan** user idle (tak ada drag/edit
    berjalan, tak ada mutasi lokal belum tersimpan) → tarik state penuh & render ulang.
  - Jika user sedang edit → tampilkan chip **"papan diperbarui — muat ulang"**; user
    memutuskan kapan reload (mencegah edit-nya tertimpa).
- Elemen kecil → render ulang penuh cukup murah untuk papan perencanaan.

## 8. Akses AI (Fase 2)

Dua alat asisten (registrasi di `ToolRegistry`), gerbang `mindmap.view` + akses per-papan:

- **`ringkas_mindmap`** (baca): argumen `papan` (nama/id, opsional). Baca node + garis
  papan yang boleh diakses user → kembalikan struktur (daftar node + relasi) supaya AI
  bisa merangkum / menjawab pertanyaan.
- **`buat_mindmap`** (tulis, `isWrite=true` → wajib konfirmasi): dari prompt, AI usulkan
  KONTEN (topik pusat → cabang → sub-cabang + hubungan) via structured tool. **Server
  yang menghitung posisi** (auto-layout radial/pohon sederhana) lalu membuat papan +
  node + garis setelah user menyetujui (pola OKR/kartu Kanban). Pakai infra
  provider + failover yang sudah ada. Masuk Audit Log.

## 9. Testing

`tests/Feature/MindmapTest.php` (+ `MindmapAiToolTest.php` untuk Fase 2):
- Akses: gerbang `mindmap.view`; mitra terblokir (`internal` → 403); non-anggota → 403;
  anggota `can_edit=false` tak bisa edit node; rename/hapus/anggota hanya owner.
- CRUD: buat/geser/edit/warna/hapus node; hapus node → garisnya ikut; buat/label/hapus garis.
- `GET /state` balas node+garis+updated_at benar; hanya untuk yang berhak.
- Render: halaman daftar + halaman papan `assertOk` (render test Blade).
- Fase 2: `ringkas_mindmap` ter-scope akses; `buat_mindmap` bikin node+garis lewat
  konfirmasi; keduanya berpagar izin.

## 10. Rollout / deploy

- Migrasi `000071` → deploy **`migrate --force`** + `optimize:clear`.
- Izin `mindmap.view` default internal (kode DEFAULTS; bisa override di Hak Akses).
- Menu sidebar **"Mindmaps"** (gerbang `mindmap.view`, hanya `isStaff()`).

## 11. Urutan bangun (tiap fase = Pint + test + full suite + commit)

- **Fase 1 (inti):** migrasi + model + izin/menu · papan CRUD + anggota · halaman
  kanvas (pan/zoom) · node CRUD · garis CRUD · auto-save + auto-refresh · test.
- **Fase 2 (AI):** alat `ringkas_mindmap` + `buat_mindmap` (konfirmasi + auto-layout) · test.

## 12. Di luar scope (YAGNI — ditunda)

Freehand/pena, upload gambar/foto, bentuk lanjutan (panah bebas, konektor bercabang),
template, kursor & edit real-time (websocket), komentar, riwayat versi, ekspor PNG/PDF.
Semua bisa jadi fase lanjutan setelah inti terbukti dipakai.
