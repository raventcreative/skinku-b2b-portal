# Design: Backdate Gerakan Stok Marketplace (TikTok & Shopee)

**Tanggal:** 2026-08-11
**Branch:** `fix/stok-backdate-marketplace` (dari main)
**Status:** Design (disetujui) → lanjut ke writing-plans

## Tujuan

Gerakan stok-keluar HQ dari penjualan **TikTok** & **Shopee** harus tercatat pada
**tanggal order (`order_created_at`)**, bukan pada tanggal operator klik "Potong Stok"
(`now()`). Dengan begitu **Laporan Mutasi Stok HQ** (harian/bulanan) menaruh penjualan
marketplace di hari transaksi yang benar.

Ini kelanjutan dari fix Produksi & Penerimaan (commit `96bf16c`/`f7e1413`, plan
`2026-08-11-fix-stok-backdate-harga-grand.md`) yang sudah memakai pola `occurredAt`.

## Latar Belakang / Akar Masalah

- `TikTokOrderService::deduct()` ([app/Services/TikTokOrderService.php:242](../../../app/Services/TikTokOrderService.php)) dan
  `ShopeeOrderService::deduct()` ([app/Services/ShopeeOrderService.php:207](../../../app/Services/ShopeeOrderService.php))
  memanggil `InventoryService::adjustHqStock(...)` **tanpa** argumen `occurredAt`.
- `InventoryService::writeMovement()` ([app/Services/InventoryService.php:266](../../../app/Services/InventoryService.php))
  men-default `created_at = $occurredAt ?? now()`. Tanpa `occurredAt`, gerakan dicap `now()`.
- `HqStockReportService` mengelompokkan mutasi HQ **by `stock_movements.created_at`**
  (`whereBetween` / `where >=`). Jadi penjualan tampil di hari potong, bukan hari order.

**Fakta kode terverifikasi:**
- `order_created_at` (cast `datetime`) ada di `TiktokOrder` & `ShopeeOrder`, diisi dari
  `create_time` API. Ini satu-satunya tanggal level-order yang tersimpan, dan sudah jadi
  acuan `isBeforeCutoff()` (guard opname).
- Penulis gerakan `tiktok_order`/`shopee_order` HANYA `deduct()` (OUT) & `reverse()` (IN).
  Retur pelanggan memakai alur terpisah `tiktok_return` (`TikTokReturnService`) — di luar lingkup.
- Di `HqStockReportService::bucketize()`, `tiktok_order` → kolom **tiktok** (`+= -delta`),
  `shopee_order` → kolom **shopee**. `tiktok_return` TIDAK masuk kolom mana pun yang khusus
  (jatuh ke `masuk_lain`) — menegaskan retur bukan bagian fix ini.
- Kedua tabel koneksi (`tiktok_connections`, `shopee_connections`) punya kolom `deduct_from`
  (cast `date`) = titik-nol opname per-platform.

## Keputusan Desain (dikonfirmasi user)

1. **Tanggal = `order_created_at`.** Tanggal order/penjualan; satu-satunya tanggal
   level-order & konsisten dgn acuan cutoff. Bila `null` → fallback `now()` (perilaku lama, tanpa regresi).
2. **Kaki `reverse()` (undo) ikut di-backdate → net-nol.** `reverse()` juga meneruskan
   `occurredAt = order_created_at`, sehingga pasangan potong+batal **saling meniadakan di
   tanggal order** (bukan meninggalkan "penjualan" di hari order + "minus penjualan" di hari
   klik-batal). `reverse()` = "batalkan pemotongan / seolah tak pernah terjual"; retur
   pelanggan nyata tetap lewat alur `tiktok_return`.
3. **Backfill data lama (migrasi `000079`).** Betulkan gerakan marketplace existing supaya
   laporan historis langsung akurat. Idempoten, satu-arah, lewati order tanpa tanggal.
4. **Clamp ke `deduct_from` (khusus backfill).** Setiap tanggal hasil backfill di-*floor* ke
   `max(order_created_at, deduct_from.startOfDay())` per-platform, supaya tak ada gerakan
   marketplace yang mendarat SEBELUM titik-nol opname (mis. bila `deduct_from` pernah digeser
   maju setelah order dipotong). Saldo tak terpengaruh (running balance netral terhadap tanggal).

## Arsitektur

Tiga bagian, meniru pola fix produksi/penerimaan:

### A. Forward fix (kode service)
Tambah `occurredAt: $order->order_created_at` pada 4 pemanggilan `adjustHqStock`:
- `TikTokOrderService::deduct()` (OUT) & `reverse()` (IN)
- `ShopeeOrderService::deduct()` (OUT) & `reverse()` (IN)

**Forward TIDAK di-clamp.** Guard `isBeforeCutoff()` sudah menjamin order yang dipotong
punya `order_created_at >= deduct_from` saat pemotongan, jadi gerakan forward inheren
`>= deduct_from`. Clamp hanya jaring pengaman untuk data historis (di backfill). Edge langka
(cutoff di-set/digeser SETELAH pemotongan) tertangani ulang bila backfill dijalankan lagi.

