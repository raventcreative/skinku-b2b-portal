# MLM Hirarki Mitra — Tahap 2: Akses Bertingkat (Visibilitas Jaringan) — Design

**Tanggal:** 2026-08-11
**Status:** Disetujui (siap masuk rencana implementasi)
**Lanjutan dari:** `docs/superpowers/specs/2026-08-10-hirarki-mitra-mlm-tahap1-design.md` (Tahap 1 — pondasi hirarki, sudah live)

---

## 1. Konteks & Tujuan

Tahap 1 sudah membangun pohon mitra (role tier `grand_distributor` → `distributor` → `reseller_bronze`/`reseller_gold`, kolom `users.upline_id` + `users.member_id`, kanvas Struktur Jaringan untuk HQ). Sekarang setiap mitra masih hanya melihat **datanya sendiri** (`isPartner()` men-scope semua ke `user_id` sendiri di ~15 tempat: PO, penjualan, laporan, dsb.).

**Tujuan Tahap 2:** memberi mitra **upline** kemampuan **memantau performa jaringan bawahannya** (subtree) — read-only, ringkasan, tanpa mengintip data pribadi customer downline. Ini fitur inti "back-office genealogy" ala MLM: atasan bisa membina & melacak kaki jaringannya.

**Bukan tujuan Tahap 2** (ditunda ke Tahap 3): harga per tier, komisi naik pohon, gating stok reseller, alur beli dari upline. Tahap 2 **murni visibilitas** — tidak menyentuh harga, stok, PO, atau komisi.

---

## 2. Ruang Lingkup

### Termasuk (in scope)
- Halaman baru **"Jaringan Saya"** — read-only, khusus mitra tier yang punya downline.
- Menampilkan **seluruh subtree** upline (Grand lihat sampai reseller) sebagai pohon berindentasi, tiap node = **ringkasan kaya**.
- Perhitungan metrik per anggota dari `PartnerSale`: omzet bulan ini, tren 3 bulan, jumlah transaksi, status aktif, jumlah downline.
- Roll-up ringkasan jaringan di atas (total anggota / aktif / omzet jaringan bulan ini).
- Method baru `PartnerHierarchyService::descendants()`.
- Gate keamanan: mitra **hanya** bisa melihat subtree-nya sendiri (dijaga server).
- Feature/unit test penuh.

### Tidak termasuk (out of scope — Tahap 3)
- Harga per tier, diskon, komisi, insentif.
- Gating/penyembunyian fitur stok untuk reseller.
- Alur pembelian beli-dari-upline / fallback HQ.
- Manage-on-behalf (mengedit data downline dari halaman ini). **Tahap 2 read-only total.**
- Menampilkan **nama/kontak customer** downline ke mitra upline (lihat §4 privasi).
- Notifikasi / ekspor / ranking / leaderboard (bisa jadi polish nanti, bukan sekarang).

---

## 3. Model Visibilitas — 2 Lapis (keputusan inti)

Ini penegasan penting yang mengunci desain (hasil diskusi):

| Peran | Yang dilihat | Sumber | Berubah di Tahap 2? |
|---|---|---|---|
| **HQ (super_admin / admin)** = operator sistem | **BUKU PENUH**: semua user, **semua nama customer**, semua nota, semua laporan | Halaman admin existing (Kelola Anggota, Laporan, kanvas Struktur Jaringan = god-view) | **TIDAK** — akses HQ tidak disentuh/dikurangi sama sekali |
| **Mitra upline (Grand / Distributor)** = peserta jaringan | **RINGKASAN** subtree-nya sendiri: omzet, transaksi, aktif, tren, jumlah downline — **TANPA** nama customer | Halaman baru "Jaringan Saya" | **YA** — ini yang ditambah |
| **Mitra tanpa downline (reseller)** | Kosong ("belum punya jaringan") | — | — |

