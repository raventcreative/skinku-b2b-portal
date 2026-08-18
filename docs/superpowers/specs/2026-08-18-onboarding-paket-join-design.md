# Onboarding / Paket Join — Desain

**Tanggal:** 2026-08-18
**Branch:** `feat/onboarding-paket-join` (dari `main` @ 1923918)
**Fase:** MLM — onboarding (pintu masuk reseller + bonus join; mengaktifkan sistem komisi yang selama ini dorman)

## Konsep

Admin/HQ mendaftarkan reseller baru lewat pilihan **Paket Join** (Bronze/Gold). Sekali submit, dalam **1 transaksi atomik** sistem: (1) membuat akun reseller di bawah upline (inviter), (2) memotong stok HQ sesuai isi paket, (3) mencatat transaksi paket + pembayaran, (4) mengkredit **bonus join 10%** dari nilai paket ke **saldo komisi** upline.

Bonus join **tidak auto-cair** — masuk saldo komisi dan hanya cair lewat alur penarikan biasa (mitra ajukan → HQ proses → transfer manual → tandai cair). Sama persis seperti komisi override.

## Keputusan (dikunci bareng user)

- **Input onboarding:** ADMIN/HQ (bukan upline sendiri / bukan registrasi publik — itu fase lanjut).
- **Isi paket:** DETAIL PRODUK (produk + qty per paket) → memotong stok HQ.
- **Stok reseller:** TIDAK dicatat di sistem. Cukup keluar dari HQ (produk fisik diterima reseller offline; konsisten dengan model reseller yang menu "Stok Saya"-nya disembunyikan).
- **Bonus join:** 10% dari nilai paket → **upline LANGSUNG (inviter)** → saldo komisi (status `saldo`). TIDAK auto-cair; cair lewat alur penarikan existing.
- **Scope onboarding-via-paket:** khusus **Reseller (Bronze/Gold)**. Distributor & Grand tetap diangkat manual lewat Kelola Anggota biasa (mereka bukan "join paket").
- **Pembayaran:** manual — admin menandai "sudah bayar" saat input (belum ada payment gateway).
- **Rate join:** dari `AppSetting` key `komisi_persen_join` (default 10, UI-nya sudah ada di Pengaturan → kartu Rate Komisi "Bonus Join").
- **Penempatan menu onboarding:** di area Kelola Anggota (tombol "Onboarding via Paket Join").

## Data — 3 tabel baru

- **Migrasi 000084 `join_packages`:** `id`, `name` (string, mis. "Bronze"/"Gold"), `price` (decimal(14,2)), `is_active` (boolean default true), `timestamps`.
- **Migrasi 000085 `join_package_items`:** `id`, `join_package_id` (FK `join_packages` cascadeOnDelete), `product_id` (FK `products` cascadeOnDelete), `qty` (unsignedInteger), `timestamps`. Index `[join_package_id]`.
- **Migrasi 000086 `join_transactions`:** `id`, `user_id` (FK `users`, reseller baru), `join_package_id` (FK `join_packages`, nullOnDelete), `inviter_id` (FK `users`, nullable, nullOnDelete — snapshot upline saat join), `price` (decimal(14,2), snapshot harga paket), `created_by` (FK `users`, admin yang input, nullOnDelete), `timestamps`. Index `[user_id]`, `[inviter_id]`.

## Komponen

1. **Model** (`app/Models/`): `JoinPackage` (fillable name/price/is_active; relasi `items()` hasMany `JoinPackageItem`), `JoinPackageItem` (fillable join_package_id/product_id/qty; relasi `product()`), `JoinTransaction` (fillable user_id/join_package_id/inviter_id/price/created_by; relasi `member()`/`package()`/`inviter()`).

2. **Katalog Paket (admin)** — halaman CRUD paket join: nama, harga, aktif/nonaktif, + daftar isi produk (produk + qty). Controller `JoinPackageController` (index/create/store/edit/update/destroy). View `join_packages/*`. Gate: izin baru `manage_join_packages` (default `[ROLE_ADMIN]`) supaya rapi & bisa diatur di Manajemen Hak Akses. Menu "Paket Join" di sidebar (dekat Kelola Anggota / Pengaturan), gated izin itu.

3. **Form Onboarding (admin)** — di area Kelola Anggota, tombol/route "Onboarding via Paket Join". Form isi: data reseller baru (name, fullname, username, email, password, company_name, region — reuse aturan validasi dari `UserController::store`), pilih **upline** (reuse pemilih upline / `PartnerHierarchyService::eligibleUplines`), pilih **paket** (dropdown paket aktif), tandai **sudah bayar**. Role otomatis dari paket: Bronze→`reseller_bronze`, Gold→`reseller_gold`. Controller `OnboardingController` (create/store). View `onboarding/create`. Gate: izin existing `manage_users`.

