# Integrasi Shopee — Fase 4 (Jurnal Akuntansi) — Design

**Tanggal:** 2026-08-24
**Status:** Disetujui user ("gas A biar kelar" — full two-stage termasuk withdrawal wallet)
**Referensi:** `docs/superpowers/research/tiktok-fase2-4-map.md` §3.3 (TikTokAccountingService "Opsi C") + §3.4 (engine `AccountingService`/`AccJournal`) + `docs/superpowers/research/shopee-payment-api-map.md` §2.4 (wallet enum) + §4 (model kas 2-tahap).

## Goal

Jurnal akrual OTOMATIS untuk Shopee — niru `TikTokAccountingService` "Opsi C", pakai data Fase 1-3 (order + escrow) **+ wallet cash-out**. Setelah ini Shopee = **100% parity + akuntansi penuh** dengan TikTok, plus lebih akurat (fee escrow pasti, bukan heuristik).

## Arsitektur

`ShopeeAccountingService` memakai engine GL yang sudah ada (`AccountingService::record` → `AccJournal`/`AccJournalLine`, double-entry, wajib balance). Semua idempoten (guard kolom journal-id / posting_status), hormati cutoff `deduct_from`, di belakang saklar `journal_enabled` (default OFF). Fase 4 juga membangun subsistem **wallet transactions** (mutasi saldo) untuk cash-out + potongan non-order.

## Global Constraints

- **Zero-dependency**.
- **Saklar OFF default**: `journal_enabled=false` — jurnal tak jalan sampai admin nyalain (mirror TikTok).
- **Preview → Post manual** (bukan cron) + **Unpost** scoped `source_type` (jangan sentuh jurnal Excel/manual/PO).
- **Balance wajib**: tiap jurnal seimbang (engine `AccountingService` menolak kalau tidak).
- **Idempoten**: guard `transit_journal_id`/`sale_journal_id` (order), `posting_status`/`journal_id` (settlement & wallet).
- **Jangan dobel-hitung**: escrow settlement (Recipe 3) & wallet `ESCROW_VERIFIED_ADD` = duit yang SAMA → wallet posting SKIP tipe escrow.
- **Deploy = git pull**: migrasi `000095` (kolom jurnal order + `journal_enabled` conn + tabel `shopee_wallet_transactions`).

## Bagan akun (`ShopeeAccountingService::accounts()`)

| key | code | nama | tipe | catatan |
|---|---|---|---|---|
| kas | `1001` | Kas Shopee | asset/cash | **saldo wallet Shopee** (seeded) |
| bank | `1002` | Bank | asset/cash | tujuan withdrawal (seeded) |
| piutang | `1104` | Piutang Shopee | asset/receivable | **mint** (lazy firstOrCreate, spt TikTok 1103) |
| transit | `1203` | Persediaan Dalam Perjalanan | asset/inventory | shared lazy |
| persediaan | `1202` | Persediaan Barang Jadi | asset/inventory | shared |
| penjualan | `4001` | Penjualan | revenue/sales | shared |
| pendapatan_lain | `4002` | Pendapatan Lain-lain | revenue/other | shared |
| hpp | `5003` | Beban HPP | expense/cogs | shared |
| fee | `6005` | Beban Biaya E-commerce | expense/operating | shared (komisi/layanan/txn/pajak/catch-all) |
| iklan | `6001` | Beban Iklan / Promosi | expense/operating | shared (campaign + PAID_ADS) |
| ongkir | `6007` | Beban Ongkos Kirim | expense/operating | shared |

`acc(code,...)` = `AccAccount::firstOrCreate(['code'=>...], [...])` (seeded menang; mint yang belum ada). Sama pola TikTok.

## Resep jurnal (5 kejadian)

Semua via `record(lines, date, reference, description, sourceType, sourceId, type)` (wrapper `AccountingService::record`, ambil `AccBranch::active()` pertama).

### Recipe 1 — Barang keluar → transit (`postTransit(ShopeeOrder)`)
Guard: `transit_journal_id` null, `hpp_amount > 0`.
- Dr **transit** (hpp) / Cr **persediaan** (hpp)
- `source_type='shopee_order_transit'`, set `order.transit_journal_id`.

