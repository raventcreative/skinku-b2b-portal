# Kalkulator ROI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fitur "Kalkulator ROI" — per produk (tarik dari katalog), hitung potongan platform TikTok + profit bersih + target ROI/ROAS iklan (dengan & tanpa affiliate) + rata-rata, dengan setelan % global yang bisa di-override per produk, tersimpan, di menu sidebar sendiri.

**Architecture:** Dua tabel baru (`roi_settings` singleton untuk default % global; `roi_items` baris produk tersimpan dengan kolom override nullable). Perhitungan murni di `RoiCalculatorService` (pisah antara math `compute()` dan resolusi override/summary). Satu controller CRUD + satu halaman Blade (panel setelan + tabel produk + footer rata-rata). Zero-dependency, ikut pola modul yang sudah ada (mis. `MarketplaceStockController`).

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind (inline, zero-dep), PHPUnit (class-style).

**Spec:** `docs/superpowers/specs/2026-09-24-kalkulator-roi-design.md`

## Global Constraints

- **Zero-dependency:** tidak menambah package composer/npm apa pun. Tulis helper minimal bila perlu.
- **Test framework:** PHPUnit **class-style** (repo TIDAK punya Pest). `Product` dibuat langsung via `Product::create([...])` di tes (repo TIDAK punya factory Product). Pola helper `admin()`/`product()` ikut `tests/Feature/MarketplaceStock/MarketplaceUiTest.php`.
- **Local test runner:** `C:\php83\php.exe artisan test`. Format sebelum commit: `C:\php83\php.exe vendor/bin/pint --dirty`.
- **Blade:** JANGAN pakai `@json([...])` dengan array literal (menyebabkan 500). Semua aksi form = POST + `@csrf`. Picker produk = native `<select>`.
- **Izin/sidebar:** gate pakai `$u->canDo('manage_roi_calculator')` (BUKAN `@can`/Gate — tidak ada Gate terdaftar). Rute dibungkus middleware `permission:manage_roi_calculator`.
- **Branch:** `feat/kalkulator-roi` (sudah dibuat dari main; spec sudah di-commit `1a00abb`).
- **Persen** disimpan sebagai angka persen (mis. `8` untuk 8%), rupiah sebagai integer. Nilai "kosong = warisi" (blank pada override → null → ikut global; modal blank → null → ikut COGS). Laravel default `ConvertEmptyStringsToNull` sudah aktif, jadi input kosong dari form tiba sebagai `null`.

---

## File Structure

**Buat:**
- `database/migrations/2026_01_01_000141_create_roi_calculator_tables.php` — 2 tabel (`roi_settings`, `roi_items`).
- `app/Models/RoiSetting.php` — singleton setelan global (`DEFAULTS`, `current()`).
- `app/Models/RoiItem.php` — baris produk (casts, relasi `product()`).
- `app/Services/RoiCalculatorService.php` — `compute()` (math murni) + `effectiveInputs()`/`rowFor()`/`summary()` (resolusi override + rata-rata).
- `app/Http/Controllers/RoiCalculatorController.php` — index + saveSettings + storeItem + updateItem + deleteItem.
- `resources/views/roi-calculator/index.blade.php` — halaman (tabel + setelan + footer).
- Tes: `tests/Unit/RoiCalculatorComputeTest.php`, `tests/Unit/RoiCalculatorResolveTest.php`, `tests/Feature/RoiCalculator/RoiCalculatorPageTest.php`, `tests/Feature/RoiCalculator/RoiCalculatorSettingsTest.php`, `tests/Feature/RoiCalculator/RoiCalculatorItemsTest.php`.

**Ubah:**
- `app/Support/Permissions.php` — daftarkan `manage_roi_calculator` di `DEFINITIONS` + `DEFAULTS`.
- `routes/web.php` — import controller + grup rute `permission:manage_roi_calculator`.
- `resources/views/layouts/app.blade.php` — item sidebar berdiri sendiri "Kalkulator ROI" + arm `navIcon`.
- `docs/SISTEM.md`, `docs/PETA-SISTEM.md` — catat modul baru.

---

### Task 1: Skema + model + izin

**Files:**
- Create: `database/migrations/2026_01_01_000141_create_roi_calculator_tables.php`
- Create: `app/Models/RoiSetting.php`
- Create: `app/Models/RoiItem.php`
- Modify: `app/Support/Permissions.php` (tambah baris di `DEFINITIONS` sesudah `'manage_marketplace_stock'` dan di `DEFAULTS` sesudah `'manage_marketplace_stock'`)
- Test: `tests/Unit/RoiCalculatorResolveTest.php` (dipakai lagi di Task 3; di task ini isinya baru tes model+izin)

**Interfaces:**
- Produces:
  - `RoiSetting::DEFAULTS` (array), `RoiSetting::current(): RoiSetting` (baris id=1, dibuat dgn DEFAULTS bila belum ada). Properti: `admin_pct, voucher_pct, komisi_pct, komisi_cap, mall_pct, pajak_pct, operasional_pct, affiliate_pct` (float/int), `packing_default, proses_order_default` (int).
  - `RoiItem` fillable: `product_id, selling_price, modal, packing, proses_order, admin_pct, voucher_pct, komisi_pct, komisi_cap, mall_pct, pajak_pct, operasional_pct, affiliate_pct`. Relasi `product(): BelongsTo`. Semua override + modal/packing/proses **nullable**; pct di-cast `float`, uang di-cast `integer`.
  - Izin `manage_roi_calculator` (default `[User::ROLE_ADMIN]`, super_admin implisit).

- [ ] **Step 1: Tulis migrasi**

`database/migrations/2026_01_01_000141_create_roi_calculator_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setelan biaya global (default %/cap/rupiah) — 1 baris (id=1).
        Schema::create('roi_settings', function (Blueprint $t) {
            $t->id();
            $t->decimal('admin_pct', 6, 3)->default(8);
            $t->decimal('voucher_pct', 6, 3)->default(4.5);
            $t->decimal('komisi_pct', 6, 3)->default(5.5);
            $t->unsignedBigInteger('komisi_cap')->default(650000);
            $t->decimal('mall_pct', 6, 3)->default(1.8);
            $t->decimal('pajak_pct', 6, 3)->default(0.5);
            $t->decimal('operasional_pct', 6, 3)->default(3);
            $t->decimal('affiliate_pct', 6, 3)->default(5);
            $t->unsignedInteger('packing_default')->default(1000);
            $t->unsignedInteger('proses_order_default')->default(1250);
            $t->timestamps();
        });

        // Baris produk tersimpan. Kolom override nullable = "ikut global";
        // modal null = "ikut COGS produk".
        Schema::create('roi_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('selling_price')->default(0);
            $t->unsignedBigInteger('modal')->nullable();
            $t->unsignedInteger('packing')->nullable();
            $t->unsignedInteger('proses_order')->nullable();
            $t->decimal('admin_pct', 6, 3)->nullable();
            $t->decimal('voucher_pct', 6, 3)->nullable();
            $t->decimal('komisi_pct', 6, 3)->nullable();
            $t->unsignedBigInteger('komisi_cap')->nullable();
            $t->decimal('mall_pct', 6, 3)->nullable();
            $t->decimal('pajak_pct', 6, 3)->nullable();
            $t->decimal('operasional_pct', 6, 3)->nullable();
            $t->decimal('affiliate_pct', 6, 3)->nullable();
            $t->timestamps();
            $t->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roi_items');
        Schema::dropIfExists('roi_settings');
    }
};
```

- [ ] **Step 2: Tulis model `RoiSetting`**

