# Fix: Backdate Stok + Harga Grand Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** (A) Produksi/Penerimaan Stok yang di-backdate mencatat gerakan stok pada tanggal backdate-nya (bukan tanggal input), + backfill data lama; (B) tampilkan kolom harga Grand di tabel produk + seed harga Grand yang benar berdasarkan SKU.

**Architecture:** Fix A meneruskan tanggal backdate ke `stock_movements.created_at` lewat param `occurredAt` yang sudah ada di `InventoryService::adjustHqStock`, plus migrasi backfill (via support class `StockMovementDateBackfill`) untuk data existing. Fix B menambah kolom tabel + seed `price_grand` berdasarkan SKU (via `GrandPriceList::applyBySku` + migrasi).

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind, Eloquent. Zero-dependency.

**Branch:** `fix/stok-backdate-harga-grand` (dari main)

## Global Constraints

- **Zero-dependency**: tak menambah paket composer/npm.
- **Runner**: `C:\php83\php.exe artisan test`. `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Migrasi**: terakhir `000076` → gunakan `000077` (backfill tanggal), `000078` (seed grand by SKU).
- **Saldo stok TIDAK boleh berubah** oleh fix A — hanya TANGGAL gerakan yang digeser. Running balance netral terhadap tanggal.
- **Portabilitas DB**: backfill & seed pakai loop PHP + `DB::table` (SQLite test / MySQL prod), bukan JOIN-UPDATE SQL.
- **priceForRole/priceField Tahap 3a TIDAK disentuh** (di luar lingkup fix ini).

---

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Services/ProductionService.php` (modify) | teruskan `occurredAt = produced_at` ke adjustHqStock |
| `app/Services/StockReceiptService.php` (modify) | teruskan `occurredAt = received_at` ke adjustHqStock |
| `app/Support/StockMovementDateBackfill.php` (create) | logika backfill created_at gerakan produksi/penerimaan = tanggal parent |
| `database/migrations/2026_01_01_000077_backfill_stock_movement_dates.php` (create) | panggil `StockMovementDateBackfill::run()` |
| `resources/views/products/index.blade.php` (modify) | kolom "Grand" di tabel |
| `app/Support/GrandPriceList.php` (modify) | `PRICES_BY_SKU` + `applyBySku()` |
| `database/migrations/2026_01_01_000078_reseed_grand_price_by_sku.php` (create) | panggil `GrandPriceList::applyBySku()` |
| tests baru | lihat tiap task |

**Fakta kode terverifikasi:**
- `InventoryService::adjustHqStock(..., ?\DateTimeInterface $occurredAt = null)` → `writeMovement(created_at: $occurredAt ?? now())`. Plumbing SUDAH ada; Production/StockReceipt belum memakainya.
- `HqStockReportService` mengelompokkan mutasi by `stock_movements.created_at` (whereBetween/where >=).
- `Production::REFERENCE_TYPE = 'production'`, `produced_at` cast `date`, fillable. `StockReceipt::REFERENCE_TYPE = 'stock_receipt'`, `received_at` cast `date`.
- `StockMovement` ditulis lewat `writeMovement` dengan `created_at` eksplisit (boleh di-set saat create).
- Tabel produk `products/index.blade.php`: thead 10 kolom (Distributor di baris 26), body harga distributor baris 57, empty-state `colspan="10"` baris 85.
- `GrandPriceList` (dari 3a) sudah ada dengan `PRICES` (by name) + `apply()`.

---

## Task 1: Produksi & Penerimaan teruskan tanggal backdate ke gerakan stok

**Files:**
- Modify: `app/Services/ProductionService.php` (import Carbon + adjustHqStock call ~baris 93)
- Modify: `app/Services/StockReceiptService.php` (import Carbon + adjustHqStock call ~baris 60)
- Test: `tests/Feature/StockBackdateMovementTest.php`

