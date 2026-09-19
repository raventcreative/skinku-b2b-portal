# Kontrol Stok Marketplace (Fase 1) — Cermin Stok TikTok ↔ Shopee

**Tanggal:** 2026-09-19
**Status:** Design (menunggu review user sebelum writing-plans)
**Modul:** Integrasi (SKINKU B2B Distributor Portal, Laravel 13 / PHP 8.3)

---

## 1. Ringkasan

Bikin **satu angka stok "marketplace" per produk** yang dijaga SKINKU supaya **selalu sama di TikTok dan Shopee**. Angka ini turun otomatis saat ada pesanan di channel mana pun, naik saat retur/batal, dan bisa di-set manual saat restock. Setiap perubahan didorong ke kedua channel lewat API.

**Masalah yang dipecahkan:** stok antar-channel sering beda karena update manual terlewat (mis. TikTok sudah habis → 0, tapi Shopee lupa di-nol-kan → order Shopee masuk padahal barang tak ada → tak bisa dikirim). Fitur ini menghilangkan selisih itu.

## 2. Tujuan & Non-Tujuan

**Tujuan (Fase 1):**
- Satu sumber angka "Stok Marketplace" per produk, **terpisah** dari stok internal.
- Dorong angka itu ke TikTok + Shopee agar keduanya identik.
- Turun otomatis saat order (TikTok/Shopee), naik saat retur/batal — pakai sinkron order yang **sudah** jalan.
- Set manual (restock) + tombol sinkron manual (per baris & massal).
- Nilai awal diambil dari TikTok (seed).

**Non-Tujuan (fase lain / eksplisit dikecualikan):**
- **TIDAK menyentuh Laporan Stok HQ / `stock_movements` / `InventoryService::adjustHqStock`.** Semantik stok HQ dibiarkan apa adanya. Menyambungkan "Stok Marketplace ↔ Stok HQ" = fase berikutnya.
- Tanpa ubah harga, status aktif listing, atau bikin listing baru (fase berikutnya).
- Tanpa buffer/stok cadangan (dikunci = 0 untuk Fase 1).
- Tanpa alokasi/split kuota per-channel (semua channel dapat angka penuh yang sama).
- Tanpa multi-gudang (pakai 1 gudang default per channel).

## 3. Keputusan desain (sudah disetujui user)

1. **Sumber master = angka "Stok Marketplace" tersendiri**, bukan stok HQ.
2. **Pemicu = otomatis + manual.** Otomatis via cron ~5 menit (diff) + potong/tambah saat order/retur. Manual via tombol.
3. **Seed awal dari TikTok.**
4. **Tanpa buffer** (0).
5. Laporan Stok HQ tidak diubah.

## 4. Istilah

- **Stok Marketplace (pool):** angka stok per produk internal (`products.id`) yang jadi master untuk marketplace. Disimpan di tabel baru `marketplace_stocks`.
- **Listing:** satu item jualan di sebuah channel, diidentifikasi `seller_sku` (kode SKU yang diketik penjual, mis. `FM-1`). Satu produk bisa punya listing di TikTok dan/atau Shopee.
- **Peta SKU:** tabel yang sudah ada (`tiktok_sku_maps`, `shopee_sku_maps`): `seller_sku → product_id + qty`. Sudah mendukung **bundle** (beberapa baris untuk satu `seller_sku` = beberapa komponen produk).
- **Siap jual (available) untuk sebuah listing:** `min` atas semua komponen dari `⌊ pool(product_id) ÷ qty ⌋`. Untuk listing 1:1 (satu komponen, qty 1), ini sama dengan `pool(product_id)`.

## 5. Arsitektur

```
Order TikTok/Shopee (sinkron yg sudah ada)
        │  (di titik potong-stok yg sama, TAMBAHAN — HQ tak diubah)
        ▼
  marketplace_stocks (pool per produk)  ◀── set manual (restock) / seed dari TikTok
        │
        ▼
  MarketplaceStockService  ── hitung "siap jual" per listing (pakai peta SKU + pool)
        │
        ├── cron `marketplace:push-stock` (tiap 5 mnt, diff: kirim yg berubah saja)
        └── manual (tombol Sinkron / Sinkron semua / set stok)
        │
        ▼
  ShopeeClient::updateStock / TikTokClient::updateStock  ── tulis ke API channel
        │
        ▼
  marketplace_listings (cache ID channel + status/hasil push terakhir)
```

