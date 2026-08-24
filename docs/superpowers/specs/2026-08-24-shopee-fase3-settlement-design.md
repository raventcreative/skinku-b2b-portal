# Integrasi Shopee — Fase 3 (Settlement / Pencairan) — Design

**Tanggal:** 2026-08-24
**Status:** Disetujui user (pilih **escrow per-order** — "yg B aja biar valid")
**Referensi:** `docs/superpowers/research/shopee-payment-api-map.md` (peta API pembayaran Shopee) + `docs/superpowers/research/tiktok-fase2-4-map.md` §3.2 (peran TikTokSettlementService yang ditiru)

## Goal

Tarik **data penyelesaian (escrow) per order** dari Shopee → simpan **rincian income + fee yang PASTI per order** (komisi, layanan, iklan/campaign, ongkir, pajak, penyesuaian, net cair) → tampil. Ini menyediakan angka keuangan **valid & akurat** per order (bukan lump kira-kira), jadi fondasi jurnal Fase 4.

**Kenapa escrow, bukan wallet-transaction:** riset menemukan toko lokal Shopee TAK punya "statement" agregat seperti TikTok. Dua sumber tersedia: wallet-transaction (gerakan saldo, kasar) & escrow per-order (rincian fee eksplisit). User memilih **escrow** karena paling valid — Shopee memberi angka fee pasti per order (TikTok tak bisa: per-order-nya tanpa fee, makanya TikTok pakai heuristik `kind`). Shopee > TikTok di sini.

## Arsitektur

Meniru **peran** `TikTokSettlementService` (simpan data pencairan → dipakai jurnal Fase 4), tapi **strukturnya per-order escrow** (bukan per-statement + kind-guess). Reuse pola sync/paginasi/token Shopee Fase 1-2. Zero-dependency.

## Global Constraints

- **Zero-dependency**: tanpa composer/npm baru; HTTP pakai `ShopeeClient` (`shopCall`).
- **Simpan saja, BELUM jurnal**: Fase 3 hanya store + tampil. Jurnal (posting ke GL) = Fase 4. Kolom `posting_status`/`journal_id`/`posted_at`/`posted_by` disiapkan tapi belum dipakai.
- **Field defensif + verifikasi sandbox**: escrow punya ~90 field; petakan yang relevan-ID ke kolom, sisanya di `raw`. Nama field bisa beda dari asumsi riset (sumber = SDK pihak-3, bukan docs resmi) → verifikasi ke sandbox saat build.
- **Idempoten**: `updateOrCreate` by `order_sn`; jangan reset `posting_status` bila row sudah ada.
- **Deploy = git pull**: 1 migrasi baru `000094`.

## Yang SUDAH ADA (reuse)

| Aset | Dipakai untuk |
|---|---|
| `ShopeeClient::shopCall` | semua endpoint escrow (shop-level, tanda tangan + access_token + shop_id) |
| `ShopeeConnection` + `ShopeeSyncService::freshToken` | token 4 jam auto-refresh |
| `ShopeeSyncService` pola paginasi + command `shopee:sync` | tambah `syncSettlements` + opsi `--settlements` |
| `ShopeeOrder` (`order_sn`) | join balik ke order (opsional di UI) |
| izin `manage_shopee` + menu Integrasi Shopee | route settlement masuk grup yang sama |

## Yang DIBANGUN (Fase 3)

### 1. Model `ShopeeSettlement` + migrasi `000094`

Tabel `shopee_settlements` — **1 baris per order** (escrow release):

| kolom | tipe | catatan |
|---|---|---|
| `order_sn` | string, unique | kunci join ke `shopee_orders` |
| `currency` | string(8), nullable | |
| `escrow_amount` | decimal(16,2), default 0 | **net yang diterima seller** (setelah semua fee) |
| `buyer_total_amount` | decimal(16,2), default 0 | total bayar pembeli (bruto) |
| `commission_fee` | decimal(16,2), default 0 | komisi Shopee |
| `service_fee` | decimal(16,2), default 0 | biaya layanan |
| `campaign_fee` | decimal(16,2), default 0 | biaya campaign/iklan platform |
| `seller_transaction_fee` | decimal(16,2), default 0 | biaya transaksi seller |
| `actual_shipping_fee` | decimal(16,2), default 0 | ongkir aktual |
| `buyer_paid_shipping_fee` | decimal(16,2), default 0 | ongkir dibayar pembeli |
| `shopee_shipping_rebate` | decimal(16,2), default 0 | subsidi ongkir Shopee |
| `escrow_tax` | decimal(16,2), default 0 | pajak (ID cross-border/PPN) |
| `withholding_tax` | decimal(16,2), default 0 | pajak dipotong (regulasi ID) |
| `total_adjustment_amount` | decimal(16,2), default 0 | total penyesuaian |
| `escrow_release_time` | dateTime, nullable | kapan escrow dirilis (**dateTime**, anti-2038) |
| `raw` | json, nullable | respons escrow mentah penuh (~90 field) — untuk remap nanti |
| `posting_status` | string(20), default `pending`, indexed | `pending`/`posted` (dipakai Fase 4) |
| `journal_id` | unsignedBigInteger, nullable | → `acc_journals.id` (Fase 4) |
| `posted_at` | dateTime, nullable | |
| `posted_by` | FK `users`, nullable, `nullOnDelete` | |
| timestamps | | |

