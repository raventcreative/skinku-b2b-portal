# Produk Master E-commerce — Stok & Harga per Unit (model ala Desty)

**Tanggal:** 2026-09-23
**Status:** Design (menunggu review user sebelum writing-plans)
**Modul:** Integrasi / Stok Marketplace (Laravel 13 / PHP 8.3).
**⚠️ Ini REWORK** yang **menggantikan** pendekatan stok Fase 1/1.5 (yang keyed per-produk + menurunkan stok bundle dari komponen) dan draft Fase 2 harga. Bagian 1:1 kebawa; bundle jadi warga kelas satu.

---

## 1. Ringkasan & kenapa di-rework

Model lama (Fase 1/1.5) menaruh stok marketplace **per produk internal** dan **menghitung stok bundle dari komponen** (min). Itu **bukan** cara Desty — dan bikin bundle tak bisa punya stok/harga sendiri.

Model baru = **Produk Master E-commerce (ala Desty)**: tiap **unit jualan** (satuan / varian / multipack / bundle) = **1 baris "master" sendiri** dengan **stok + harga sendiri** (di-set langsung), **tertaut** ke listing TikTok/Shopee. Set stok/harga di master (atau override per-channel) → **sync** ke listing. **Terpisah total dari stok gudang (HQ).**

## 2. Tujuan & Non-Tujuan

**Tujuan:**
- Halaman **Produk Master**: tiap unit (termasuk bundle/multipack) baris sendiri, **stok + harga sendiri** (di-set langsung, TANPA hitung komponen).
- **Override per-channel** (TikTok/Shopee) untuk stok &/atau harga; tombol "Ikut Master".
- **Tautkan** listing marketplace ke master (auto-buat master saat resolve; bisa gabung listing lintas-channel ke 1 master).
- Push stok+harga ke channel; order di marketplace **nurunin stok master** (sync antar-channel per unit).
- **Sepenuhnya terpisah dari HQ.**

**Non-Tujuan:**
- **Stok HQ / `stock_movements` / potong-stok gudang TIDAK diubah** — tetap auto-potong dari e-commerce & offline (PO), bundle→komponen, seperti sekarang. Master e-commerce TIDAK menyentuh & TIDAK ditarik dari HQ.
- Tanpa grouping parent-varian (Scrub 1 Pcs & 3 Pcs = 2 master terpisah, bukan 1 parent 2 varian) — bisa fase lanjut.
- Tanpa "master auto-ikut stok HQ" (opsi fase lanjut).
- Tanpa promo/harga terjadwal.

## 3. Keputusan terkunci (dari user)

