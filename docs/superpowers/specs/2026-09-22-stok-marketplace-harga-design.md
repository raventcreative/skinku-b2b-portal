# Stok Marketplace — Fase 2: Harga (Master + Override Per-Channel)

**Tanggal:** 2026-09-22
**Status:** Design (menunggu review user sebelum writing-plans)
**Modul:** Integrasi / Stok Marketplace (Laravel 13 / PHP 8.3). Lanjutan Fase 1 (stok, LIVE) & Fase 1.5 (override per-channel, LIVE `79a0eef`).

---

## 1. Ringkasan

Tambah kontrol **harga jual marketplace** dengan pola yang sama seperti stok: **1 harga Master per produk** → didorong ke TikTok & Shopee; tiap channel bisa **override harga sendiri** tanpa mengubah channel lain (tombol "Ikut Master" untuk balik). Harga ini **manual** (tidak auto-berubah — beda dari stok yang turun saat order): set → push. Digabung ke halaman Stok yang sudah ada (jadi "Stok & Harga").

## 2. Tujuan & Non-Tujuan

**Tujuan:**
- Set harga Master per produk → semua channel yang "ikut Master" ngikut.
- Override harga per-channel (TikTok/Shopee sendiri) tanpa ganggu channel lain; "Ikut Master" untuk lepas override.
- Push harga ke channel (Shopee `update_price`, TikTok price update), catat hasil.
- Digabung di halaman Stok Master/TikTok/Shopee (1 baris = stok + harga per channel).

**Non-Tujuan:**
- **Stok HQ / `stock_movements` TIDAK disentuh.** Perilaku stok Fase 1/1.5 TIDAK berubah.
- Tanpa promo, harga diskon terjadwal, atau strategi harga otomatis (fase lanjut).
- Harga tidak auto-berubah saat order/retur (tak ada mirror seperti stok).
- Tanpa buffer/aturan.

## 3. Keputusan (sudah disetujui user)

1. Model harga = **Master + override per-channel** (mirror stok).
2. Penempatan = **gabung di halaman Stok yang ada** (kolom Harga). Judul halaman → "Stok & Harga".
3. Harga **manual** (set → push); tak ada auto-decrement.

## 4. Model data (tabel/kolom baru — additive)

### 4.1 `marketplace_prices` (Master price per produk)
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| product_id | FK → products.id, **unique** | |
| price | decimal(12,2) | harga Master (Rupiah) |
| created_at / updated_at | timestamp | |

### 4.2 `marketplace_channel_price_overrides` (override harga per channel)
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| product_id | FK → products.id | |
| channel | string(16) | tiktok / shopee |
| price | decimal(12,2) | harga mandiri channel ini |
| created_at / updated_at | timestamp | |

Unik `(product_id, channel)`. **Ada baris = channel override harga; tak ada = ikut Master.** (Tak perlu `seeded_at` — harga tak auto-berubah.)

### 4.3 `marketplace_listings` (tabel Fase 1) — tambah kolom
- `last_pushed_price` decimal(12,2) nullable
- `last_price_status` string(16) nullable (`ok`/`failed`/`unmapped`)
- `last_price_error` text nullable
- `last_price_pushed_at` timestamp nullable

(Kolom stok yang ada — `last_pushed_qty`, `last_status`, dll — TIDAK diubah; harga dapat kolom sendiri agar status stok & harga terpisah.)

## 5. Harga efektif

`effectivePrice(int $productId, string $channel): ?float` = harga override `(product,channel)` bila ada; kalau tidak → `marketplace_prices` (Master); kalau tidak → `null`. Beda dari stok: **tidak bundle-aware** (harga = harga LISTING, bukan hasil hitung komponen). Harga listing = `effectivePrice(product, channel)` dari **produk utama** listing tsb.

> **Bundle & harga:** untuk listing bundle multi-komponen, "produk utama" ambigu. Fase 2: **listing dgn tepat 1 komponen** yang dapat push harga otomatis; listing bundle (≥2 komponen) **dilewati push harga** (status `unmapped` untuk kolom harga) — atur harganya manual di Seller Center. (Konsisten dgn seed stok yg juga hanya 1:1.)

## 6. Alur

### 6.1 Set harga Master (halaman Stok Master)
`setMasterPrice(Product, float): MarketplacePrice` (`updateOrCreate`). Channel "ikut Master" ngikut; channel yg override harga tak terpengaruh. Lalu push harga produk itu.

### 6.2 Set override harga channel (halaman channel)
`setChannelPrice(Product, channel, float): MarketplaceChannelPriceOverride` (`updateOrCreate`). Channel itu jadi mandiri; channel lain tak berubah. Lalu push.