Konstanta: `POST_PENDING='pending'`, `POST_POSTED='posted'`. `$casts`: semua amount `decimal:2`, `raw`→array, `escrow_release_time`/`posted_at`→datetime. Helper `isPosted(): bool`. `fee_total()` (Σ semua fee) untuk tampilan.

### 2. `ShopeeClient` — +2 method escrow

```php
/** Daftar order yang escrow-nya sudah dirilis dalam rentang waktu (discovery ringan). */
public function getEscrowList(string $accessToken, string $shopId, int $releaseFrom, int $releaseTo, int $pageNo = 1, int $pageSize = 100): array
// GET /api/v2/payment/get_escrow_list — params: release_time_from/to, page_no, page_size

/** Rincian income/fee untuk ≤50 order sekaligus. */
public function getEscrowDetailBatch(string $accessToken, string $shopId, array $orderSns): array
// POST /api/v2/payment/get_escrow_detail_batch — body: order_sn_list (≤50)
```

Pakai `shopCall`. `getEscrowDetailBatch` = POST (order_sn_list). Field respons dipetakan **defensif** di service + verifikasi sandbox.

### 3. `ShopeeSettlementService` — store + peta field

- `store(array $apiEscrowDetails): int` — `updateOrCreate` by `order_sn`; map `order_income.*` → kolom (defensif, `?? 0`); `raw` = detail penuh; **tak reset `posting_status`** bila row ada. Return jumlah.
- `mapIncome(array $orderIncome): array` — helper petakan ~90 field → kolom kita (yang relevan-ID), sisanya diabaikan (ada di `raw`).
- `feeTotal(ShopeeSettlement $s): float` — Σ fee (untuk tampilan/verifikasi net = buyer_total − fee).

Tak ada `kind` (tak perlu — tiap baris = 1 order penjualan, fee sudah jadi kolom eksplisit; TikTok butuh `kind` karena datanya lump, Shopee tidak).

### 4. `ShopeeSyncService::syncSettlements(ShopeeConnection $conn): array`

`freshToken` → `getEscrowList(releaseFrom=now-14d, releaseTo=now)` (paginasi `page_no`, guard 40 halaman + `Log::warning`) → kumpulkan `order_sn` → **chunk per ≤50** → `getEscrowDetailBatch` tiap chunk → kumpulkan detail → `ShopeeSettlementService::store`. Return `['count'=>int]` (+ `keys` diagnostik dari halaman pertama, mirror TikTok `syncSettlements` untuk deteksi perubahan bentuk respons).

### 5. `ShopeeSyncCommand` — opsi `--settlements`

Tambah `{--settlements : Sekalian tarik pencairan/escrow}`. Blok terpisah try/catch (satu gagal tak gagalkan order/retur). **Cron**: `shopee:sync --settlements` harian (mis. `01:30`, hemat kuota — escrow jarang berubah setelah rilis).

### 6. `ShopeeController` — aksi settlement

- `settlementList()` — `ShopeeSettlement::latest('escrow_release_time')->latest('id')->paginate(25)` → render `shopee.settlements`.
- `syncSettlements(Request)` — `$sync->syncSettlements($conn)` → redirect + jumlah (hint bila 0: mungkin belum ada order released / cek scan window).
- `settlementDetail(ShopeeSettlement)` — tampil rincian fee + dump `raw` (untuk verifikasi bentuk field asli, mirror TikTok settlement_detail).

Audit log `shopee_sync_settlements`. Inject `ShopeeSettlementService` (bila perlu) — utama lewat `ShopeeSyncService`.

### 7. Route + View

