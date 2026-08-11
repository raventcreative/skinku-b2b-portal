# MLM Hirarki Mitra — Tahap 3a: Harga Grand per Produk — Design

**Tanggal:** 2026-08-11
**Status:** Disetujui (siap masuk rencana implementasi)
**Bagian dari:** Tahap 3 (harga/komisi/rantai-pasok). Ini **sub-fase pertama** — dipecah kecil.
**Lanjutan dari:** Tahap 1 (pondasi hirarki, live) & Tahap 2 (visibilitas "Jaringan Saya", merged).

---

## 1. Konteks & Tujuan

Sekarang harga beli mitra cuma 2 level: `price_distributor` (dipakai distributor **dan** grand) & `price_reseller` (dipakai reseller/bronze/gold). Padahal Grand Distributor punya harga sendiri yang **lebih murah** dari distributor (lihat pricelist §3).

**Tujuan 3a:** Grand Distributor punya **harga beli produk sendiri per produk** (`price_grand`), lebih murah dari distributor. Semua tier lain **tidak berubah**.

**Bukan tujuan 3a** (ditunda):
- Biaya daftar/upgrade tier (149rb Bronze / 459rb Gold) — sub-fase onboarding sendiri.
- Komisi naik pohon — Tahap 3b.
- Gating stok reseller + purchasing routing — Tahap 3c.
- Membedakan harga beli Bronze vs Gold — **tidak** dilakukan (keputusan: keduanya tetap `price_reseller`; bedanya di biaya daftar & komisi, bukan harga beli).
- Update harga Reseller/Distributor/Het existing — di luar lingkup (3a cuma **menambah** kolom Grand).

---

## 2. Keputusan Inti (hasil brainstorming)

| Keputusan | Nilai |
|---|---|
| Level harga beli | **3 level**: Grand < Distributor < Reseller. (Bronze=Gold=reseller, tak dibedakan.) |
| Mekanisme harga Grand | **Kolom per-produk** `price_grand` (bukan % global) — angka di-set tangan per produk |
| Sumber data awal | **Pricelist resmi** (§3), di-seed pas migrasi (cocokkan nama produk) |
| Anti Rp0 | **Fallback**: kalau `price_grand` kosong → pakai `price_distributor` |
| Backfill rumus | **TIDAK** — diskon nyata variasi ~8–10% & dibulatkan tangan, rumus ×0.92 salah |

---

## 3. Data Harga Grand (dikonfirmasi user, dari PRICELIST SKINKU)

Kolom `Grand` = `price_grand` yang di-seed. (Het/Reseller/Distributor cuma referensi — **tidak** disentuh 3a.)

| Produk | Distributor (ref) | **Grand (seed)** |
|---|---|---|
| Sabun | 24.000 | **22.000** |
| Serum/Lotion | 37.000 | **34.000** |
| Scrub | 24.000 | **22.000** |
| Serum Wajah | 35.000 | **32.000** |
| Sabun Cair | 29.000 | **26.000** |
| Reina Underarm | 25.000 | **23.000** |
| Face Mist | 15.000 | **13.500** |
| Mouth Spray | 15.000 | **13.500** |
| Day Cream | 39.000 | **35.000** |
| Night Cream | 45.000 | **41.000** |

**Catatan seed:** cocokkan **nama produk** (case-insensitive, di-trim) dengan yang ada di DB. Produk yang namanya **tak cocok** (mis. DB pakai nama lain) → `price_grand` dibiarkan null → jatuh ke fallback (harga distributor) sampai admin isi manual via form. **Nama produk asli DB diverifikasi saat implementasi** (rencana), lalu peta seed disesuaikan.

---

## 4. Arsitektur & Komponen

### 4.1 Migrasi `2026_01_01_000076_add_price_grand_to_products.php`
- `up()`: `$table->decimal('price_grand', 15, 2)->nullable()->after('price_distributor');` (ikut presisi kolom harga lain; **nullable** — null = belum diset → fallback).
- Seed (dalam `up()`, setelah kolom dibuat): untuk tiap baris pricelist §3, `DB::table('products')->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(nama)])->update(['price_grand' => nilai])`. Idempoten & aman: cuma isi yang cocok, tak menimpa kolom lain.
- `down()`: `dropColumn('price_grand')`.

### 4.2 `App\Models\Product`
- `fillable` += `price_grand`.
- `casts` += `'price_grand' => 'decimal:2'`.
- **`priceForRole()`** — Grand baca kolom baru + fallback:
  ```php
  return match ($role) {
      User::ROLE_GRAND_DISTRIBUTOR => (float) ($this->price_grand ?? $this->price_distributor),
      User::ROLE_DISTRIBUTOR => (float) $this->price_distributor,
      User::ROLE_RESELLER, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD => (float) $this->price_reseller,
      default => (float) $this->price_retail,
  };
  ```
  (Grand tak lagi jatuh ke `price_distributor` secara statis — sekarang `price_grand` dulu, fallback distributor.)

### 4.3 Harga beli lewat SATU jalur (fallback-safe)
Masalah: `PurchaseOrderService` hitung harga PO dari `$product->{$buyer->priceField()}` (akses kolom mentah). Kalau `priceField()` balikin `'price_grand'` dan kolomnya null → **Grand kebeli Rp0**.

