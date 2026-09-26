# Produk Master — Modal "Kaitkan Produk" ala Desty

**Tanggal:** 2026-09-26
**Status:** Design (disetujui user — "gas")
**Modul:** Stok Marketplace / Produk Master E-commerce (Laravel 13 / PHP 8.3)
**⚠️ Iterasi UI di atas model manual (Fase 2c yang sudah live).** Mengganti picker `<select>` kecil "Tambah ke Marketplace" dengan **modal "Kaitkan Produk"** ala Desty yang jelas menunjukkan listing mana yang **sudah tertaut** vs **belum**.

---

## 1. Masalah & keputusan terkunci (dari user)

Picker sekarang cuma `<select>` berisi listing yang belum tertaut — admin tak bisa lihat listing apa saja yang **sudah** tertaut ke master, tak bisa **melepas**, dan tak jelas status keseluruhan. User mau seperti Desty (gambar 2 & 3): panel dengan tab **Semua / Produk Terkait / Produk Tidak Terkait**.

Keputusan terkunci (AskUserQuestion):
1. **Bentuk = MODAL pop-up** (persis Desty), pakai JS vanilla + data di-embed sekali (konsisten dg app yang sudah banyak pakai `<script>` inline). Bukan halaman terpisah.
2. **Data = RINGAN**: tampilkan Nama listing + SKU Marketplace + Channel/Toko + status tertaut. **TIDAK** menarik stok/status/harga live per listing dari API (hindari beban + rate limit).
3. Modal dibuka dari tombol **"Tambah ke Marketplace"** DAN dari klik angka **Produk Terkait / Toko Terkait** di tabel (gambar 2 = viewer toko terkait, disatukan ke modal ini).
4. Bisa **Tautkan** (link) dan **Lepas** (unlink). Mesin sinkron dipakai ulang (pushMaster saat tautkan).
5. HQ tetap tak disentuh. Tanpa migrasi baru.

## 2. Data model

Tak ada perubahan skema. Pakai `marketplace_listings` (punya `channel`, `seller_sku`, `title`, `master_id`) + `marketplace_masters`. "Tertaut" = `listing.master_id`. Nama toko dari `TiktokConnection::shop_name` / `ShopeeConnection::shop_name` (latest), nullable.

## 3. Backend

### 3.1 Service (`MarketplaceMasterService`)
- `linkListings(MarketplaceMaster $m, array $ids): int` — set `master_id = $m->id` untuk listing ber-id di `$ids`. Return jumlah ter-update.
- `unlinkListings(MarketplaceMaster $m, array $ids): int` — set `master_id = null` **HANYA** untuk listing yang `master_id`-nya == `$m->id` (safety: tak bisa melepas listing milik master lain lewat request iseng). Return jumlah.

### 3.2 Controller (`MarketplaceStockController`)
- `kaitkan(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc)`: validate `listing_ids` = required array, `listing_ids.*` = integer exists `marketplace_listings,id`. `$svc->linkListings($master, $ids)` lalu `$svc->pushMaster($master)` (dorong stok Master→listing). Redirect back status "N listing ditautkan ke {name}."
- `lepas(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc)`: validate sama. `$svc->unlinkListings($master, $ids)`. Redirect back status "N listing dilepas dari {name}."
- `index()`: tambah data untuk modal → `allListings` (semua `marketplace_listings`: id, channel, seller_sku, title, master_id), `masterNames` (id→name semua master, untuk label "tertaut ke master lain"), `shopNames` (['tiktok'=>?, 'shopee'=>?]).

### 3.3 Rute (grup `permission:manage_marketplace_stock`)
- `POST /marketplace-stock/master/{master}/kaitkan` → `kaitkan` (name `marketplace-stock.kaitkan`)
- `POST /marketplace-stock/master/{master}/lepas` → `lepas` (name `marketplace-stock.lepas`)
- `tautkan` lama TETAP (dipakai halaman channel untuk tautkan SKU inline) — tak dihapus.

## 4. Frontend (modal, JS vanilla)

