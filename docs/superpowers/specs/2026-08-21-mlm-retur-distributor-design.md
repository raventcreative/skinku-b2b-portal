# MLM Retur — Design (direkonsiliasi ke Model A)

**Tanggal:** 2026-08-21
**Status:** Desain — dari brainstorm terkunci (memori `project-skinku-distributor-retur`), DIREKONSILIASI ke Model A. **Perlu review user sebelum build.** Fitur MLM ke-4 (terakhir).

## Goal

Retur (undo) **sebagian/penuh** dari PO yang sudah `completed`: balikin **stok** + **clawback komisi** + catat **refund** (manual). Append-only.

## Rekonsiliasi Model A (⚠️ desain lama = era terpusat/override)

Retur berlaku ke **PO completed apa pun** (dua jenis di Model A):
- **PO ke HQ (`seller_id` null)** — GD restock (atau distri-fallback). Retur: **HQ +qty** (barang normal) / write-off (rusak); **buyer −qty**. **Clawback:** `ro_cashback` (perekrut GD) proporsional.
- **PO inter-partner (`seller_id`=GD)** — distri beli ke GD. Retur: **GD(seller) +qty** (normal); **distri(buyer) −qty**. **Komisi: TAK ada** (margin bukan komisi) → cuma reversal stok + catat refund.

**Clawback komisi (baris NEGATIF, append-only — dikunci user 2026-08-21: SEMUA bisa ditarik):**
- **`ro_cashback`** — proporsional ke fraksi retur (nilai barang diretur ÷ subtotal PO), via `CommissionService::recordReturnReversal`.
- **`volume_bonus`** — **IKUT ditarik** (revisi user): pasca-retur, `netTotal` GD turun (Σ PO − Σ retur applied). Re-evaluasi volume dengan clawback: kalau hak(netTotal) < yang sudah diberi → tulis baris NEGATIF selisihnya. (VolumeIncentiveService diubah: pakai netTotal + izinkan award negatif; evaluate dipanggil juga pasca-retur.)
- **`override`** — kalau suatu saat dihidupkan (dorman sekarang).
- **`join`** — di-clawback lewat **flow BATAL JOIN terpisah** (bukan retur PO; join dari paket/onboarding, bukan PO). Lihat bagian di bawah.
- Saldo boleh **minus** (kalau sudah ditarik) = utang, ketutup komisi berikutnya (withdrawal existing tolak narik kalau `availableBalance` < jumlah).

## Batal/Retur Join (onboarding) — flow terpisah (dikunci user 2026-08-21)

Kalau join member dibatalkan (paket dibalikin / member keluar): **clawback bonus join** (baris negatif ke perekrut) + **balikin stok paket ke HQ** (adjustHqStock +qty per item paket) + tandai JoinTransaction dibatalkan + (opsional) nonaktifkan member. Reuse `recordReturnReversal`-style negatif. Fase-nya: engine bareng Fase 1 (atau sub-fase), UI bareng Fase 2.

## Keputusan terkunci (carry-over)

- **Partial & full:** retur per-item (qty) atau semua.
- **Siapa input:** **HQ** (izin `process_return`) → **langsung berlaku**. **Mitra buyer** input → **PENGAJUAN** (status pending) → **HQ acc** baru berlaku (pola Withdrawal ajukan→proses).
- **Kondisi barang:** per-retur **Normal** (masuk stok penerima, bisa dijual lagi) / **Rusak** (write-off, tak nambah stok). Default Normal.
- **Undo/delete (super_admin):** hapus retur yang sudah `applied` kalau ada kesalahan → **undo semua efek**: stok balik pre-retur, komisi clawback dibatalkan via **baris kompensasi POSITIF** (append-only), nota ditandai **void** TAPI tetap kecatat di **Audit Log**.
- **Uang:** refund **manual** (belum ada gateway) — sistem catat nota + jejak.

## Arsitektur

- **Reuse:** `InventoryService::adjustHqStock`/`adjustPartnerStock` (balik stok), `CommissionService` (method baru `recordReturnReversal` — baris negatif proporsional), pola pengajuan→approve dari Withdrawal.
- **Data (migrasi 000090):** `returns` (`purchase_order_id`, `status` [pending/applied/rejected/void], `kondisi` [normal/rusak], `reason` nullable, `requested_by`, `approved_by` nullable, `applied_at` nullable, timestamps) + `return_items` (`return_id`, `purchase_order_item_id`, `qty`).

## Fase

### Fase 1 — Engine (money-critical, dormant sampai dipakai)
- `ReturService::apply(Return $retur)`: dalam 1 transaksi — reversal stok (per jenis PO + kondisi) + `recordReturnReversal` (clawback ro_cashback/override proporsional) + set status `applied` + `applied_at`. Guard: qty retur ≤ qty PO item; PO harus completed.
- `CommissionService::recordReturnReversal(PurchaseOrder $po, float $fraction, Return $retur)`: tulis baris negatif = −(Σ ro_cashback/override PO × fraction), type sama + `source_po_id`, `status` saldo. Idempoten per retur.
- **Tes:** clawback ro_cashback proporsional (retur 40% → −40%); volume/join TAK kena; stok balik (HQ+/buyer− & inter-partner seller+/buyer−); rusak → write-off (penerima tak nambah); saldo bisa minus.

### Fase 2 — Workflow (input + approve)
- HQ input (izin `process_return`) → `apply` langsung. Mitra buyer input → status `pending`. HQ acc → `apply`; tolak → `rejected`.
- Controller + form (pilih PO, item+qty, kondisi, alasan) + daftar/antrean retur + nav. Guard otorisasi.
- **Tes:** HQ input langsung applied; mitra input pending; acc → applied + efek; non-owner 403.

### Fase 3 — Undo (super_admin void)
- `ReturService::void(Return $retur)`: reverse efek (stok balik, komisi kompensasi POSITIF), status `void`, Audit. Hanya `applied`.
- **Tes:** void balikin stok + komisi; nota void tapi kecatat.

## Di luar cakupan
- Batas waktu retur (tak dibatasi dulu). Gateway refund. Buku per-mitra (akuntansi HOLD).

## Deploy
Migrasi 000090. Deploy sekali di akhir (bareng 087-089): `git pull && migrate --force && optimize:clear` + hard-refresh.