Prinsip: **satu jalur push** (`MarketplaceStockService`). Order/seed/manual hanya mengubah `marketplace_stocks`; pengiriman ke channel dilakukan lewat service yang sama supaya penanganan error & idempotensi terpusat.

## 6. Model data (tabel baru — additive)

### 6.1 `marketplace_stocks` (pool per produk)
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| product_id | FK → products.id, **unique** | satu pool per produk |
| quantity | int | angka stok marketplace (master) |
| seeded_at | timestamp, nullable | patokan "titik reconcile": order dengan `order_created_at` sebelum ini TAK mengurangi pool (hindari dobel-hitung histori saat seed/set manual) |
| created_at / updated_at | timestamp | |

Terpisah total dari `stock_movements`/`inventory`.

### 6.2 `marketplace_listings` (target push + status, per listing per channel)
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| channel | string | `tiktok` / `shopee` |
| seller_sku | string | join ke peta SKU |
| item_id | string | Shopee: `item_id`; TikTok: `product_id` |
| variation_id | string, nullable | Shopee: `model_id` (0 jika tanpa varian); TikTok: `sku_id` |
| warehouse_id | string, nullable | TikTok: warehouse; Shopee: null (seller stock) |
| title | string, nullable | untuk tampilan |
| last_pushed_qty | int, nullable | angka terakhir yang berhasil dikirim |
| last_status | string, nullable | `ok` / `failed` / `unmapped` |
| last_error | text, nullable | pesan error terakhir |
| last_pushed_at | timestamp, nullable | |
| resolved_at | timestamp, nullable | kapan ID channel terakhir di-resolve |
| created_at / updated_at | timestamp | |

Indeks unik: `(channel, seller_sku)`.

## 7. Alur

### 7.1 Resolve listing (dapatkan ID channel)
Karena API tulis butuh ID internal channel (bukan teks SKU), ada langkah resolve:
- **Shopee:** `get_item_list` → daftar `item_id`; `get_item_base_info` → info + `item_sku` + `has_model`; `get_model_list` → `model_id` + `model_sku`. Cocokkan `model_sku`/`item_sku` == `seller_sku` di peta → isi `item_id` + `variation_id`.
- **TikTok:** ambil daftar produk (products search) → tiap produk punya `product_id` + `skus[]` (`seller_sku`, `id`). Cocokkan `seller_sku` → isi `item_id`(=product_id) + `variation_id`(=sku_id). `warehouse_id` dari daftar warehouse (ambil gudang default pertama).
- Listing di peta SKU yang tak ketemu ID-nya → simpan `last_status='unmapped'`, tampilkan sebagai "belum terpetakan" di UI.
- Dijalankan: tombol "Refresh listing" di UI + command terjadwal harian.

### 7.2 Seed dari TikTok (sekali / bisa diulang)
- Baca stok TikTok saat ini per `seller_sku` (via API produk TikTok).
- Untuk listing TikTok **1:1** (satu komponen, qty 1): set `marketplace_stocks[product_id].quantity = stok_tiktok`.
- Listing bundle / multi-komponen dilewati (dilaporkan) — di-set manual.
- Setelah seed, jalankan push → Shopee ikut menyamakan.
- Aksi via tombol "Tarik stok awal dari TikTok".

### 7.3 Cermin otomatis saat order MASUK (bukan saat dikirim; HQ tak diubah)
Pool berkurang saat **pesanan masuk** (bukan saat barang dikirim), supaya channel lain cepat ikut turun sebelum sempat oversell — sesuai maksud "turun saat ada pesanan". Ini SENGAJA terpisah dari potong stok HQ (yang berbasis "dikirim/`deduct`") — HQ tak disentuh.
- Di `TikTokOrderService::store()` & `ShopeeOrderService::store()` (titik ingest order dari sinkron), setelah upsert tiap order:
  - **Order BARU** (`$existing === null`), status bukan batal, dan `order_created_at >= marketplace_stocks.seeded_at` produk komponennya → `MarketplaceStockService::adjustPool($product, -(order_qty × map.qty))` untuk tiap komponen.
  - **Transisi ke BATAL** (status lama ter-hitung → status baru batal) → `adjustPool(+…)` (kembalikan).
