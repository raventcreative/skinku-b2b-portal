# Sistem Komisi Override + Saldo + Withdraw — Desain

**Tanggal:** 2026-08-15
**Branch:** `feat/override-komisi` (dari `main` @ d9ac213)
**Fase:** MLM Tahap 3c — bagian komisi (fase terakhir). **Menggantikan** desain override lama yang berbasis PO antar-mitra (usang setelah revisi model).

## Perubahan model (penting)
Model bisnis direvisi dari "Model X" (beli-dari-upline + transfer stok antar-mitra) ke **model komisi terpusat**:
- **Semua order langsung ke HQ.** HQ = penjual & pengirim tunggal; kirim langsung ke mitra. Mitra **tetap pegang stok** (beli stok dari HQ di harga tier, jual ke customer-nya sendiri = untung margin pribadi). Pakai **alur HQ→mitra + inventori mitra yang SUDAH ADA** (sebelum Model X).
- **Tidak ada PO antar-mitra.** Downline tak beli dari upline; semua ke HQ. Engine Model X (`purchase_orders.seller_id` inter-partner) **tetap dorman** — di model ini `seller_id` selalu null.
- **Untung mitra = (1) margin harga tier** [bawaan, lewat harga — bukan komisi] **+ (2) komisi override** [HQ bayar → saldo → withdraw].

## Aturan komisi (dikunci dari user Freddie)
- **Override (repeat order):** saat downline order (PO Selesai) ke HQ, telusuri rantai upline; tiap upline dapat **rate rank-nya** × nilai barang order. **Differensial per rank, naik-pohon.** Default: **Grand 6% · Distributor 4% · Reseller 2%**.
- **Join (order pertama member):** upline **langsung** dapat **10%** dari nilai order pertama downline (sekali).
- **Basis:** nilai barang (subtotal) order downline.
- **Semua rate configurable** di Pengaturan (matriks per rank) — ubah kapan saja, komisi ke depan ikut. Jangan hardcode.
- **Akuntansi hold:** komisi = saldo/catatan; **TIDAK** auto-jurnal. Pencairan dicatat, transfer manual.

## Aturan tingkat
Tiap tier cuma rekrut tier di bawahnya (Grand→Distributor→Reseller). Distributor **tidak** bisa punya downline Distributor — untuk itu harus naik Grand. (Kemungkinan **sudah** ditegakkan `PartnerHierarchy::allowedParentRoles` — verifikasi di plan; kalau sudah, fase penempatan minim.) **User = customer akhir** (bukan member/akun) — member paling bawah = Reseller.

## Withdraw (default, bisa disesuaikan)
Mitra **ajukan penarikan** (jumlah ≤ saldo, ≥ **Rp100.000**) + isi rekening → HQ **verifikasi & setujui/tolak** → tandai **cair** (transfer manual). **Tanpa potongan admin** (default). Ada **riwayat**.

## Arsitektur
Zero-dependency. **2 migrasi** (`commissions` 000081, `withdrawals` 000082). Reuse: `PurchaseOrderService::complete()` (pemicu), rantai upline (`User::upline` walk), `AppSetting` (rate + config), pola laporan/halaman existing.

## Komponen & fase build

### FASE 1 — Engine komisi + saldo
1. **Rate config (`AppSetting`):** per-rank override + join. Key: `komisi_persen_grand_distributor`=6, `komisi_persen_distributor`=4, `komisi_persen_reseller`=2 (bronze/gold ikut reseller=2), `komisi_persen_join`=10. HQ ubah di Pengaturan (fase 3 kasih UI; fase 1 cukup nilai default + bisa lewat AppSetting).
2. **Migrasi 000081 `commissions` + model `Commission`:** `id`, `user_id` (penerima/upline, FK users), `source_po_id` (FK purchase_orders), `source_user_id` (downline pembeli, FK users), `type` (`override`/`join`), `level` (jarak naik; join=1), `rate` (persen saat hitung), `base_amount` (nilai barang), `amount`, `status` (`saldo`/`ditarik`), `created_at`. Relasi penerima/po/downline.
3. **`CommissionService::recordForCompletedPo(PurchaseOrder $po)`:**
   - Tentukan order **pertama** (belum ada PO completed lain milik `$po->user`) → **join**; selain itu → **repeat/override**.
   - **Join:** upline langsung `$po->user->upline` dapat `join% × base` (type=join). (Kalau tak ada upline → nihil.)
   - **Override (repeat):** telusuri rantai upline `$po->user` naik; tiap mitra-upline dapat `rateRank(upline) % × base` (type=override, level bertambah). Berhenti di puncak/non-mitra.
   - **Idempoten:** guard per `source_po_id` (jangan dobel).
   - **Nol** kalau pembeli tak punya mitra-upline (mis. Grand puncak / PO HQ langsung).
