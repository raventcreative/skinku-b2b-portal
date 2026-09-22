# Stok Marketplace — Fase 1.5: Override Per-Channel (Master + Produk E-commerce)

**Tanggal:** 2026-09-22
**Status:** Design (menunggu review user sebelum writing-plans)
**Modul:** Integrasi (SKINKU B2B, Laravel 13 / PHP 8.3). Lanjutan **Fase 1** (`2026-09-19-kontrol-stok-marketplace-design.md`, sudah LIVE di main `f37eb12`).

---

## 1. Ringkasan

Tambahkan **lapis kontrol per-channel** di atas pool Master yang sudah ada, meniru struktur Desty (Produk Master + Produk E-commerce). Master tetap mengatur semua channel; tapi tiap channel bisa **di-override** supaya punya angka stok sendiri **tanpa mengubah channel lain** — dan channel yang di-override **tetap turun otomatis** saat ada order di channel itu ("mandiri tapi tetap auto").

## 2. Tujuan & Non-Tujuan

**Tujuan:**
- Bisa set stok 1 channel sendiri (override) dari menu channel-nya, tanpa menyentuh channel lain.
- Master tetap jadi default & mengatur semua channel yang "ikut Master".
- Channel yang di-override tetap ikut turun otomatis saat ada order di channel itu (anti-oversell per-channel tetap jalan).
- Tombol **"Ikut Master"** untuk melepas override → channel balik mengikuti Master.
- Menu sidebar terpisah: **Stok Master**, **Stok TikTok**, **Stok Shopee**.
- **Tautkan SKU yang belum terpetakan LANGSUNG dari halaman ini** (pilih produk master) — tanpa bolak-balik ke halaman peta SKU TikTok/Shopee. (Banyak seller sudah punya toko sebelum pakai SKINKU → kode SKU channel sering beda dari SKU internal.)

**Non-Tujuan:**
- **HQ stock / `stock_movements` / `adjustHqStock` tetap TIDAK disentuh.**
- Tanpa ubah harga / status listing / bikin listing (fase lain).
- Tanpa buffer (tetap 0, warisan Fase 1).
- Tidak mengubah perilaku Master yang sudah live (channel "ikut Master" = persis Fase 1).

## 3. Keputusan (sudah disetujui user)

1. Override channel = **mandiri tapi tetap auto** (punya stok sendiri, lepas dari Master, tetap turun otomatis saat order di channel itu).
2. Struktur = **menu sidebar terpisah** (Stok Master + Stok TikTok + Stok Shopee), bukan tab.
3. Default tiap channel = **ikut Master** (belum ada override).

## 4. Model data

### 4.1 `marketplace_stocks` (Master pool per produk) — SUDAH ADA (Fase 1), tak berubah.
`product_id` unik, `quantity`, `seeded_at`.

### 4.2 `marketplace_channel_overrides` (baru) — override per (produk, channel)
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| product_id | FK → products.id | |
| channel | string(16) | `tiktok` / `shopee` |
| quantity | int | stok mandiri channel ini |
| seeded_at | timestamp, nullable | patokan reconcile (order sebelum ini tak mengurangi override) |
| created_at / updated_at | timestamp | |

Unik: `(product_id, channel)`. **Ada baris = channel itu "override/mandiri"; tak ada baris = "ikut Master".**

## 5. Stok efektif per channel

- `channelStock(int $productId, string $channel): ?int` = quantity dari `marketplace_channel_overrides` bila ada; kalau tidak → `marketplace_stocks.quantity` (Master); `null` bila dua-duanya tak ada (belum di-set/di-seed).
- `availableForListing(MarketplaceListing $l)` (rework, channel-aware) = `min` atas komponen dari `⌊ channelStock(komponen.product_id, $l->channel) ÷ qty ⌋`. Return `null` bila `seller_sku` tak terpetakan **atau** ada komponen yang `channelStock`-nya null (**pengaman anti-push-0 tetap berlaku**).

Efeknya: listing di channel yang "ikut Master" pakai angka Master; listing di channel yang di-override pakai angka override. Tetap bundle-aware.