`app/Models/RoiSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoiSetting extends Model
{
    protected $fillable = [
        'admin_pct', 'voucher_pct', 'komisi_pct', 'komisi_cap', 'mall_pct',
        'pajak_pct', 'operasional_pct', 'affiliate_pct', 'packing_default', 'proses_order_default',
    ];

    protected function casts(): array
    {
        return [
            'admin_pct' => 'float', 'voucher_pct' => 'float', 'komisi_pct' => 'float',
            'komisi_cap' => 'integer', 'mall_pct' => 'float', 'pajak_pct' => 'float',
            'operasional_pct' => 'float', 'affiliate_pct' => 'float',
            'packing_default' => 'integer', 'proses_order_default' => 'integer',
        ];
    }

    /** Default pabrik (dipakai saat baris belum ada). */
    public const DEFAULTS = [
        'admin_pct' => 8, 'voucher_pct' => 4.5, 'komisi_pct' => 5.5, 'komisi_cap' => 650000,
        'mall_pct' => 1.8, 'pajak_pct' => 0.5, 'operasional_pct' => 3, 'affiliate_pct' => 5,
        'packing_default' => 1000, 'proses_order_default' => 1250,
    ];

    /** Baris setelan tunggal (id=1); dibuat dgn DEFAULTS bila belum ada. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], self::DEFAULTS);
    }
}
```

- [ ] **Step 3: Tulis model `RoiItem`**

`app/Models/RoiItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoiItem extends Model
{
    protected $fillable = [
        'product_id', 'selling_price', 'modal', 'packing', 'proses_order',
        'admin_pct', 'voucher_pct', 'komisi_pct', 'komisi_cap', 'mall_pct',
        'pajak_pct', 'operasional_pct', 'affiliate_pct',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'integer', 'modal' => 'integer', 'packing' => 'integer',
            'proses_order' => 'integer', 'komisi_cap' => 'integer',
            'admin_pct' => 'float', 'voucher_pct' => 'float', 'komisi_pct' => 'float',
            'mall_pct' => 'float', 'pajak_pct' => 'float', 'operasional_pct' => 'float',
            'affiliate_pct' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 4: Daftarkan izin**

Di `app/Support/Permissions.php`, tambah baris terakhir array `DEFINITIONS` (sesudah `'manage_marketplace_stock' => 'Kontrol Stok Marketplace',`):

```php
        'manage_roi_calculator' => 'Kalkulator ROI',
```

Dan baris terakhir array `DEFAULTS` (sesudah `'manage_marketplace_stock' => [User::ROLE_ADMIN],`):

```php
        'manage_roi_calculator' => [User::ROLE_ADMIN],
```

- [ ] **Step 5: Tulis tes model+izin (gagal dulu)**

`tests/Unit/RoiCalculatorResolveTest.php` (task ini hanya bagian model+izin; method resolusi service ditambah di Task 3):

```php
<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoiCalculatorResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_membuat_baris_setelan_dengan_default(): void
    {
        $s = RoiSetting::current();

        $this->assertSame(1, $s->id);
        $this->assertSame(8.0, $s->admin_pct);
        $this->assertSame(650000, $s->komisi_cap);
        $this->assertSame(1250, $s->proses_order_default);
        // Panggilan kedua tidak membuat baris baru.
        RoiSetting::current();
        $this->assertSame(1, RoiSetting::count());
    }

    public function test_item_punya_relasi_product(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]);

        $this->assertSame('Sabun', $item->product->name);
        $this->assertNull($item->modal);        // nullable -> ikut COGS nanti
        $this->assertNull($item->admin_pct);    // nullable -> ikut global nanti
    }

    public function test_izin_default_admin_saja(): void
    {
        $this->assertTrue(Permissions::roleHas(User::ROLE_ADMIN, 'manage_roi_calculator'));
        $this->assertTrue(Permissions::roleHas(User::ROLE_SUPER_ADMIN, 'manage_roi_calculator'));
        $this->assertFalse(Permissions::roleHas(User::ROLE_RESELLER, 'manage_roi_calculator'));
    }
}
```

- [ ] **Step 6: Jalankan tes — pastikan hijau**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorResolveTest`
Expected: 3 passed (migrasi jalan otomatis via RefreshDatabase).

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Models/RoiSetting.php app/Models/RoiItem.php app/Support/Permissions.php database/migrations/2026_01_01_000141_create_roi_calculator_tables.php tests/Unit/RoiCalculatorResolveTest.php
git commit -m "feat(roi): skema roi_settings/roi_items + model + izin manage_roi_calculator"
```

---

### Task 2: `RoiCalculatorService::compute()` (math murni)

**Files:**
- Create: `app/Services/RoiCalculatorService.php`
- Test: `tests/Unit/RoiCalculatorComputeTest.php`

**Interfaces:**
- Produces: `RoiCalculatorService::compute(array $in): array`.
  - `$in` (semua sudah "efektif", bukan nullable): `selling_price, modal, packing, proses_order` (angka rupiah), `admin_pct, voucher_pct, komisi_pct, mall_pct, pajak_pct, operasional_pct, affiliate_pct` (angka persen mis. 8), `komisi_cap` (rupiah; 0 = tanpa cap).
  - Return array key: `profit, admin, voucher, komisi, mall, pajak, operasional, total_biaya, profit_bersih, affiliate, profit_after_aff, bep_roi, bep_roi_aff, target_noaff, target_aff, avg_min, avg_optimum`. `target_noaff`/`target_aff` = array `[5=>?float,10=>?float,15=>?float,20=>?float]`. Semua nilai ROI (`bep_*`, `target_*`, `avg_*`) = `?float` (**null bila penyebut ≤ 0** = rugi/tak tercapai). Nilai uang = `float`.

- [ ] **Step 1: Tulis tes math (gagal dulu)**

`tests/Unit/RoiCalculatorComputeTest.php` (murni, tanpa DB):

```php
<?php

namespace Tests\Unit;

use App\Services\RoiCalculatorService;
use Tests\TestCase;

class RoiCalculatorComputeTest extends TestCase
{
    private function svc(): RoiCalculatorService
    {
        return new RoiCalculatorService();
    }

    /** Acuan Excel baris 7 (Sabun). */
    private function row7(): array
    {
        return [
            'selling_price' => 39000, 'modal' => 13755, 'packing' => 2000, 'proses_order' => 1250,
            'admin_pct' => 8, 'voucher_pct' => 4.5, 'komisi_pct' => 5.5, 'komisi_cap' => 650000,
            'mall_pct' => 1.8, 'pajak_pct' => 0.5, 'operasional_pct' => 3, 'affiliate_pct' => 5,
        ];
    }

    public function test_biaya_dan_profit_cocok_dengan_excel_baris_7(): void
    {
        $r = $this->svc()->compute($this->row7());

        $this->assertSame(25245.0, $r['profit']);
        $this->assertSame(3120.0, $r['admin']);
        $this->assertSame(1755.0, $r['voucher']);
        $this->assertSame(2145.0, $r['komisi']);
        $this->assertSame(702.0, $r['mall']);
        $this->assertSame(195.0, $r['pajak']);
        $this->assertSame(1170.0, $r['operasional']);
        // Total Biaya = SUM(G:M) termasuk proses order (1250).
        $this->assertSame(10337.0, $r['total_biaya']);
        // Profit Bersih = Harga - Modal - Packing - Total Biaya.
        $this->assertSame(12908.0, $r['profit_bersih']);
        $this->assertEqualsWithDelta(3.0213, $r['bep_roi'], 0.001);
    }

    public function test_target_roi_dan_rata_rata(): void
    {
        $r = $this->svc()->compute($this->row7());

        // affiliate 5% * 39000 = 1950 -> profit_after_aff = 12908 - 1950 = 10958
        $this->assertSame(1950.0, $r['affiliate']);
        $this->assertSame(10958.0, $r['profit_after_aff']);
        // Target 10% tanpa aff = 39000 / (12908 - 3900) = 39000/9008
        $this->assertEqualsWithDelta(39000 / 9008, $r['target_noaff'][10], 0.001);
        // Target 10% dgn aff = 39000 / (10958 - 3900) = 39000/7058
        $this->assertEqualsWithDelta(39000 / 7058, $r['target_aff'][10], 0.001);
        // Rata2 Min (10%) = (target_noaff[10] + target_aff[10]) / 2
        $this->assertEqualsWithDelta(((39000 / 9008) + (39000 / 7058)) / 2, $r['avg_min'], 0.001);
    }

