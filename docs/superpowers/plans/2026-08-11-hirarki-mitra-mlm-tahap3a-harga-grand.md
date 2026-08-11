# MLM Tahap 3a — Harga Grand per Produk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Grand Distributor punya harga beli produk sendiri per produk (`price_grand`), lebih murah dari distributor; semua tier lain tak berubah.

**Architecture:** Tambah kolom `price_grand` (nullable) + seed dari pricelist resmi (support class `GrandPriceList`). Grand price dibaca lewat `Product::priceForRole()` dengan fallback ke `price_distributor` (anti Rp0). Jalur beli (PurchaseOrderService) & tampilan (form Create PO) dialihkan ke `priceForRole`. Form produk dapat input harga Grand.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind, Eloquent. Zero-dependency.

**Spec:** `docs/superpowers/specs/2026-08-11-hirarki-mitra-mlm-tahap3a-harga-grand-design.md`
**Branch:** `feat/mlm-tahap3a-harga-grand` (spec sudah di-commit)

## Global Constraints

- **Zero-dependency**: tak menambah paket composer/npm.
- **Runner**: `C:\php83\php.exe artisan test`. `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **priceField() SENGAJA TIDAK diubah** (Grand tetap `'price_distributor'`) — jaring pengaman untuk konsumen kolom-mentah + nol regresi tes Tahap 1.
- **Fallback wajib**: Grand tak boleh pernah Rp0/retail — `price_grand ?? price_distributor`.
- **Cuma Grand berubah**: Distributor/Bronze/Gold/Reseller/Retail identik seperti sekarang.
- **Kolom** `price_grand` = `decimal(15,2)` NULLABLE (ikut presisi kolom harga lain).
- **Migrasi 000076** (terakhir 000075).

## Catatan Konsumen Harga (audit)

- `priceForRole()` saat ini **nol pemanggil** di app — Task 3 & 4 jadi pemakai pertamanya.
- `priceField()` dipakai: `PurchaseOrderService` (harga baris) & `purchase_orders/create.blade.php:18` (tampil). Keduanya dialihkan ke `priceForRole` (Task 3 & 4).
- **Deferred-minor (DI LUAR 3a):** `resources/views/purchase_orders/backdated.blade.php:178` — JS prefill `role==='distributor' ? price_distributor : price_reseller` (Grand jatuh ke reseller). Tool staf dengan override manual; server-nya lewat `createForPartner`→`priceForRole` (sudah benar bila tanpa override). Fix prefill Grand-nya = follow-up terpisah, bukan bagian 3a.

---

## Task 1: Kolom `price_grand` + seed harga (`GrandPriceList` + migrasi 000076)

**Files:**
- Create: `app/Support/GrandPriceList.php`
- Create: `database/migrations/2026_01_01_000076_add_price_grand_to_products.php`
- Test: `tests/Feature/GrandPriceListTest.php`

**Interfaces:**
- Produces: `App\Support\GrandPriceList::PRICES` (array nama-lowercase => harga), `GrandPriceList::apply(): void` (set `products.price_grand` untuk nama yang cocok, no-op untuk yang tak cocok). Kolom baru `products.price_grand` (decimal 15,2, nullable).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/GrandPriceListTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\GrandPriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrandPriceListTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => $name, 'sku' => 'SKU-'.(++$this->seq),
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    public function test_apply_isi_price_grand_untuk_nama_yang_cocok(): void
    {
        $faceMist = $this->product('Face Mist', ['price_distributor' => 15000]);
        $unknown = $this->product('Produk Antah Berantah');

        GrandPriceList::apply();

        $this->assertEqualsWithDelta(13500, (float) $faceMist->fresh()->price_grand, 0.01);
        $this->assertNull($unknown->fresh()->price_grand);
    }

    public function test_apply_cocok_case_insensitive_dan_trim(): void
    {
        $p = $this->product('  NIGHT CREAM  ');

        GrandPriceList::apply();

        $this->assertEqualsWithDelta(41000, (float) $p->fresh()->price_grand, 0.01);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=GrandPriceListTest`