## 6. Alur

### 6.1 Set Master (halaman Stok Master)
`setPool(product, qty)` (SUDAH ADA) → set `marketplace_stocks` + stamp `seeded_at`. Channel yang "ikut Master" otomatis ikut; channel yang di-override **tidak** terpengaruh.

### 6.2 Set override channel (halaman Stok TikTok/Shopee)
`setChannelOverride(product, channel, qty)` (baru) → `updateOrCreate` baris `marketplace_channel_overrides` + stamp `seeded_at = now()`. Channel itu jadi mandiri; channel lain tak berubah.

### 6.3 "Ikut Master" (halaman channel)
`clearChannelOverride(product, channel)` (baru) → hapus baris override → channel balik ikut Master. Lalu push produk itu supaya channel langsung selaras Master.

### 6.4 Cermin otomatis saat order (channel-aware) — HQ tak disentuh
Rework hook `MarketplaceStockService::applyOrderDelta` jadi channel-aware:
`applyOrderDelta(Product $p, string $channel, int $delta, Carbon $orderCreatedAt)`:
- Kalau ada override `(p, channel)` → ubah **override** itu (clamp ≥ 0, hormati `seeded_at` override).
- Kalau tidak → ubah **Master pool** `p` (seperti Fase 1; hormati `seeded_at` Master).
- Guard `seeded_at`, best-effort try/catch, idempoten (hanya order baru), sama seperti Fase 1.

Hook di `*OrderService::store()` mengirim channel-nya (TikTok service → `'tiktok'`, Shopee → `'shopee'`). Order di channel yang "ikut Master" tetap menurunkan Master → semua channel "ikut Master" ikut turun (proteksi Fase 1 utuh). Order di channel yang di-override hanya menurunkan override channel itu.

### 6.5 Push (cron + manual)
`pushListing`/`pushProduct`/`pushDirty`/`pushAll` (SUDAH ADA) — mekanik sama, cuma `availableForListing` kini channel-aware, jadi tiap listing dapat angka sesuai keadaan channel-nya. Tetap: diff-only di cron, catat `last_status`/`last_error`, lewati bila `available` null.

### 6.6 Seed dari TikTok (halaman Master)
`seedFromTiktok` (SUDAH ADA) → isi **Master**. Channel yang di-override tak terpengaruh. Tak berubah.

### 6.7 Resolve listing
`resolveListings` (SUDAH ADA) — tak berubah.

### 6.8 Tautkan SKU langsung dari sini (tanpa bolak-balik ke halaman peta SKU)
Bagian "Listing belum terpetakan" diberi aksi tautkan **inline**: pilih **produk master** (native `<select>`, label nama-depan sesuai pola yang disukai) + **qty** (default 1) + tombol **Tautkan** → buat baris `tiktok_sku_maps`/`shopee_sku_maps` (`{channel}_sku = seller_sku`, `product_id`, `qty`), lalu bersihkan tanda `unmapped` pada baris `marketplace_listings` yang cocok. Untuk **bundle multi-produk**, tautkan komponen berulang (tiap submit menambah 1 komponen ke `seller_sku` itu) atau pakai halaman peta SKU lama. **Reuse tabel peta SKU yang sudah ada** — sekali tautkan, dipakai untuk stok-mirror DAN potong-stok HQ (konsisten, tak dobel). Setelah tautkan, produk itu muncul di tabel utama & ikut sinkron.

## 7. UI + sidebar

**3 menu sidebar** (grup Integrasi), semua gate `permission:manage_marketplace_stock`:
- **Stok Master** (`/marketplace-stock`, halaman Fase 1 yang di-repurpose): tabel 1 baris/produk; input **Master pool** (Simpan); kolom status ringkas per channel (`Ikut Master` / `Override: N` + terkirim/last_status). Tombol header: Tarik stok awal dari TikTok, Refresh listing, Sinkron semua.
- **Stok TikTok** (`/marketplace-stock/tiktok`): tabel produk yang punya listing TikTok; tampilkan **stok efektif TikTok** (override atau Master) + input set **override TikTok** (Simpan) + tombol **Ikut Master** per baris; per-baris Sinkron; status kirim. Header: Refresh listing, Sinkron semua (TikTok).
- **Stok Shopee** (`/marketplace-stock/shopee`): sama, khusus Shopee.

