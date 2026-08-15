# Override Komisi (Purchase Commission) — Desain

**Tanggal:** 2026-08-15
**Branch:** `feat/override-komisi` (dari `main` @ d9ac213 — engine + companion + workflow + member-id semua merged & live)
**Fase:** MLM Tahap 3c bagian override komisi — **bagian PURCHASE-COMMISSION (2,5%) dulu**. Join-bonus (10% dari biaya daftar) DITUNDA sampai fitur onboarding/paket-join ada (belum ada "event join" + nominal).

## Tujuan
Tiap PO downline **Selesai**, HQ bayar komisi override ke **rantai upline** si pembeli. Komisi dicatat sebagai **terutang** (accrual); pembayaran manual/Excel (akuntansi tetap hold). Rate bisa diubah HQ kapan saja.

## Aturan (dikunci dari user Freddie)
- **Naik-pohon:** SETIAP mitra-upline di atas si pembeli dapat komisi (sampai puncak rantai yang masih mitra).
- **Rate default 2,5%**, **bisa diubah HQ kapan saja** (disimpan di Pengaturan, bukan hardcode).
- **Basis:** 2,5% dari **nilai barang** PO (subtotal, tanpa ongkir).
- **Sumber:** HQ (makan margin HQ). Dicatat terutang; **TIDAK auto-jurnal** (akuntansi-hold) — laporan buat rekons manual.
- **Kapan:** saat PO **Selesai**. PO batal/belum selesai → nol komisi.

### ⚠️ KEPUTUSAN yang perlu user tegaskan saat review spec
Karena naik-pohon, **upline LANGSUNG** (yang juga jadi penjual & sudah dapat **margin**) ikut dapat override → **margin + 2,5%** pada transaksi yang sama.
- **DEFAULT (dipakai di spec ini):** upline langsung BOLEH dobel (margin + override).
- Alternatif (kalau user mau): override cuma untuk tingkat **DI ATAS penjual** (upline langsung cukup margin). → kalau dipilih, `CommissionService` mulai walk dari upline-nya-upline (skip level 1). Satu baris kode beda.

## Arsitektur
Zero-dependency. **Satu migrasi baru** (tabel `commissions`, 000081). Reuse: `PurchaseOrderService::complete()` (titik pemicu), rantai upline via `User::upline` / helper hierarki, `AppSetting` (rate), pola halaman laporan (`reports.*`).

## Contoh
Reseller beli barang **Rp1.000.000** (selesai). Rantai: Grand → Distributor → Reseller.
- Distributor (upline langsung) → **Rp25.000**
- Grand (upline Distributor) → **Rp25.000**
- HQ tanggung total **Rp50.000**. Dicatat 2 baris komisi terutang.

## Komponen

### 1. Rate configurable (`AppSetting`)
- Key `komisi_pembelian_persen` (default `2.5`). HQ ubah di **Pengaturan Sistem**.
- Diambil saat hitung komisi (bukan hardcode). Struktur siap tambah `komisi_join_persen` (10%) nanti pas onboarding.

### 2. Tabel & model `commissions` (migrasi 000081)
Kolom: `id`, `user_id` (penerima/upline, FK users), `source_po_id` (FK purchase_orders), `source_user_id` (downline pembeli, FK users), `level` (tingkat naik: 1=upline langsung, 2=grand-upline, dst), `rate` (persen saat dihitung, mis. 2.50), `base_amount` (nilai barang PO), `amount` (komisi = rate% × base), `status` (`terutang`/`dibayar`), `paid_at` (nullable), `paid_by` (nullable, admin yg menandai), `created_at`. Model `Commission` (+ relasi penerima, po, downline).

### 3. `CommissionService::recordForCompletedPo(PurchaseOrder $po)`
- Ambil rate dari AppSetting.
- Telusuri rantai upline **si pembeli** (`$po->user`): level 1 = upline langsung, naik terus selama masih mitra (`isPartner()`), berhenti di puncak/HQ (upline null / non-mitra).
- Basis = nilai barang PO (subtotal). Untuk tiap mitra-upline: buat baris `commissions` (amount = rate% × basis, status terutang).
- **Idempoten:** kalau sudah ada komisi untuk `source_po_id` ini, jangan buat lagi (guard; `complete()` sudah anti-double-complete, tapi tetap jaga-jaga).
- Nol efek kalau pembeli tak punya mitra-upline (PO HQ / Grand puncak) → tak ada baris.