Route grup `permission:manage_shopee` (mirror TikTok settlement):
- `GET /shopee/settlements` → `shopee.settlements`
- `POST /shopee/settlements/sync` → `shopee.settlements.sync`
- `GET /shopee/settlements/{settlement}/detail` → `shopee.settlements.detail`

View `resources/views/shopee/settlements.blade.php` (mirror `tiktok/settlements.blade.php`, gaya Tailwind Shopee Fase 1-2): tabel per-order (order_sn, tanggal rilis, buyer_total, total fee, **net escrow**), tombol sync, link detail. `settlement_detail.blade.php`: kartu ringkasan (net/fee breakdown) + dump `raw` JSON. **TANPA UI jurnal** (itu Fase 4). Link "Pencairan" di `shopee/index.blade.php`.

### 8. Tests (mirror pola TikTok settlement)

- `store` map field escrow → kolom benar (fee positif, net = escrow_amount); idempoten (`posting_status` tak reset).
- `syncSettlements` dengan ShopeeClient fake (getEscrowList 1 halaman + getEscrowDetailBatch balikin detail) → row tersimpan + jumlah benar; chunk ≤50 dipatuhi.
- `settlementList`/`settlementDetail` render + `manage_shopee` (RESELLER → 403).

## Alur data

1. **Sync** — cron `shopee:sync --settlements` (harian) / tombol → `getEscrowList` (order released) → `getEscrowDetailBatch` (rincian fee) → `store` → `shopee_settlements` (`posting_status=pending`).
2. **Tampil** — daftar pencairan per-order + rincian fee (komisi/iklan/ongkir/pajak) + net cair. Detail = dump `raw` untuk audit.
3. **Fase 4** — jurnal baca `shopee_settlements` (income+fee) + tambah wallet cash-out (Saldo Shopee → Kas).

## Error handling

- **Token 4 jam** → `freshToken` sebelum sync.
- **Batch ≤50** → chunk `order_sn_list`; per chunk try/catch (satu chunk gagal tak batalkan semua) + `Log::warning`.
- **Page cap 40** → `Log::warning`.
- **Bentuk field beda** → map defensif (`?? 0`), `raw` simpan penuh; diagnostik `keys` bila 0 row; koreksi ke bentuk sandbox saat build.
- **Escrow belum rilis** (sandbox) → getEscrowList 0 order → 0 settlement (wajar; sign tetap terverifikasi).

## Di luar scope Fase 3 (Fase 4)

- **Wallet cash-out** (`get_wallet_transaction_list`: WITHDRAWAL_COMPLETED, PAID_ADS_CHARGE non-order, adjustment) — untuk "duit cair ke bank" + potongan non-order.
- **Model kas 2-tahap** (Saldo Shopee → Kas Bank).
- **Jurnal akuntansi** (`ShopeeAccountingService` niru `TikTokAccountingService`; Kas Shopee `1001` sudah seed, mint Piutang/Saldo Shopee).

## Deploy

- 1 migrasi baru (`000094` `create_shopee_settlements_table`) → `git pull` + `migrate --force` + `optimize:clear`.
- Cron `shopee:sync --settlements` masuk scheduler.

## Verifikasi sandbox — SUDAH DIPROBE 2026-08-24 ✅

Probe langsung ke sandbox (via `shopCall` generik) SUDAH dilakukan:
- `get_escrow_list` (14 hari): **sign diterima**, `escrow_list: []` (order test belum "released" — wajar).
- `get_escrow_detail` (order **`2608247FYHUBMG`**): **balik data ASLI** — nama field `order_income` **PERSIS** asumsi riset (SDK pihak-3 valid). Nilai nyata:
  `escrow_amount=64675`, `buyer_total_amount=77665`, `actual_shipping_fee=11765`, `buyer_paid_shipping_fee=11765`, `buyer_transaction_fee=900`, `commission_fee=0`, `service_fee=0`, `campaign_fee=0`, `seller_transaction_fee=0`, `cost_of_goods_sold=65000`, `escrow_tax=0`, `total_adjustment_amount=0`, `escrow_amount_after_adjustment=64675`.

Jadi `mapIncome` pakai nama field ini (tervalidasi, bukan nebak). Saat build, tes `store` bisa pakai fixture dari data asli ini. Catatan: `get_escrow_list` kosong di sandbox → `syncSettlements` end-to-end balik 0 di sandbox (sign tetap kebukti); untuk demo data nyata, panggil `getEscrowDetail(2608247FYHUBMG)` langsung.
