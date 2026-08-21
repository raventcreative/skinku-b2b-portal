# MLM Sponsor + Dual-Link — Design

**Tanggal:** 2026-08-21
**Status:** Desain (dari brainstorm terkunci — memori `project-skinku-sponsor-role`). **Perlu review user sebelum build.** Di atas fondasi Model A (sudah di main).

## Goal

Tambah jalur **REKRUTMEN** yang TERPISAH dari jalur pasok: role **Sponsor** (perekrut murni) + kolom `sponsor_id` + bonus rekrutmen (**10% join** universal + **5% RO cashback** dari GD). Rate bisa diatur.

## Latar

Model A (di main) = jalur **pasok** (`upline_id`, untung margin). Sponsor = jalur **rekrutmen** (siapa bawa siapa). **Tiap member punya 2 ikatan terpisah** — boleh beda orang. Sponsor nempel di "shared hook" yang Model A sudah jaga bersih (order GD ke HQ) — nol bongkar-ulang Model A.

## Keputusan terkunci

1. **Role `sponsor`** = perekrut murni: punya akun + login + **saldo + tarik dana**, TAPI **tanpa menu stok/PO** (tak jualan, tak pegang barang). Bukan tier stockist.
2. **Dual-link:** kolom `sponsor_id` (siapa merekrut) TERPISAH dari `upline_id` (pasok). Boleh beda orang; boleh null.
3. **10% join** — SEKALI, universal: siapa pun yang merekrut member baru dapat 10% × nilai paket join. (Generalisasi: bonus join sekarang ke `sponsor_id`, bukan otomatis `upline`.)
4. **5% RO cashback** — tiap **Repeat Order (order ke-2 dst)** dari member yang direkrut, dari **nilai omzet**, **HANYA kalau member = Grand (GD)**. Direkrut Distributor & dia RO → perekrut dapat **0**. Relasi-based & **stack** (GD-A rekrut GD-B → GD-A tetap dapat margin distri-nya + 5% RO GD-B). GD tanpa perekrut (`sponsor_id` null) → **nol** cashback (HQ tak bayar diri sendiri).
5. **Rate configurable** (WAJIB, jangan hardcode): 10% join (`komisi_persen_join`, sudah ada) + 5% RO cashback (key baru `komisi_persen_ro_cashback`). Diatur di kartu Rate Komisi.
6. **Clawback saat retur:** 10%/5% bisa ditarik balik (baris negatif) — MEKANIK-nya dibangun di fitur **Retur** (nanti); Sponsor cukup pastikan komisinya append-only & clawback-able (source_po_id/source_user_id kecatat).
7. **Dashboard Sponsor:** lihat daftar rekrutan (lead) + earning + tarik dana.

## Fase

### Fase 1 — Data foundation (dormant, aman)
- **Migrasi 000087:** `users.sponsor_id` (nullable, `foreignId constrained('users') nullOnDelete`, after `upline_id`).
- **User:** `fillable += sponsor_id`; relasi `sponsor()` belongsTo(User,'sponsor_id') + `recruits()` hasMany(User,'sponsor_id'); konstanta `ROLE_SPONSOR = 'sponsor'`.
- **Role `sponsor` integrasi:** masuk `isPartner()` (biar bisa saldo/withdraw) TAPI `PartnerHierarchy::holdsStock('sponsor') = false` & TIDAK masuk `TIERS` supply (bukan tier level). Izin default: TANPA `create_po`/stok; DENGAN akses saldo komisi + withdraw. Login via member_id seperti mitra lain.
- **Tes:** kolom + relasi; sponsor isPartner true tapi holdsStock false; sponsor tak bisa buat PO (403).

### Fase 2 — Commission engine (money-critical; nempel di shared hook)
- **10% join → perekrut:** `CommissionService::recordJoinBonus` diubah — penerima = **`member->sponsor_id`** (bukan `inviter`/upline). Kalau `sponsor_id` null → fallback perilaku existing (upline) ATAU nol (KEPUTUSAN spec — default: **ke sponsor_id; null → tak ada join**). Type tetap `join`.
- **5% RO cashback (BARU):** method `recordRoCashback(PurchaseOrder $po)` dipanggil dari `recordForCompletedPo` (atau `complete()`) HANYA saat: `seller_id === null` (order ke HQ) + `buyer role = grand_distributor` + buyer punya `sponsor_id` + ini **RO** (bukan order pertama GD ke HQ). Rate `komisi_persen_ro_cashback` (default 5). Base = `subtotal − discount`. Type baru `ro_cashback`, status `saldo`. Idempoten (source_po_id).
- **Deteksi RO:** order ke-2 dst GD ke HQ = ada ≥1 PO completed sebelumnya (buyer=GD, seller null). Order pertama = join (10% sudah lewat saat onboarding). Tentukan basis "pertama" di plan (COUNT PO completed HQ milik GD sebelum PO ini).
- **RATE_DEFAULTS += `komisi_persen_ro_cashback => 5.0`.**
- **Tes:** join ke sponsor (bukan upline); sponsor null → tak ada join; RO GD dgn sponsor → 5%; order pertama GD → tak ada RO (join saja); distri RO → nol; sponsor null GD → nol; idempoten; rate configurable.

### Fase 3 — UX (role, dashboard, onboarding, setting)
- **Role Sponsor:** nav minimal (Dashboard sponsor + Saldo Komisi/withdraw existing), sembunyikan menu stok/PO (gate holdsStock/permission). Permission set default role sponsor.
- **Onboarding form:** tambah pemilih **Sponsor** (opsional) saat daftar member → set `sponsor_id`. `OnboardingService::onboard` set sponsor_id + arahkan join bonus ke sponsor.
- **Dashboard Sponsor** (`/sponsor` atau reuse dashboard): daftar rekrutan (`recruits()`) + ringkasan earning (join + ro_cashback) + link tarik dana.
- **Setting:** field **RO Cashback (%)** di kartu Rate Komisi (di samping Bonus Join).
- **Label** `ro_cashback` di Laporan Komisi.
- **Tes:** onboarding set sponsor + join ke sponsor; dashboard render; setting simpan ro_cashback; sponsor tak lihat menu stok.

## Forward-compat
- Nempel di **shared hook Model A** (order GD→HQ) → tak ubah routing/kode Model A.
- Tabel `commissions` type-agnostic (type `ro_cashback` tanpa migrasi struktur).
- `sponsor_id` juga dipakai Volume Incentive? Tidak — Volume basis belanja GD, tak butuh sponsor. Retur clawback: pakai source_po_id/source_user_id komisi Sponsor.

## Di luar cakupan (fitur terpisah)
- **Insentif Volume Grand**, **Retur Distributor** (mekanik clawback aktual). Keduanya numpang fondasi ini + Model A.

## Migrasi & deploy
Migrasi **000087** (`sponsor_id`). Deploy nanti (sekali di akhir bareng Volume+Retur) = `git pull && migrate --force && optimize:clear` + hard-refresh.