    public function test_komisi_kena_cap(): void
    {
        $in = $this->row7();
        $in['selling_price'] = 20000000; // 5.5% = 1.100.000 > cap 650.000
        $r = $this->svc()->compute($in);

        $this->assertSame(650000.0, $r['komisi']);
    }

    public function test_cap_nol_berarti_tanpa_cap(): void
    {
        $in = $this->row7();
        $in['selling_price'] = 20000000;
        $in['komisi_cap'] = 0;
        $r = $this->svc()->compute($in);

        $this->assertSame(1100000.0, $r['komisi']); // 5.5% * 20jt
    }

    public function test_profit_bersih_negatif_membuat_roi_null(): void
    {
        $in = $this->row7();
        $in['modal'] = 39000; // profit 0 -> profit_bersih negatif
        $r = $this->svc()->compute($in);

        $this->assertTrue($r['profit_bersih'] < 0);
        $this->assertNull($r['bep_roi']);
        $this->assertNull($r['target_noaff'][10]);
        $this->assertNull($r['avg_min']);
    }

    public function test_target_null_saat_penyebut_habis_walau_profit_positif(): void
    {
        // profit_bersih kecil, x*price besar -> penyebut <= 0 -> null utk target itu.
        $in = [
            'selling_price' => 10000, 'modal' => 8000, 'packing' => 0, 'proses_order' => 0,
            'admin_pct' => 0, 'voucher_pct' => 0, 'komisi_pct' => 0, 'komisi_cap' => 0,
            'mall_pct' => 0, 'pajak_pct' => 0, 'operasional_pct' => 0, 'affiliate_pct' => 0,
        ];
        $r = $this->svc()->compute($in);

        $this->assertSame(2000.0, $r['profit_bersih']);      // 10000-8000
        $this->assertEqualsWithDelta(5.0, $r['bep_roi'], 0.0001); // 10000/2000
        // Target 20% -> penyebut = 2000 - 2000 = 0 -> null
        $this->assertNull($r['target_noaff'][20]);
    }
}
```

- [ ] **Step 2: Jalankan tes — pastikan gagal**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorComputeTest`
Expected: FAIL ("Class RoiCalculatorService not found" / method compute tidak ada).

- [ ] **Step 3: Tulis `RoiCalculatorService::compute()`**

`app/Services/RoiCalculatorService.php`:

```php
<?php

namespace App\Services;

/**
 * Kalkulator ROI TikTok Shop: dari Harga Jual + Modal + potongan platform,
 * hitung Profit Bersih dan target ROI/ROAS iklan (dgn & tanpa affiliate).
 * compute() murni (tanpa DB) — semua input sudah "efektif" (bukan nullable).
 */
class RoiCalculatorService
{
    public function compute(array $in): array
    {
        $price = (float) ($in['selling_price'] ?? 0);
        $modal = (float) ($in['modal'] ?? 0);
        $packing = (float) ($in['packing'] ?? 0);
        $proses = (float) ($in['proses_order'] ?? 0);

        $pct = fn (string $k) => (float) ($in[$k] ?? 0) / 100 * $price;

        $profit = $price - $modal;
        $admin = $pct('admin_pct');
        $voucher = $pct('voucher_pct');
        $komisiRaw = $pct('komisi_pct');
        $cap = (float) ($in['komisi_cap'] ?? 0);
        $komisi = $cap > 0 ? min($komisiRaw, $cap) : $komisiRaw;
        $mall = $pct('mall_pct');
        $pajak = $pct('pajak_pct');
        $operasional = $pct('operasional_pct');

        // Total Biaya = SUM(G:M): admin+voucher+komisi+proses+mall+pajak+operasional.
        $totalBiaya = $admin + $voucher + $komisi + $proses + $mall + $pajak + $operasional;
        $profitBersih = $profit - $packing - $totalBiaya;
        $affiliate = $pct('affiliate_pct');
        $profitAfterAff = $profitBersih - $affiliate;

        // ROI = harga / penyebut; penyebut <= 0 => null (rugi / target tak tercapai).
        $roi = fn (float $denom): ?float => $denom > 0 ? $price / $denom : null;
        $target = fn (float $base, int $x): ?float => $roi($base - ($x / 100 * $price));

        $targetNoaff = [
            5 => $target($profitBersih, 5), 10 => $target($profitBersih, 10),
            15 => $target($profitBersih, 15), 20 => $target($profitBersih, 20),
        ];
        $targetAff = [
            5 => $target($profitAfterAff, 5), 10 => $target($profitAfterAff, 10),
            15 => $target($profitAfterAff, 15), 20 => $target($profitAfterAff, 20),
        ];

        $avg = fn (?float $a, ?float $b): ?float => ($a !== null && $b !== null) ? ($a + $b) / 2 : null;

        return [
            'profit' => $profit,
            'admin' => $admin,
            'voucher' => $voucher,
            'komisi' => $komisi,
            'mall' => $mall,
            'pajak' => $pajak,
            'operasional' => $operasional,
            'total_biaya' => $totalBiaya,
            'profit_bersih' => $profitBersih,
            'affiliate' => $affiliate,
            'profit_after_aff' => $profitAfterAff,
            'bep_roi' => $roi($profitBersih),
            'bep_roi_aff' => $roi($profitAfterAff),
            'target_noaff' => $targetNoaff,
            'target_aff' => $targetAff,
            'avg_min' => $avg($targetNoaff[10], $targetAff[10]),
            'avg_optimum' => $avg($targetNoaff[20], $targetAff[20]),
        ];
    }
}
```

- [ ] **Step 4: Jalankan tes — pastikan hijau**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorComputeTest`
Expected: 6 passed.

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/RoiCalculatorService.php tests/Unit/RoiCalculatorComputeTest.php
git commit -m "feat(roi): RoiCalculatorService::compute (biaya+profit+target ROI, cap komisi, guard bagi-nol)"
```

---

### Task 3: Resolusi override + summary (`effectiveInputs`/`rowFor`/`summary`)

**Files:**
- Modify: `app/Services/RoiCalculatorService.php` (tambah 3 method)
- Test: `tests/Unit/RoiCalculatorResolveTest.php` (tambah tes resolusi ke file yang dibuat di Task 1)

**Interfaces:**
- Consumes: `RoiCalculatorService::compute()`, `RoiItem`, `RoiSetting`, `Product::cogs`.
- Produces:
  - `effectiveInputs(RoiItem $item, RoiSetting $s): array` — gabung override baris dengan global; `modal ?? round(product.cogs)`; `packing/proses ?? default`; tiap pct `?? global`. Bentuk array = input `compute()`.
  - `rowFor(RoiItem $item, RoiSetting $s): array` → `['item'=>RoiItem, 'product'=>?Product, 'in'=>array, 'result'=>array]`.
  - `summary(iterable $rows): array` → `['avg_min_all'=>?float, 'avg_optimum_all'=>?float]` (rata-rata `avg_min`/`avg_optimum` dari baris yang tidak null; kosong → null).

- [ ] **Step 1: Tambah tes resolusi (gagal dulu)**

Tambahkan method berikut ke class `Tests\Unit\RoiCalculatorResolveTest` (yang dibuat di Task 1):