Expected: FAIL — `Class "App\Support\GrandPriceList" not found` (dan kolom belum ada).

- [ ] **Step 3: Buat support class `GrandPriceList`**

Buat `app/Support/GrandPriceList.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Harga Grand Distributor per produk dari PRICELIST resmi SKINKU.
 * Kunci = nama produk (lowercase). Dipakai migrasi 000076 untuk seed kolom
 * price_grand, dicocokkan LOWER(TRIM(products.name)). Aman & idempoten:
 * produk yang namanya tak ada di sini dibiarkan (fallback ke price_distributor).
 */
class GrandPriceList
{
    /** nama produk (lowercase) => harga grand (rupiah). */
    public const PRICES = [
        'sabun' => 22000,
        'serum/lotion' => 34000,
        'scrub' => 22000,
        'serum wajah' => 32000,
        'sabun cair' => 26000,
        'reina underarm' => 23000,
        'face mist' => 13500,
        'mouth spray' => 13500,
        'day cream' => 35000,
        'night cream' => 41000,
    ];

    /** Set price_grand untuk produk yang namanya cocok (case-insensitive, trim). */
    public static function apply(): void
    {
        foreach (self::PRICES as $name => $price) {
            DB::table('products')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->update(['price_grand' => $price]);
        }
    }
}
```

- [ ] **Step 4: Buat migrasi 000076**

Buat `database/migrations/2026_01_01_000076_add_price_grand_to_products.php`:

```php
<?php

use App\Support\GrandPriceList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price_grand', 15, 2)->nullable()->after('price_distributor');
        });

        // Seed harga Grand dari pricelist resmi (cocokkan nama produk).
        GrandPriceList::apply();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_grand');
        });
    }
};
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=GrandPriceListTest`
Expected: PASS (2 test). (RefreshDatabase menjalankan migrasi 000076 → kolom ada.)

- [ ] **Step 6: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Support/GrandPriceList.php database/migrations/2026_01_01_000076_add_price_grand_to_products.php tests/Feature/GrandPriceListTest.php
git commit -m "feat(mlm): kolom price_grand + seed harga Grand dari pricelist" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `Product` kenal `price_grand` (fillable + cast + priceForRole)

**Files:**
- Modify: `app/Models/Product.php`
- Test: `tests/Feature/GrandPriceForRoleTest.php`