### 6.3 "Ikut Master" harga (halaman channel)
`clearChannelPrice(Product, channel): void` → hapus override harga → balik ikut Master. Lalu push.

### 6.4 Push harga
`pushPriceListing(MarketplaceListing $l, bool $force=false): string` (`ok|skip|failed|unmapped`):
- Hitung harga efektif listing (via produk komponen tunggal); bundle/tak-terpetakan/harga-null → tak push (`unmapped`/`skip`).
- Kalau `!force` & `last_pushed_price == harga` → `skip`.
- Panggil `updatePrice` channel; catat `last_pushed_price/last_price_status/last_price_error/last_price_pushed_at`.
`pushProduct` (SUDAH ADA, Fase 1) diperluas: push **stok DAN harga** untuk tiap listing produk. `pushDirty` (cron) & `pushAll` juga push dua-duanya (diff terpisah: stok pakai `last_pushed_qty`, harga pakai `last_pushed_price`). Cron `marketplace:push-stock` yang ada dipakai ulang (retry harga yg gagal ikut ke sini).

### 6.5 Manual
Set harga (Master/override/ikut-master) → langsung `pushProduct` (push stok+harga). Tombol "Sinkron"/"Sinkron semua" yg ada jadi sinkron stok+harga.

## 7. UI (gabung di halaman Stok)

Halaman **Stok Master / Stok TikTok / Stok Shopee** (Fase 1.5) ditambah kolom **Harga**:
- **Stok Master**: kolom Harga Master (input, Simpan) di samping Pool Stok; status per channel ringkas (Ikut Master / Override) untuk stok & harga.
- **Stok TikTok / Shopee**: kolom Harga efektif channel + input set **override harga** + tombol **Ikut Master (harga)**; tampilkan harga terkirim + status.
- Judul halaman jadi **"Stok & Harga — Master/TikTok/Shopee"**. Menu sidebar tetap (grup "Stok Marketplace"; label item boleh tetap "Stok Master" dst — isinya kini stok+harga).
- Native input angka; tanpa `@json([...])` literal; form POST + `@csrf`.

## 8. Rute & izin

Grup `permission:manage_marketplace_stock` (SUDAH ADA — harga bagian dari kontrol yang sama), tambah:
- `POST /marketplace-stock/harga/{product}` → set harga Master
- `POST /marketplace-stock/{channel}/harga/{product}` → set override harga channel
- `POST /marketplace-stock/{channel}/harga-ikut-master/{product}` → clear override harga

## 9. Client API (method baru)

- `ShopeeClient::updatePrice(accessToken, shopId, int itemId, int modelId, float price): array` → `POST /api/v2/product/update_price` body `{ item_id, price_list:[{ model_id, original_price: price }] }`.
- `TikTokClient::updatePrice(accessToken, shopCipher, string productId, string skuId, float price): array` → `POST /product/202309/products/{productId}/prices/update` body `{ skus:[{ id: skuId, price:{ amount:(string)price, currency:'IDR' } }] }`.
Verifikasi endpoint/format ke docs saat writing-plans (mis. Shopee butuh `original_price` sbg number; TikTok `amount` string + `currency`).

## 10. Anti-push & testing

- **Anti-push:** harga listing null (Master & override kosong) → tak push (mirror pengaman stok). Bundle ≥2 komponen → tak push harga (manual di Seller Center).
- Zero-dependency. **Tes PHPUnit class-style (no Pest); Product dibuat langsung.**
- Unit: `effectivePrice` (override>master>null). Feature: set harga Master → channel ikut-master dapat harga itu, channel override tak berubah; set override → hanya channel itu; Ikut Master → hapus override; push harga kirim payload benar per channel (Http fake); **stok tak terpengaruh perubahan harga & sebaliknya**; render halaman gabungan (stok+harga); kontrol akses.

## 11. Out of scope
Promo/diskon terjadwal, harga coret, strategi harga otomatis, harga per-varian granular di luar 1:1, bundle price otomatis.

## 12. Deploy
`git pull origin main` + `migrate --force` (tabel harga + kolom `last_pushed_price` dst di `marketplace_listings`) + `optimize:clear`. Tak perlu re-auth (scope Product sudah ada; `update_price` termasuk scope Product yang sama — verifikasi saat deploy).

## 13. Risiko & keterbukaan
- Harga bundle multi-komponen sengaja tak di-push (ambigu) — didokumentasikan; user atur manual.
- `update_price` mungkin butuh scope/format spesifik channel — verifikasi live saat deploy (sama seperti stok Fase 1).
- Menambah kolom status harga di `marketplace_listings` — additive, tak ganggu logika stok.