```php
    private function svc(): \App\Services\RoiCalculatorService
    {
        return new \App\Services\RoiCalculatorService();
    }

    public function test_effective_inputs_pakai_cogs_saat_modal_null(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]); // modal null
        $s = RoiSetting::current();

        $in = $this->svc()->effectiveInputs($item, $s);

        $this->assertSame(13755, $in['modal']);         // dari COGS
        $this->assertSame(1000, $in['packing']);        // default global
        $this->assertSame(1250, $in['proses_order']);   // default global
        $this->assertSame(8.0, $in['admin_pct']);       // global
        $this->assertSame(650000, $in['komisi_cap']);   // global
    }

    public function test_override_baris_menang_atas_global(): void
    {
        $p = Product::create(['name' => 'Serum', 'sku' => 'SR-1', 'status' => 'active', 'cogs' => 20000]);
        $item = RoiItem::create([
            'product_id' => $p->id, 'selling_price' => 50000,
            'modal' => 18000, 'packing' => 3000, 'admin_pct' => 10, 'komisi_cap' => 100000,
        ]);
        $s = RoiSetting::current();

        $in = $this->svc()->effectiveInputs($item, $s);

        $this->assertSame(18000, $in['modal']);      // override, bukan COGS
        $this->assertSame(3000, $in['packing']);     // override
        $this->assertSame(10.0, $in['admin_pct']);   // override
        $this->assertSame(100000, $in['komisi_cap']); // override
        $this->assertSame(4.5, $in['voucher_pct']);  // tak di-override -> global
    }

    public function test_summary_merata_ratakan_dan_lewati_null(): void
    {
        $svc = $this->svc();
        $rows = [
            ['result' => ['avg_min' => 4.0, 'avg_optimum' => 6.0]],
            ['result' => ['avg_min' => 2.0, 'avg_optimum' => 8.0]],
            ['result' => ['avg_min' => null, 'avg_optimum' => null]], // rugi -> dilewati
        ];

        $sum = $svc->summary($rows);

        $this->assertSame(3.0, $sum['avg_min_all']);       // (4+2)/2
        $this->assertSame(7.0, $sum['avg_optimum_all']);   // (6+8)/2
    }

    public function test_row_for_merangkai_item_product_dan_result(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'packing' => 2000]);
        $row = $this->svc()->rowFor($item, RoiSetting::current());

        $this->assertSame($p->id, $row['product']->id);
        $this->assertSame(12908.0, $row['result']['profit_bersih']); // cocok Excel baris 7
    }
```

- [ ] **Step 2: Jalankan tes — pastikan gagal**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorResolveTest`
Expected: FAIL (method `effectiveInputs`/`rowFor`/`summary` belum ada).

- [ ] **Step 3: Tambah 3 method ke service**

Tambahkan ke `app/Services/RoiCalculatorService.php` (import `use App\Models\RoiItem; use App\Models\RoiSetting;` di atas class):

```php
    /** Gabung override baris dgn setelan global; modal null -> COGS produk. */
    public function effectiveInputs(RoiItem $item, RoiSetting $s): array
    {
        $cogs = (int) round((float) ($item->product->cogs ?? 0));

        return [
            'selling_price' => (int) $item->selling_price,
            'modal' => $item->modal ?? $cogs,
            'packing' => $item->packing ?? $s->packing_default,
            'proses_order' => $item->proses_order ?? $s->proses_order_default,
            'admin_pct' => $item->admin_pct ?? $s->admin_pct,
            'voucher_pct' => $item->voucher_pct ?? $s->voucher_pct,
            'komisi_pct' => $item->komisi_pct ?? $s->komisi_pct,
            'komisi_cap' => $item->komisi_cap ?? $s->komisi_cap,
            'mall_pct' => $item->mall_pct ?? $s->mall_pct,
            'pajak_pct' => $item->pajak_pct ?? $s->pajak_pct,
            'operasional_pct' => $item->operasional_pct ?? $s->operasional_pct,
            'affiliate_pct' => $item->affiliate_pct ?? $s->affiliate_pct,
        ];
    }

    /** Satu baris siap-render: item + produk + input efektif + hasil hitung. */
    public function rowFor(RoiItem $item, RoiSetting $s): array
    {
        $in = $this->effectiveInputs($item, $s);

        return [
            'item' => $item,
            'product' => $item->product,
            'in' => $in,
            'result' => $this->compute($in),
        ];
    }

    /** Rata-rata Target ROI Min & Optimum lintas baris (lewati yang null). */
    public function summary(iterable $rows): array
    {
        $mins = [];
        $opts = [];
        foreach ($rows as $r) {
            if (($r['result']['avg_min'] ?? null) !== null) {
                $mins[] = $r['result']['avg_min'];
            }
            if (($r['result']['avg_optimum'] ?? null) !== null) {
                $opts[] = $r['result']['avg_optimum'];
            }
        }

        return [
            'avg_min_all' => $mins ? array_sum($mins) / count($mins) : null,
            'avg_optimum_all' => $opts ? array_sum($opts) / count($opts) : null,
        ];
    }
```

- [ ] **Step 4: Jalankan tes — pastikan hijau**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorResolveTest`
Expected: 7 passed (3 dari Task 1 + 4 baru).

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/RoiCalculatorService.php tests/Unit/RoiCalculatorResolveTest.php
git commit -m "feat(roi): resolusi override->global + modal->COGS + summary rata-rata target"
```

---

### Task 4: Controller index + rute + sidebar + halaman baca (tabel hasil)

**Files:**
- Create: `app/Http/Controllers/RoiCalculatorController.php` (method `index()`; method lain ditambah Task 5-6)
- Create: `resources/views/roi-calculator/index.blade.php` (alerts + tabel hasil + footer + empty state; form ditambah Task 5-6)
- Modify: `routes/web.php` (import + grup rute, baru rute index)
- Modify: `resources/views/layouts/app.blade.php` (arm `navIcon` + item sidebar)
- Test: `tests/Feature/RoiCalculator/RoiCalculatorPageTest.php`

**Interfaces:**
- Consumes: `RoiCalculatorService::rowFor()/summary()`, `RoiSetting::current()`, `RoiItem`, `Product`.
- Produces:
  - Rute bernama `roi-calculator.index` (GET `/kalkulator-roi`), grup middleware `permission:manage_roi_calculator`.
  - `RoiCalculatorController::index(RoiCalculatorService $svc): View` → view `roi-calculator.index` dengan `settings` (RoiSetting), `rows` (Collection hasil `rowFor`, urut nama produk), `summary` (array), `products` (produk yang BELUM ada di roi_items, untuk dropdown Tambah di Task 6).

- [ ] **Step 1: Tulis tes halaman (gagal dulu)**

`tests/Feature/RoiCalculator/RoiCalculatorPageTest.php`:

```php
<?php

namespace Tests\Feature\RoiCalculator;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoiCalculatorPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'admin', 'fullname' => 'Admin', 'username' => 'admin'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function reseller(): User
    {
        return User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_halaman_menampilkan_hasil_hitung_untuk_baris_tersimpan(): void
    {
        $p = Product::create(['name' => 'Sabun Wajah', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'packing' => 2000]);

        $this->actingAs($this->admin())
            ->get('/kalkulator-roi')
            ->assertOk()
            ->assertSee('Kalkulator ROI')
            ->assertSee('Sabun Wajah')
            ->assertSee('12.908')   // Profit Bersih (format ribuan Indonesia)
            ->assertSee('3,02');    // BEP ROI (2 desimal, koma)
    }

    public function test_empty_state_saat_belum_ada_baris(): void
    {
        $this->actingAs($this->admin())
            ->get('/kalkulator-roi')
            ->assertOk()
            ->assertSee('Belum ada produk');
    }

    public function test_reseller_ditolak(): void
    {
        $this->actingAs($this->reseller())
            ->get('/kalkulator-roi')
            ->assertForbidden();
    }

    public function test_menu_sidebar_tampil_sesuai_izin(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertSee('Kalkulator ROI');
        $this->actingAs($this->reseller())->get('/dashboard')->assertOk()->assertDontSee('Kalkulator ROI');
    }
}
```

- [ ] **Step 2: Jalankan tes — pastikan gagal**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorPageTest`
Expected: FAIL (rute `/kalkulator-roi` belum ada → 404).