**Prinsip privasi:** aturan "sembunyikan customer" berlaku **antar-mitra** (upline tidak boleh lihat nama customer downline — cegah "colong customer", jaga kepercayaan tim), **bukan** untuk HQ. HQ perusahaan/operator → wajib pegang buku lengkap (fulfillment, pajak, sengketa, audit, hitung komisi nanti). Persis model MLM nyata: perusahaan lihat semua, upline lihat volume kakinya.

---

## 4. Keamanan & Privasi

1. **Scope subtree, server-enforced.** Data yang ditampilkan **selalu** dihitung dari `descendants(Auth::user())`. Tidak ada parameter `id` dari client yang menentukan "lihat jaringan siapa" — mitra tidak akan pernah bisa mengintip jaringan lain, bahkan dengan URL manipulasi.
2. **Read-only total.** Halaman ini nol aksi/mutasi — tidak ada tombol edit, hapus, pindah, ubah tier. Semua tindakan itu tetap di halaman HQ (Struktur Jaringan / Kelola Anggota).
3. **Nol PII customer untuk mitra.** Query untuk halaman ini **tidak pernah** menyeleksi kolom `partner_sales.customer_name` maupun `notes`. Hanya agregat (jumlah/omzet) yang keluar. (Test khusus memverifikasi `customer_name` tak bocor.)
4. **Gate akses.** Route di-guard `auth`; controller `abort_unless($user->isPartner(), 403)`. HQ (bukan partner) tidak melihat menu ini (mereka pakai god-view existing). Reseller tanpa downline boleh membuka tapi lihat kosong.

---

## 5. Arsitektur & Komponen

Ikuti pola existing (controller tipis + service + Blade + vanilla JS opsional). Zero-dependency.

### 5.1 `App\Services\PartnerHierarchyService::descendants(User $root): Collection`
Kembalikan **semua** user turunan di bawah `$root` (rekursif, semua level), aman dari loop.

```php
/** Semua turunan (semua level) di bawah $root, BFS per tingkat, aman-loop. */
public function descendants(User $root): Collection
{
    $all = collect();
    $frontierIds = collect([$root->id]);
    $depthGuard = 0;

    while ($frontierIds->isNotEmpty() && $depthGuard++ < 20) {
        $children = User::whereIn('upline_id', $frontierIds->all())
            ->orderBy('fullname')
            ->get();
        if ($children->isEmpty()) {
            break;
        }
        $all = $all->concat($children);
        $frontierIds = $children->pluck('id');
    }

    return $all; // Collection<User>, TIDAK termasuk $root
}
```
- Pohon terkunci-level (Tahap 1) → siklus mustahil; `depthGuard` cuma jaring pengaman.
- Jaringan kecil (puluhan–ratusan) → performa non-isu.

### 5.2 `App\Http\Controllers\JaringanSayaController`
Satu action `index(Request)`:
1. `$me = $request->user(); abort_unless($me->isPartner(), 403);`
2. `$members = $this->hierarchy->descendants($me);` → kalau kosong, render state "belum punya jaringan".
3. Hitung metrik untuk **semua** id di `$members` (lihat §6) — beberapa query ter-grup, **bukan** N+1.
4. Susun struktur pohon nested (parent→children) di PHP dari `$members` (pakai `upline_id`).
5. Hitung roll-up jaringan (total anggota, jumlah aktif, total omzet jaringan bulan ini).
6. Kirim ke view `jaringan_saya.index`.

Controller **tidak** menerima parameter node dari client. "Drill-down" diwujudkan sebagai **pohon berindentasi** yang menampilkan seluruh subtree sekaligus (bisa expand/collapse via JS opsional), sehingga tak ada endpoint per-node yang perlu diamankan.

### 5.3 Route
Di `routes/web.php`, dalam grup `auth` (sejajar partner-sales; gate isPartner ditangani controller):
```php
Route::get('jaringan-saya', [JaringanSayaController::class, 'index'])->name('jaringan-saya.index');
```