### 4. Hook di `complete()`
Di `PurchaseOrderService::complete()`, SETELAH transfer stok berhasil (dalam DB transaction yang sama, di akhir sebelum return), panggil `CommissionService::recordForCompletedPo($po)`. Perubahan di `complete()` = **minimal** (satu pemanggilan). Untuk PO HQ / pembeli tanpa upline → service tak mencatat apa pun (jalur HQ existing NOL perubahan perilaku selain 1 call yang no-op).

### 5. Laporan Komisi (HQ, staff-only)
Halaman baru (menu **Komisi**): daftar komisi per mitra — total **terutang** & **dibayar**, rincian per PO (dari downline mana, level, amount). Aksi **"Tandai dibayar"** (per baris / bulk) → set status `dibayar` + `paid_at`/`paid_by`. Filter periode. Dormant: kosong sampai ada PO antar-mitra.

## Akuntansi
Override = **catatan terutang saja**. TIDAK nulis `acc_`/jurnal (akuntansi-hold). Komisi = **biaya HQ** (bukan omzet) → tak nyampur laporan omzet. Rekons pembayaran manual/Excel. Kalau nanti masuk pembukuan, itu fase akuntansi terpisah.

## Dormant-safe
Jaringan kosong → pembeli tak punya mitra-upline → nol komisi. Rate default 2,5% tapi nol PO antar-mitra → nol baris. Deploy aman, nyala per-mitra begitu jaringan diisi + ada transaksi.

## Testing
- **Naik-pohon:** Reseller (upline Distributor, grand Grand) beli & selesai → 2 baris komisi (Distributor level 1, Grand level 2), amount = 2,5% × subtotal masing-masing.
- **Pembeli tanpa mitra-upline:** Grand (upline null) beli dari HQ → nol komisi.
- **Rate dari AppSetting:** ubah `komisi_pembelian_persen` → 5 → komisi ikut jadi 5%.
- **Basis nilai barang:** komisi dihitung dari subtotal (tanpa ongkir).
- **PO batal:** cancel sebelum selesai → nol komisi.
- **Idempoten:** panggil ulang recordForCompletedPo → tak dobel.
- **Regresi:** `complete()` existing (HQ + inter-partner) tetap jalan; transfer stok tak terpengaruh; suite existing hijau.
- **Laporan:** HQ lihat komisi per mitra; "Tandai dibayar" → status berubah; mitra lain tak kelihatan (staff-only).

## Rencana build (subagent-driven)
1. **Engine komisi** — migrasi 000081 `commissions` + model + AppSetting rate + `CommissionService::recordForCompletedPo` + hook di `complete()` + tes (naik-pohon, tanpa-upline, rate, idempoten, regresi).
2. **Laporan Komisi HQ** — halaman + "Tandai dibayar" (route staff-only) + tes.

## Out of scope (fase lain)
- **Join-bonus 10%** — nunggu onboarding/paket-join (belum ada biaya daftar).
- **Auto-jurnal akuntansi** — hold.
- **Pembayaran komisi otomatis / gateway** — manual dulu.
- **Layar mitra lihat komisinya** — opsional, fase kecil nyusul (bukan di build ini).

## Self-review
- **Placeholder:** tak ada; tiap komponen konkret.
- **Konsistensi:** rate dari AppSetting (bukan hardcode) konsisten di service + laporan. Basis = subtotal konsisten. Hook di complete() minimal.
- **Ambiguitas:** double-dip upline-langsung ditandai KEPUTUSAN (default dobel; user tegaskan saat review). Basis = nilai barang (subtotal) eksplisit.
- **Scope:** satu subsistem (purchase-commission accrual + laporan) — pas untuk satu plan. Join-bonus & mitra-view dipisah.