### B. Backfill (support class + migrasi 000079)
`App\Support\MarketplaceMovementDateBackfill::run()`:
- Untuk tiap `tiktok_orders` (lalu `shopee_orders`) dgn `order_created_at` tidak null:
  - `date = Carbon(order_created_at)`; bila `cutoff && date < cutoff` → `date = cutoff`.
  - `UPDATE stock_movements SET created_at = date WHERE reference_type=? AND reference_id=order.id`
    (mengenai KEDUA kaki OUT & IN — sesuai keputusan #2).
- `cutoff` per-platform = `deduct_from` koneksi terbaru → `startOfDay()`, atau null.
- Pure `DB::table` (portabel SQLite/MySQL, aman dijalankan dalam migrasi; tanpa Eloquent).

### C. Migrasi `2026_01_01_000079_backfill_marketplace_movement_dates.php`
`up()` panggil `MarketplaceMovementDateBackfill::run()`. `down()` no-op (koreksi satu-arah;
timestamp `now()` asli tak disimpan).

## Aliran Data

```
Order (order_created_at) ──▶ deduct()/reverse()
                                   │ occurredAt = order_created_at
                                   ▼
              InventoryService::adjustHqStock(occurredAt)
                                   │ writeMovement(created_at = occurredAt ?? now())
                                   ▼
                    stock_movements.created_at = tgl order
                                   │
                                   ▼
     HqStockReportService (bucket by created_at) → penjualan di hari order ✔
```

Backfill menulis ulang `stock_movements.created_at` untuk baris marketplace yang sudah ada.

## Komponen (file)

| File | Aksi | Tanggung jawab |
|---|---|---|
| `app/Services/TikTokOrderService.php` | modify | `occurredAt` di `deduct()` (~b.242) & `reverse()` (~b.303) |
| `app/Services/ShopeeOrderService.php` | modify | `occurredAt` di `deduct()` (~b.207) & `reverse()` (~b.269) |
| `app/Support/MarketplaceMovementDateBackfill.php` | create | backfill created_at + clamp per-platform |
| `database/migrations/2026_01_01_000079_backfill_marketplace_movement_dates.php` | create | panggil `run()` |
| `tests/Feature/MarketplaceBackdateMovementTest.php` | create | forward fix (deduct+reverse, TikTok+Shopee, fallback null, level-laporan) |
| `tests/Feature/MarketplaceMovementDateBackfillTest.php` | create | backfill (kedua kaki, clamp, null, idempoten) |

## Edge Case & Penanganan Error

- **`order_created_at` null:** forward → `occurredAt` null → fallback `now()`. Backfill → order dilewati (gerakan dibiarkan).
- **Clamp:** hanya di backfill; forward mengandalkan guard. Floor = `deduct_from.startOfDay()`.
- **Idempoten:** backfill men-set nilai deterministik; jalan berkali-kali hasil sama. Migrasi via `RefreshDatabase` di test = no-op (tak ada order).
- **Net-nol reversal:** potong+batal keduanya di `order_created_at` → kolom tiktok/shopee net 0 di hari order.
- **Tanpa dampak akuntansi:** hanya `stock_movements.created_at` yang bergerak. `deducted_at`
  (audit "kapan diklik") dan `acc_journals` (didorong oleh settlement/delivery) TIDAK disentuh.
- **Saldo tak berubah:** yang bergeser hanya TANGGAL, bukan kuantitas. `HqStockReportService`
  menurunkan saldo dari stok sekarang − Σdelta setelah batas → selalu balance.

## Strategi Test (TDD)

**MarketplaceBackdateMovementTest** (Red dulu — sekarang gerakan bercap `now()`):
- TikTok `deduct`: order `order_created_at=2026-08-05`, `setTestNow(2026-08-11)` → gerakan `created_at` = 2026-08-05; saldo benar.
- TikTok `reverse`: setelah potong+batal, kaki IN juga bertanggal 2026-08-05 (net-nol).
- Shopee `deduct` & `reverse`: idem.
- Fallback: `order_created_at` null → gerakan bertanggal `now()` (tak crash).
- Level-laporan: penjualan TikTok muncul di laporan harian 2026-08-05, bukan 08-11.

**MarketplaceMovementDateBackfillTest** (Red dulu — class belum ada):
- Gerakan `tiktok_order`/`shopee_order` salah-tanggal `now()` → setelah `run()` = `order_created_at`.
- Kedua kaki (OUT+IN order yang pernah dibatalkan) → keduanya ke `order_created_at`.
- Clamp: order `order_created_at` < `deduct_from` → gerakan di-floor ke `deduct_from`.
- Null: order tanpa `order_created_at` → gerakan tak berubah.
- Idempoten: `run()` dua kali → hasil sama.

Runner: `C:\php83\php.exe artisan test`. Regresi penuh sebelum finishing.

## Deploy

Ada 1 migrasi baru (`000079`). Prosedur prod:
```
git pull origin main
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
```
Verifikasi: buka Laporan Stok HQ, cek penjualan TikTok/Shopee kini muncul di tanggal order
(bukan tanggal potong).

## Di Luar Lingkup

- Retur pelanggan (`tiktok_return`) — alur & tanggal review terpisah; tak masuk kolom tiktok/shopee.
- Pembukuan/jurnal (`acc_journals`) — tak berubah.
- `deduct_from`/logika opname itu sendiri — dipakai apa adanya.
- Clamp pada forward path — sengaja tidak (guard sudah menjamin), agar perubahan pada
  kode live deduct/reverse seminimal mungkin.

## Self-Review

1. **Placeholder:** tak ada TBD; `down()` no-op diberi alasan (koreksi satu-arah).
2. **Konsistensi:** `occurredAt: $order->order_created_at` cocok signature `?\DateTimeInterface`
   (Carbon implements-nya; null → fallback). `reference_type` `'tiktok_order'`/`'shopee_order'`
   cocok bucketize & backfill. Clamp pakai `deduct_from` yg sama dgn `cutoff()` service.
3. **Cakupan:** forward (4 titik) + backfill (kedua kaki) + clamp + fallback null + tanpa
   dampak saldo/akuntansi — semua tercakup. Migrasi `000079` unik (terakhir `000078`).
4. **Ambiguitas:** "tanggal" = `order_created_at` (bukan ship/delivery/settlement) — eksplisit.
   Clamp hanya backfill — eksplisit.