4. **`OnboardingService::onboard(array $data, JoinPackage $paket, ?int $uplineId, int $adminId): User`** (baru) — 1 `DB::transaction`:
   - Buat `User` (role dari paket, status active) + set upline via `PartnerHierarchyService::assignUpline` (reuse guard tingkat).
   - Cek stok HQ cukup untuk SEMUA item paket; kalau kurang → lempar `RuntimeException` (rollback) dengan pesan jelas.
   - `InventoryService::adjustHqStock(-qty, occurredAt: now, movementType: 'paket_join', ...)` per item.
   - Buat `JoinTransaction` (snapshot price + inviter + admin).
   - Panggil `CommissionService::recordJoinBonus($inviter, $newUser, $paket->price)` (kalau ada inviter partner).

5. **`CommissionService::recordJoinBonus(User $inviter, User $member, float $paketPrice): void`** (baru) — tulis `Commission`: `user_id`=inviter, `source_user_id`=member, `source_po_id`=null, `type`='join', `level`=1, `rate`=`AppSetting::float('komisi_persen_join', 10)`, `base_amount`=paketPrice, `amount`=round(price×rate/100, 2), `status`='saldo'. Guard: `$inviter->isPartner()` && `$rate > 0`. Append-only (masuk saldo → withdrawable lewat alur existing). Ini melengkapi engine komisi yang sekarang cuma nulis type 'override'.

6. **Tampil di:** Laporan Komisi (baris type 'join' sudah didukung view — label "Join"), riwayat paket join (list HQ opsional, dari `join_transactions`), Audit Log (`AuditService::log('onboard_reseller', ...)`).

## Aturan & guard

- Stok HQ kurang untuk salah satu item → onboarding GAGAL total (rollback): user tak dibuat, stok utuh, nol komisi. Pesan: "Stok HQ tidak cukup untuk paket [nama]".
- Paket harus `is_active` && punya ≥1 item untuk dipakai onboarding.
- Bonus join hanya jika inviter ada & partner; reseller tanpa upline (langsung HQ) → nol bonus (nihil, bukan error).
- Semua efek (user, stok, transaksi, komisi) dalam 1 DB transaction (atomik).
- Aturan tingkat: reseller (level 3) hanya boleh punya upline distributor/grand — ditegakkan `assignUpline` existing.

## Akuntansi

Bonus join = saldo/catatan (`Commission` append-only), BUKAN jurnal `acc_`. Cair lewat alur penarikan manual. Stok HQ keluar tercatat sebagai `stock_movement` (auditable, movementType `paket_join`).

## Testing

- **Onboarding sukses:** user baru dibuat (role Bronze/Gold benar + upline benar), stok HQ turun tepat sesuai isi paket, `JoinTransaction` tercatat, bonus join 10% ke inviter (status saldo).
- **Stok HQ kurang → gagal & rollback:** user tak dibuat, stok utuh, nol komisi, nol join_transaction.
- **Tanpa upline → nol bonus join,** user tetap dibuat.
- **Bonus join = 10% nilai paket** (Bronze 149rb → 14.900); ubah `komisi_persen_join` via AppSetting → ikut.
- **Bonus join TIDAK auto-cair:** status `saldo`, masuk `availableBalance`, butuh Ajukan Penarikan (tak ada withdrawal otomatis).
- **Katalog paket:** CRUD paket + item; paket nonaktif/kosong tak bisa dipakai onboarding.
- **Izin:** non-admin (mitra) tak bisa buka onboarding / katalog paket (403).

## Out of scope (fase lanjut)

- Onboarding oleh upline sendiri / registrasi publik.
- Payment gateway (bayar paket manual dulu).
- Insentif volume Grand (fitur terpisah).
- Distributor/Grand via paket-join.
- Pelacakan stok di sisi reseller.

## Zero-dependency

Blade + Eloquent + helper existing (InventoryService, CommissionService, PartnerHierarchyService, AuditService, AppSetting). Tanpa paket composer/npm baru. Deploy: `git pull` + `migrate --force` (000084-086) + `optimize:clear`.

## Self-review

- **Placeholder:** tak ada; tiap komponen + tabel + tes konkret.
- **Konsistensi:** bonus join pakai `komisi_persen_join` (yang UI-nya sudah dibangun Fase 3) + masuk saldo (append-only, sama seperti override) → nyambung dengan alur withdraw existing. Stok pakai `adjustHqStock` existing. Role dari paket konsisten (bronze/gold).
- **Ambiguitas:** stok reseller TIDAK dicatat (diputus); onboarding khusus reseller (diputus); bonus tidak auto-cair (diputus).
- **Scope:** satu plan cukup — katalog paket + onboarding + join bonus saling terkait erat, dorman-safe (tak ada efek sampai dipakai).