**Interfaces:**
- Consumes: kolom `price_grand` (Task 1).
- Produces: `Product::priceForRole('grand_distributor')` = `price_grand ?? price_distributor`. `price_grand` fillable + cast decimal:2.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/GrandPriceForRoleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrandPriceForRoleTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Produk '.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    public function test_grand_pakai_price_grand_bila_terisi(): void
    {
        $p = $this->product(['price_grand' => 22000]);
        $this->assertEqualsWithDelta(22000, $p->priceForRole(User::ROLE_GRAND_DISTRIBUTOR), 0.01);
    }

    public function test_grand_fallback_ke_distributor_bila_price_grand_null(): void
    {
        $p = $this->product(['price_grand' => null]);
        // Fallback = price_distributor (24000), BUKAN 0, BUKAN retail.
        $this->assertEqualsWithDelta(24000, $p->priceForRole(User::ROLE_GRAND_DISTRIBUTOR), 0.01);
    }

    public function test_tier_lain_tidak_berubah(): void
    {
        $p = $this->product(['price_grand' => 22000]);
        $this->assertEqualsWithDelta(24000, $p->priceForRole(User::ROLE_DISTRIBUTOR), 0.01);
        $this->assertEqualsWithDelta(29000, $p->priceForRole(User::ROLE_RESELLER), 0.01);
        $this->assertEqualsWithDelta(29000, $p->priceForRole(User::ROLE_RESELLER_BRONZE), 0.01);
        $this->assertEqualsWithDelta(29000, $p->priceForRole(User::ROLE_RESELLER_GOLD), 0.01);
        $this->assertEqualsWithDelta(39000, $p->priceForRole('customer'), 0.01);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=GrandPriceForRoleTest`
Expected: FAIL — `test_grand_pakai_price_grand_bila_terisi` dapat 24000 (masih price_distributor), harusnya 22000. (`price_grand` juga belum fillable → tak tersimpan.)

- [ ] **Step 3: Update `Product` model**

Di `app/Models/Product.php`:

1. Tambah `price_grand` ke `$fillable` (setelah `'price_distributor',`):
```php
    protected $fillable = [
        'name', 'sku', 'category', 'description', 'image',
        'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs',
        'hq_stock', 'status',
    ];
```

2. Tambah cast (di `casts()`, setelah `'price_distributor' => 'decimal:2',`):
```php
            'price_grand' => 'decimal:2',
```

3. Ubah `priceForRole()` — Grand baca `price_grand` dengan fallback:
```php
    public function priceForRole(string $role): float
    {
        return match ($role) {
            User::ROLE_GRAND_DISTRIBUTOR => (float) ($this->price_grand ?? $this->price_distributor),
            User::ROLE_DISTRIBUTOR => (float) $this->price_distributor,
            User::ROLE_RESELLER, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD => (float) $this->price_reseller,
            default => (float) $this->price_retail,
        };
    }
```

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=GrandPriceForRoleTest`
Expected: PASS (3 test).

- [ ] **Step 5: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Models/Product.php tests/Feature/GrandPriceForRoleTest.php
git commit -m "feat(mlm): Product::priceForRole Grand pakai price_grand + fallback distributor" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Harga beli PO Grand lewat `priceForRole` (fallback-safe)

**Files:**
- Modify: `app/Services/PurchaseOrderService.php:50,52,72`
- Test: `tests/Feature/GrandPoPriceTest.php`

**Interfaces:**
- Consumes: `Product::priceForRole($role)` (Task 2).
- Produces: harga baris PO (non-override) = `$product->priceForRole($buyer->role)`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/GrandPoPriceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GrandPoPriceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Produk '.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    public function test_po_grand_pakai_price_grand(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(['price_grand' => 22000]);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 2]], null, null);

        $this->assertEqualsWithDelta(22000, (float) $po->items->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(44000, (float) $po->total_amount, 0.01);
    }

    public function test_po_grand_fallback_distributor_bila_price_grand_null(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(['price_grand' => null]);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 1]], null, null);

        // Fallback distributor (24000), BUKAN 0.
        $this->assertEqualsWithDelta(24000, (float) $po->items->first()->unit_price, 0.01);
    }

    public function test_po_distributor_tidak_berubah(): void
    {
        $dist = $this->user(User::ROLE_DISTRIBUTOR);
        $p = $this->product(['price_grand' => 22000]);

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 1]], null, null);

        $this->assertEqualsWithDelta(24000, (float) $po->items->first()->unit_price, 0.01);
    }

    public function test_price_override_tetap_menang(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(['price_grand' => 22000]);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 1]], null, null, [$p->id => 20000]);

        $this->assertEqualsWithDelta(20000, (float) $po->items->first()->unit_price, 0.01);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=GrandPoPriceTest`
Expected: FAIL — `test_po_grand_pakai_price_grand` dapat 24000 (masih `priceField()`→price_distributor), harusnya 22000.

- [ ] **Step 3: Alihkan harga baris ke `priceForRole`**

Di `app/Services/PurchaseOrderService.php`, method `createForPartner`:

1. Hapus baris 50 `$priceField = $buyer->priceField();`.
2. Ubah `use (...)` closure (baris 52) — buang `$priceField`:
```php
        return DB::transaction(function () use ($buyer, $clean, $shippingAddress, $notes, $priceOverrides) {
```
3. Ubah harga baris (baris ~70-72):
```php
                $unitPrice = isset($priceOverrides[$productId])
                    ? (float) $priceOverrides[$productId]
                    : (float) $product->priceForRole($buyer->role);
```

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=GrandPoPriceTest`
Expected: PASS (4 test).

- [ ] **Step 5: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/PurchaseOrderService.php tests/Feature/GrandPoPriceTest.php
git commit -m "feat(mlm): harga PO Grand via priceForRole (fallback-safe)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Form Create PO tampilkan harga Grand (via `priceForRole`)

**Files:**
- Modify: `app/Http/Controllers/PurchaseOrderController.php:75,81`
- Modify: `resources/views/purchase_orders/create.blade.php:18`
- Test: `tests/Feature/GrandPoCreatePageTest.php`

**Interfaces:**
- Consumes: `Product::priceForRole($role)` (Task 2). View `purchase_orders/create` menerima `$products` & `$user` (tak lagi `$priceField`).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/GrandPoCreatePageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GrandPoCreatePageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => 'p', 'fullname' => 'P', 'username' => 'p', 'email' => 'p@skinku.test',
            'password' => Hash::make('secret123'), 'company_name' => 'CV P', 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Sabun', 'sku' => 'SB1',
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'price_grand' => 22000, 'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    public function test_grand_lihat_harga_grand_di_form_create_po(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->product();

        $this->actingAs($grand)->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertSee('22.000');   // harga Grand, bukan 24.000 (distributor)
    }
}
```

> Catatan: `create_po` diasumsikan aktif untuk Grand (default Tahap 1 = "seperti distributor", dan distributor punya create_po). Bila subagent menemukan Grand belum punya `create_po` di matriks izin default, itu temuan terpisah — laporkan, jangan longgarkan test.

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=GrandPoCreatePageTest`
Expected: FAIL — halaman menampilkan `24.000` (masih `$p->{$priceField}` = price_distributor untuk Grand), bukan `22.000`.