### 5.4 View `resources/views/jaringan_saya/index.blade.php`
- Header: judul + kartu roll-up (Total anggota · Aktif · Omzet jaringan bulan ini).
- Badge periode: "Bulan ini (Agustus 2026)".
- Pohon berindentasi via partial rekursif Blade (`_node.blade.php`), tiap node menampilkan:
  - Nama · Member ID · badge tier (warna per level, pakai `PartnerHierarchy::label()`/`levelOf()`) · region.
  - **Omzet bulan ini** (Rp) + **tren 3 bulan** (mini: `↑`/`↓`/`→` + 3 angka bulanan).
  - **Jumlah transaksi** bulan ini.
  - **Status aktif** (badge hijau "Aktif" bila ada penjualan ≤30 hari, abu "Pasif" bila tidak).
  - **Jumlah downline langsung**.
  - *(Opsional)* produk terlaris (nama + qty) — nice-to-have, boleh di-drop saat planning bila menambah kompleksitas.
- Empty state bila `$members` kosong.
- Tanpa kolom/teks apa pun yang memuat nama customer.
- **Progressive enhancement:** halaman berfungsi penuh tanpa JS; expand/collapse cabang = polish opsional (vanilla JS inline).

### 5.5 Navigasi (sidebar)
Di `resources/views/layouts/app.blade.php`, blok partner (dekat baris 84–91, area Dashboard/Riwayat PO), tambah:
```blade
@if($u->isPartner() && $u->downlines()->exists())
    {!! navItem('jaringan-saya.index', 'Jaringan Saya', 'jaringan-saya.index') !!}
@endif
```
Tampil hanya untuk mitra yang **punya** downline (kurangi clutter untuk reseller ujung). Route tetap aman kalau diakses langsung (render kosong).

---

## 6. Data & Perhitungan (dari `PartnerSale`)

Kolom relevan: `user_id` (penjual = mitra), `sold_at` (cast `date`), `total_amount` (cast `decimal:2`), relasi `items` (`PartnerSaleItem`: `product_id`, `qty`, `price`). **`customer_name` & `notes` tidak pernah diseleksi.**

**Portabilitas DB (penting):** test lokal pakai SQLite (`RefreshDatabase`), prod MySQL. **Hindari fungsi tanggal DB-spesifik** (`MONTH()`, `strftime`, `DATE_FORMAT`). Strategi: tarik baris mentah `PartnerSale (user_id, total_amount, sold_at)` untuk **rentang 3 bulan terakhir** atas seluruh id subtree dalam **satu query**, lalu **agregasi di PHP** (kelompokkan per `user_id` + per bulan). Jaringan kecil → aman.

Metrik per anggota (dihitung di PHP dari baris yang ditarik):
- **Omzet bulan ini** = Σ `total_amount` dengan `sold_at` di bulan berjalan.
- **Jumlah transaksi bulan ini** = COUNT baris bulan berjalan.
- **Tren 3 bulan** = Σ per masing-masing dari 3 bulan terakhir (mis. Jun/Jul/Agu) + arah panah (bandingkan bulan ini vs bulan lalu).
- **Status aktif** = `max(sold_at) >= today()->subDays(30)`.
- **Jumlah downline langsung** = COUNT anggota dengan `upline_id = node.id` (dari koleksi `$members`, bukan query baru).
- **Produk terlaris (opsional)** = agregasi `PartnerSaleItem.qty` per `product_id` (butuh join `items`; hanya bila fitur opsional diambil).

**Roll-up jaringan (header):**
- Total anggota = `$members->count()`.
- Aktif = jumlah anggota berstatus aktif.
- Omzet jaringan bulan ini = Σ omzet-bulan-ini seluruh anggota subtree.

Angka Rupiah diformat `number_format(x, 0, ',', '.')`. Bulan pakai `Illuminate\Support\Carbon` (jangan hardcode; hormati timezone app).

---

## 7. Edge Cases & Penanganan