**Interfaces:**
- Consumes: `InventoryService::adjustHqStock(..., occurredAt:)` (sudah ada).
- Produces: gerakan stok produksi/penerimaan ber-`created_at` = tanggal `produced_at`/`received_at`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/StockBackdateMovementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\ProductionService;
use App\Services\StockReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StockBackdateMovementTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 0, 'status' => 'active',
        ]);
    }

    public function test_produksi_backdate_gerakan_stok_pakai_tanggal_produksi(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product();

        app(ProductionService::class)->produce(
            ['product_id' => $p->id, 'output_qty' => 998, 'produced_at' => '2026-08-07', 'notes' => null],
            [], []
        );

        $mv = StockMovement::where('reference_type', 'production')->where('product_id', $p->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-07', $mv->created_at->toDateString()); // bukan 2026-08-11
        $this->assertSame(998, (int) $p->fresh()->hq_stock);              // saldo tetap benar
    }

    public function test_penerimaan_backdate_gerakan_stok_pakai_tanggal_terima(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product();

        app(StockReceiptService::class)->receive(
            ['received_at' => '2026-08-05', 'supplier_name' => null, 'reference_no' => null, 'notes' => null],
            [['product_id' => $p->id, 'quantity' => 50, 'unit_cost' => 10000]]
        );

        $mv = StockMovement::where('reference_type', 'stock_receipt')->where('product_id', $p->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        $this->assertSame(50, (int) $p->fresh()->hq_stock);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=StockBackdateMovementTest`
Expected: FAIL — `created_at` gerakan = `2026-08-11` (now), bukan tanggal backdate.

- [ ] **Step 3: ProductionService teruskan occurredAt**

Di `app/Services/ProductionService.php`:

1. Tambah import (dekat `use` lain): `use Illuminate\Support\Carbon;`
2. Pada pemanggilan `$this->inventory->adjustHqStock(...)` (blok "4. Finished product", ~baris 93-100), tambah argumen `occurredAt`:
```php
            $this->inventory->adjustHqStock(
                product: $product,
                delta: $outputQty,
                movementType: StockMovement::TYPE_IN,
                notes: 'Hasil produksi '.$production->production_number,
                referenceType: Production::REFERENCE_TYPE,
                referenceId: $production->id,
                occurredAt: Carbon::parse($header['produced_at']),
            );
```

- [ ] **Step 4: StockReceiptService teruskan occurredAt**

Di `app/Services/StockReceiptService.php`:

1. Tambah import: `use Illuminate\Support\Carbon;`
2. Pada pemanggilan `$this->inventory->adjustHqStock(...)` (~baris 60-67), tambah argumen `occurredAt`:
```php
                $this->inventory->adjustHqStock(
                    product: $product,
                    delta: $qty,
                    movementType: StockMovement::TYPE_IN,
                    notes: 'Stok masuk '.$receipt->receipt_number.' @ Rp '.number_format($unitCost, 0, ',', '.'),
                    referenceType: StockReceipt::REFERENCE_TYPE,
                    referenceId: $receipt->id,
                    occurredAt: Carbon::parse($header['received_at']),
                );
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=StockBackdateMovementTest`
Expected: PASS (2 test).

- [ ] **Step 6: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/ProductionService.php app/Services/StockReceiptService.php tests/Feature/StockBackdateMovementTest.php
git commit -m "fix(stok): produksi & penerimaan backdate catat gerakan di tanggal backdate" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Backfill tanggal gerakan stok existing (migrasi 000077)

**Files:**
- Create: `app/Support/StockMovementDateBackfill.php`
- Create: `database/migrations/2026_01_01_000077_backfill_stock_movement_dates.php`
- Test: `tests/Feature/StockMovementDateBackfillTest.php`

**Interfaces:**
- Produces: `StockMovementDateBackfill::run(): void` — set `stock_movements.created_at` = tanggal parent untuk gerakan `production`/`stock_receipt`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/StockMovementDateBackfillTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Production;
use App\Models\StockMovement;
use App\Support\StockMovementDateBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementDateBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_geser_created_at_gerakan_ke_tanggal_produksi(): void
    {
        $product = Product::create([
            'name' => 'Mizu', 'sku' => 'MZ-500ML',
            'price_distributor' => 29000, 'price_reseller' => 38000, 'price_retail' => 65000,
            'cogs' => 14000, 'hq_stock' => 998, 'status' => 'active',
        ]);
        $prod = Production::create([
            'production_number' => 'PRD-00001', 'product_id' => $product->id, 'product_name' => $product->name,
            'produced_at' => '2026-08-07', 'output_qty' => 998, 'created_by' => null,
        ]);
        // Gerakan lama SALAH tanggal (dicap tanggal input 11 Agu, bukan produced_at 7 Agu).
        StockMovement::create([
            'product_id' => $product->id, 'user_id' => null, 'movement_type' => 'in', 'quantity' => 998,
            'before_qty' => 0, 'after_qty' => 998, 'reference_type' => 'production', 'reference_id' => $prod->id,
            'created_at' => '2026-08-11 10:00:00',
        ]);

        StockMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'production')->where('reference_id', $prod->id)->first();
        $this->assertSame('2026-08-07', $mv->created_at->toDateString());
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=StockMovementDateBackfillTest`
Expected: FAIL — `Class "App\Support\StockMovementDateBackfill" not found`.

- [ ] **Step 3: Buat support class**

Buat `app/Support/StockMovementDateBackfill.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Koreksi satu-arah: geser created_at gerakan stok produksi & penerimaan ke
 * TANGGAL parent-nya (productions.produced_at / stock_receipts.received_at).
 *
 * Perlu karena dulu ProductionService/StockReceiptService tak meneruskan tanggal
 * backdate ke gerakan stok, jadi gerakan lama dicap tanggal input. Saldo TIDAK
 * berubah (running balance netral terhadap tanggal) — hanya tanggal digeser
 * supaya Laporan Stok HQ akurat. Idempoten.
 */
class StockMovementDateBackfill
{
    public static function run(): void
    {
        foreach (DB::table('productions')->select('id', 'produced_at')->get() as $p) {
            DB::table('stock_movements')
                ->where('reference_type', 'production')
                ->where('reference_id', $p->id)
                ->update(['created_at' => $p->produced_at]);
        }

        foreach (DB::table('stock_receipts')->select('id', 'received_at')->get() as $r) {
            DB::table('stock_movements')
                ->where('reference_type', 'stock_receipt')
                ->where('reference_id', $r->id)
                ->update(['created_at' => $r->received_at]);
        }
    }
}
```

- [ ] **Step 4: Buat migrasi 000077**

Buat `database/migrations/2026_01_01_000077_backfill_stock_movement_dates.php`:

```php
<?php

use App\Support\StockMovementDateBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Betulkan tanggal gerakan produksi/penerimaan lama = tanggal backdate parent.
        StockMovementDateBackfill::run();
    }

    public function down(): void
    {
        // Koreksi satu arah — tanggal input asli tak disimpan, tak bisa dibalik.
    }
};
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=StockMovementDateBackfillTest`
Expected: PASS. (RefreshDatabase menjalankan migrasi 000077 saat tabel kosong = no-op; test memanggil `run()` langsung atas data yang di-seed.)

- [ ] **Step 6: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Support/StockMovementDateBackfill.php database/migrations/2026_01_01_000077_backfill_stock_movement_dates.php tests/Feature/StockMovementDateBackfillTest.php
git commit -m "fix(stok): backfill tanggal gerakan produksi/penerimaan lama ke tanggal backdate" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Kolom "Grand" di tabel produk

**Files:**
- Modify: `resources/views/products/index.blade.php:26,57,85`
- Test: `tests/Feature/ProductGrandColumnTest.php`

**Interfaces:**
- Consumes: kolom `products.price_grand` (dari 3a).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/ProductGrandColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductGrandColumnTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_tabel_produk_ada_kolom_grand_dan_nilainya(): void
    {
        Product::create([
            'name' => 'Mizu', 'sku' => 'MZ-500ML',
            'price_distributor' => 29000, 'price_reseller' => 38000, 'price_retail' => 65000,
            'price_grand' => 26000, 'cogs' => 14000, 'hq_stock' => 10, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())->get(route('products.index'))
            ->assertOk()
            ->assertSee('Grand')       // header kolom
            ->assertSee('26.000');     // nilai harga Grand
    }

    public function test_produk_tanpa_price_grand_tampil_strip(): void
    {
        Product::create([
            'name' => 'Tanpa Grand', 'sku' => 'NG-1',
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'price_grand' => null, 'cogs' => 10000, 'hq_stock' => 5, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())->get(route('products.index'))
            ->assertOk()
            ->assertSee('—'); // em-dash untuk price_grand null
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=ProductGrandColumnTest`
Expected: FAIL — kolom "Grand" belum ada (`assertSee('26.000')`/`'—'` gagal).

- [ ] **Step 3: Tambah kolom di view**

Di `resources/views/products/index.blade.php`:

1. **thead** — setelah `<th class="text-right">Distributor</th>` (baris 26), tambah:
```blade
                <th class="text-right">Grand</th>
```

2. **tbody** — setelah baris harga distributor (`<td class="text-right">Rp {{ number_format($p->price_distributor, 0, ',', '.') }}</td>`, baris 57), tambah:
```blade
                    <td class="text-right">{{ $p->price_grand !== null ? 'Rp '.number_format($p->price_grand, 0, ',', '.') : '—' }}</td>
```

3. **empty-state colspan** (baris 85) — ubah `colspan="10"` jadi `colspan="11"`:
```blade
                <tr><td colspan="11" class="px-4 py-6 text-center text-stone-400">Belum ada produk.</td></tr>
```

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=ProductGrandColumnTest`
Expected: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add resources/views/products/index.blade.php tests/Feature/ProductGrandColumnTest.php
git commit -m "feat(mlm): kolom harga Grand di tabel produk" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Seed harga Grand berdasarkan SKU (migrasi 000078) + regresi

**Files:**
- Modify: `app/Support/GrandPriceList.php`
- Create: `database/migrations/2026_01_01_000078_reseed_grand_price_by_sku.php`
- Test: `tests/Feature/GrandPriceBySkuTest.php`

**Interfaces:**
- Consumes: kolom `products.price_grand`, `products.sku`.
- Produces: `GrandPriceList::PRICES_BY_SKU`, `GrandPriceList::applyBySku(): void`.

**Kenapa:** seed by-name (3a) kena 0 match di prod karena nama produk pakai brand lengkap (mis. "MIZU BODY WASH - 500ml") bukan "Sabun Cair". SKU stabil & cocok (dikonfirmasi user dari layar prod).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/GrandPriceBySkuTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\GrandPriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrandPriceBySkuTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $sku): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => $sku,
            'price_distributor' => 29000, 'price_reseller' => 38000, 'price_retail' => 65000,
            'cogs' => 14000, 'hq_stock' => 0, 'status' => 'active',
        ]);
    }

    public function test_apply_by_sku_isi_price_grand_untuk_sku_dikenal(): void
    {
        $mizu = $this->product('MZ-500ML');
        $soap = $this->product('SOAP-1');
        $unknown = $this->product('XYZ-999');

        GrandPriceList::applyBySku();

        $this->assertEqualsWithDelta(26000, (float) $mizu->fresh()->price_grand, 0.01);
        $this->assertEqualsWithDelta(22000, (float) $soap->fresh()->price_grand, 0.01);
        $this->assertNull($unknown->fresh()->price_grand);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=GrandPriceBySkuTest`
Expected: FAIL — `Call to undefined method App\Support\GrandPriceList::applyBySku()`.

- [ ] **Step 3: Tambah PRICES_BY_SKU + applyBySku()**

Di `app/Support/GrandPriceList.php`, tambah const + method (setelah `apply()`):

```php
    /** SKU produk (dari master prod) => harga grand. Dipakai migrasi 000078. */
    public const PRICES_BY_SKU = [
        'SOAP-1' => 22000,
        'SK-YK' => 34000,
        'JPE-100ML' => 22000,
        'HG-FC-20ml' => 32000,
        'MZ-500ML' => 26000,
        'REI-30G' => 23000,
        'HG-1' => 13500,
        'HK-1' => 13500,
        'AG-DC-1' => 35000,
        'YR-NC-1' => 41000,
    ];

    /** Set price_grand berdasar SKU (cocok persis). No-op untuk SKU tak dikenal. */
    public static function applyBySku(): void
    {
        foreach (self::PRICES_BY_SKU as $sku => $price) {
            DB::table('products')->where('sku', $sku)->update(['price_grand' => $price]);
        }
    }
```

(`use Illuminate\Support\Facades\DB;` sudah ada di file dari 3a.)

- [ ] **Step 4: Buat migrasi 000078**

Buat `database/migrations/2026_01_01_000078_reseed_grand_price_by_sku.php`:

```php
<?php

use App\Support\GrandPriceList;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Seed by-name (000076) kena 0 match di prod (nama brand). Seed ulang by SKU.
        GrandPriceList::applyBySku();
    }

    public function down(): void
    {
        // Tak membalik harga (data koreksi).
    }
};
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=GrandPriceBySkuTest`
Expected: PASS.

- [ ] **Step 6: Jalankan SELURUH suite (regresi)**

Run: `C:\php83\php.exe artisan test`
Expected: PASS semua (existing + test baru). Perbaiki bila ada yang merah sebelum commit.

- [ ] **Step 7: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Support/GrandPriceList.php database/migrations/2026_01_01_000078_reseed_grand_price_by_sku.php tests/Feature/GrandPriceBySkuTest.php
git commit -m "fix(mlm): seed harga Grand berdasarkan SKU (nama brand tak match seed lama)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian

Setelah 4 task selesai & suite hijau:
- **REQUIRED SUB-SKILL:** superpowers:finishing-a-development-branch.
- **Deploy prod (ADA 2 migrasi 000077+000078):** `git pull origin main && /opt/alt/php83/usr/bin/php artisan migrate --force && /opt/alt/php83/usr/bin/php artisan optimize:clear` + hard-refresh.
  - Setelah deploy: cek Laporan Stok HQ Mizu — produksi 998 sekarang muncul di **7 Agu**; cek tabel produk — kolom **Grand** terisi.

---

## Self-Review (penulis rencana)

**1. Cakupan:**
- Fix A root cause (Production/StockReceipt tak teruskan tanggal) → Task 1 ✅
- Fix A data lama → Task 2 (backfill 000077) ✅
- Fix B kolom tabel → Task 3 ✅
- Fix B seed benar (by SKU) → Task 4 (000078) ✅
- Regresi penuh → Task 4 Step 6 ✅

**2. Placeholder scan:** Semua langkah berisi kode nyata; migrasi `down()` no-op diberi alasan (koreksi satu-arah), bukan placeholder.

**3. Konsistensi tipe:** `occurredAt: Carbon::parse(...)` cocok dengan signature `adjustHqStock(?\DateTimeInterface $occurredAt)`. `StockMovementDateBackfill::run()` & `GrandPriceList::applyBySku()` dipakai konsisten migrasi↔test. `REFERENCE_TYPE` string `'production'`/`'stock_receipt'` cocok bucketize HqStockReportService. Kolom `price_grand` (nullable, 3a) konsisten Task 3 & 4. Migrasi 000077/000078 unik (terakhir 000076).

**Catatan risiko (untuk reviewer/final):** backfill 000077 menggeser `created_at` gerakan produksi/penerimaan ke tanggal parent — termasuk yang backdate-nya SEBELUM titik-nol opname (14 Jul). Itu sesuai niat user (backdate) & laporan sudah menandai "data sebelum opname belum tentu akurat"; saldo tak berubah. Non-blocking, tapi disebut agar sadar.