4. **Hook di `complete()`:** SETELAH stok (dalam DB transaction), panggil `CommissionService::recordForCompletedPo($po)`. Perubahan minimal (satu call). Jalur existing (semua PO seller null) tetap normal; call cuma mencatat komisi kalau ada upline.
5. **Saldo:** `CommissionService::balance(User $mitra)` = SUM(`commissions.amount` status=saldo) − SUM(pencairan disetujui). (Withdraw fase 2; fase 1 saldo = SUM komisi.)
6. **Tes:** override differensial naik-pohon (Reseller order → Dist 4% + Grand 6%); join (order pertama → upline 10%, order kedua → override); rate dari AppSetting (ubah → ikut); basis nilai barang; idempoten; pembeli tanpa upline → nol; regresi `complete()` (HQ path) hijau.

### FASE 2 — Withdraw
1. **Migrasi 000082 `withdrawals` + model:** `id`, `user_id`, `amount`, `bank_name`, `bank_account`, `account_holder`, `status` (`diajukan`/`disetujui`/`ditolak`/`cair`), `note`, `requested_at`, `processed_by`, `processed_at`.
2. **Mitra ajukan:** form (jumlah ≤ saldo & ≥ min Rp100.000, rekening). Guard saldo cukup.
3. **HQ proses:** setujui/tolak → `cair` (kurangi saldo: tandai commissions terkait `ditarik` atau catat pencairan). Riwayat.
4. **Tes:** ajukan > saldo ditolak; ajukan < min ditolak; approve → saldo turun; tolak → saldo utuh; hanya HQ yang approve (izin); mitra cuma lihat/ajukan miliknya (403 lintas-mitra).

### FASE 3 — Laporan HQ + layar mitra + aturan tingkat
1. **Laporan Komisi (HQ):** komisi per mitra + saldo + antrean withdraw (approve/reject). Pengaturan rate (UI edit matriks).
2. **Layar mitra:** lihat saldo + riwayat komisi + ajukan withdraw + riwayat withdraw (di dashboard/Jaringan Saya).
3. **Verifikasi/tegakkan aturan tingkat** (distri≠distri) di `PartnerHierarchy` bila belum.

## Akuntansi
Komisi & pencairan = **catatan/saldo**, bukan jurnal `acc_`. Rekons manual. (Fase akuntansi masuk-buku terpisah bila perlu.)

## Dormant-safe
Jaringan kosong → pembeli tak punya upline → nol komisi → nol saldo → nol withdraw. Rate default terisi tapi tak ada efek sampai jaringan + transaksi ada. Deploy aman.

## Out of scope
- **Bonus kepemimpinan** (bonus grup bulanan Grand) — fase lanjut.
- **Payment gateway** withdraw otomatis (Xendit) — transfer manual dulu.
- **Auto-jurnal** akuntansi komisi — hold.
- Margin resale mitra (untung pribadi dari jualan ke customer) = di luar sistem (offline); sistem cuma hitung komisi override.

## Self-review
- **Placeholder:** tak ada; tiap komponen + fase konkret.
- **Konsistensi:** rate dari AppSetting (bukan hardcode) di service + laporan; basis subtotal; hook complete() minimal; commissions/withdrawals FK konsisten. Override = rate RANK penerima (differensial); join = 10% upline langsung.
- **Ambiguitas:** join vs override dipisah tegas (order pertama = join saja; berikutnya = override). Basis = nilai barang. User = customer (bukan member).
- **Scope:** dipecah 3 fase; fase 1 (engine+saldo) berdiri sendiri, dorman-safe, testable. Withdraw & UI fase terpisah.
