# MLM Model A (Margin / Inter-Partner) — Design

**Tanggal:** 2026-08-21
**Status:** Desain — dari brainstorm panjang (terkunci di memori). **Perlu review user sebelum lanjut ke plan.**

## Goal

Ubah SKINKU MLM dari **model komisi terpusat** (semua order ke HQ; GD dapat override %) ke **Model A (margin / inter-partner)**: HQ hanya melayani **Grand Distributor**; distributor beli ke **GD-nya** dan GD untung dari **selisih harga** (margin); **override dibuang**.

## Latar

Model terpusat (pivot 15 Agu) bikin HQ tetap mengirim ke semua level (termasuk distributor) supaya bisa hitung override — itu **bentrok** dengan arah user "HQ cuma urus GD". Override wajib distri pesan ke HQ. Margin tidak: GD beli dari HQ (harga grand), jual ke distri (harga distri), untungnya otomatis dari beda harga — HQ tak perlu sentuh distri. Keputusan user 2026-08-20: **balik ke Model A**.

## Keputusan terkunci

1. **HQ cuma layani GD.** GD pesan ke HQ; HQ kirim ke GD.
2. **Distri punya GD → beli ke GD** (PO inter-partner). GD kirim dari gudangnya. Untung GD = margin (harga tier pembeli − harga tier GD).
3. **Distri TANPA GD → beli ke HQ sementara** (fallback, dormant-safe: `seller_id = null`).
4. **Rantai tak kaku:** pemegang stok (GD/Distri) boleh jual ke siapa saja di bawahnya. Jual ke **member** = PO inter-partner (transfer stok). Jual ke **end-customer / reseller (offline)** = penjualan retail (stok penjual keluar, pembeli tak dilacak).
5. **Override DIBUANG.** Margin menggantikan. (Bonus rekrutmen — join 10%, RO cashback 5% — adalah fitur **Sponsor** terpisah, di luar cakupan spec ini.)
6. **Stok dilacak sampai Distributor.** HQ + GD + Distributor = stockist (`holds_stock=true`). Reseller `holds_stock=false` (tak ada laporan stok; beli offline). Konsekuensi: jual ke reseller = stok KELUAR sepihak dari penjual (sama seperti jual ke customer).
7. **Akuntansi HOLD:** PO inter-partner TAK auto-journal ke buku HQ (jualan distributor ≠ jualan HQ).
8. **Rate configurable** (untuk fitur bonus terpisah nanti) — tak hardcode.

## Yang SUDAH ada vs yang BARU

| Bagian | Status |
|---|---|
| Kolom `purchase_orders.seller_id` (null=HQ) + relasi `seller()` | ✅ ADA (migrasi 000080) |
| `complete()` cabang inter-partner (potong stok GD + tambah stok distri, atomik, guard stok-kurang→rollback) | ✅ ADA, teruji (`InterPartnerFulfillmentTest`) |
| Guard komisi skip inter-partner (`recordForCompletedPo`: `if seller_id !== null return`) | ✅ ADA → override auto-skip untuk order ke GD |
| Harga tier (price_grand<distributor<reseller<retail) | ✅ ADA (Tahap 3a) |
| Workflow "Pesanan Downline" (`DownlineOrderController` + izin `process_downline_po` + verif bayar/kirim/tolak) | 🔁 ADA di git **commit 919c113** (dihapus saat pivot) → resurrect |
| **Routing** distri→GD | ❌ MATI (`createForPartner` hardcode `seller_id=null`) → flip |
| **Pembayaran Distri→GD** | ❌ BELUM PERNAH ADA → bangun |
| Override aktif (centralized) | ⚠️ perlu dipadamkan (rate→0 / dokumentasikan dormant) |

## Arsitektur & fase

Bangun bertahap (tiap fase self-contained, dormant-safe, deployable sendiri) — pola sama Model X dulu:

### Fase 1 — Routing + padamkan override (fondasi, dormant-safe)
- **`PurchaseOrderService::createForPartner`**: set `seller_id = buyer->upline_id` **kalau** upline ada & upline `holdsStock` (GD/Distri); else `null` (HQ). (Balik dari hardcode `null`.)
- **Override padam:** karena engine komisi sudah skip `seller_id !== null`, order ke GD otomatis tanpa override. Untuk order HQ (GD ke HQ = upline null → tak ada penerima; distri-tanpa-GD = upline null → tak ada penerima), override praktis tak pernah cair. Set default rate override (`komisi_persen_grand_distributor` dll) ke **0** di RATE_DEFAULTS supaya eksplisit "dibuang" — tetap configurable kalau suatu saat dihidupkan. Join bonus (onboarding 10%) TETAP.
- **Dormant-safe:** jaringan prod KOSONG (semua `upline_id` null) → semua PO tetap `seller_id=null` = HQ = perilaku sekarang. Model A nyala per-mitra begitu upline diisi. NOL disrupsi saat deploy.
- **Tes:** distri dgn upline-GD → `seller_id`=GD; distri tanpa upline → `seller_id`=null; GD → `seller_id`=null; order ke GD tak catat komisi override; regresi PO HQ existing hijau.

### Fase 2 — Workflow "Pesanan Downline" (resurrect)
- Bangkitkan `DownlineOrderController` + izin `process_downline_po` + route + view + nav dari **commit 919c113** (`git show 919c113 -- <path>` / cherry-pick relevan), sesuaikan ke kode terkini (routing Fase 1, engine dorman yang sudah ada).
- GD melihat PO downline-nya (`seller_id === me`), verifikasi bayar → kirim (memicu `complete()` → transfer stok) / tolak. Otorisasi airtight: guard `seller_id===auth` di baris pertama tiap aksi (mitra tak bisa sentuh PO HQ / mitra lain).
- **Tes:** GD lihat & proses PO downline; mitra lain 403; kirim → stok transfer; regresi.

### Fase 3 — Pembayaran Distri→GD (baru)
- Distri bayar ke GD (di luar HQ). Mekanisme **default (untuk review): manual + bukti transfer** — reuse pola payment-proof PO existing (distri unggah bukti → GD verifikasi di Pesanan Downline). Gateway (Xendit dll) = opsi masa depan, tidak di fase ini.
- Rekening GD: tampilkan rekening GD ke distri saat bayar (reuse field bank di profil mitra dari fitur withdraw).
- **Akuntansi:** transaksi Distri↔GD TAK masuk jurnal HQ. Cukup catat status bayar + jejak (Audit).
- **Tes:** distri unggah bukti; GD verifikasi → boleh kirim; belum-lunas tak boleh kirim.

## Data & stok

- Tak ada migrasi baru untuk Fase 1 & 2 (kolom `seller_id` sudah ada). Fase 3 mungkin butuh kolom/tabel bukti bayar inter-partner (tentukan di plan; bisa reuse mekanik payment-proof existing).
- Stok: HQ (`hq_stock`) + partner stock (GD/Distri) via `InventoryService::adjustPartnerStock`. Reseller/customer di luar.

## Testing (strategi)

Per fase (TDD): routing (Fase 1), workflow+otorisasi (Fase 2), pembayaran (Fase 3). Plus regresi menyeluruh tiap fase (alur PO↔HQ existing WAJIB tetap hijau — Grand & mitra tanpa upline). Dormant-safe diverifikasi: jaringan kosong → nol perubahan.

## Rollout / dormant-safe

Deploy tiap fase aman karena jaringan prod kosong (semua ke HQ sampai upline diisi). Aktivasi bertahap: angkat Grand → set upline distri → Model A nyala per-cabang. Bisa uji gradual.

## Di luar cakupan (fitur TERPISAH, spec sendiri nanti)

- **Sponsor + dual-link** (role Sponsor, 10% join universal, 5% RO cashback dari GD, kolom `sponsor_id`).
- **Insentif Volume Grand** (bonus tahunan GD dari total belanja ke HQ).
- **Retur Distributor** (retur + clawback bonus).

Ketiganya numpang di atas fondasi Model A ini; dibangun setelahnya.