1. Tiap unit (satuan/varian/**bundle**) = master sendiri, **stok + harga di-set langsung** (bukan turunan komponen).
2. **HQ terpisah total** — auto-potong e-commerce + offline seperti sekarang; tak berhubungan dengan master e-commerce.
3. Harga & stok **manual** di master; override per-channel; sync ke listing.

## 4. Model data

### 4.1 `marketplace_masters` (BARU — "Produk Master e-commerce")
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| master_sku | string, **unique** | identitas unit (mis. `FM-1`, `Scrub3`, `BD-DN-2`) |
| name | string | nama tampil |
| product_id | FK products.id, **nullable** | referensi opsional ke produk internal (buat tampil/HPP nanti) — **BUKAN** sumber stok |
| base_stock | int, nullable | stok Master (null = belum di-set → tak di-push) |
| base_price | decimal(12,2), nullable | harga Master (null = tak di-push) |
| seeded_at | timestamp, nullable | patokan reconcile order-mirror (stok) |
| timestamps | | |

### 4.2 `marketplace_master_channels` (BARU — override per channel)
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| master_id | FK marketplace_masters | |
| channel | string(16) | tiktok / shopee |
| stock | int, nullable | override stok channel (null = ikut Master) |
| price | decimal(12,2), nullable | override harga channel (null = ikut Master) |
| seeded_at | timestamp, nullable | patokan reconcile stok override |
| timestamps | | |

Unik `(master_id, channel)`.

### 4.3 `marketplace_listings` (dari Fase 1) — diubah
- **Tambah** `master_id` FK nullable → listing tertaut ke master mana.
- **Tambah** kolom harga: `last_pushed_price` decimal(12,2) null, `last_price_status` string(16) null, `last_price_error` text null, `last_price_pushed_at` timestamp null.
- Kolom stok yang ada (`last_pushed_qty`, `last_status`, dll) tetap.

### 4.4 DIHENTIKAN (superseded)
`marketplace_stocks` & `marketplace_channel_overrides` (Fase 1/1.5, product-keyed) **diganti** oleh master. Migrasi: buat master dari data lama (per produk yg punya pool → master `master_sku`=Product.sku, `base_stock`=quantity), lalu **drop** kedua tabel lama + method service yang keyed-per-produk. (Prod masih tahap setup → data minim.)

## 5. Nilai efektif (per listing)

Listing punya `master_id` + `channel`.
- `effectiveStock(master, channel)` = `marketplace_master_channels.stock` bila ada (non-null) → else `master.base_stock` → else `null`.
- `effectivePrice(master, channel)` = channel `price` bila non-null → else `master.base_price` → else `null`.
- **Anti-push:** kalau null → listing itu TAK di-push (stok/harga masing-masing). **Tak ada hitung komponen** — bundle = angka master-nya sendiri.

## 6. Alur

### 6.1 Resolve + auto-buat master
`resolveListings(channel)` (dari Fase 1, diubah): tarik listing channel (item_id/variation_id/warehouse_id + seller_sku + title). Untuk tiap listing: **cari/auto-buat** `marketplace_masters` by `master_sku = seller_sku` (name dari title; product_id diisi bila ada Product ber-SKU sama), lalu set `listing.master_id`. Jadi tiap seller_sku unik → 1 master.

### 6.2 Tautkan / gabung listing (halaman)
Unit yang seller-SKU-nya **beda antar channel** (mis. REINA: TikTok `REI-3`, Shopee `REI-30G`) auto jadi 2 master. UI **Tautkan** memungkinkan **pindahkan listing ke master lain** (gabung) → 1 master, 2 listing (TikTok+Shopee) → sync. Juga tautkan listing "belum termaster" ke master (pilih master via native `<select>`, atau buat baru).

### 6.3 Set Master (halaman Produk Master)
`setMasterStock(master, int)` / `setMasterPrice(master, float)` → set `base_stock`/`base_price` (+ `seeded_at=now()` utk stok). Channel "ikut Master" ngikut. Lalu push master.

### 6.4 Override per-channel (halaman channel)
`setChannelStock(master, channel, int)` / `setChannelPrice(master, channel, float)` → `updateOrCreate` `marketplace_master_channels` (+ `seeded_at` utk stok). Channel lain tak berubah. Lalu push.
`ikutMaster(master, channel, field)` → set override field itu ke null (atau hapus baris bila stok & harga dua-duanya null). Lalu push.

### 6.5 Push
`pushListing(listing, force)` → hitung effectiveStock+effectivePrice (via master+channel); push stok (`update_stock`) & harga (`update_price`) yang berubah/`force`; catat status stok & harga terpisah; lewati yang null. `pushMaster(master)` → push semua listing master itu. `pushDirty` (cron 5mnt) & `pushAll` → semua. Anti-push null tetap.

### 6.6 Cermin order (anti-oversell per unit) — HQ tak disentuh
Di `*OrderService::store()` (best-effort try/catch): untuk tiap line (channel, seller_sku) → cari `marketplace_listings(channel, seller_sku)` → `master_id` → kurangi **effectiveStock bucket** master itu utk channel tsb (override stok bila ada, else base_stock; hormati `seeded_at`; clamp ≥0; hanya order baru). Lalu push master (sync antar-channel). **Tak pakai peta komponen** (itu khusus HQ). HQ deduction lama TETAP jalan terpisah.

### 6.7 Seed dari TikTok
`seedFromTiktok` → set `base_stock` (& opsi `base_price`) master dari stok/harga listing TikTok saat ini. **Sekarang jalan utk SEMUA unit termasuk bundle** (tiap listing = 1 master), bukan cuma 1:1.

## 7. Pemisahan dari HQ (tegas)
- Master e-commerce **tak baca & tak tulis** `products.hq_stock`/`stock_movements`.
- Potong stok HQ (e-commerce + offline/PO, bundle→komponen) = fitur lama, **tak diubah**, jalan sendiri.
- Peta SKU (`tiktok_sku_maps`/`shopee_sku_maps`) tetap **hanya** untuk HQ deduction; master e-commerce pakai `listing.master_id`, bukan peta itu.

## 8. UI (grup sidebar "Stok Marketplace" yang sudah ada)
- **Produk Master** (`/marketplace-stock`): tabel master; per baris: name+master_sku, **input Stok Master + Harga Master**, ringkasan per channel (Ikut Master / Override: N + status kirim stok & harga), tombol Sinkron. Header: Tarik stok awal TikTok, Refresh listing, Sinkron semua. Bagian "Listing belum termaster" + Tautkan.
- **Stok & Harga TikTok / Shopee** (`/marketplace-stock/{channel}`): master yg punya listing di channel itu; input override **stok & harga** channel + tombol **Ikut Master**; tampil nilai efektif + status kirim.
- Judul mencerminkan **Stok & Harga**. Native input; tanpa `@json([...])`; form POST + `@csrf`.

## 9. Rute & izin (grup `permission:manage_marketplace_stock`)
- `POST /marketplace-stock/master/{master}/stok` & `.../harga` → set base stok/harga
- `POST /marketplace-stock/{channel}/master/{master}/stok` & `.../harga` → override channel
- `POST /marketplace-stock/{channel}/master/{master}/ikut-master` → clear override (field via body)
- `POST /marketplace-stock/tautkan` → tautkan listing ke master (master_id existing / buat baru)
- `POST .../push/{master}`, `push-all`, `resolve`, `seed-tiktok` (dari Fase 1, diarahkan ke master)

## 10. Client API
- `ShopeeClient::updateStock` (SUDAH ADA). `ShopeeClient::updatePrice(accessToken, shopId, itemId, modelId, float price)` → `POST /api/v2/product/update_price` (`price_list:[{model_id, original_price}]`).
- `TikTokClient::updateStock` (SUDAH ADA). `TikTokClient::updatePrice(accessToken, shopCipher, productId, skuId, float price)` → `POST /product/202309/products/{productId}/prices/update` (`skus:[{id, price:{amount:(string), currency:'IDR'}}]`). Verifikasi endpoint/format ke docs saat planning.

## 11. Migrasi dari Fase 1/1.5
1. Tambah tabel `marketplace_masters`, `marketplace_master_channels`; alter `marketplace_listings` (master_id + kolom harga).
2. Migrasi data: tiap `marketplace_stocks` → buat master (master_sku=Product.sku, name=Product.name, product_id, base_stock=quantity, seeded_at); tiap `marketplace_channel_overrides` → `marketplace_master_channels.stock`. Set `listing.master_id` by mencocokkan seller_sku↔master_sku (fallback: via Product.sku).
3. Drop `marketplace_stocks` + `marketplace_channel_overrides`; buang method service product-keyed.

## 12. Anti-push, zero-dep, testing
- Anti-push null (stok/harga) tetap. Zero-dependency. **Tes PHPUnit class-style (no Pest); Product dibuat langsung.**
- Unit: effectiveStock/effectivePrice (override>base>null). Feature: set master stok/harga → channel ikut-master dapat, channel override tak berubah & sebaliknya; ikut-master clear; order di listing → stok MASTER turun (bukan HQ; assert `StockMovement` tetap); push kirim payload stok+harga benar per channel (Http fake); resolve auto-buat master + tautkan; **bundle punya stok/harga sendiri (bukan komponen)**; kontrol akses.

## 13. Out of scope
Grouping parent-varian, master auto-ikut HQ, promo/harga terjadwal, multi-gudang.

## 14. Deploy & risiko
- Deploy: `git pull` + `migrate --force` (tabel master + alter listings + drop tabel lama) + `optimize:clear`. Tak perlu re-auth (scope Product sudah ada; `update_price` = scope sama, verifikasi live).
- Risiko: rework nyentuh fitur live Fase 1/1.5 → dijaga tes + migrasi data. `update_price` format channel diverifikasi saat deploy. Unit yg seller-SKU beda antar channel perlu **digabung manual** ke 1 master (kalau tidak, ke-2 channel jalan sendiri-sendiri — tetap benar, cuma tak saling-sync).