- **Guard dobel-hitung:** hanya order baru yang dikurangi; re-sync (existing) tak mengurangi lagi. `seeded_at` mencegah order histori (sebelum titik seed/set) ikut mengurangi.
- **Best-effort:** dibungkus try/catch + log — kegagalan mirror TAK BOLEH menggagalkan sinkron order inti.
- **Retur** (restock) di Fase 1 lewat **set manual** (aman: paling banter under-sell, tak pernah oversell). Auto retur→pool = fase lanjut.
- Perubahan pool **tidak** langsung push (biar ingest tak ke-block API); push oleh cron 5 menit berikutnya / manual.

### 7.4 Cron push (diff)
- Command `marketplace:push-stock`, terjadwal tiap 5 menit `withoutOverlapping`.
- Untuk tiap `marketplace_listings` yang sudah ter-resolve: hitung `available`; jika `available != last_pushed_qty` → panggil `updateStock` channel terkait → catat `last_pushed_qty/last_status/last_error/last_pushed_at`.
- Listing dengan `available` null (belum dipetakan / pool belum di-set/di-seed) **dilewati** — TIDAK dikirim (mencegah tak sengaja mengirim 0 dan mengosongkan listing yang masih aktif sebelum seed).
- Gagal di satu listing tak menghentikan yang lain.

### 7.5 Manual
- **Set stok:** ubah angka pool per produk di UI → simpan → langsung `pushProduct` (kirim semua listing produk itu) tanpa nunggu cron.
- **Sinkron (per baris):** paksa push satu produk sekarang.
- **Sinkron semua:** paksa push semua listing ter-resolve.

## 8. Client API (method baru)

Pakai `ShopeeClient::shopCall()` & `TikTokClient::request()` yang sudah bertanda tangan.

- `ShopeeClient::updateStock(string $accessToken, string $shopId, int $itemId, int $modelId, int $qty): array`
  → `POST /api/v2/product/update_stock` body `{ item_id, stock_list: [{ model_id, seller_stock: [{ stock: qty }] }] }`.
- `ShopeeClient::getItemList(...)`, `getItemBaseInfo(...)` (sudah ada), `getModelList(...)` → untuk resolve.
- `TikTokClient::updateStock(string $accessToken, string $shopCipher, string $productId, string $skuId, string $warehouseId, int $qty): array`
  → `POST /product/202309/products/{productId}/inventory/update` body `{ skus: [{ id: skuId, inventory: [{ warehouse_id, quantity: qty }] }] }`.
- `TikTokClient::getProducts(...)` (search) + `getWarehouses(...)` → untuk resolve.

Endpoint & versi pasti dikonfirmasi saat writing-plans terhadap docs channel.

## 9. Service

`App\Services\MarketplaceStockService`:
- `availableForListing(MarketplaceListing $l): ?int` — null jika `seller_sku` tak ada di peta **atau** ada komponen yang produknya belum punya baris `marketplace_stocks` (pool belum di-set/di-seed). `pushListing` **melewati pengiriman** saat `available` null (pengaman anti-menol-kan; lihat §12).
- `adjustPool(Product $product, int $delta): void` — ubah `marketplace_stocks` (clamp ≥ 0); buat baris bila belum ada.
- `setPool(Product $product, int $qty): void` — set manual + stamp `seeded_at = now()` (jadi patokan reconcile baru).
- `pushListing(MarketplaceListing $l, bool $force = false): array` — hitung available; kirim bila `force` atau beda dari `last_pushed_qty`; catat hasil.
- `pushProduct(Product $product, bool $force = true): array` — push semua listing yang komponennya memuat produk ini.
- `pushDirty(): array` — dipakai cron (kirim yang berubah saja).
- `pushAll(): array` — paksa semua (manual "Sinkron semua").
- `resolveListings(string $channel): array` — isi/segarkan ID channel di `marketplace_listings`.
- `seedFromTiktok(): array` — set pool dari stok TikTok (listing 1:1) + stamp `seeded_at = now()`, lalu push.

## 10. UI

Menu **Integrasi → "Stok Marketplace"**. Controller `MarketplaceStockController`, view `resources/views/marketplace-stock/index.blade.php` (pola & gaya mengikuti halaman Integrasi TikTok/Shopee yang ada).

**Isi halaman:**
- Tombol: **Tarik stok awal dari TikTok**, **Refresh listing**, **Sinkron semua**.
- Tabel 1 baris per produk yang punya ≥1 listing terpetakan:
  - Produk (nama + SKU internal)
  - **Stok Marketplace** (input angka, bisa diedit → simpan)
  - Kolom **TikTok**: seller_sku + angka terkirim + status/waktu (atau "belum terpetakan")
  - Kolom **Shopee**: idem
  - Aksi: **Sinkron** (per baris)