- [ ] **Step 3: Tulis controller (index)**

`app/Http/Controllers/RoiCalculatorController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Services\RoiCalculatorService;
use Illuminate\View\View;

/**
 * Kalkulator ROI: tabel produk (tarik katalog) -> hitung biaya platform TikTok,
 * profit bersih, dan target ROI/ROAS iklan. Setelan % global + override per baris.
 */
class RoiCalculatorController extends Controller
{
    public function index(RoiCalculatorService $svc): View
    {
        $settings = RoiSetting::current();
        $items = RoiItem::with('product')->get();

        $rows = $items->map(fn (RoiItem $i) => $svc->rowFor($i, $settings))
            ->sortBy(fn ($r) => $r['product']?->name ?? '')
            ->values();

        $existingIds = $items->pluck('product_id')->all();
        $products = Product::whereNotIn('id', $existingIds)->orderBy('name')->get(['id', 'name', 'sku', 'cogs']);

        return view('roi-calculator.index', [
            'settings' => $settings,
            'rows' => $rows,
            'summary' => $svc->summary($rows),
            'products' => $products,
        ]);
    }
}
```

- [ ] **Step 4: Tambah rute**

Di `routes/web.php`, tambah import di antara grup `use App\Http\Controllers\...` (mis. setelah baris `MindmapController`):

```php
use App\Http\Controllers\RoiCalculatorController;
```

Lalu tambahkan grup rute BARU tepat setelah blok "Kontrol Stok Marketplace" (setelah baris penutup `});` grup marketplace-stock, ~baris 645):

```php
    /* ---------------- Kalkulator ROI ---------------- */
    Route::middleware('permission:manage_roi_calculator')->group(function () {
        Route::get('/kalkulator-roi', [RoiCalculatorController::class, 'index'])->name('roi-calculator.index');
    });
```

- [ ] **Step 5: Tambah ikon + item sidebar**

Di `resources/views/layouts/app.blade.php`, dalam `match ($key)` fungsi `navIcon` (sebelum `default => '',` di ~baris 157), tambah arm (ikon kalkulator — pakai ulang path `accounting.index`, zero fetch baru):

```php
                            'roi-calculator.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z"/>',
```

Tambahkan item sidebar berdiri sendiri tepat SETELAH blok grup "Stok Marketplace" (`@endif` di ~baris 339, sebelum `@if($u->canDo('view_learning'))`):

```blade
            @if($u->canDo('manage_roi_calculator'))
                {!! navItem('roi-calculator.index', 'Kalkulator ROI', 'roi-calculator.*') !!}
            @endif
```

- [ ] **Step 6: Tulis halaman (tabel baca-saja + footer + empty state)**

`resources/views/roi-calculator/index.blade.php`:

```blade
@extends('layouts.app')
@section('title','Kalkulator ROI')
@section('heading','Kalkulator ROI')
@section('content')
@php
    $rp = fn ($v) => 'Rp'.number_format((float) $v, 0, ',', '.');
    $roi = fn ($v) => $v === null ? '—' : number_format($v, 2, ',', '.').'×';
@endphp
<div class="space-y-4">

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>
    @endif

    {{-- (Task 5) Panel Setelan Biaya global disisipkan di sini. --}}
    {{-- (Task 6) Form Tambah Produk disisipkan di sini. --}}

    {{-- Tabel hasil --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Produk — {{ count($rows) }}</div>
        @if(count($rows))
            <div class="overflow-x-auto">
                <table class="w-full text-xs whitespace-nowrap">
                    <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                        <th class="text-left px-4 py-2">Produk</th>
                        <th class="text-right">Harga Jual</th>
                        <th class="text-right">Modal</th>
                        <th class="text-right">Total Biaya</th>
                        <th class="text-right">Profit Bersih</th>
                        <th class="text-right">BEP ROI</th>
                        <th class="text-right">Target Min 10%</th>
                        <th class="text-right pr-4">Target Optimum 20%</th>
                    </tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php $p = $row['product']; $res = $row['result']; $in = $row['in']; @endphp
                            <tr class="border-t border-stone-100 align-top">
                                <td class="px-4 py-2.5">
                                    <div class="font-semibold text-stone-800">{{ $p?->name ?? '(produk dihapus)' }}</div>
                                    <div class="text-[11px] text-stone-400 font-mono">{{ $p?->sku }}</div>
                                </td>
                                <td class="text-right">{{ $rp($in['selling_price']) }}</td>
                                <td class="text-right">{{ $rp($in['modal']) }}</td>
                                <td class="text-right text-stone-600">{{ $rp($res['total_biaya']) }}</td>
                                <td class="text-right font-semibold {{ $res['profit_bersih'] > 0 ? 'text-emerald-700' : 'text-rose-600' }}">{{ $rp($res['profit_bersih']) }}</td>
                                <td class="text-right">{{ $roi($res['bep_roi']) }}</td>
                                <td class="text-right">{{ $roi($res['avg_min']) }}</td>
                                <td class="text-right pr-4">{{ $roi($res['avg_optimum']) }}</td>
                            </tr>
                            {{-- Rincian lengkap (native <details>, zero-JS) --}}
                            <tr class="border-t border-stone-50 bg-stone-50/40">
                                <td colspan="8" class="px-4 py-1.5">
                                    <details>
                                        <summary class="cursor-pointer text-[11px] text-indigo-700 hover:underline">Rincian biaya & target</summary>
                                        <div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-1 text-[11px] text-stone-600">
                                            <div>Profit kotor: <span class="font-semibold text-stone-800">{{ $rp($res['profit']) }}</span></div>
                                            <div>Admin: {{ $rp($res['admin']) }}</div>
                                            <div>Voucher Xtra: {{ $rp($res['voucher']) }}</div>
                                            <div>Komisi Dinamis: {{ $rp($res['komisi']) }}</div>
                                            <div>Layanan Mall: {{ $rp($res['mall']) }}</div>
                                            <div>Pajak: {{ $rp($res['pajak']) }}</div>
                                            <div>Operasional: {{ $rp($res['operasional']) }}</div>
                                            <div>Proses order: {{ $rp($in['proses_order']) }}</div>
                                            <div>Packing: {{ $rp($in['packing']) }}</div>
                                            <div>Komisi Affiliate: {{ $rp($res['affiliate']) }}</div>
                                            <div>Profit − Aff: <span class="font-semibold text-stone-800">{{ $rp($res['profit_after_aff']) }}</span></div>
                                            <div>BEP ROI (dgn aff): {{ $roi($res['bep_roi_aff']) }}</div>
                                        </div>
                                        <div class="mt-2 overflow-x-auto">
                                            <table class="text-[11px] text-stone-600">
                                                <thead class="text-stone-400"><tr>
                                                    <th class="text-left pr-3">Target ROI</th>
                                                    <th class="text-right px-3">5%</th><th class="text-right px-3">10%</th>
                                                    <th class="text-right px-3">15%</th><th class="text-right px-3">20%</th>
                                                </tr></thead>
                                                <tbody>
                                                    <tr><td class="pr-3">Tanpa Aff</td>
                                                        @foreach([5,10,15,20] as $x)<td class="text-right px-3">{{ $roi($res['target_noaff'][$x]) }}</td>@endforeach
                                                    </tr>
                                                    <tr><td class="pr-3">Dgn Aff</td>
                                                        @foreach([5,10,15,20] as $x)<td class="text-right px-3">{{ $roi($res['target_aff'][$x]) }}</td>@endforeach
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-stone-50 text-stone-700 font-semibold">
                        <tr class="border-t border-stone-200">
                            <td class="px-4 py-2.5" colspan="6">Rata-rata semua produk (patokan setelan iklan)</td>
                            <td class="text-right">{{ $roi($summary['avg_min_all']) }}</td>
                            <td class="text-right pr-4">{{ $roi($summary['avg_optimum_all']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada produk. Tambahkan produk dari katalog untuk mulai menghitung.</p>
        @endif
    </div>

</div>
@endsection
```