### Recipe 2 — Order sampai (COMPLETED) → omzet + HPP (`postSale(ShopeeOrder)`)
Guard: `sale_journal_id` null, `isDelivered()` (status COMPLETED).
- Dr **piutang** (total_amount) / Cr **penjualan** (total_amount)  [bruto]
- Bila `transit_journal_id` sudah ada: Dr **hpp** (hpp) / Cr **transit** (hpp)
- Omzet TAK digate oleh HPP (spt TikTok). `source_type='shopee_order_sale'`, set `order.sale_journal_id`.

### Recipe 3 — Escrow cair → kas wallet + fee, lunasi piutang (`postSettlement(ShopeeSettlement)`)
Guard: `posting_status` pending. Basis kontrol-akun (tak cocokin per-order):
- Dr **kas** (escrow_amount = net masuk wallet)
- Dr **ongkir** (actual_shipping_fee)  [bila > 0]
- Dr **iklan** (campaign_fee)  [bila > 0]
- Dr **fee** (buyer_total_amount − escrow_amount − actual_shipping_fee − campaign_fee)  [catch-all: komisi+layanan+txn+pajak+lain; selalu ≥ 0; bila > 0]
- Cr **piutang** (buyer_total_amount)
- **Selalu balance** (Σ Dr = buyer_total = Cr). Melunasi Piutang yang di-Dr saat Recipe 2 (bila order.total_amount == buyer_total_amount; residu kecil dibiarkan di Piutang). `source_type='shopee_settlement'`, set `posting_status=posted`+`journal_id`.

### Recipe 4 — Tarik ke bank (`postWallet(ShopeeWalletTransaction)`, tipe WITHDRAWAL_COMPLETED)
- Dr **bank** (amount) / Cr **kas** (amount)  [saldo wallet → bank]

### Recipe 5 — Potongan/penyesuaian wallet non-order (`postWallet`, tipe lain)
Peta `transaction_type` → jurnal (money_flow / tanda amount menentukan arah; verifikasi tanda di go-live):
- `PAID_ADS_CHARGE` → Dr **iklan** / Cr **kas**
- `PAID_ADS_REFUND` → Dr **kas** / Cr **iklan**
- `ADJUSTMENT_ADD` / `ADJUSTMENT_CENTER_ADD` / `FBS_ADJUSTMENT_ADD` → Dr **kas** / Cr **pendapatan_lain**
- `ADJUSTMENT_MINUS` / `ADJUSTMENT_CENTER_DEDUCT` / `FBS_ADJUSTMENT_MINUS` → Dr **fee** / Cr **kas**
- `AFFILIATE_ADS_SELLER_FEE`(+refund) → seperti PAID_ADS
- **SKIP (jangan post):** `ESCROW_VERIFIED_ADD`/`ESCROW_VERIFIED_MINUS` (sudah di Recipe 3), `WITHDRAWAL_CREATED`/`WITHDRAWAL_CANCELLED` (cuma COMPLETED yang gerak kas), tipe tak dikenal → skip + `Log`.
- `source_type='shopee_wallet'`, set `posting_status=posted`+`journal_id`.

### Batch & rollback
- `postPending(): array{transit,sale,settlement,wallet,failed}` — backfill HPP bila 0; lalu transit (DEDUCTED & transit_journal_id null), sale (COMPLETED & sale_journal_id null), settlement (pending), wallet (pending). Hormati cutoff (order: `order_created_at`; settlement: `escrow_release_time`; wallet: `create_time`). Per-baris try/catch + Log. **Throw bila `!enabled()`.**
- `unpostAll(): array` — hapus AccJournal `source_type IN ('shopee_order_transit','shopee_order_sale','shopee_settlement','shopee_wallet')` (line cascade), reset journal-id/posting_status. Scoped ketat.
- `enabled()` = `ShopeeConnection::latest('id')->first()?->journal_enabled`. `cutoff()` = `deduct_from` (sudah ada).
- `preview*()` (tiap recipe) balikin lines untuk UI "Rencana Jurnal" sebelum post.