- [ ] **Step 3: Alihkan tampilan ke `priceForRole`**

1. `app/Http/Controllers/PurchaseOrderController.php::create` — hapus baris 75 (`$priceField = $user->priceField();`) dan ubah baris 81 kirim view tanpa `priceField`:
```php
        return view('purchase_orders.create', compact('products', 'user'));
```

2. `resources/views/purchase_orders/create.blade.php:18` — ubah:
```blade
                        @php $price = $p->priceForRole($user->role); $urls = $p->imageUrls(); @endphp
```

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=GrandPoCreatePageTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/PurchaseOrderController.php resources/views/purchase_orders/create.blade.php tests/Feature/GrandPoCreatePageTest.php
git commit -m "feat(mlm): form Create PO tampilkan harga Grand via priceForRole" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Form produk — input "Harga Grand" (simpan + edit)

**Files:**
- Modify: `app/Http/Controllers/ProductController.php:118-134` (validateData)
- Modify: `resources/views/products/index.blade.php:76,106,140`
- Test: `tests/Feature/ProductGrandPriceFormTest.php`

**Interfaces:**
- Consumes: `price_grand` fillable (Task 2).
- Produces: `products.store`/`products.update` menerima & menyimpan `price_grand` (nullable).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/ProductGrandPriceFormTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductGrandPriceFormTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sabun', 'sku' => 'SB1',
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides);
    }

    public function test_simpan_produk_dengan_price_grand(): void
    {
        $this->actingAs($this->admin())->post(route('products.store'), $this->payload(['price_grand' => 22000]))
            ->assertRedirect();

        $this->assertEqualsWithDelta(22000, (float) Product::first()->price_grand, 0.01);
    }

    public function test_price_grand_boleh_kosong_null(): void
    {
        $this->actingAs($this->admin())->post(route('products.store'), $this->payload())
            ->assertRedirect();

        $this->assertNull(Product::first()->price_grand);
    }

    public function test_update_price_grand(): void
    {
        $p = Product::create($this->payload(['sku' => 'SB2', 'price_grand' => 22000]));

        $this->actingAs($this->admin())->put(route('products.update', $p), $this->payload(['sku' => 'SB2', 'price_grand' => 21000]))
            ->assertRedirect();

        $this->assertEqualsWithDelta(21000, (float) $p->fresh()->price_grand, 0.01);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=ProductGrandPriceFormTest`
Expected: FAIL — `price_grand` tak tervalidasi/disimpan (`test_simpan_produk_dengan_price_grand` dapat null).

- [ ] **Step 3: Validasi `price_grand` di controller**

Di `app/Http/Controllers/ProductController.php::validateData`, tambah aturan (setelah baris `'price_distributor' => [...]`):
```php
            'price_grand' => ['nullable', 'numeric', 'min:0'],
```
(store/update sudah `Product::create/update(Arr::except($data, [...]))` → `price_grand` ikut karena ada di `$data` & fillable.)

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=ProductGrandPriceFormTest`
Expected: PASS (3 test).

- [ ] **Step 5: Tambah input di form produk**

Di `resources/views/products/index.blade.php`:

1. Input harga Grand — sisipkan setelah input "Harga Distributor" (baris 106):
```blade
            <div><label class="block text-xs font-semibold mb-1">Harga Grand Distributor</label><input type="number" step="0.01" name="price_grand" class="w-full px-3 py-2 border border-stone-300 rounded-lg" placeholder="kosong = ikut distributor"></div>
```

2. Pre-fill saat Edit — di `onclick='openProduct(...)'` (baris 76), tambah `"price_grand"` ke daftar `only([...])`:
```blade
                                onclick='openProduct({{ json_encode($p->only(["id","name","sku","category","description","price_grand","price_distributor","price_reseller","price_retail","cogs","hq_stock","status"]) + ["gallery" => $gallery]) }})'>Edit</button>
```

3. Isi field saat Edit — di JS `openProduct` (baris 140), tambah `'price_grand'` ke array key:
```blade
            for (const k of ['name','sku','category','description','price_grand','price_distributor','price_reseller','price_retail','cogs','hq_stock','status']) {
```

- [ ] **Step 6: Jalankan seluruh suite (regresi)**

Run: `C:\php83\php.exe artisan test`
Expected: PASS semua (existing + test 3a baru). Perbaiki bila ada yang merah sebelum commit.

- [ ] **Step 7: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/ProductController.php resources/views/products/index.blade.php tests/Feature/ProductGrandPriceFormTest.php
git commit -m "feat(mlm): input Harga Grand di form produk (simpan + edit)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian

Setelah 5 task selesai & suite hijau:
- **REQUIRED SUB-SKILL:** superpowers:finishing-a-development-branch (verifikasi tes, pilih integrasi, deploy).
- **Deploy prod (ADA migrasi):** `git pull origin main && /opt/alt/php83/usr/bin/php artisan migrate --force && /opt/alt/php83/usr/bin/php artisan optimize:clear` + hard-refresh. Setelah deploy: cek produk yang namanya cocok pricelist sudah punya harga Grand; sisanya diisi via form.

---

## Self-Review (penulis rencana)

**1. Cakupan spec:**
- §4.1 migrasi + seed → Task 1 ✅
- §4.2 Product fillable/cast/priceForRole → Task 2 ✅
- §4.3 harga via priceForRole (PO service + create view; priceField TAK diubah) → Task 3 & 4 ✅
- §4.4 form produk price_grand → Task 5 ✅
- §5 dampak/keamanan (cuma Grand, fallback anti-Rp0) → Task 2 fallback + Task 3 fallback test ✅
- §6 rencana uji (priceForRole, PO Grand+fallback, tier lain, form, seed) → Task 1–5 test ✅
- §3 seed 10 harga → Task 1 `GrandPriceList::PRICES` (nilai persis) ✅
- Konsumen backdated → dicatat deferred-minor (bukan tugas) ✅

**2. Placeholder scan:** Semua langkah berisi kode nyata. JS saran "distributor−8%" (opsional di spec) sengaja TIDAK dimasukkan (YAGNI) — bukan placeholder.

**3. Konsistensi tipe:** `price_grand` (decimal:2, nullable) konsisten migrasi→model→form. `priceForRole($role): float` dipakai sama di Task 3 & 4. `GrandPriceList::apply()`/`PRICES` konsisten Task 1. Nama route `purchase-orders.create`, `products.store/update` sesuai kode existing. `User::ROLE_GRAND_DISTRIBUTOR` dipakai konsisten.