| Kasus | Perilaku |
|---|---|
| Mitra tanpa downline (reseller ujung) buka halaman | Render empty state "Kamu belum punya jaringan." (200, bukan 403) |
| Non-partner (HQ/staf) akses route langsung | `abort(403)` (mereka pakai god-view existing) |
| Downline belum pernah jualan | Muncul di pohon dengan omzet Rp0, transaksi 0, status Pasif |
| Downline non-aktif (`status != active`) | Tetap muncul di pohon (badge "Nonaktif"); ikut dihitung anggota, tidak dihitung "aktif" |
| Subtree sangat dalam / anomali data | `depthGuard` 20 mencegah loop tak hingga |
| Anggota dipindah upline (Tahap 1) saat dibuka | Halaman selalu hitung fresh dari `upline_id` terkini |

---

## 8. Rencana Pengujian (feature/unit)

Runner lokal: `C:\php83\php.exe artisan test`. Pint `--dirty` sebelum commit.

**Unit — `PartnerHierarchyServiceTest` (tambah):**
- `descendants()` kembalikan seluruh subtree multi-level (Grand → distributor → bronze/gold), tidak termasuk root.
- `descendants()` kembalikan koleksi kosong untuk node daun.
- Dua Grand terpisah: `descendants()` Grand A tidak memuat anggota Grand B (isolasi).

**Feature — `JaringanSayaTest` (baru):**
- Grand melihat halaman berisi seluruh subtree (distributor + reseller di bawahnya).
- Distributor hanya melihat reseller-nya; **tidak** melihat jaringan distributor lain / Grand di atasnya.
- Omzet bulan ini, jumlah transaksi, status aktif, jumlah downline terhitung benar (seed `PartnerSale` beberapa bulan).
- Tren 3 bulan menampilkan 3 angka bulanan yang benar.
- Status aktif: penjualan ≤30 hari → "Aktif"; >30 hari → "Pasif".
- Reseller tanpa downline → 200 + empty state (bukan 403).
- Non-partner (admin/staf) → 403.
- **Privasi:** respons halaman **tidak memuat** `customer_name` downline (assertDontsee nama customer yang di-seed).
- Roll-up header (total anggota / aktif / omzet jaringan) benar.

**Navigasi — `JaringanSayaNavTest` (baru, opsional bisa digabung):**
- Mitra dengan downline → menu "Jaringan Saya" tampil.
- Mitra tanpa downline & non-partner → menu tidak tampil.

---

## 9. File yang Disentuh (ringkas)

**Buat:**
- `app/Http/Controllers/JaringanSayaController.php`
- `resources/views/jaringan_saya/index.blade.php` (+ `_node.blade.php` partial rekursif)
- `tests/Feature/JaringanSayaTest.php`
- (opsional) `tests/Feature/JaringanSayaNavTest.php`

**Ubah:**
- `app/Services/PartnerHierarchyService.php` (+ `descendants()`; + test di `PartnerHierarchyServiceTest.php`)
- `routes/web.php` (+ route `jaringan-saya.index`)
- `resources/views/layouts/app.blade.php` (+ nav item di blok partner)

**Tidak disentuh:** harga, stok, PO, komisi, `isPartner()` scoping existing di ~15 halaman (mereka tetap "data sendiri"). Nol risiko regresi ke sistem berjalan.

---

## 10. Deploy

Sama seperti biasa (tanpa migrasi — Tahap 2 tak menambah kolom):
```
git pull origin main && /opt/alt/php83/usr/bin/php artisan optimize:clear
```
+ hard-refresh browser (view/JS inline bisa ke-cache). Hermes sudah dihapus → tak perlu ritual stash.

---

## 11. Jalur ke Tahap 3 (catatan, bukan lingkup sekarang)
Setelah visibilitas mapan, Tahap 3 membangun: harga per tier (Grand diskon / Bronze / Gold), komisi naik pohon (butuh `descendants()`/`ancestors()` yang sudah ada), gating stok reseller, alur beli-dari-upline. Halaman "Jaringan Saya" nanti bisa ditambah kolom komisi tanpa ubah struktur.