- [ ] **Step 7: Jalankan tes — pastikan hijau**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorPageTest`
Expected: 4 passed.

- [ ] **Step 8: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/RoiCalculatorController.php resources/views/roi-calculator/index.blade.php routes/web.php resources/views/layouts/app.blade.php tests/Feature/RoiCalculator/RoiCalculatorPageTest.php
git commit -m "feat(roi): halaman Kalkulator ROI (tabel hasil+rincian+footer) + rute + menu sidebar"
```

---

### Task 5: Setelan biaya global (edit)

**Files:**
- Modify: `app/Http/Controllers/RoiCalculatorController.php` (tambah `saveSettings()`)
- Modify: `routes/web.php` (tambah rute settings di grup)
- Modify: `resources/views/roi-calculator/index.blade.php` (sisipkan panel form setelan)
- Test: `tests/Feature/RoiCalculator/RoiCalculatorSettingsTest.php`

**Interfaces:**
- Consumes: `RoiSetting::current()`, rute `roi-calculator.index`.
- Produces: rute `roi-calculator.settings` (POST `/kalkulator-roi/settings`); `saveSettings(Request): RedirectResponse` (validasi semua field required numeric/integer ≥0, update baris `current()`, redirect back + status).

- [ ] **Step 1: Tulis tes setelan (gagal dulu)**

`tests/Feature/RoiCalculator/RoiCalculatorSettingsTest.php`:

```php
<?php

namespace Tests\Feature\RoiCalculator;

use App\Models\RoiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoiCalculatorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'admin', 'fullname' => 'Admin', 'username' => 'admin'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'admin_pct' => 8, 'voucher_pct' => 4.5, 'komisi_pct' => 5.5, 'komisi_cap' => 650000,
            'mall_pct' => 1.8, 'pajak_pct' => 0.5, 'operasional_pct' => 3, 'affiliate_pct' => 5,
            'packing_default' => 1000, 'proses_order_default' => 1250,
        ], $override);
    }

    public function test_simpan_setelan_memperbarui_baris_current(): void
    {
        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/settings', $this->payload(['admin_pct' => 9.25, 'komisi_cap' => 700000]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $s = RoiSetting::current();
        $this->assertSame(9.25, $s->admin_pct);
        $this->assertSame(700000, $s->komisi_cap);
        $this->assertSame(1, RoiSetting::count());
    }

    public function test_menolak_persen_negatif(): void
    {
        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/settings', $this->payload(['admin_pct' => -1]))
            ->assertSessionHasErrors('admin_pct');
    }

    public function test_form_menampilkan_nilai_saat_ini(): void
    {
        RoiSetting::current()->update(['operasional_pct' => 2.75]);

        $this->actingAs($this->admin())
            ->get('/kalkulator-roi')
            ->assertOk()
            ->assertSee('Setelan Biaya')
            ->assertSee('2.75'); // value input (float, titik desimal di atribut value)
    }
}
```

- [ ] **Step 2: Jalankan tes — pastikan gagal**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorSettingsTest`
Expected: FAIL (rute settings 404 / tak ada "Setelan Biaya").

- [ ] **Step 3: Tambah `saveSettings()` ke controller**

Tambahkan `use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request;` di atas class, lalu method:

```php
    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'admin_pct' => ['required', 'numeric', 'min:0'],
            'voucher_pct' => ['required', 'numeric', 'min:0'],
            'komisi_pct' => ['required', 'numeric', 'min:0'],
            'komisi_cap' => ['required', 'integer', 'min:0'],
            'mall_pct' => ['required', 'numeric', 'min:0'],
            'pajak_pct' => ['required', 'numeric', 'min:0'],
            'operasional_pct' => ['required', 'numeric', 'min:0'],
            'affiliate_pct' => ['required', 'numeric', 'min:0'],
            'packing_default' => ['required', 'integer', 'min:0'],
            'proses_order_default' => ['required', 'integer', 'min:0'],
        ]);

        RoiSetting::current()->update($data);

        return back()->with('status', 'Setelan biaya global disimpan.');
    }
```

- [ ] **Step 4: Tambah rute settings**

Di grup `permission:manage_roi_calculator` (`routes/web.php`), tambah di bawah rute index:

```php
        Route::post('/kalkulator-roi/settings', [RoiCalculatorController::class, 'saveSettings'])->name('roi-calculator.settings');
```

- [ ] **Step 5: Sisipkan panel form setelan di view**

Di `resources/views/roi-calculator/index.blade.php`, ganti komentar `{{-- (Task 5) ... --}}` dengan panel (native `<details>` agar collapsible, zero-JS):

```blade
    {{-- Setelan Biaya global --}}
    <details class="bg-white rounded-2xl border border-stone-200 p-5">
        <summary class="cursor-pointer text-sm font-bold text-stone-800">Setelan Biaya (global)</summary>
        <p class="text-xs text-stone-500 mt-1 mb-4">Default untuk semua produk. Persen dalam angka (mis. 8 = 8%). Bisa di-override per produk.</p>
        <form method="POST" action="{{ route('roi-calculator.settings') }}">
            @csrf
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                @php
                    $fields = [
                        'admin_pct' => 'Admin %', 'voucher_pct' => 'Voucher Xtra %', 'komisi_pct' => 'Komisi Dinamis %',
                        'komisi_cap' => 'Cap Komisi (Rp)', 'mall_pct' => 'Layanan Mall %', 'pajak_pct' => 'Pajak %',
                        'operasional_pct' => 'Operasional %', 'affiliate_pct' => 'Affiliate %',
                        'packing_default' => 'Packing (Rp)', 'proses_order_default' => 'Proses Order (Rp)',
                    ];
                @endphp
                @foreach($fields as $name => $label)
                    <label class="block">
                        <span class="text-[11px] text-stone-500">{{ $label }}</span>
                        <input type="number" step="any" min="0" name="{{ $name }}" value="{{ old($name, $settings->$name) }}"
                            class="mt-0.5 w-full px-2 py-1 border border-stone-300 rounded-lg text-xs @error($name) border-rose-400 @enderror">
                        @error($name)<span class="text-[10px] text-rose-600">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>
            <button class="mt-4 px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Simpan Setelan</button>
        </form>
    </details>
```

- [ ] **Step 6: Jalankan tes — pastikan hijau**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorSettingsTest`
Expected: 3 passed.

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/RoiCalculatorController.php routes/web.php resources/views/roi-calculator/index.blade.php tests/Feature/RoiCalculator/RoiCalculatorSettingsTest.php
git commit -m "feat(roi): panel setelan biaya global (edit + validasi)"
```

---

### Task 6: CRUD baris produk (tambah / edit / hapus + override %)

**Files:**
- Modify: `app/Http/Controllers/RoiCalculatorController.php` (tambah `storeItem()`, `updateItem()`, `deleteItem()`)
- Modify: `routes/web.php` (3 rute item)
- Modify: `resources/views/roi-calculator/index.blade.php` (form Tambah Produk + per-baris form edit incl override % + tombol Hapus)
- Test: `tests/Feature/RoiCalculator/RoiCalculatorItemsTest.php`

**Interfaces:**
- Consumes: rute `roi-calculator.index`, `RoiItem`, `Product`.
- Produces: rute `roi-calculator.items.store` (POST `/kalkulator-roi/items`), `roi-calculator.items.update` (POST `/kalkulator-roi/items/{item}`), `roi-calculator.items.destroy` (DELETE `/kalkulator-roi/items/{item}`). `{item}` = binding `RoiItem $item`.
  - `storeItem`: validasi `product_id` (exists + unik di roi_items) + `selling_price` required; buat baris (`modal`/override tetap null = warisi).
  - `updateItem`: `selling_price` required; `modal/packing/proses_order` + semua pct `nullable` (kosong = warisi). `$item->update($data)`.
  - `deleteItem`: hapus baris.

- [ ] **Step 1: Tulis tes CRUD (gagal dulu)**

`tests/Feature/RoiCalculator/RoiCalculatorItemsTest.php`:

```php
<?php