## Yang DIBANGUN (task besar)

1. **Migrasi `000095`**: `shopee_orders` +`transit_journal_id`/`sale_journal_id`; `shopee_connections` +`journal_enabled` (bool default false); tabel `shopee_wallet_transactions` (`transaction_id` unik, `transaction_type`, `kind`, `amount`, `current_balance`, `money_flow`, `order_sn`, `refund_sn`, `reason`, `status`, `transaction_time`, `raw`, `posting_status`/`journal_id`/`posted_at`/`posted_by`).
2. **Wallet subsystem**: model `ShopeeWalletTransaction` (+`isPosted`, konstanta kind); `ShopeeClient::getWalletTransactionList`; `ShopeeWalletService` (store + `kindFromType` peta 21 enum → label ID); `ShopeeSyncService::syncWallet` + `shopee:sync --wallet` + cron.
3. **`ShopeeAccountingService`**: `accounts()`, `previewTransit/postTransit`, `previewSale/postSale`, `previewSettlement/postSettlement`, `previewWallet/postWallet`, `postPending`, `unpostAll`, `enabled`, `cutoff`, `preview` (per objek utk UI).
4. **Controller + UI**: aksi `postJournals`/`unpostJournals`/`toggleJournal` + panel jurnal di halaman Pencairan (saklar `journal_enabled`, tombol Posting/Unpost dgn konfirmasi, preview rencana jurnal per settlement) — mirror `tiktok/settlements.blade.php`. Route grup `manage_shopee` (super_admin/admin; posting = aksi sensitif). Audit log.
5. **Tests**: siklus penuh Opsi C (transit clears, piutang lunas via settlement, kas→bank via withdrawal); idempoten (post 2× tak dobel); unpost cuma jurnal Shopee; saklar OFF default throw; balance tiap recipe; wallet kind mapping + SKIP escrow-type.

## Alur

1. Sync order/escrow/wallet (Fase 1-3 + `--wallet`) → data di `shopee_orders`/`shopee_settlements`/`shopee_wallet_transactions`.
2. Admin nyalain `journal_enabled` → **Preview** rencana jurnal → **Posting** (manual). `postPending` bikin jurnal balance ke GL.
3. Neraca/Buku Besar (LedgerService yang ada) otomatis mencakup jurnal Shopee (source_type `shopee_*`).
4. Salah? **Unpost** cabut semua jurnal Shopee (aman, scoped).

## Error handling

- `!enabled()` → `postPending` throw (UI tampilkan "nyalakan saklar dulu").
- Jurnal tak balance → `AccountingService` throw `AccountingException` (tak akan terjadi bila recipe benar; per-baris try/catch di postPending menangkap + Log + lanjut).
- Belum ada cabang (`acc_branches`) → throw (mirror TikTok).
- Wallet tipe tak dikenal / tanda amount ambigu → skip + Log (verifikasi tanda di go-live).
- Double-count → dicegah dgn SKIP tipe escrow di wallet posting + guard journal-id.

## Verifikasi sandbox (saat build)

- **Escrow settlement journal** bisa diverifikasi dgn DATA ASLI: order `2608247FYHUBMG` (escrow_amount 64675, buyer_total 77665, ongkir 11765) → `postSettlement` → cek jurnal balance (Dr kas 64675 + ongkir 11765 + fee 1225 = Cr piutang 77665). **Bukti nyata.**
- **Transit/Sale**: pakai order test yang sudah dipotong stok (hpp) + status COMPLETED (bisa di-set) → verifikasi jurnal.
- **Wallet/withdrawal**: sandbox 0 transaksi → tes via unit (fake). `get_wallet_transaction_list` sign SUDAH kebukti (toko local). Field/tanda amount asli diverifikasi go-live.

## Di luar scope (sudah tuntas / terpisah)

- Retur (Fase 2), Escrow (Fase 3) — dipakai, tak diubah.
- **AMS/Affiliate** = subsistem terpisah.

## Deploy

- Migrasi `000095` → `git pull` + `migrate --force` + `optimize:clear`.
- `shopee:sync --wallet` masuk scheduler. `journal_enabled` OFF sampai admin siap.