Menandai baris "override vs ikut master" jelas (mis. badge). Tanpa `@json([...])` literal. Ikuti gaya halaman Integrasi yang ada.

## 8. Rute & izin

Grup `permission:manage_marketplace_stock` (SUDAH ADA), tambah:
- `GET /marketplace-stock/tiktok` → `channel('tiktok')`; `GET /marketplace-stock/shopee` → `channel('shopee')`
- `POST /marketplace-stock/{channel}/override/{product}` → set override (validasi `channel` ∈ {tiktok,shopee}, `quantity` int ≥ 0)
- `POST /marketplace-stock/{channel}/ikut-master/{product}` → clear override
- `POST /marketplace-stock/{channel}/tautkan` → buat peta SKU untuk listing belum terpetakan (validasi `channel` ∈ {tiktok,shopee}, `seller_sku` wajib, `product_id` ada, `qty` int ≥ 1)
- (rute Master `set`/`push`/`push-all`/`resolve`/`seed`/`index` tetap)

Item sidebar baru di `resources/views/layouts/app.blade.php` (grup Integrasi), gate `$u->canDo('manage_marketplace_stock')` (ikut pola item yang ada — **BUKAN `@can`**, codebase pakai `canDo`). Grup accordion Integrasi sudah meng-OR `manage_marketplace_stock` (dari Fase 1) → aman.

## 9. Anti-oversell & interaksi (jujur)

- Channel "ikut Master": persis Fase 1 (order di salah satu channel → Master turun → semua channel ikut). 
- Channel "override": mandiri — order di channel itu turunin override-nya sendiri (tetap auto, tetap anti-oversell untuk channel itu), tapi **lepas dari Master** (disengaja).
- **Pengaman anti-push-0** tetap: `availableForListing` null (belum di-set/di-seed) → tak di-push.
- Edge pre-existing (Fase 1, di-park): clamp ≥ 0 bisa over-restore saat cancel kalau order qty > stok — sama berlaku di override; tetap di-park (Fase 1.5 tak menyelesaikannya, tapi juga tak memperburuk).

## 10. Zero-dependency & testing

Tanpa composer package baru. Reuse service/clients Fase 1.
Runner: `C:\php83\php.exe artisan test`; Pint `--dirty` sebelum commit. **Tes PHPUnit class-style (repo tak pakai Pest); `Product` dibuat langsung (tanpa factory)** — sama seperti Fase 1.
- Unit: `channelStock` (override vs master vs null); `availableForListing` channel-aware (override 1 channel tak mengubah channel lain; bundle min).
- Feature: set override 1 channel → channel lain tak berubah & Master tak berubah; Ikut Master → override hilang, balik ikut Master; order di channel override → override turun, Master TETAP; order di channel ikut-master → Master turun (channel ikut-master lain ikut, channel override TIDAK); `StockMovement` (HQ) tetap; render 3 halaman; kontrol akses; push channel-aware kirim angka benar per channel (Http fake).

## 11. Out of scope
Harga, status/aktif listing, bikin listing, buffer, multi-gudang, penyelesaian edge clamp-over-restore.

## 12. Deploy
`git pull origin main` + `migrate --force` (migrasi baru = tabel `marketplace_channel_overrides`, lanjut dari `000139` → `000140`) + `optimize:clear`. Tak butuh re-auth baru (scope Product sudah dari Fase 1).

## 13. Risiko & keterbukaan
- Menambah state (override) = lebih banyak kombinasi; ditutup tes matriks (ikut-master vs override × order channel A/B).
- Kompleksitas UI 3 halaman; dijaga tetap tipis (controller pass-through ke service).
- Bundle + override per-produk: konsisten via `channelStock` per komponen (min), tapi seed otomatis tetap hanya listing 1:1 (bundle set manual — warisan Fase 1).