namespace Tests\Feature\RoiCalculator;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoiCalculatorItemsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'admin', 'fullname' => 'Admin', 'username' => 'admin'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(string $sku = 'SB-1', float $cogs = 13755): Product
    {
        return Product::create(['name' => 'Sabun', 'sku' => $sku, 'status' => 'active', 'cogs' => $cogs]);
    }

    public function test_tambah_produk_membuat_baris_modal_ikut_cogs(): void
    {
        $p = $this->product();

        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/items', ['product_id' => $p->id, 'selling_price' => 39000])
            ->assertRedirect()->assertSessionHas('status');

        $item = RoiItem::firstWhere('product_id', $p->id);
        $this->assertSame(39000, $item->selling_price);
        $this->assertNull($item->modal); // null -> compute pakai COGS
    }

    public function test_tak_boleh_produk_dobel(): void
    {
        $p = $this->product();
        RoiItem::create(['product_id' => $p->id, 'selling_price' => 1000]);

        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/items', ['product_id' => $p->id, 'selling_price' => 2000])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(1, RoiItem::where('product_id', $p->id)->count());
    }

    public function test_update_menyimpan_override_dan_modal(): void
    {
        $p = $this->product();
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]);

        $this->actingAs($this->admin())
            ->post("/kalkulator-roi/items/{$item->id}", [
                'selling_price' => 42000, 'modal' => 15000, 'admin_pct' => 10,
                'packing' => '', 'proses_order' => '', 'voucher_pct' => '', 'komisi_pct' => '',
                'komisi_cap' => '', 'mall_pct' => '', 'pajak_pct' => '', 'operasional_pct' => '', 'affiliate_pct' => '',
            ])
            ->assertRedirect()->assertSessionHas('status');

        $item->refresh();
        $this->assertSame(42000, $item->selling_price);
        $this->assertSame(15000, $item->modal);
        $this->assertSame(10.0, $item->admin_pct);
        $this->assertNull($item->voucher_pct); // kosong -> warisi global
        $this->assertNull($item->packing);
    }

    public function test_update_kosongkan_override_kembali_warisi(): void
    {
        $p = $this->product();
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'admin_pct' => 12, 'modal' => 20000]);

        $this->actingAs($this->admin())
            ->post("/kalkulator-roi/items/{$item->id}", [
                'selling_price' => 39000, 'modal' => '', 'admin_pct' => '',
                'packing' => '', 'proses_order' => '', 'voucher_pct' => '', 'komisi_pct' => '',
                'komisi_cap' => '', 'mall_pct' => '', 'pajak_pct' => '', 'operasional_pct' => '', 'affiliate_pct' => '',
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertNull($item->admin_pct); // di-kosongkan -> warisi lagi
        $this->assertNull($item->modal);
    }

    public function test_hapus_baris(): void
    {
        $p = $this->product();
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]);

        $this->actingAs($this->admin())
            ->delete("/kalkulator-roi/items/{$item->id}")
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(0, RoiItem::count());
    }

    public function test_menolak_selling_price_negatif_saat_tambah(): void
    {
        $p = $this->product();

        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/items', ['product_id' => $p->id, 'selling_price' => -5])
            ->assertSessionHasErrors('selling_price');
    }
}
```

- [ ] **Step 2: Jalankan tes — pastikan gagal**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorItemsTest`
Expected: FAIL (rute item belum ada).

- [ ] **Step 3: Tambah method CRUD ke controller**

Tambahkan `use App\Models\RoiItem;` (sudah ada) dan `use Illuminate\Validation\Rule;` di atas class, lalu:

```php
    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id', Rule::unique('roi_items', 'product_id')],
            'selling_price' => ['required', 'integer', 'min:0'],
        ]);

        RoiItem::create($data); // modal/override tetap null = warisi COGS/global

        return back()->with('status', 'Produk ditambahkan ke kalkulator.');
    }

    public function updateItem(Request $request, RoiItem $item): RedirectResponse
    {
        $data = $request->validate([
            'selling_price' => ['required', 'integer', 'min:0'],
            'modal' => ['nullable', 'integer', 'min:0'],
            'packing' => ['nullable', 'integer', 'min:0'],
            'proses_order' => ['nullable', 'integer', 'min:0'],
            'admin_pct' => ['nullable', 'numeric', 'min:0'],
            'voucher_pct' => ['nullable', 'numeric', 'min:0'],
            'komisi_pct' => ['nullable', 'numeric', 'min:0'],
            'komisi_cap' => ['nullable', 'integer', 'min:0'],
            'mall_pct' => ['nullable', 'numeric', 'min:0'],
            'pajak_pct' => ['nullable', 'numeric', 'min:0'],
            'operasional_pct' => ['nullable', 'numeric', 'min:0'],
            'affiliate_pct' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Field kosong tiba sbg null (ConvertEmptyStringsToNull) -> warisi COGS/global.
        $item->update($data);

        return back()->with('status', "Baris {$item->product?->name} diperbarui.");
    }

    public function deleteItem(RoiItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('status', 'Baris dihapus.');
    }
```

- [ ] **Step 4: Tambah 3 rute item**

Di grup `permission:manage_roi_calculator` (`routes/web.php`), di bawah rute settings:

```php
        Route::post('/kalkulator-roi/items', [RoiCalculatorController::class, 'storeItem'])->name('roi-calculator.items.store');
        Route::post('/kalkulator-roi/items/{item}', [RoiCalculatorController::class, 'updateItem'])->name('roi-calculator.items.update');
        Route::delete('/kalkulator-roi/items/{item}', [RoiCalculatorController::class, 'deleteItem'])->name('roi-calculator.items.destroy');
```

- [ ] **Step 5: Tambah form Tambah Produk + per-baris edit + hapus di view**

(a) Ganti komentar `{{-- (Task 6) Form Tambah Produk ... --}}` dengan (native `<select>`; hanya tampil bila ada produk yg belum ditambah):

```blade
    {{-- Tambah produk dari katalog --}}
    @if(count($products))
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <form method="POST" action="{{ route('roi-calculator.items.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="block">
                    <span class="text-[11px] text-stone-500">Produk</span>
                    <select name="product_id" required class="mt-0.5 px-2 py-1.5 border border-stone-300 rounded-lg text-xs max-w-[240px]">
                        <option value="">Pilih produk…</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-[11px] text-stone-500">Harga Jual (Rp)</span>
                    <input type="number" name="selling_price" min="0" step="1" required class="mt-0.5 w-32 px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                </label>
                <button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">+ Tambah Produk</button>
                @error('product_id')<span class="text-[11px] text-rose-600">{{ $message }}</span>@enderror
            </form>
        </div>
    @endif
```

(b) Di baris `<details>` rincian (dalam `<tbody>`), tambahkan form edit inline DI ATAS blok grid rincian (di dalam `<details>`, setelah `<summary>`). Ganti isi `<details>...</details>` sehingga memuat form edit dulu lalu rincian. Sisipkan tepat setelah `<summary ...>Rincian biaya & target</summary>`:

```blade
                                        <form method="POST" action="{{ route('roi-calculator.items.update', $row['item']) }}" class="mt-2 flex flex-wrap items-end gap-2 pb-2 border-b border-stone-100">
                                            @csrf
                                            @php
                                                $edit = [
                                                    'selling_price' => ['Harga Jual', $in['selling_price']],
                                                    'modal' => ['Modal', $row['item']->modal],
                                                    'packing' => ['Packing', $row['item']->packing],
                                                    'proses_order' => ['Proses Order', $row['item']->proses_order],
                                                    'admin_pct' => ['Admin %', $row['item']->admin_pct],
                                                    'voucher_pct' => ['Voucher %', $row['item']->voucher_pct],
                                                    'komisi_pct' => ['Komisi %', $row['item']->komisi_pct],
                                                    'komisi_cap' => ['Cap Komisi', $row['item']->komisi_cap],
                                                    'mall_pct' => ['Mall %', $row['item']->mall_pct],
                                                    'pajak_pct' => ['Pajak %', $row['item']->pajak_pct],
                                                    'operasional_pct' => ['Operasional %', $row['item']->operasional_pct],
                                                    'affiliate_pct' => ['Affiliate %', $row['item']->affiliate_pct],
                                                ];
                                            @endphp
                                            @foreach($edit as $name => [$label, $val])
                                                <label class="block">
                                                    <span class="text-[10px] text-stone-400">{{ $label }}</span>
                                                    <input type="number" step="any" min="0" name="{{ $name }}" value="{{ $val }}"
                                                        placeholder="{{ $name === 'selling_price' ? '' : 'warisi' }}"
                                                        {{ $name === 'selling_price' ? 'required' : '' }}
                                                        class="mt-0.5 w-20 px-1.5 py-1 border border-stone-300 rounded text-[11px]">
                                                </label>
                                            @endforeach
                                            <button class="px-3 py-1.5 text-[11px] bg-stone-800 text-white rounded-lg hover:bg-stone-900">Simpan</button>
                                        </form>
```

(c) Tambahkan kolom Aksi (Hapus) — tambahkan `<th>` kosong di `<thead>` (pr-4 pindah ke kolom aksi) DAN sel hapus di baris utama. Ubah header terakhir `Target Optimum 20%` agar tidak lagi `pr-4`, lalu tambah `<th class="text-right pr-4"></th>`; dan di baris utama, setelah sel `avg_optimum` (hapus `pr-4` darinya) tambahkan:

```blade
                                <td class="text-right pr-4">
                                    <form method="POST" action="{{ route('roi-calculator.items.destroy', $row['item']) }}" onsubmit="return confirm('Hapus baris ini?')">
                                        @csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline text-[11px]">Hapus</button>
                                    </form>
                                </td>
```

Sesuaikan `colspan` baris `<details>` dari `8` menjadi `9`, dan `colspan` footer pertama tetap `6` dengan menggeser 2 sel angka + 1 sel kosong (tambah `<td></td>` kosong di akhir tfoot agar total kolom = 9).

- [ ] **Step 6: Jalankan tes — pastikan hijau**

Run: `C:\php83\php.exe artisan test --filter=RoiCalculatorItemsTest`
Expected: 6 passed. Lalu jalankan ulang PageTest untuk pastikan tak ada regresi kolom: `C:\php83\php.exe artisan test --filter=RoiCalculator`
Expected: semua hijau.

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/RoiCalculatorController.php routes/web.php resources/views/roi-calculator/index.blade.php tests/Feature/RoiCalculator/RoiCalculatorItemsTest.php
git commit -m "feat(roi): CRUD baris produk (tambah/edit+override %/hapus)"
```

---

### Task 7: Dokumentasi

**Files:**
- Modify: `docs/SISTEM.md` (tambah seksi ringkas modul Kalkulator ROI)
- Modify: `docs/PETA-SISTEM.md` (tambah entri status CODE-VERIFIED)

**Interfaces:** tidak ada (dokumentasi).

- [ ] **Step 1: Verifikasi rute terdaftar**

Run: `C:\php83\php.exe artisan route:list --name=roi-calculator`
Expected: 5 rute (index, settings, items.store, items.update, items.destroy).

- [ ] **Step 2: Tambah entri di `docs/SISTEM.md`**

Tambahkan sub-seksi baru (di dekat seksi Integrasi/Marketplace), ringkas: apa itu Kalkulator ROI, tabel `roi_settings`/`roi_items`, rumus (Total Biaya=SUM(G:M), Profit Bersih, BEP & target ROI dgn/tanpa aff, cap komisi), izin `manage_roi_calculator`, lokasi (`RoiCalculatorService`/`RoiCalculatorController`/`roi-calculator.index`). ± 8-12 baris, gaya konsisten dgn seksi lain.

- [ ] **Step 3: Tambah entri di `docs/PETA-SISTEM.md`**

Tambahkan baris status: "Kalkulator ROI — SELESAI (branch feat/kalkulator-roi): 2 tabel (000141), RoiCalculatorService (compute+resolve), 1 controller, halaman `/kalkulator-roi`, izin manage_roi_calculator, tes Unit+Feature." Sertakan jumlah tes bila mudah.

- [ ] **Step 4: Jalankan SELURUH suite — pastikan hijau**

Run: `C:\php83\php.exe artisan test`
Expected: semua hijau (suite lama + ~26 tes baru modul ROI).

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add docs/SISTEM.md docs/PETA-SISTEM.md
git commit -m "docs(roi): catat modul Kalkulator ROI di SISTEM.md + PETA-SISTEM.md"
```

---

## Self-Review

**1. Spec coverage:**
- Tabel produk tarik katalog + nama auto → Task 4 (tabel) + Task 6 (dropdown katalog). ✓
- Modal default COGS tapi editable → Task 3 (`effectiveInputs` modal??cogs) + Task 6 (edit modal, kosong=warisi). ✓
- Harga Jual manual → Task 6 (store/update). ✓
- Packing + Proses Order input (default settings) → Task 1 (default) + Task 3 (??default) + Task 5/6. ✓
- Per-row outputs (Admin/Voucher/Komisi+cap/Mall/Pajak/Operasional, Total=SUM(G:M), Profit Bersih, Komisi Aff, Profit-Aff, BEP tanpa/dgn aff, Target @5/10/15/20 tanpa/dgn aff, Rata2 Min/Optimum) → Task 2 (`compute`) + Task 4 (render + rincian). ✓
- Footer rata-rata semua produk → Task 3 (`summary`) + Task 4 (tfoot). ✓
- Fee % global default + override per produk (incl cap & affiliate %) → Task 1/3/5/6. ✓
- Tersimpan → Task 1 (tabel) + Task 6 (CRUD). ✓
- Menu berdiri sendiri "Kalkulator ROI" → Task 4 (sidebar). ✓
- Izin `manage_roi_calculator` → Task 1 + rute Task 4. ✓
- Guard bagi-nol → Task 2. ✓
- Zero-dep, PHPUnit class-style, native select, no @json literal → seluruh task. ✓
- Out of scope (multi-channel, auto harga, export, grafik) → tidak dibangun. ✓

**2. Placeholder scan:** Tidak ada TBD/TODO; semua step berisi kode nyata atau perintah konkret. Task 7 step 2-3 mendeskripsikan isi doc (bukan kode) — dapat diterima untuk task dokumentasi.

**3. Type consistency:**
- `compute()` return key dipakai konsisten di Task 2 (definisi), Task 3 (`summary` baca `avg_min`/`avg_optimum`), Task 4 (view baca `total_biaya`,`profit_bersih`,`bep_roi`,`avg_min`,`avg_optimum`,`target_noaff/aff`,`profit`,`admin`,...). ✓
- `rowFor()` return `['item','product','in','result']` dipakai di controller + view. ✓
- Nama field DB/fillable identik di migrasi, model, service, controller, view. ✓
- Nama rute `roi-calculator.index/settings/items.store/items.update/items.destroy` konsisten controller↔routes↔view. ✓
- `{item}` binding → param `RoiItem $item` di updateItem/deleteItem. ✓

---

## Execution Handoff

Rencana lengkap & tersimpan di `docs/superpowers/plans/2026-09-24-kalkulator-roi.md`. Dua opsi eksekusi:

**1. Subagent-Driven (disarankan)** — dispatch subagent segar per task, review antar task, iterasi cepat.

**2. Inline Execution** — eksekusi di sesi ini via executing-plans, batch dgn checkpoint.

Pilih yang mana?