**Solusi:** alihkan pembacaan harga beli mitra ke **`Product::priceForRole($role)`** (punya fallback), bukan akses kolom mentah:
- `app/Services/PurchaseOrderService.php` — baris harga (non-override) jadi `$product->priceForRole($buyer->role)` (ganti `(float) $product->{$priceField}`). Logika `priceOverrides` tetap.
- `app/Http/Controllers/PurchaseOrderController.php::create` + view `purchase_orders/create` — harga tampil per produk pakai `$product->priceForRole($user->role)` (ganti pemakaian `$priceField`). Boleh via peta harga yang dikirim controller, atau panggil langsung di Blade (view punya `$user`).
- `User::priceField()` — **TIDAK diubah** (Grand tetap balikin `'price_distributor'`). Ini justru **jaring pengaman**: kalau ada konsumen lain yang masih akses kolom mentah `$product->{priceField}`, Grand dapat harga distributor (aman, tak Rp0) — bukan `price_grand` yang bisa null. Diskon Grand **hanya** diterapkan lewat `priceForRole()` yang sudah kita alihkan (PO service + tampil PO). Bonus: tes Tahap 1 yang mengecek `priceField()` Grand = `price_distributor` tetap hijau (nol regresi).

> ⚠️ Saat implementasi: cari **semua** pemakaian `priceField()` + `priceForRole()` (mis. export/laporan/backdated) — untuk tiap tempat yang menampilkan/menagih harga beli Grand, pastikan pakai `priceForRole` biar diskon Grand konsisten (tampil = tertagih) & tak ada mismatch.

### 4.4 Form Produk (`ProductController` + view create/edit)
- Tambah input **"Harga Grand Distributor"** dekat input `price_distributor`.
- Validasi: `'price_grand' => ['nullable','numeric','min:0']` (nullable → fallback kalau dikosongkan).
- `store()`/`update()`: masukkan `price_grand` ke data yang disimpan.
- **Helper UX (opsional, JS):** saat input distributor diisi, saran-isi `price_grand ≈ distributor − 8%` (cuma prefilled, bisa ditimpa). Boleh di-drop kalau nambah kompleksitas.

---

## 5. Dampak & Keamanan

- **Cuma Grand yang berubah.** Distributor/Bronze/Gold/Reseller/Retail persis seperti sekarang → nol regresi harga.
- **Grand tak akan pernah Rp0/retail**: fallback ke `price_distributor` bila `price_grand` null.
- **Additif**: kolom baru nullable, tak mengubah data existing lain. Seed cuma isi produk yang cocok nama.
- Zero-dependency. Semua klasifikasi harga tetap nyalur ke `priceForRole()` (dan `priceField()` yang selaras).

---

## 6. Rencana Pengujian

Runner lokal: `C:\php83\php.exe artisan test`. Pint `--dirty` sebelum commit.

**Unit — `Product::priceForRole()`:**
- Grand → `price_grand` bila terisi (mis. 22.000).
- Grand → **fallback `price_distributor`** bila `price_grand` null (bukan 0, bukan retail).
- Distributor → `price_distributor` (tak berubah).
- Bronze/Gold/Reseller → `price_reseller` (tak berubah).
- Non-mitra/role lain → `price_retail`.

**Feature — PO oleh Grand:**
- Grand bikin PO produk yang punya `price_grand` → harga baris = `price_grand`.
- Grand bikin PO produk yang `price_grand` null → harga baris = `price_distributor` (fallback, bukan 0).
- Distributor bikin PO → harga baris = `price_distributor` (regresi: tak berubah).
- `priceOverrides` tetap menang atas harga default.

**Feature — Form Produk:**
- Simpan produk dengan `price_grand` → tersimpan benar.
- Simpan produk tanpa `price_grand` (kosong) → null (fallback jalan saat dibaca).

**Migrasi/seed:**
- Setelah migrasi, produk yang namanya cocok pricelist punya `price_grand` sesuai §3 (mis. "Face Mist" → 13.500).
- Produk yang namanya tak ada di pricelist → `price_grand` null.

---

## 7. File yang Disentuh (ringkas)

**Buat:**
- `database/migrations/2026_01_01_000076_add_price_grand_to_products.php`
- Test: `tests/Feature/GrandPriceTest.php` (priceForRole + PO Grand + fallback), `tests/Feature/ProductGrandPriceFormTest.php` (form simpan)

**Ubah:**
- `app/Models/Product.php` (fillable + cast + priceForRole)
- `app/Services/PurchaseOrderService.php` (harga via priceForRole)
- `app/Http/Controllers/PurchaseOrderController.php` + `resources/views/purchase_orders/create.blade.php` (tampil harga via priceForRole)
- `app/Http/Controllers/ProductController.php` + view form produk (input + validasi + simpan price_grand)

**Tidak disentuh:** stok, komisi, PO routing, harga tier lain, halaman Jaringan Saya.

---

## 8. Deploy

ADA migrasi (kolom + seed) → 
```
git pull origin main && /opt/alt/php83/usr/bin/php artisan migrate --force && /opt/alt/php83/usr/bin/php artisan optimize:clear
```
+ hard-refresh. Seed jalan otomatis di `migrate`. Setelah deploy, cek: produk yang namanya cocok pricelist sudah punya harga Grand; sisanya diisi via form.

---

## 9. Jalur ke sub-fase berikut
Setelah 3a: **biaya daftar tier** (149/459, onboarding+pembayaran) · **Tahap 3b komisi** · **Tahap 3c rantai pasok**. Harga Grand di 3a jadi dasar hitung margin/komisi nanti.