`resources/views/marketplace-stock/index.blade.php`:
- **Buang** picker `<details>`/`<select>` "Tambah ke Marketplace" lama di menu Atur.
- Menu Atur: **"Tambah ke Marketplace"** jadi tombol `type="button"` dengan `data-kaitkan`, `data-master-id`, `data-master-sku`, `data-master-name` → buka modal.
- Kolom **Produk Terkait** & **Toko Terkait**: bila > 0, jadikan tombol/link (`data-kaitkan …` + `data-tab="terkait"`) yang buka modal langsung ke tab Produk Terkait. Bila 0, teks "—" biasa.
- **Satu modal** (id `kaitkanModal`) hidden default, berisi:
  - Header: "Kaitkan Produk (<span id=kaitkanSku>)" + tombol tutup (✕).
  - Kontrol: input search (`kaitkanSearch`), select filter channel (Semua/TikTok/Shopee, `kaitkanChannel`).
  - Tabs (tombol): **Semua** / **Produk Terkait** / **Produk Tidak Terkait** (+ badge jumlah `kaitkanCountAll/Terkait/Tidak`).
  - Tabel body `kaitkanRows` (diisi JS): kolom Centang · Informasi Produk (nama) · SKU Marketplace · Channel/Toko · Status.
  - Catatan: "Pengaitan akan mendorong stok dari Master ke listing."
  - Footer: tombol **Tautkan** (`kaitkanLink`) · **Lepas** (`kaitkanUnlink`) · **Tutup**.
  - Dua `<form>` tersembunyi: `kaitkanForm` (POST ke kaitkan) + `lepasForm` (POST ke lepas), masing-masing `@csrf` + kontainer hidden-input.
- **Embed data sekali** via `<script>` di akhir view (pakai `json_encode`, BUKAN `@json([...])` literal):
  `window.__mp = { listings, masterNames, shopNames, kaitkanTpl, lepasTpl }` (tpl = route dg placeholder `__ID__`).

### 4.1 Logika JS (vanilla)
- Buka modal untuk master {id, sku, name, tab?}: partisi `listings` → **terkait** (`master_id==id`), **tidakTerkait** (`master_id==null`), **lain** (`master_id!=null && !=id`). Render sesuai tab aktif + search + filter channel.
- Baris:
  - terkait → checkbox aktif, badge hijau "✅ Tertaut", checkbox untuk **Lepas**.
  - tidakTerkait → checkbox aktif, badge abu "⬜ Belum", checkbox untuk **Tautkan**.
  - lain → badge kuning "🔗 {masterNames[master_id]}", checkbox **disabled** (tak bisa dipindah sembarangan dari sini; MVP aman).
- Tab **Semua** = terkait + tidakTerkait + lain; **Produk Terkait** = terkait; **Produk Tidak Terkait** = tidakTerkait. Badge jumlah per tab.
- Search: filter baris by nama ATAU SKU (case-insensitive). Channel filter: tampilkan channel terpilih saja.
- **Tautkan**: kumpulkan id tercentang yang statusnya tidakTerkait → isi `kaitkanForm` (hidden `listing_ids[]`) → submit ke `kaitkanTpl.replace('__ID__', masterId)`.
- **Lepas**: kumpulkan id tercentang yang statusnya terkait → isi `lepasForm` → submit ke `lepasTpl...`.
- Submit = full page reload (server redirect back + flash). Modal tak perlu AJAX.

## 5. Testing (PHPUnit class-style, zero-dep)

- **KaitkanTest** (Feature): `kaitkan` menautkan banyak listing sekaligus (`master_id` keset) + memanggil push (assert `last_pushed_*`/status berubah atau minimal tak error dg Http::fake); `lepas` hanya melepas listing milik master itu (listing milik master lain di `$ids` TAK terlepas); validasi `listing_ids` wajib array; akses ditolak non-izin (reseller 403); HQ tak tersentuh (assert `stock_movements` count tetap).
- **CatalogPageTest** (update): halaman memuat modal (`id="kaitkanModal"`), meng-embed `window.__mp`, tombol "Tambah ke Marketplace" `data-kaitkan`, dan kolom Produk/Toko Terkait bisa diklik saat > 0. (Perilaku JS tak diuji unit — tak ada infra JS; cukup assert markup + data ter-embed.)
- Tes lama tetap hijau (picker lama diganti — sesuaikan assertion CatalogPageTest yang mungkin cek `<select name="listing_id">` lama).

## 6. Out of scope

Tarik stok/status/harga live per listing; bikin listing baru di marketplace; pindah listing dari master lain lewat modal (baris "lain" read-only); pagination modal (jumlah listing kecil).

## 7. Deploy & risiko

- Deploy: `git pull` + `optimize:clear` (tanpa migrasi baru). 
- Risiko: JS modal — pastikan tutup/buka + submit jalan lintas browser (vanilla, tanpa lib). Data embed via `json_encode` (aman untuk `<script>`; hindari `</script>` di data — pakai flag `JSON_HEX_TAG`). HQ tak disentuh.