- Bagian "Listing belum terpetakan" (dari `last_status='unmapped'`) + tautan ke UI peta SKU yang sudah ada (`/tiktok/sku-map`, `/shopee/sku-map`).
- Interaksi AJAX ringan (pola inline vanilla JS seperti modul lain), tanpa dependency.

## 11. Rute & izin

Grup baru `Route::middleware('permission:manage_marketplace_stock')`, prefix `/marketplace-stock`, nama `marketplace-stock.*`:
- `GET /marketplace-stock` → index
- `POST /marketplace-stock/set/{product}` → set stok manual
- `POST /marketplace-stock/push/{product}` → sinkron per baris
- `POST /marketplace-stock/push-all` → sinkron semua
- `POST /marketplace-stock/resolve` → refresh listing
- `POST /marketplace-stock/seed-tiktok` → seed

**Izin baru** `manage_marketplace_stock` didaftarkan mengikuti pola permission yang ada (mis. `manage_tiktok`) dan diberikan ke role yang sekarang punya `manage_tiktok` (super_admin + staf terkait). Item menu sidebar "Stok Marketplace" tampil di grup Integrasi, di-gate izin ini.

## 12. Anti-oversell & penanganan error (jujur)

- **Bukan 0% oversell, tapi jauh berkurang.** Pool turun saat order MASUK (secepat sinkron order menariknya) lalu didorong ke channel lain oleh cron ≤5 menit → total jeda ≈ interval sinkron order + ≤5 menit; jauh lebih cepat daripada berbasis "dikirim". Set manual instan untuk kasus mendesak. Alokasi/split kuota per-channel = opsi masa depan bila perlu lebih ketat.
- Pool tak boleh negatif (clamp ke 0).
- Kegagalan push per listing dicatat (`last_error`) dan ditampilkan; tak mengganggu listing lain; dicoba lagi otomatis di run cron berikutnya (karena `available` masih beda dari `last_pushed_qty`).
- **Pengaman seed (penting):** sebelum pool sebuah produk di-set/di-seed, listing-nya **tak pernah** di-push (`available` null → dilewati). Jadi tak ada risiko tak sengaja mengirim `0` dan mengosongkan listing yang masih aktif. Produk yang hanya ada di Shopee (tak ke-seed dari TikTok) di-set manual dulu sebelum ikut ter-sinkron.
- **Izin scope:** butuh re-authorize app dengan **scope Product** (TikTok = app Shop utama, bukan Affiliate). Sebelum itu, API tulis akan ditolak channel → tampil sebagai `failed` dengan pesan jelas. Panduan setel di luar spec ini (operasional).

## 13. Zero-dependency

Tanpa composer package baru. Reuse `ShopeeClient`/`TikTokClient`, peta SKU + UI-nya, sinkron order yang ada. HTTP pakai Laravel Http yang sudah dipakai client.

## 14. Testing

Runner lokal: `C:\php83\php.exe artisan test`; `C:\php83\php.exe vendor/bin/pint --dirty` sebelum commit.
- Unit: `availableForListing` — 1:1 (pool langsung) & bundle (min atas komponen); clamp negatif.
- Feature: cron push hanya mengirim yang berubah (diff); set manual → push langsung; order TikTok & Shopee → pool turun (dan HQ **tetap** seperti sebelumnya, dites bahwa `stock_movements` tak berubah perilakunya); retur/batal → pool naik; listing unmapped ditampilkan benar; kontrol akses (`manage_marketplace_stock`).
- Semua panggilan API channel di-fake/mock (tanpa jaringan) — cek payload `updateStock` benar (item_id/model_id/qty; product_id/sku_id/warehouse_id/qty).

## 15. Deploy

Standar: `git pull origin main && php artisan migrate --force && php artisan optimize:clear` (ada migrasi tabel baru + izin). Tambahkan `marketplace:push-stock` ke schedule; pastikan cron Laravel jalan. Re-authorize app (scope Product) sebelum push benar-benar berfungsi.

## 16. Risiko & keterbukaan

- Bergantung pada **ketepatan peta SKU** (listing↔produk). Kalau salah petakan, angka yang didorong bisa salah. UI menonjolkan "belum terpetakan".
- Bergantung pada **scope izin channel** (di luar kendali kode).
- Latensi cermin ≤5 menit (disengaja, disetujui).
- Seed hanya menangani listing 1:1; bundle di-set manual (dilaporkan saat seed).
