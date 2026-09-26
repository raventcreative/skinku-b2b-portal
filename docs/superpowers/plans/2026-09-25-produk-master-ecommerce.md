# Produk Master E-commerce — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti model stok marketplace Fase 1/1.5 (product-keyed, stok bundle dihitung dari komponen) menjadi **Produk Master E-commerce** ala Desty: tiap unit jualan (satuan/varian/**bundle**) = 1 master dengan **stok + harga di-set langsung**, tertaut ke listing TikTok/Shopee, override per-channel, terpisah total dari stok HQ.

**Architecture:** Bangun engine baru **`MarketplaceMasterService`** secara ADITIF (service lama `MarketplaceStockService` + semua tesnya TIDAK disentuh sampai cutover) → tabel `marketplace_masters` + `marketplace_master_channels`, `marketplace_listings` dapat `master_id` + kolom harga. Setelah engine baru lengkap & tertes, **cutover** (controller/route/view + cron + 2 OrderService diarahkan ke engine baru; tes lama diganti tes master). Terakhir **removal** (hapus service+model lama, drop tabel lama via migrasi data). Suite hijau di tiap batas task.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind (inline, zero-dep), PHPUnit class-style.

**Spec:** `docs/superpowers/specs/2026-09-23-produk-master-ecommerce-design.md`

## Global Constraints

- **Zero-dependency:** tak menambah package composer/npm. Tulis helper minimal bila perlu.
- **Test framework:** PHPUnit **class-style** (repo TIDAK punya Pest, TIDAK punya ProductFactory). `Product`/`User` dibuat langsung via `::create([...])`. Ikuti pola helper `product()`/`admin()` di `tests/Feature/MarketplaceStock/MarketplaceUiTest.php`.
- **Local runner:** `C:\php83\php.exe artisan test`. Format sebelum commit: `C:\php83\php.exe vendor/bin/pint --dirty`.
- **Blade:** JANGAN `@json([...])` dgn array literal (500). Semua aksi form = POST + `@csrf`. Picker = native `<select>`.
- **Izin/rute:** gate `$u->canDo('manage_marketplace_stock')` (izin lama, dipakai ulang — TIDAK bikin izin baru). Rute di grup middleware `permission:manage_marketplace_stock`.
- **HQ TERPISAH TOTAL:** master e-commerce TAK baca/tulis `products.hq_stock` / `stock_movements`. Potong stok HQ (`InventoryService`) + peta SKU (`tiktok_sku_maps`/`shopee_sku_maps`) tetap HANYA untuk HQ, TIDAK diubah. Setiap task yang menyentuh OrderService WAJIB memastikan potong-stok HQ tetap jalan (assert `StockMovement` di tes).
- **Anti-push null:** stok/harga efektif `null` → listing itu TAK di-push (masing-masing field). Tak ada hitung komponen — bundle = angka master-nya sendiri.
- **Transitional duplication OK:** `MarketplaceMasterService` boleh menduplikasi helper token/http + logika paging/upsert listing dari `MarketplaceStockService` selama T2–T6; service lama DIHAPUS di Task 7. Reviewer: jangan flag duplikasi ini sbg defect — disengaja & sementara.
- **Branch:** `feat/stok-marketplace-harga` (sudah berisi main + Kalkulator ROI). Migrasi lanjut dari **000141** (terakhir) → **000142**, **000143**.
- **Persen/uang:** stok = integer; harga = decimal(12,2). TikTok `update_price` butuh amount **string**; Shopee `update_price` butuh `original_price` **number**.

---

## File Structure

**Buat:**
- `database/migrations/2026_01_01_000142_create_marketplace_master_tables.php` — masters + master_channels + alter listings (T1).
- `app/Models/MarketplaceMaster.php`, `app/Models/MarketplaceMasterChannel.php` (T1).
- `app/Services/MarketplaceMasterService.php` — engine baru (T2–T5).
- `resources/views/marketplace-stock/index.blade.php` — DITULIS ULANG jadi halaman Produk Master (T6, replace file lama).
- `resources/views/marketplace-stock/channel.blade.php` — DITULIS ULANG jadi Stok & Harga per channel (T6).
- `database/migrations/2026_01_01_000143_drop_legacy_marketplace_stock_tables.php` — migrasi data lama→master lalu drop (T7).
- Tes baru: `tests/Unit/MarketplaceMaster/EffectiveValuesTest.php`, `.../SettersTest.php`; `tests/Feature/MarketplaceMaster/{ResolveMasterTest,TautkanTest,PushMasterTest,ClientUpdatePriceTest,SeedMasterTest,OrderMirrorMasterTest,MasterPageTest,ChannelPageTest,AccessTest}.php`.

**Ubah:**
- `app/Models/MarketplaceListing.php` — tambah `master_id` + kolom harga ke fillable/casts + relasi `master()` (T1).
- `app/Services/TikTokClient.php`, `app/Services/ShopeeClient.php` — tambah `updatePrice()` (T4).
- `app/Http/Controllers/MarketplaceStockController.php` — ditulis ulang master-keyed (T6).
- `routes/web.php` — grup `manage_marketplace_stock` ditulis ulang master-keyed (T6).
- `app/Console/Commands/MarketplacePushStockCommand.php` — arahkan ke `MarketplaceMasterService::pushDirty` (T6).
- `app/Services/TikTokOrderService.php`, `app/Services/ShopeeOrderService.php` — `mirrorMarketplace()` diarahkan ke engine baru (T6).
- `docs/SISTEM.md`, `docs/PETA-SISTEM.md` (T7).

**Hapus (T7):** `app/Services/MarketplaceStockService.php`, `app/Models/MarketplaceStock.php`, `app/Models/MarketplaceChannelOverride.php`, dan tes lama yg menguji engine lama (didaftar di T6/T7).

---

### Task 1: Skema master + model (aditif)

**Files:**
- Create: `database/migrations/2026_01_01_000142_create_marketplace_master_tables.php`
- Create: `app/Models/MarketplaceMaster.php`, `app/Models/MarketplaceMasterChannel.php`
- Modify: `app/Models/MarketplaceListing.php`
- Test: `tests/Feature/MarketplaceMaster/ModelTest.php`

**Interfaces:**
- Produces:
  - Tabel `marketplace_masters` (id, master_sku unique, name, product_id nullable FK, base_stock int null, base_price decimal(12,2) null, seeded_at ts null, timestamps).
  - Tabel `marketplace_master_channels` (id, master_id FK cascade, channel string(16), stock int null, price decimal(12,2) null, seeded_at ts null, timestamps; unique(master_id,channel)).
  - `marketplace_listings` + kolom: `master_id` nullable FK (nullOnDelete), `last_pushed_price` decimal(12,2) null, `last_price_status` string(16) null, `last_price_error` text null, `last_price_pushed_at` ts null.
  - `MarketplaceMaster` model: fillable `master_sku,name,product_id,base_stock,base_price,seeded_at`; casts `base_stock=>integer, base_price=>decimal:2, seeded_at=>datetime`; relations `product()` belongsTo Product, `channels()` hasMany MarketplaceMasterChannel, `listings()` hasMany MarketplaceListing.
  - `MarketplaceMasterChannel` model: fillable `master_id,channel,stock,price,seeded_at`; casts `stock=>integer, price=>decimal:2, seeded_at=>datetime`; relation `master()` belongsTo.
  - `MarketplaceListing` model: `master_id` + 4 price cols added to fillable/casts; relation `master()` belongsTo MarketplaceMaster.
- Consumes: `App\Models\Product` (existing), `MarketplaceListing` (existing, table `marketplace_listings`).

- [ ] **Step 1: Tulis migrasi**

`database/migrations/2026_01_01_000142_create_marketplace_master_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Produk Master e-commerce: tiap unit jualan (satuan/varian/bundle) = 1 baris.
        Schema::create('marketplace_masters', function (Blueprint $t) {
            $t->id();
            $t->string('master_sku')->unique();
            $t->string('name');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // referensi opsional, BUKAN sumber stok
            $t->integer('base_stock')->nullable();       // null = belum di-set → tak di-push
            $t->decimal('base_price', 12, 2)->nullable();
            $t->timestamp('seeded_at')->nullable();      // patokan reconcile order-mirror (stok)
            $t->timestamps();
        });

        // Override per channel di atas Master.
        Schema::create('marketplace_master_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('master_id')->constrained('marketplace_masters')->cascadeOnDelete();
            $t->string('channel', 16); // tiktok | shopee
            $t->integer('stock')->nullable();            // null = ikut Master
            $t->decimal('price', 12, 2)->nullable();     // null = ikut Master
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
            $t->unique(['master_id', 'channel']);
        });

        // Listing tertaut ke master + jejak push harga (jejak push stok sudah ada dari Fase 1).
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->foreignId('master_id')->nullable()->after('id')->constrained('marketplace_masters')->nullOnDelete();
            $t->decimal('last_pushed_price', 12, 2)->nullable()->after('last_pushed_qty');
            $t->string('last_price_status', 16)->nullable()->after('last_pushed_price'); // ok | failed
            $t->text('last_price_error')->nullable()->after('last_price_status');
            $t->timestamp('last_price_pushed_at')->nullable()->after('last_price_error');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('master_id');
            $t->dropColumn(['last_pushed_price', 'last_price_status', 'last_price_error', 'last_price_pushed_at']);
        });
        Schema::dropIfExists('marketplace_master_channels');
        Schema::dropIfExists('marketplace_masters');
    }
};
```

- [ ] **Step 2: Tulis model `MarketplaceMaster`**

`app/Models/MarketplaceMaster.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceMaster extends Model
{
    protected $fillable = ['master_sku', 'name', 'product_id', 'base_stock', 'base_price', 'seeded_at'];

    protected function casts(): array
    {
        return [
            'base_stock' => 'integer',
            'base_price' => 'decimal:2',
            'seeded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(MarketplaceMasterChannel::class, 'master_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'master_id');
    }
}
```

- [ ] **Step 3: Tulis model `MarketplaceMasterChannel`**

`app/Models/MarketplaceMasterChannel.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceMasterChannel extends Model
{
    protected $fillable = ['master_id', 'channel', 'stock', 'price', 'seeded_at'];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'price' => 'decimal:2',
            'seeded_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(MarketplaceMaster::class, 'master_id');
    }
}
```

- [ ] **Step 4: Update model `MarketplaceListing`**

Ganti isi `app/Models/MarketplaceListing.php` menjadi (mempertahankan kolom lama + menambah master & harga):

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceListing extends Model
{
    protected $fillable = [
        'channel', 'seller_sku', 'item_id', 'variation_id', 'warehouse_id', 'title',
        'master_id',
        'last_pushed_qty', 'last_status', 'last_error', 'last_pushed_at', 'resolved_at',
        'last_pushed_price', 'last_price_status', 'last_price_error', 'last_price_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_pushed_qty' => 'integer',
            'last_pushed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_pushed_price' => 'decimal:2',
            'last_price_pushed_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(MarketplaceMaster::class, 'master_id');
    }
}
```

- [ ] **Step 5: Tulis tes model (gagal dulu)**

`tests/Feature/MarketplaceMaster/ModelTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class ModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_menyimpan_dan_relasi(): void
    {
        $p = Product::create(['name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist', 'product_id' => $p->id, 'base_stock' => 100, 'base_price' => 39000]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 50]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id]);

        $this->assertSame(100, $m->base_stock);
        $this->assertSame('39000.00', (string) $m->base_price);
        $this->assertSame('Face Mist', $p->refresh() && $m->product->name);
        $this->assertSame(1, $m->channels()->count());
        $this->assertSame($m->id, $l->master->id);
    }

    public function test_master_sku_unik(): void
    {
        MarketplaceMaster::create(['master_sku' => 'DUP', 'name' => 'A']);
        $this->expectException(QueryException::class);
        MarketplaceMaster::create(['master_sku' => 'DUP', 'name' => 'B']);
    }

    public function test_channel_unik_per_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X']);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 1]);
        $this->expectException(QueryException::class);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 2]);
    }

    public function test_bundle_master_punya_stok_sendiri_tanpa_produk(): void
    {
        // Bundle = master SKU baru, product_id null, stok/harga sendiri.
        $m = MarketplaceMaster::create(['master_sku' => 'BUNDLE-3', 'name' => 'Paket 3pcs', 'product_id' => null, 'base_stock' => 7, 'base_price' => 99000]);
        $this->assertNull($m->product_id);
        $this->assertSame(7, $m->base_stock);
    }
}
```

- [ ] **Step 6: Jalankan tes — hijau**

Run: `C:\php83\php.exe artisan test --filter=MarketplaceMaster\\ModelTest`
Expected: 4 passed (migrasi otomatis via RefreshDatabase). Lalu `C:\php83\php.exe artisan test --filter=MarketplaceStock` untuk pastikan Fase 1/1.5 lama TETAP hijau (aditif).

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000142_create_marketplace_master_tables.php app/Models/MarketplaceMaster.php app/Models/MarketplaceMasterChannel.php app/Models/MarketplaceListing.php tests/Feature/MarketplaceMaster/ModelTest.php
git commit -m "feat(mp-master): skema marketplace_masters/master_channels + master_id & harga di listing + model"
```

---

### Task 2: Engine — nilai efektif, setter, `updatePrice` client (aditif)

**Files:**
- Create: `app/Services/MarketplaceMasterService.php`
- Modify: `app/Services/TikTokClient.php`, `app/Services/ShopeeClient.php`
- Test: `tests/Unit/MarketplaceMaster/EffectiveValuesTest.php`, `tests/Unit/MarketplaceMaster/SettersTest.php`, `tests/Feature/MarketplaceMaster/ClientUpdatePriceTest.php`

**Interfaces:**
- Consumes: models dari Task 1; `ShopeeClient`, `TikTokClient` (existing), `TiktokConnection`/`ShopeeConnection` (existing).
- Produces (semua public di `MarketplaceMasterService`):
  - `effectiveStock(MarketplaceMaster $m, string $channel): ?int` — override channel (stock non-null) → base_stock → null.
  - `effectivePrice(MarketplaceMaster $m, string $channel): ?float` — override channel (price non-null) → base_price → null.
  - `setMasterStock(MarketplaceMaster $m, int $qty): void` — base_stock=max(0,qty), seeded_at=now().
  - `setMasterPrice(MarketplaceMaster $m, float $price): void` — base_price=max(0,price).
  - `setChannelStock(MarketplaceMaster $m, string $channel, int $qty): MarketplaceMasterChannel` — updateOrCreate, stock=max(0,qty), seeded_at=now().
  - `setChannelPrice(MarketplaceMaster $m, string $channel, float $price): MarketplaceMasterChannel` — updateOrCreate, price=max(0,price).
  - `ikutMaster(MarketplaceMaster $m, string $channel, string $field): void` — field ∈ {stock,price}; null-kan field itu; hapus baris channel bila stock & price dua-duanya null.
  - `TikTokClient::updatePrice(string $accessToken, string $shopCipher, string $productId, string $skuId, float $price): array`.
  - `ShopeeClient::updatePrice(string $accessToken, string $shopId, int $itemId, int $modelId, float $price): array`.

- [ ] **Step 1: Tulis tes nilai efektif + setter (gagal dulu)**

`tests/Unit/MarketplaceMaster/EffectiveValuesTest.php`:

```php
<?php

namespace Tests\Unit\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectiveValuesTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    public function test_stok_efektif_override_menang_lalu_base_lalu_null(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->assertNull($this->svc()->effectiveStock($m, 'tiktok')); // belum ada apa-apa

        $m->update(['base_stock' => 40]);
        $this->assertSame(40, $this->svc()->effectiveStock($m->refresh(), 'tiktok')); // base

        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 12]);
        $this->assertSame(12, $this->svc()->effectiveStock($m->refresh(), 'tiktok')); // override menang
        $this->assertSame(40, $this->svc()->effectiveStock($m, 'shopee')); // channel lain tetap base
    }

    public function test_harga_efektif_override_menang_lalu_base_lalu_null(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->assertNull($this->svc()->effectivePrice($m, 'tiktok'));

        $m->update(['base_price' => 39000]);
        $this->assertSame(39000.0, $this->svc()->effectivePrice($m->refresh(), 'tiktok'));

        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'price' => 42000]);
        $this->assertSame(42000.0, $this->svc()->effectivePrice($m->refresh(), 'tiktok'));
    }

    public function test_override_stok_saja_harga_ikut_base(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 40, 'base_price' => 39000]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 5, 'price' => null]);
        $this->assertSame(5, $this->svc()->effectiveStock($m, 'tiktok'));
        $this->assertSame(39000.0, $this->svc()->effectivePrice($m, 'tiktok')); // price override null → ikut base
    }
}
```

`tests/Unit/MarketplaceMaster/SettersTest.php`:

```php
<?php

namespace Tests\Unit\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettersTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    public function test_set_master_stock_menyetel_dan_stempel_seeded(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setMasterStock($m, 25);
        $m->refresh();
        $this->assertSame(25, $m->base_stock);
        $this->assertNotNull($m->seeded_at);
    }

    public function test_set_master_stock_clamp_negatif_ke_nol(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setMasterStock($m, -5);
        $this->assertSame(0, $m->refresh()->base_stock);
    }

    public function test_set_master_price(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setMasterPrice($m, 39000);
        $this->assertSame('39000.00', (string) $m->refresh()->base_price);
    }

    public function test_set_channel_stock_updateOrCreate_dan_stempel(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $row = $this->svc()->setChannelStock($m, 'tiktok', 7);
        $this->assertSame(7, $row->stock);
        $this->assertNotNull($row->seeded_at);
        $this->svc()->setChannelStock($m, 'tiktok', 9); // update, bukan baris baru
        $this->assertSame(1, MarketplaceMasterChannel::where('master_id', $m->id)->count());
        $this->assertSame(9, MarketplaceMasterChannel::where('master_id', $m->id)->first()->stock);
    }

    public function test_ikut_master_nullkan_field_dan_hapus_baris_bila_kosong(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setChannelStock($m, 'tiktok', 7);
        $this->svc()->setChannelPrice($m, 'tiktok', 42000);

        $this->svc()->ikutMaster($m, 'tiktok', 'stock'); // harga masih override → baris tetap
        $row = MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->stock);
        $this->assertSame('42000.00', (string) $row->price);

        $this->svc()->ikutMaster($m, 'tiktok', 'price'); // dua-duanya null → hapus baris
        $this->assertSame(0, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->count());
    }
}
```

- [ ] **Step 2: Jalankan — gagal** (`MarketplaceMasterService` belum ada).
Run: `C:\php83\php.exe artisan test --filter=MarketplaceMaster`

- [ ] **Step 3: Tulis `MarketplaceMasterService` (skeleton + helper token/http + effective + setters)**

`app/Services/MarketplaceMasterService.php` (helper token/http = SALINAN dari `MarketplaceStockService`, sengaja diduplikasi selama transisi):

```php
<?php

namespace App\Services;

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use Carbon\Carbon;

/**
 * Engine "Produk Master E-commerce" (ala Desty): tiap unit jualan (satuan/varian/
 * bundle) = 1 master dgn stok+harga di-set langsung, tertaut ke listing TikTok/
 * Shopee, override per-channel. TERPISAH TOTAL dari stok HQ.
 */
class MarketplaceMasterService
{
    public function __construct(private ShopeeClient $shopee, private TikTokClient $tiktok) {}

    // ---- Nilai efektif per (master, channel) ----

    public function effectiveStock(MarketplaceMaster $m, string $channel): ?int
    {
        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->stock !== null) {
            return (int) $ch->stock;
        }

        return $m->base_stock !== null ? (int) $m->base_stock : null;
    }

    public function effectivePrice(MarketplaceMaster $m, string $channel): ?float
    {
        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->price !== null) {
            return (float) $ch->price;
        }

        return $m->base_price !== null ? (float) $m->base_price : null;
    }

    // ---- Setter Master ----

    public function setMasterStock(MarketplaceMaster $m, int $qty): void
    {
        $m->update(['base_stock' => max(0, $qty), 'seeded_at' => now()]);
    }

    public function setMasterPrice(MarketplaceMaster $m, float $price): void
    {
        $m->update(['base_price' => max(0, $price)]);
    }

    // ---- Setter override channel ----

    public function setChannelStock(MarketplaceMaster $m, string $channel, int $qty): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['stock' => max(0, $qty), 'seeded_at' => now()],
        );
    }

    public function setChannelPrice(MarketplaceMaster $m, string $channel, float $price): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['price' => max(0, $price)],
        );
    }

    /** Kembalikan satu field channel ke "ikut Master"; hapus baris bila stock & price dua-duanya null. */
    public function ikutMaster(MarketplaceMaster $m, string $channel, string $field): void
    {
        $row = MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if (! $row) {
            return;
        }
        $row->update([$field => null]);
        if ($row->stock === null && $row->price === null) {
            $row->delete();
        }
    }

    // ---- Helper koneksi & token (SALINAN transisi dari MarketplaceStockService) ----

    private function tiktokConn(): ?TiktokConnection
    {
        return TiktokConnection::latest('id')->first();
    }

    private function shopeeConn(): ?ShopeeConnection
    {
        return ShopeeConnection::latest('id')->first();
    }

    private function tiktokToken(TiktokConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->tiktok->refreshToken($c->refresh_token);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->tiktokExpiry($t['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->tiktokExpiry($t['refresh_token_expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    private function tiktokExpiry(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        $v = (int) $v;

        return $v > 1_000_000_000 ? Carbon::createFromTimestamp($v) : now()->addSeconds($v);
    }

    private function shopeeToken(ShopeeConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->shopee->refreshToken($c->refresh_token, $c->shop_id);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->shopeeExpiry($t['expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    private function shopeeExpiry(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }
}
```

- [ ] **Step 4: Tulis tes client updatePrice (gagal dulu)**

`tests/Feature/MarketplaceMaster/ClientUpdatePriceTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Services\ShopeeClient;
use App\Services\TikTokClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientUpdatePriceTest extends TestCase
{
    public function test_tiktok_update_price_mengirim_amount_string_dan_currency(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        app(TikTokClient::class)->updatePrice('tok', 'cipher', 'PID1', 'SKU1', 39000);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            return str_contains($req->url(), '/product/202309/products/PID1/prices/update')
                && $body['skus'][0]['id'] === 'SKU1'
                && $body['skus'][0]['price']['amount'] === '39000'
                && $body['skus'][0]['price']['currency'] === 'IDR';
        });
    }

    public function test_shopee_update_price_mengirim_model_id_dan_original_price(): void
    {
        Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);
        app(ShopeeClient::class)->updatePrice('tok', '123', 555, 66, 42000);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/api/v2/product/update_price')
                && $req['item_id'] === 555
                && $req['price_list'][0]['model_id'] === 66
                && (int) $req['price_list'][0]['original_price'] === 42000;
        });
    }
}
```

- [ ] **Step 5: Tambah `updatePrice` ke kedua client**

Di `app/Services/TikTokClient.php`, tambah method (letakkan tepat setelah `updateStock`). Amount WAJIB string (format tanpa desimal utk IDR):

```php
    /** Perbarui harga satu SKU (Product Price API 202309). Amount = string, currency IDR. */
    public function updatePrice(string $accessToken, string $shopCipher, string $productId, string $skuId, float $price): array
    {
        return $this->request('POST', "/product/202309/products/{$productId}/prices/update", $accessToken, $shopCipher, [], [
            'skus' => [['id' => $skuId, 'price' => ['amount' => (string) (int) round($price), 'currency' => 'IDR']]],
        ]);
    }
```

Di `app/Services/ShopeeClient.php`, tambah method (setelah `updateStock`):

```php
    /** Perbarui harga satu model/varian (model_id=0 kalau item tanpa varian). */
    public function updatePrice(string $accessToken, string $shopId, int $itemId, int $modelId, float $price): array
    {
        return $this->shopCall('POST', '/api/v2/product/update_price', $accessToken, $shopId, [
            'item_id' => $itemId,
            'price_list' => [['model_id' => $modelId, 'original_price' => (int) round($price)]],
        ]);
    }
```

- [ ] **Step 6: Jalankan tes — hijau**

Run: `C:\php83\php.exe artisan test --filter=MarketplaceMaster` (EffectiveValues 3 + Setters 5 + ClientUpdatePrice 2 = 10 + ModelTest 4). Lalu `--filter=MarketplaceStock` tetap hijau.

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MarketplaceMasterService.php app/Services/TikTokClient.php app/Services/ShopeeClient.php tests/Unit/MarketplaceMaster tests/Feature/MarketplaceMaster/ClientUpdatePriceTest.php
git commit -m "feat(mp-master): nilai efektif + setter master/channel + client updatePrice (TikTok/Shopee)"
```

---

### Task 3: Engine — resolve auto-buat master + tautkan (aditif)

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php`
- Test: `tests/Feature/MarketplaceMaster/ResolveMasterTest.php`, `tests/Feature/MarketplaceMaster/TautkanTest.php`

**Interfaces:**
- Consumes: `TikTokClient::searchProducts`/`getWarehouses` & `ShopeeClient::getItemList`/`getModelList`/`getItemBaseInfo` (existing — dipakai persis spt `MarketplaceStockService::resolveTiktok/resolveShopee`), models Task 1.
- Produces:
  - `resolveListings(string $channel): array` → `['found'=>int,'mastered'=>int]` (tiap listing dapat master).
  - `findOrCreateMaster(string $sellerSku, ?string $title): MarketplaceMaster`.
  - `tautkanListing(MarketplaceListing $listing, ?int $masterId, ?string $newSku = null, ?string $newName = null): void` — pindahkan listing ke master lain, atau buat master baru lalu tautkan.

> **Catatan implementer:** logika paging `resolveTiktok()`/`resolveShopee()` + `upsertListing()` disalin dari `app/Services/MarketplaceStockService.php` (baca file itu: method `resolveTiktok` :264, `resolveShopee` :381, `upsertListing` :449). Perbedaan HANYA: (a) buang parameter/pemakaian `componentsFor()` & flag `unmapped` berbasis SkuMap; (b) setelah upsert listing, panggil `findOrCreateMaster(seller_sku, title)` dan set `listing.master_id`. Ingat: `TikTokClient::request()` sudah unwrap ke `data`, jadi path `data_get()` TANPA prefiks `data.` (lihat komentar di resolveTiktok lama).

- [ ] **Step 1: Tulis tes resolve + tautkan (gagal dulu)**

`tests/Feature/MarketplaceMaster/ResolveMasterTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_tiktok_auto_buat_master_dan_set_master_id(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        // Produk internal ber-SKU sama → master.product_id keisi (opsional).
        Product::create(['name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'PID1', 'title' => 'Face Mist 60ml',
                    'skus' => [['id' => 'SKU1', 'seller_sku' => 'FM-1', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 10]]]],
                ]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH1', 'type' => 'SALES_WAREHOUSE']]]]),
        ]);

        $r = app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $this->assertSame(1, $r['found']);
        $this->assertSame(1, $r['mastered']);
        $m = MarketplaceMaster::where('master_sku', 'FM-1')->first();
        $this->assertNotNull($m);
        $this->assertSame('Face Mist 60ml', $m->name);
        $this->assertNotNull($m->product_id); // produk ber-SKU sama ditemukan
        $l = MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->first();
        $this->assertSame($m->id, $l->master_id);
    }

    public function test_resolve_tanpa_koneksi_mengembalikan_nol(): void
    {
        $r = app(MarketplaceMasterService::class)->resolveListings('tiktok');
        $this->assertSame(0, $r['found']);
    }
}
```

`tests/Feature/MarketplaceMaster/TautkanTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TautkanTest extends TestCase
{
    use RefreshDatabase;

    public function test_tautkan_ke_master_existing_menggabungkan_listing(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'REI-3', 'name' => 'Reina 3']);
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'REI-30G', 'item_id' => 'IT']);

        app(MarketplaceMasterService::class)->tautkanListing($l, $m->id);

        $this->assertSame($m->id, $l->refresh()->master_id);
    }

    public function test_tautkan_buat_master_baru(): void
    {
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'NEW-1', 'item_id' => 'IT']);

        app(MarketplaceMasterService::class)->tautkanListing($l, null, 'NEW-1', 'Produk Baru');

        $m = MarketplaceMaster::where('master_sku', 'NEW-1')->first();
        $this->assertNotNull($m);
        $this->assertSame($m->id, $l->refresh()->master_id);
    }
}
```

- [ ] **Step 2: Jalankan — gagal.** Run: `C:\php83\php.exe artisan test --filter="ResolveMasterTest|TautkanTest"`

- [ ] **Step 3: Implementasi resolve + findOrCreateMaster + tautkan + upsert (di `MarketplaceMasterService`)**

Tambahkan method berikut (adaptasi paging dari service lama; tambahkan `use` yang perlu: `App\Models\MarketplaceListing`, `App\Models\Product`, `Illuminate\Support\Facades\Log`):

```php
    public function resolveListings(string $channel): array
    {
        return $channel === 'tiktok' ? $this->resolveTiktok() : $this->resolveShopee();
    }

    public function findOrCreateMaster(string $sellerSku, ?string $title): MarketplaceMaster
    {
        $m = MarketplaceMaster::firstOrNew(['master_sku' => $sellerSku]);
        if (! $m->exists) {
            $m->name = $title !== null && $title !== '' ? $title : $sellerSku;
            $m->product_id = Product::where('sku', $sellerSku)->value('id'); // opsional; boleh null
            $m->save();
        }

        return $m;
    }

    public function tautkanListing(MarketplaceListing $listing, ?int $masterId, ?string $newSku = null, ?string $newName = null): void
    {
        if ($masterId !== null) {
            $listing->update(['master_id' => $masterId]);

            return;
        }
        $sku = $newSku !== null && $newSku !== '' ? $newSku : $listing->seller_sku;
        $m = $this->findOrCreateMaster($sku, $newName ?? $listing->title);
        $listing->update(['master_id' => $m->id]);
    }

    private function upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title): MarketplaceListing
    {
        $l = MarketplaceListing::updateOrCreate(
            ['channel' => $channel, 'seller_sku' => $sellerSku],
            ['item_id' => $itemId, 'variation_id' => $variationId, 'warehouse_id' => $warehouseId, 'title' => $title, 'resolved_at' => now()],
        );
        $master = $this->findOrCreateMaster($sellerSku, $title);
        if ($l->master_id === null) {
            $l->update(['master_id' => $master->id]);
        }

        return $l;
    }

    private function resolveTiktok(): array
    {
        $c = $this->tiktokConn();
        if (! $c) {
            return ['found' => 0, 'mastered' => 0];
        }
        $tok = $this->tiktokToken($c);
        // Gudang SALES pertama sbg default (sama spt service lama).
        $warehouseId = null;
        foreach (data_get($this->tiktok->getWarehouses($tok, $c->shop_cipher), 'warehouses', []) as $w) {
            if (($w['type'] ?? null) === 'SALES_WAREHOUSE') {
                $warehouseId = (string) $w['id'];
                break;
            }
        }

        $found = $mastered = 0;
        $pageToken = '';
        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
            foreach (data_get($res, 'products', []) as $prod) {
                $pid = (string) data_get($prod, 'id', '');
                $title = data_get($prod, 'title');
                foreach ($prod['skus'] ?? [] as $sku) {
                    $sellerSku = (string) data_get($sku, 'seller_sku', '');
                    if ($sellerSku === '') {
                        continue;
                    }
                    $this->upsertListing('tiktok', $sellerSku, $pid, (string) data_get($sku, 'id', ''), $warehouseId, $title !== null ? (string) $title : null);
                    $found++;
                    $mastered++;
                }
            }
            $pageToken = (string) data_get($res, 'next_page_token', '');
            if ($pageToken === '') {
                break;
            }
            if ($guard === 199) {
                Log::warning('[mp-master:resolve] TikTok mentok 200 halaman.');
            }
        }

        return compact('found', 'mastered');
    }

    private function resolveShopee(): array
    {
        $c = $this->shopeeConn();
        if (! $c) {
            return ['found' => 0, 'mastered' => 0];
        }
        $tok = $this->shopeeToken($c);
        $found = $mastered = 0;
        $offset = 0;
        for ($guard = 0; $guard < 200; $guard++) {
            $list = $this->shopee->getItemList($tok, $c->shop_id, $offset, 50);
            $items = data_get($list, 'response.item', []);
            foreach ($items as $it) {
                $itemId = (string) data_get($it, 'item_id', '');
                if ($itemId === '') {
                    continue;
                }
                $base = $this->shopee->getItemBaseInfo($tok, $c->shop_id, [$itemId]);
                $info = data_get($base, 'response.item_list.0', []);
                $title = data_get($info, 'item_name');
                $models = data_get($this->shopee->getModelList($tok, $c->shop_id, (int) $itemId), 'response.model', []);
                if ($models) {
                    foreach ($models as $mo) {
                        $sellerSku = (string) data_get($mo, 'model_sku', '');
                        if ($sellerSku === '') {
                            continue;
                        }
                        $this->upsertListing('shopee', $sellerSku, $itemId, (string) data_get($mo, 'model_id', '0'), null, $title !== null ? (string) $title : null);
                        $found++;
                        $mastered++;
                    }
                } else {
                    $sellerSku = (string) data_get($info, 'item_sku', '');
                    if ($sellerSku !== '') {
                        $this->upsertListing('shopee', $sellerSku, $itemId, '0', null, $title !== null ? (string) $title : null);
                        $found++;
                        $mastered++;
                    }
                }
            }
            $hasNext = (bool) data_get($list, 'response.has_next_page', false);
            $offset = (int) data_get($list, 'response.next_offset', $offset + 50);
            if (! $hasNext || ! $items) {
                break;
            }
            if ($guard === 199) {
                Log::warning('[mp-master:resolve] Shopee mentok 200 halaman.');
            }
        }

        return compact('found', 'mastered');
    }
```

> **Implementer:** verifikasi nama & signature method client (`searchProducts`, `getWarehouses`, `getItemList`, `getItemBaseInfo`, `getModelList`) dan bentuk respons dengan MEMBACA `app/Services/MarketplaceStockService.php::resolveTiktok/resolveShopee` yang sudah jalan + tesnya `tests/Feature/MarketplaceStock/ResolveListingsTest.php`. Samakan persis pemakaiannya (paths `response.*` untuk Shopee; unwrapped untuk TikTok). Sesuaikan bila berbeda.

- [ ] **Step 4: Jalankan tes — hijau.** Run: `C:\php83\php.exe artisan test --filter=MarketplaceMaster` lalu `--filter=MarketplaceStock` (lama tetap hijau).

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MarketplaceMasterService.php tests/Feature/MarketplaceMaster/ResolveMasterTest.php tests/Feature/MarketplaceMaster/TautkanTest.php
git commit -m "feat(mp-master): resolve auto-buat master + set listing.master_id + tautkan/gabung listing"
```

---

### Task 4: Engine — push stok+harga via master (aditif)

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php`
- Test: `tests/Feature/MarketplaceMaster/PushMasterTest.php`

**Interfaces:**
- Consumes: `effectiveStock/effectivePrice` (T2), `TikTokClient::updateStock/updatePrice` & `ShopeeClient::updateStock/updatePrice`, `MarketplaceListing` (dgn `master`).
- Produces:
  - `pushListing(MarketplaceListing $l, bool $force = false): array` → `['stock'=>string,'price'=>string]` (nilai: `ok|failed|skip`).
  - `pushMaster(MarketplaceMaster $m, bool $force = true): array` → `['pushed'=>int,'skipped'=>int,'failed'=>int]`.
  - `pushDirty(): array` & `pushAll(): array` → `['pushed'=>int,'skipped'=>int,'failed'=>int]` (menghitung stok & harga sbg unit terpisah dalam tally).

- [ ] **Step 1: Tulis tes push (gagal dulu)**

`tests/Feature/MarketplaceMaster/PushMasterTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushMasterTest extends TestCase
{
    use RefreshDatabase;

    private function tiktokConn(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    public function test_push_listing_kirim_stok_dan_harga_lalu_catat(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM', 'base_stock' => 30, 'base_price' => 39000]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);

        $this->assertSame('ok', $r['stock']);
        $this->assertSame('ok', $r['price']);
        $l->refresh();
        $this->assertSame(30, $l->last_pushed_qty);
        $this->assertSame('39000.00', (string) $l->last_pushed_price);
        $this->assertSame('ok', $l->last_status);
        $this->assertSame('ok', $l->last_price_status);
        Http::assertSentCount(2); // 1 update_stock + 1 update_price
    }

    public function test_push_listing_null_dilewati_tanpa_http(): void
    {
        $this->tiktokConn();
        Http::fake();
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM']); // base_stock & base_price null
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);

        $this->assertSame('skip', $r['stock']);
        $this->assertSame('skip', $r['price']);
        Http::assertNothingSent();
    }

    public function test_push_listing_tanpa_master_dilewati(): void
    {
        $this->tiktokConn();
        Http::fake();
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'X', 'master_id' => null, 'item_id' => 'PID1']);
        $r = app(MarketplaceMasterService::class)->pushListing($l, true);
        $this->assertSame('skip', $r['stock']);
        Http::assertNothingSent();
    }

    public function test_push_diff_hanya_kirim_yang_berubah(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM', 'base_stock' => 30, 'base_price' => 39000]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1',
            'last_pushed_qty' => 30, 'last_pushed_price' => 39000]);

        $r = app(MarketplaceMasterService::class)->pushListing($l, false); // tak berubah → skip dua-duanya
        $this->assertSame('skip', $r['stock']);
        $this->assertSame('skip', $r['price']);
        Http::assertNothingSent();
    }

    public function test_push_listing_gagal_catat_status_dan_error(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 36004, 'message' => 'no permission'], 200)]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM', 'base_stock' => 30]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);
        $this->assertSame('failed', $r['stock']);
        $this->assertSame('failed', $l->refresh()->last_status);
        $this->assertNotNull($l->last_error);
    }

    public function test_push_shopee_kirim_item_dan_model_id_integer(): void
    {
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'S-1', 'name' => 'S', 'base_stock' => 8, 'base_price' => 12000]);
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'S-1', 'master_id' => $m->id, 'item_id' => '555', 'variation_id' => '66']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);
        $this->assertSame('ok', $r['stock']);
        $this->assertSame('ok', $r['price']);
    }
}
```

- [ ] **Step 2: Jalankan — gagal.** Run: `C:\php83\php.exe artisan test --filter=PushMasterTest`

- [ ] **Step 3: Implementasi push (di `MarketplaceMasterService`)**

Tambahkan (butuh `use App\Models\MarketplaceListing;` sudah ada dari T3):

```php
    /** Push stok & harga efektif satu listing (via master+channel). Kedua field independen, anti-push null. */
    public function pushListing(MarketplaceListing $l, bool $force = false): array
    {
        $master = $l->master_id ? $l->master : null;
        if (! $master || ! $l->item_id) {
            return ['stock' => 'skip', 'price' => 'skip'];
        }
        $stock = $this->effectiveStock($master, $l->channel);
        $price = $this->effectivePrice($master, $l->channel);

        return [
            'stock' => $this->pushStock($l, $stock, $force),
            'price' => $this->pushPrice($l, $price, $force),
        ];
    }

    private function pushStock(MarketplaceListing $l, ?int $stock, bool $force): string
    {
        if ($stock === null) {
            return 'skip';
        }
        if (! $force && $l->last_pushed_qty === $stock) {
            return 'skip';
        }
        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $this->tiktok->updateStock($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, (string) $l->warehouse_id, $stock);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $this->shopee->updateStock($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $stock);
            }
            $l->update(['last_pushed_qty' => $stock, 'last_status' => 'ok', 'last_error' => null, 'last_pushed_at' => now()]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'failed';
        }
    }

    private function pushPrice(MarketplaceListing $l, ?float $price, bool $force): string
    {
        if ($price === null) {
            return 'skip';
        }
        if (! $force && $l->last_pushed_price !== null && (float) $l->last_pushed_price === $price) {
            return 'skip';
        }
        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $this->tiktok->updatePrice($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, $price);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $this->shopee->updatePrice($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $price);
            }
            $l->update(['last_pushed_price' => $price, 'last_price_status' => 'ok', 'last_price_error' => null, 'last_price_pushed_at' => now()]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_price_status' => 'failed', 'last_price_error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'failed';
        }
    }

    public function pushMaster(MarketplaceMaster $m, bool $force = true): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($m->listings()->whereNotNull('item_id')->get() as $l) {
            $this->tallyPush($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    public function pushDirty(): array
    {
        return $this->pushEach(false);
    }

    public function pushAll(): array
    {
        return $this->pushEach(true);
    }

    private function pushEach(bool $force): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (MarketplaceListing::whereNotNull('item_id')->whereNotNull('master_id')->get() as $l) {
            $this->tallyPush($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    /** Hitung stok & harga sbg dua unit terpisah: ok→pushed, failed→failed, skip→skipped. */
    private function tallyPush(array &$out, array $res): void
    {
        foreach (['stock', 'price'] as $field) {
            match ($res[$field]) {
                'ok' => $out['pushed']++,
                'failed' => $out['failed']++,
                default => $out['skipped']++,
            };
        }
    }
```

- [ ] **Step 4: Jalankan tes — hijau.** Run: `C:\php83\php.exe artisan test --filter=MarketplaceMaster` + `--filter=MarketplaceStock`.

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MarketplaceMasterService.php tests/Feature/MarketplaceMaster/PushMasterTest.php
git commit -m "feat(mp-master): push stok+harga per listing via master (anti-push null, diff, status terpisah) + pushMaster/pushDirty/pushAll"
```

---

### Task 5: Engine — seed dari TikTok (semua unit) + cermin order via master (aditif)

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php`
- Test: `tests/Feature/MarketplaceMaster/SeedMasterTest.php`, `tests/Feature/MarketplaceMaster/OrderDeltaMasterTest.php`

**Interfaces:**
- Consumes: `resolveListings`/`findOrCreateMaster` (T3), `setMasterStock`/`effectiveStock` (T2), `pushAll` (T4).
- Produces:
  - `seedFromTiktok(): array` → `['seeded'=>int,'skipped'=>int]` — set `base_stock` master dari inventory listing TikTok (SEMUA unit termasuk bundle), lalu `pushAll()`.
  - `applyOrderDelta(MarketplaceListing $listing, int $delta, Carbon $orderCreatedAt): void` — kurangi/kembalikan **bucket efektif** master utk channel listing (override stok bila ada & seeded, else base_stock bila seeded); clamp ≥0; hormati `seeded_at`; abaikan order sebelum seed. HQ TAK disentuh.

- [ ] **Step 1: Tulis tes seed + order delta (gagal dulu)**

`tests/Feature/MarketplaceMaster/SeedMasterTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeedMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_set_base_stock_semua_unit_termasuk_bundle(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'PID1', 'title' => 'Paket',
                    'skus' => [
                        ['id' => 'S1', 'seller_sku' => 'SATUAN', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 10]]],
                        ['id' => 'S2', 'seller_sku' => 'BUNDLE-3', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 4]]], // bundle: tetap di-seed
                    ],
                ]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH1', 'type' => 'SALES_WAREHOUSE']]]]),
            '*' => Http::response(['code' => 0, 'data' => []]), // update_stock/price dari pushAll
        ]);

        $r = app(MarketplaceMasterService::class)->seedFromTiktok();

        $this->assertSame(2, $r['seeded']); // bundle IKUT di-seed
        $this->assertSame(10, MarketplaceMaster::where('master_sku', 'SATUAN')->value('base_stock'));
        $this->assertSame(4, MarketplaceMaster::where('master_sku', 'BUNDLE-3')->value('base_stock'));
    }

    public function test_seed_lewati_sku_tanpa_inventory(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [['id' => 'PID1', 'title' => 'X', 'skus' => [['id' => 'S1', 'seller_sku' => 'NOINV']]]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => []]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $r = app(MarketplaceMasterService::class)->seedFromTiktok();
        $this->assertSame(1, $r['skipped']);
        $this->assertNull(MarketplaceMaster::where('master_sku', 'NOINV')->value('base_stock'));
    }
}
```

`tests/Feature/MarketplaceMaster/OrderDeltaMasterTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDeltaMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_kurangi_base_stock_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => now()->subDay()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);

        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now());
        $this->assertSame(17, $m->refresh()->base_stock);
    }

    public function test_order_kurangi_override_channel_kalau_ada(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => now()->subDay()]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 8, 'seeded_at' => now()->subDay()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);

        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now());
        $this->assertSame(5, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->value('stock'));
        $this->assertSame(20, $m->refresh()->base_stock); // base tak tersentuh
    }

    public function test_order_sebelum_seeded_diabaikan(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => now()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now()->subDay()); // sebelum seed
        $this->assertSame(20, $m->refresh()->base_stock);
    }

    public function test_clamp_tidak_minus(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 2, 'seeded_at' => now()->subDay()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l, -5, now());
        $this->assertSame(0, $m->refresh()->base_stock);
    }

    public function test_tanpa_master_atau_belum_seed_no_op(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => null]); // belum seed
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now());
        $this->assertSame(20, $m->refresh()->base_stock);

        $l2 = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'B', 'master_id' => null]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l2, -3, now()); // tak ada master → no-op, tak error
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Jalankan — gagal.** Run: `C:\php83\php.exe artisan test --filter="SeedMasterTest|OrderDeltaMasterTest"`

- [ ] **Step 3: Implementasi seed + applyOrderDelta**

Tambahkan (butuh `use Carbon\Carbon;` sudah ada):

```php
    public function seedFromTiktok(): array
    {
        $c = $this->tiktokConn();
        if (! $c) {
            return ['seeded' => 0, 'skipped' => 0];
        }
        $tok = $this->tiktokToken($c);
        $seeded = $skipped = 0;
        $pageToken = '';

        // TikTokClient::request() sudah unwrap 'data' → path TANPA prefiks 'data.'.
        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
            foreach (data_get($res, 'products', []) as $prod) {
                $title = data_get($prod, 'title');
                foreach ($prod['skus'] ?? [] as $sku) {
                    $sellerSku = (string) data_get($sku, 'seller_sku', '');
                    if ($sellerSku === '') {
                        continue;
                    }
                    $qty = data_get($sku, 'inventory.0.quantity'); // absen → data tak lengkap
                    if ($qty === null) {
                        $skipped++;

                        continue;
                    }
                    // SEMUA unit (termasuk bundle) → tiap seller_sku = 1 master.
                    $m = $this->findOrCreateMaster($sellerSku, $title !== null ? (string) $title : null);
                    $this->setMasterStock($m, (int) $qty);
                    $seeded++;
                }
            }
            $pageToken = (string) data_get($res, 'next_page_token', '');
            if ($pageToken === '') {
                break;
            }
        }

        $this->pushAll();

        return compact('seeded', 'skipped');
    }

    /** Cermin order marketplace → turunkan/kembalikan bucket efektif stok master (HQ TAK disentuh). */
    public function applyOrderDelta(MarketplaceListing $listing, int $delta, Carbon $orderCreatedAt): void
    {
        if (! $listing->master_id) {
            return;
        }
        $master = $listing->master;
        if (! $master) {
            return;
        }
        $channel = $listing->channel;
        $override = MarketplaceMasterChannel::where('master_id', $master->id)->where('channel', $channel)->first();

        if ($override && $override->stock !== null) {
            if ($override->seeded_at === null || $orderCreatedAt->lt($override->seeded_at)) {
                return;
            }
            $override->update(['stock' => max(0, (int) $override->stock + $delta)]);

            return;
        }

        if ($master->base_stock === null || $master->seeded_at === null || $orderCreatedAt->lt($master->seeded_at)) {
            return;
        }
        $master->update(['base_stock' => max(0, (int) $master->base_stock + $delta)]);
    }
```

- [ ] **Step 4: Jalankan tes — hijau.** Run: `C:\php83\php.exe artisan test --filter=MarketplaceMaster` + `--filter=MarketplaceStock`.

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MarketplaceMasterService.php tests/Feature/MarketplaceMaster/SeedMasterTest.php tests/Feature/MarketplaceMaster/OrderDeltaMasterTest.php
git commit -m "feat(mp-master): seed semua unit (incl bundle) + cermin order via master (HQ terpisah)"
```

---

### Task 6: CUTOVER — controller + rute + view + cron + OrderService ke engine master; ganti tes lama

**Files:**
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (tulis ulang)
- Modify: `routes/web.php` (grup `manage_marketplace_stock` — tulis ulang)
- Modify: `resources/views/marketplace-stock/index.blade.php`, `channel.blade.php` (tulis ulang)
- Modify: `app/Console/Commands/MarketplacePushStockCommand.php`
- Modify: `app/Services/TikTokOrderService.php`, `app/Services/ShopeeOrderService.php` (`mirrorMarketplace`)
- Create: `tests/Feature/MarketplaceMaster/MasterPageTest.php`, `ChannelPageMasterTest.php`, `OrderMirrorMasterTest.php`
- Delete: tes lama yg menguji UI/mirror/push/resolve/seed engine LAMA (didaftar di Step 7)

**Interfaces:**
- Consumes: seluruh `MarketplaceMasterService` (T2–T5).
- Produces (rute bernama, semua di grup `permission:manage_marketplace_stock`):
  - `GET /marketplace-stock` → `marketplace-stock.index` (Produk Master)
  - `GET /marketplace-stock/{channel}` → `marketplace-stock.channel`
  - `POST /marketplace-stock/master/{master}/stok` → `marketplace-stock.master.stok`
  - `POST /marketplace-stock/master/{master}/harga` → `marketplace-stock.master.harga`
  - `POST /marketplace-stock/{channel}/master/{master}/stok` → `marketplace-stock.channel.stok`
  - `POST /marketplace-stock/{channel}/master/{master}/harga` → `marketplace-stock.channel.harga`
  - `POST /marketplace-stock/{channel}/master/{master}/ikut-master` → `marketplace-stock.ikut-master` (body `field=stock|price`)
  - `POST /marketplace-stock/tautkan` → `marketplace-stock.tautkan`
  - `POST /marketplace-stock/push/{master}` → `marketplace-stock.push`
  - `POST /marketplace-stock/push-all` → `marketplace-stock.push-all`
  - `POST /marketplace-stock/resolve` → `marketplace-stock.resolve`
  - `POST /marketplace-stock/seed-tiktok` → `marketplace-stock.seed`

- [ ] **Step 1: Tulis controller baru**

Ganti seluruh isi `app/Http/Controllers/MarketplaceStockController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Services\MarketplaceMasterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produk Master E-commerce: tiap unit (satuan/varian/bundle) = 1 master dgn
 * stok+harga sendiri, tertaut ke listing TikTok/Shopee. HQ TIDAK disentuh.
 */
class MarketplaceStockController extends Controller
{
    public function index(MarketplaceMasterService $svc): View
    {
        $masters = MarketplaceMaster::with(['channels', 'listings'])->orderBy('name')->get();
        $rows = $masters->map(fn (MarketplaceMaster $m) => [
            'master' => $m,
            'tiktok' => $this->channelSummary($svc, $m, 'tiktok'),
            'shopee' => $this->channelSummary($svc, $m, 'shopee'),
        ]);

        return view('marketplace-stock.index', [
            'rows' => $rows,
            'unmastered' => MarketplaceListing::whereNull('master_id')->get(),
            'masters' => $masters,
        ]);
    }

    public function channel(string $channel, MarketplaceMasterService $svc): View
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);

        $masterIds = MarketplaceListing::where('channel', $channel)->whereNotNull('master_id')->distinct()->pluck('master_id');
        $masters = MarketplaceMaster::with(['channels', 'listings'])->whereIn('id', $masterIds)->orderBy('name')->get();
        $rows = $masters->map(function (MarketplaceMaster $m) use ($svc, $channel) {
            $ch = $m->channels->firstWhere('channel', $channel);

            return [
                'master' => $m,
                'override_stock' => $ch?->stock,
                'override_price' => $ch?->price,
                'eff_stock' => $svc->effectiveStock($m, $channel),
                'eff_price' => $svc->effectivePrice($m, $channel),
                'listing' => $m->listings->firstWhere('channel', $channel),
            ];
        });

        return view('marketplace-stock.channel', [
            'channel' => $channel,
            'rows' => $rows,
            'unmastered' => MarketplaceListing::where('channel', $channel)->whereNull('master_id')->get(),
            'masters' => MarketplaceMaster::orderBy('name')->get(['id', 'master_sku', 'name']),
        ]);
    }

    public function setMasterStock(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $r->validate(['quantity' => ['required', 'integer', 'min:0']]);
        $svc->setMasterStock($master, (int) $r->quantity);
        $svc->pushMaster($master);

        return back()->with('status', "Stok master {$master->name} disetel & disinkron.");
    }

    public function setMasterPrice(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $r->validate(['price' => ['required', 'numeric', 'min:0']]);
        $svc->setMasterPrice($master, (float) $r->price);
        $svc->pushMaster($master);

        return back()->with('status', "Harga master {$master->name} disetel & disinkron.");
    }

    public function setChannelStock(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $r->validate(['quantity' => ['required', 'integer', 'min:0']]);
        $svc->setChannelStock($master, $channel, (int) $r->quantity);
        $svc->pushMaster($master);

        return back()->with('status', "Stok {$channel} — {$master->name} disetel sendiri.");
    }

    public function setChannelPrice(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $r->validate(['price' => ['required', 'numeric', 'min:0']]);
        $svc->setChannelPrice($master, $channel, (float) $r->price);
        $svc->pushMaster($master);

        return back()->with('status', "Harga {$channel} — {$master->name} disetel sendiri.");
    }

    public function ikutMaster(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $data = $r->validate(['field' => ['required', 'in:stock,price']]);
        $svc->ikutMaster($master, $channel, $data['field']);
        $svc->pushMaster($master);

        return back()->with('status', "{$channel} — {$master->name} ({$data['field']}) kembali ikut Master.");
    }

    public function tautkan(Request $r, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate([
            'listing_id' => ['required', 'integer', 'exists:marketplace_listings,id'],
            'master_id' => ['nullable', 'integer', 'exists:marketplace_masters,id'],
            'new_sku' => ['nullable', 'string'],
            'new_name' => ['nullable', 'string'],
        ]);
        $listing = MarketplaceListing::findOrFail($data['listing_id']);
        $svc->tautkanListing($listing, $data['master_id'] ?? null, $data['new_sku'] ?? null, $data['new_name'] ?? null);

        return back()->with('status', "Listing {$listing->seller_sku} ditautkan.");
    }

    public function push(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $svc->pushMaster($master);

        return back()->with('status', 'Disinkron.');
    }

    public function pushAll(MarketplaceMasterService $svc): RedirectResponse
    {
        $r = $svc->pushAll();

        return back()->with('status', "Sinkron semua: {$r['pushed']} terkirim, {$r['skipped']} dilewati, {$r['failed']} gagal.");
    }

    public function resolve(MarketplaceMasterService $svc): RedirectResponse
    {
        $notes = [];
        $errors = [];
        foreach (['tiktok', 'shopee'] as $channel) {
            try {
                $r = $svc->resolveListings($channel);
                $notes[] = ucfirst($channel).": {$r['found']} listing ({$r['mastered']} termaster)";
            } catch (\Throwable $e) {
                $errors[] = ucfirst($channel).' gagal: '.$e->getMessage();
            }
        }
        $redirect = back();
        if ($notes) {
            $redirect->with('status', 'Refresh listing — '.implode(' · ', $notes));
        }
        if ($errors) {
            $redirect->with('error', implode(' · ', $errors).' — cek izin/scope Product di app channel.');
        }

        return $redirect;
    }

    public function seed(MarketplaceMasterService $svc): RedirectResponse
    {
        try {
            $r = $svc->seedFromTiktok();
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal tarik stok awal dari TikTok: '.$e->getMessage().' — cek izin/scope Product.');
        }

        return back()->with('status', "Tarik stok awal dari TikTok: {$r['seeded']} unit di-seed, {$r['skipped']} dilewati.");
    }

    /** Ringkasan per channel utk tabel Produk Master: efektif + penanda override + status kirim. */
    private function channelSummary(MarketplaceMasterService $svc, MarketplaceMaster $m, string $channel): array
    {
        $ch = $m->channels->firstWhere('channel', $channel);
        $listing = $m->listings->firstWhere('channel', $channel);

        return [
            'eff_stock' => $svc->effectiveStock($m, $channel),
            'eff_price' => $svc->effectivePrice($m, $channel),
            'override_stock' => $ch?->stock,
            'override_price' => $ch?->price,
            'listing' => $listing,
        ];
    }
}
```

- [ ] **Step 2: Tulis ulang grup rute**

Di `routes/web.php`, ganti seluruh isi blok `Route::middleware('permission:manage_marketplace_stock')->group(function () { ... });` menjadi:

```php
    /* ---------------- Kontrol Stok & Harga Marketplace (Produk Master) ---------------- */
    Route::middleware('permission:manage_marketplace_stock')->group(function () {
        Route::get('/marketplace-stock', [MarketplaceStockController::class, 'index'])->name('marketplace-stock.index');
        Route::get('/marketplace-stock/{channel}', [MarketplaceStockController::class, 'channel'])->name('marketplace-stock.channel');
        Route::post('/marketplace-stock/master/{master}/stok', [MarketplaceStockController::class, 'setMasterStock'])->name('marketplace-stock.master.stok');
        Route::post('/marketplace-stock/master/{master}/harga', [MarketplaceStockController::class, 'setMasterPrice'])->name('marketplace-stock.master.harga');
        Route::post('/marketplace-stock/{channel}/master/{master}/stok', [MarketplaceStockController::class, 'setChannelStock'])->name('marketplace-stock.channel.stok');
        Route::post('/marketplace-stock/{channel}/master/{master}/harga', [MarketplaceStockController::class, 'setChannelPrice'])->name('marketplace-stock.channel.harga');
        Route::post('/marketplace-stock/{channel}/master/{master}/ikut-master', [MarketplaceStockController::class, 'ikutMaster'])->name('marketplace-stock.ikut-master');
        Route::post('/marketplace-stock/tautkan', [MarketplaceStockController::class, 'tautkan'])->name('marketplace-stock.tautkan');
        Route::post('/marketplace-stock/push/{master}', [MarketplaceStockController::class, 'push'])->name('marketplace-stock.push');
        Route::post('/marketplace-stock/push-all', [MarketplaceStockController::class, 'pushAll'])->name('marketplace-stock.push-all');
        Route::post('/marketplace-stock/resolve', [MarketplaceStockController::class, 'resolve'])->name('marketplace-stock.resolve');
        Route::post('/marketplace-stock/seed-tiktok', [MarketplaceStockController::class, 'seed'])->name('marketplace-stock.seed');
    });
```

> **Penting urutan rute:** `GET /marketplace-stock/{channel}` akan menangkap juga `/marketplace-stock/master/...` bila `{channel}` tanpa batasan. Batasi `{channel}` dgn `->whereIn('channel', ['tiktok','shopee'])` pada rute channel GET **atau** taruh rute statis (`master/...`) SEBELUM route `{channel}` — implementer: tambahkan `->whereIn('channel', ['tiktok', 'shopee'])` ke rute `marketplace-stock.channel` untuk aman. (POST channel routes sudah diawali segmen `{channel}` + path spesifik, tapi tambahkan `whereIn` yang sama pada semua rute ber-`{channel}` demi konsistensi.)

- [ ] **Step 3: Tulis ulang view index (Produk Master)**

Ganti seluruh isi `resources/views/marketplace-stock/index.blade.php`:

```blade
@extends('layouts.app')
@section('title','Produk Master')
@section('heading','Produk Master E-commerce')
@section('content')
@php
    $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.');
    $badge = function ($override, $eff) use ($rp) {
        if ($override !== null) return '<span class="inline-block px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 text-[10px] font-semibold">Override: '.e($rp($override)).'</span>';
        return '<span class="inline-block px-1.5 py-0.5 rounded bg-stone-100 text-stone-500 text-[10px] font-semibold">Ikut Master</span>';
    };
@endphp
<div class="space-y-4">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input yang dimasukkan.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-1">Produk Master</h3>
        <p class="text-xs text-stone-500 mb-4">Tiap unit (satuan/varian/bundle) punya stok & harga sendiri. Set di Master → semua channel ikut, kecuali yang di-override. Terpisah dari stok gudang (HQ).</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.seed') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">⬇ Tarik stok awal TikTok</button></form>
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Refresh listing</button></form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <a href="{{ route('marketplace-stock.channel', 'tiktok') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga TikTok →</a>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga Shopee →</a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Master — {{ count($rows) }} unit</div>
        @if(count($rows))
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Unit (Master)</th>
                    <th class="text-left">Stok Master</th>
                    <th class="text-left">Harga Master</th>
                    <th class="text-left">TikTok</th>
                    <th class="text-left">Shopee</th>
                    <th class="text-left pr-4">Aksi</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php $m = $row['master']; @endphp
                    <tr class="border-t border-stone-100 align-top">
                        <td class="px-4 py-2.5"><div class="font-semibold text-stone-800">{{ $m->name }}</div><div class="text-[11px] text-stone-400 font-mono">{{ $m->master_sku }}</div></td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="quantity" min="0" step="1" value="{{ $m->base_stock }}" placeholder="belum di-set" class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2.5 py-1 bg-stone-800 text-white rounded-lg hover:bg-stone-900 text-[11px]">Simpan</button>
                            </form>
                        </td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="price" min="0" step="any" value="{{ $m->base_price !== null ? (int) $m->base_price : '' }}" placeholder="belum di-set" class="w-28 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2.5 py-1 bg-stone-800 text-white rounded-lg hover:bg-stone-900 text-[11px]">Simpan</button>
                            </form>
                        </td>
                        @foreach(['tiktok','shopee'] as $ch)
                            <td class="py-2.5">
                                <div class="text-stone-800">Stok: <span class="font-semibold">{{ $row[$ch]['eff_stock'] ?? '—' }}</span> {!! $badge($row[$ch]['override_stock'], $row[$ch]['eff_stock']) !!}</div>
                                <div class="text-stone-800">Harga: <span class="font-semibold">{{ $rp($row[$ch]['eff_price']) }}</span> {!! $badge($row[$ch]['override_price'] !== null ? (int) $row[$ch]['override_price'] : null, $row[$ch]['eff_price']) !!}</div>
                                @if($row[$ch]['listing'])
                                    <div class="text-[10px] text-stone-400">kirim: {{ $row[$ch]['listing']->last_status ?? '—' }}/{{ $row[$ch]['listing']->last_price_status ?? '—' }}</div>
                                @else
                                    <div class="text-[10px] text-stone-400">belum ada listing</div>
                                @endif
                            </td>
                        @endforeach
                        <td class="py-2.5 pr-4"><form method="POST" action="{{ route('marketplace-stock.push', $m) }}">@csrf<button class="px-2.5 py-1 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800 text-[11px]">Sinkron</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada master. Klik "Refresh listing" untuk menarik listing & membuat master otomatis.</p>
        @endif
    </div>

    @if(count($unmastered))
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Listing belum termaster — {{ count($unmastered) }}</div>
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr><th class="text-left px-4 py-2">Channel</th><th class="text-left">Seller SKU</th><th class="text-left">Judul</th><th class="text-left pr-4">Tautkan</th></tr></thead>
                <tbody>
                @foreach($unmastered as $l)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2">{{ ucfirst($l->channel) }}</td>
                        <td class="font-mono text-stone-700">{{ $l->seller_sku }}</td>
                        <td class="text-stone-500">{{ $l->title ?? '—' }}</td>
                        <td class="py-2 pr-4">
                            <form method="POST" action="{{ route('marketplace-stock.tautkan') }}" class="flex items-center gap-1.5">@csrf
                                <input type="hidden" name="listing_id" value="{{ $l->id }}">
                                <select name="master_id" class="px-2 py-1 border border-stone-300 rounded-lg text-xs max-w-[200px]">
                                    <option value="">— buat master baru ({{ $l->seller_sku }}) —</option>
                                    @foreach($masters as $mm)<option value="{{ $mm->id }}">{{ $mm->name }} ({{ $mm->master_sku }})</option>@endforeach
                                </select>
                                <button class="px-2.5 py-1 bg-emerald-700 text-white rounded-lg hover:bg-emerald-800 text-[11px]">Tautkan</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</div>
@endsection
```

- [ ] **Step 4: Tulis ulang view channel (Stok & Harga per channel)**

Ganti seluruh isi `resources/views/marketplace-stock/channel.blade.php`:

```blade
@extends('layouts.app')
@section('title','Stok & Harga '.ucfirst($channel))
@section('heading','Stok & Harga '.ucfirst($channel))
@section('content')
@php $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.'); @endphp
<div class="space-y-4">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input yang dimasukkan.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-xs text-stone-500 mb-3">Efektif = override {{ ucfirst($channel) }} kalau ada, kalau tidak ikut Master. "Ikut Master" mengembalikannya.</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Refresh listing</button></form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <a href="{{ route('marketplace-stock.index') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">← Produk Master</a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Master di {{ ucfirst($channel) }} — {{ count($rows) }}</div>
        @if(count($rows))
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Unit</th><th class="text-left">Stok Efektif</th><th class="text-left">Override Stok</th><th class="text-left">Harga Efektif</th><th class="text-left">Override Harga</th><th class="text-left pr-4">Kirim</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php $m = $row['master']; @endphp
                    <tr class="border-t border-stone-100 align-top">
                        <td class="px-4 py-2.5"><div class="font-semibold text-stone-800">{{ $m->name }}</div><div class="text-[11px] text-stone-400 font-mono">{{ $m->master_sku }}</div></td>
                        <td class="py-2.5 font-semibold">{{ $row['eff_stock'] ?? '—' }}</td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.channel.stok', ['channel'=>$channel,'master'=>$m]) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="quantity" min="0" step="1" value="{{ $row['override_stock'] }}" placeholder="ikut master" class="w-20 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">Set</button>
                            </form>
                            @if($row['override_stock'] !== null)
                                <form method="POST" action="{{ route('marketplace-stock.ikut-master', ['channel'=>$channel,'master'=>$m]) }}" class="mt-1">@csrf<input type="hidden" name="field" value="stock"><button class="px-2 py-1 bg-amber-600 text-white rounded-lg text-[11px]">Ikut Master</button></form>
                            @endif
                        </td>
                        <td class="py-2.5 font-semibold">{{ $rp($row['eff_price']) }}</td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.channel.harga', ['channel'=>$channel,'master'=>$m]) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="price" min="0" step="any" value="{{ $row['override_price'] !== null ? (int) $row['override_price'] : '' }}" placeholder="ikut master" class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">Set</button>
                            </form>
                            @if($row['override_price'] !== null)
                                <form method="POST" action="{{ route('marketplace-stock.ikut-master', ['channel'=>$channel,'master'=>$m]) }}" class="mt-1">@csrf<input type="hidden" name="field" value="price"><button class="px-2 py-1 bg-amber-600 text-white rounded-lg text-[11px]">Ikut Master</button></form>
                            @endif
                        </td>
                        <td class="py-2.5 pr-4 text-[11px] text-stone-500">{{ $row['listing']->last_status ?? '—' }}/{{ $row['listing']->last_price_status ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada master di channel ini.</p>
        @endif
    </div>
</div>
@endsection
```

- [ ] **Step 5: Arahkan cron ke engine baru**

Di `app/Console/Commands/MarketplacePushStockCommand.php`, ganti type-hint `handle()`:

```php
    public function handle(\App\Services\MarketplaceMasterService $svc): int
    {
        $r = $svc->pushDirty();
        $this->info("Push stok+harga: {$r['pushed']} terkirim · {$r['skipped']} dilewati · {$r['failed']} gagal.");

        return self::SUCCESS;
    }
```

- [ ] **Step 6: Arahkan cermin order ke engine baru (kedua OrderService)**

Di `app/Services/TikTokOrderService.php`: ganti konstruktor dependency `MarketplaceStockService` → `MarketplaceMasterService` (ubah `use` + property type). Ganti akhir `mirrorMarketplace()` (loop yang memanggil `applyOrderDelta`) menjadi berbasis listing:

```php
        foreach ($this->normalizeItems($o) as $it) {
            $listing = \App\Models\MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', $it['sku'])->first();
            if ($listing) {
                $this->marketplace->applyOrderDelta($listing, $sign * (int) $it['qty'], $createdAt);
            }
        }
```

Lakukan hal sama di `app/Services/ShopeeOrderService.php` dengan `->where('channel', 'shopee')`. **JANGAN sentuh** bagian potong-stok HQ (`$this->inventory->...` / `deduct`/`reverse`) — biarkan apa adanya. (Hapus pemanggilan `$this->resolve()` khusus mirror bila hanya dipakai untuk `applyOrderDelta` lama; jika `resolve()` juga dipakai jalur HQ, biarkan.)

> **Implementer:** baca `mirrorMarketplace()` + `resolve()`/`normalizeItems()` di kedua file. `normalizeItems($o)` mengembalikan item dgn `sku`+`qty`. Cari listing per `seller_sku`. Pastikan HQ deduction tetap utuh (tes HQ harus tetap hijau).

- [ ] **Step 7: Ganti tes lama → tes master + hapus tes usang**

Buat 3 tes baru (adaptasi dari yang lama, master-keyed):

`tests/Feature/MarketplaceMaster/MasterPageTest.php` — render halaman + set stok/harga master + akses:
```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasterPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_render_dan_set_master_stok(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist']);

        $this->actingAs($this->admin())->get('/marketplace-stock')->assertOk()->assertSee('Produk Master')->assertSee('Face Mist');

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.stok', $m), ['quantity' => 25])->assertRedirect();
        $this->assertSame(25, $m->refresh()->base_stock);
    }

    public function test_set_master_harga(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM']);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.harga', $m), ['price' => 39000])->assertRedirect();
        $this->assertSame('39000.00', (string) $m->refresh()->base_price);
    }

    public function test_menu_sidebar_dan_akses_role(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertSee('Stok Marketplace');
        $reseller = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($reseller)->get('/marketplace-stock')->assertForbidden();
    }
}
```

`tests/Feature/MarketplaceMaster/ChannelPageMasterTest.php` — override channel + ikut-master:
```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelPageMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_set_override_stok_hanya_channel_itu(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id, 'item_id' => 'P']);

        $this->actingAs($this->admin())->post(route('marketplace-stock.channel.stok', ['channel' => 'tiktok', 'master' => $m]), ['quantity' => 5])->assertRedirect();
        $this->assertSame(5, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->value('stock'));
    }

    public function test_ikut_master_hapus_override(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 5]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.ikut-master', ['channel' => 'tiktok', 'master' => $m]), ['field' => 'stock'])->assertRedirect();
        $this->assertSame(0, MarketplaceMasterChannel::where('master_id', $m->id)->count());
    }

    public function test_channel_invalid_404(): void
    {
        $this->actingAs($this->admin())->get('/marketplace-stock/lazada')->assertNotFound();
    }
}
```

`tests/Feature/MarketplaceMaster/OrderMirrorMasterTest.php` — order turunkan stok MASTER, HQ tetap (assert StockMovement). **Implementer:** adaptasi dari `tests/Feature/MarketplaceStock/OrderMirrorTest.php` (baca file itu untuk cara membangun order TikTok/Shopee + assert `StockMovement`). Ganti assertion pool lama (`MarketplaceStock`) → `MarketplaceMaster.base_stock`. Wajib mempertahankan minimal: order baru menurunkan `base_stock` master (via listing.master_id), order sebelum `seeded_at` diabaikan, transisi→batal mengembalikan, DAN `StockMovement` HQ tetap tercatat (HQ tak terpengaruh rework).

Lalu **HAPUS** file tes engine LAMA (semuanya menguji `MarketplaceStockService`/model lama yg akan dihapus di Task 7):
```bash
git rm tests/Feature/MarketplaceStock/MarketplaceUiTest.php \
       tests/Feature/MarketplaceStock/ChannelPageTest.php \
       tests/Feature/MarketplaceStock/ChannelOrderMirrorTest.php \
       tests/Feature/MarketplaceStock/ChannelOverrideModelTest.php \
       tests/Feature/MarketplaceStock/OrderMirrorTest.php \
       tests/Feature/MarketplaceStock/PushStockTest.php \
       tests/Feature/MarketplaceStock/ResolveListingsTest.php \
       tests/Feature/MarketplaceStock/SeedFromTiktokTest.php \
       tests/Feature/MarketplaceStock/LinkSkuTest.php \
       tests/Feature/MarketplaceStock/MarketplaceModelTest.php \
       tests/Unit/MarketplaceStock/AvailabilityTest.php \
       tests/Unit/MarketplaceStock/ChannelStockTest.php
```
**PERTAHANKAN** (tak bergantung engine lama): `tests/Feature/MarketplaceStock/MarketplaceAccessTest.php`, `PushCommandTest.php`, `ClientUpdateStockTest.php` — tapi **cek**: `PushCommandTest` me-mock `MarketplaceStockService`; ubah mock-nya ke `MarketplaceMasterService` (target `handle()` yang baru). `MarketplaceAccessTest` cek izin generik — biarkan bila lulus. `ClientUpdateStockTest` menguji client updateStock — biarkan.

> **Implementer:** setelah menghapus, GREP untuk referensi tersisa ke `MarketplaceStockService`/`MarketplaceStock`/`MarketplaceChannelOverride` di dalam `app/` (selain file lama yg baru dihapus di Task 7) — pastikan controller/cron/order-service sudah tak mereferensikannya. Service+model lama masih ADA (dihapus Task 7) supaya tak ada import error sekarang.

- [ ] **Step 8: Jalankan SELURUH suite — hijau**

Run: `C:\php83\php.exe artisan test`
Expected: semua hijau. Fokus cek: grup `MarketplaceMaster` hijau; tes HQ (`MarketplaceBackdateMovementTest`, `MarketplaceMovementDateBackfillTest`) tetap hijau; `PushCommandTest` (mock diperbarui) hijau. Kalau ada merah, perbaiki sebelum commit.

- [ ] **Step 9: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add -A
git commit -m "feat(mp-master): CUTOVER controller/rute/view + cron + OrderService ke engine master; ganti tes lama"
```

---

### Task 7: Removal — hapus engine lama + drop tabel + docs

**Files:**
- Delete: `app/Services/MarketplaceStockService.php`, `app/Models/MarketplaceStock.php`, `app/Models/MarketplaceChannelOverride.php`
- Create: `database/migrations/2026_01_01_000143_drop_legacy_marketplace_stock_tables.php`
- Modify: `docs/SISTEM.md`, `docs/PETA-SISTEM.md`

**Interfaces:** tak ada yang baru (pembersihan).

- [ ] **Step 1: Pastikan tak ada referensi tersisa**

Run (harus KOSONG di luar file yg akan dihapus):
`C:\php83\php.exe artisan tinker --execute="echo 'ok';"` lalu grep manual: cari `MarketplaceStockService`, `MarketplaceChannelOverride`, `App\\Models\\MarketplaceStock` di `app/`, `routes/`, `tests/`. Bila ada di luar 3 file yg akan dihapus → perbaiki dulu.

- [ ] **Step 2: Tulis migrasi drop (data-migrate dulu, aman bila tabel/data tak ada)**

`database/migrations/2026_01_01_000143_drop_legacy_marketplace_stock_tables.php`:

```php
<?php

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\MarketplaceListing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrasi data lama → master (hanya bila tabel lama ada & berisi).
        if (Schema::hasTable('marketplace_stocks')) {
            foreach (DB::table('marketplace_stocks')->get() as $old) {
                $product = DB::table('products')->where('id', $old->product_id)->first();
                if (! $product) {
                    continue;
                }
                $m = MarketplaceMaster::firstOrCreate(
                    ['master_sku' => $product->sku],
                    ['name' => $product->name, 'product_id' => $product->id],
                );
                $m->update(['base_stock' => $old->quantity, 'seeded_at' => $old->seeded_at]);
                // Tautkan listing yg seller_sku-nya == product.sku bila belum termaster.
                MarketplaceListing::where('seller_sku', $product->sku)->whereNull('master_id')->update(['master_id' => $m->id]);
            }
        }
        if (Schema::hasTable('marketplace_channel_overrides')) {
            foreach (DB::table('marketplace_channel_overrides')->get() as $ov) {
                $product = DB::table('products')->where('id', $ov->product_id)->first();
                if (! $product) {
                    continue;
                }
                $m = MarketplaceMaster::where('master_sku', $product->sku)->first();
                if (! $m) {
                    continue;
                }
                MarketplaceMasterChannel::updateOrCreate(
                    ['master_id' => $m->id, 'channel' => $ov->channel],
                    ['stock' => $ov->quantity, 'seeded_at' => $ov->seeded_at],
                );
            }
        }

        Schema::dropIfExists('marketplace_channel_overrides');
        Schema::dropIfExists('marketplace_stocks');
    }

    public function down(): void
    {
        // Rekreasi skema lama (tanpa data) supaya rollback tak fatal.
        if (! Schema::hasTable('marketplace_stocks')) {
            Schema::create('marketplace_stocks', function ($t) {
                $t->id();
                $t->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
                $t->integer('quantity')->default(0);
                $t->timestamp('seeded_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('marketplace_channel_overrides')) {
            Schema::create('marketplace_channel_overrides', function ($t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->string('channel', 16);
                $t->integer('quantity')->default(0);
                $t->timestamp('seeded_at')->nullable();
                $t->timestamps();
                $t->unique(['product_id', 'channel']);
            });
        }
    }
};
```

- [ ] **Step 3: Hapus service + model lama**

```bash
git rm app/Services/MarketplaceStockService.php app/Models/MarketplaceStock.php app/Models/MarketplaceChannelOverride.php
```

- [ ] **Step 4: Jalankan SELURUH suite — hijau**

Run: `C:\php83\php.exe artisan test`
Expected: semua hijau (migrasi 000143 jalan via RefreshDatabase; tak ada import error). Bila ada referensi tersisa → perbaiki.

- [ ] **Step 5: Update docs**

- `docs/SISTEM.md`: ganti/segarkan seksi Kontrol Stok Marketplace jadi model **Produk Master E-commerce** (tabel `marketplace_masters`/`marketplace_master_channels`, listing.master_id + harga; stok+harga per unit; override per channel; push stok+harga; order→turun stok master; terpisah HQ; izin `manage_marketplace_stock`; `MarketplaceMasterService`).
- `docs/PETA-SISTEM.md`: perbarui baris status: rework Produk Master SELESAI (branch feat/stok-marketplace-harga); tabel lama di-drop (migrasi 000143); engine `MarketplaceMasterService`.

- [ ] **Step 6: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add -A
git commit -m "refactor(mp-master): hapus engine stok lama (service+model) + drop tabel lama (migrasi data) + docs"
```

---

## Self-Review

**1. Spec coverage:**
- Master per unit (satuan/varian/**bundle**), stok+harga di-set langsung → T1 (tabel/model) + T2 (setter). ✓
- Override per-channel stok &/atau harga + Ikut Master → T2 (setChannel*/ikutMaster) + T6 (UI). ✓
- Tautkan/gabung listing lintas channel + auto-buat master saat resolve → T3. ✓
- Push stok+harga; anti-push null; status terpisah → T4. ✓
- Order marketplace turunkan stok master (sync per unit), HQ tak disentuh → T5 (applyOrderDelta) + T6 (OrderService cutover, assert StockMovement). ✓
- Seed dari TikTok utk SEMUA unit termasuk bundle → T5. ✓
- Terpisah total dari HQ (tak baca/tulis hq_stock/stock_movements; peta SKU tetap HQ-only) → Global Constraint + T5/T6. ✓
- Nilai efektif override>base>null → T2. ✓
- Client updatePrice TikTok/Shopee → T2 (client) + T4 (dipakai push). ✓
- UI: Produk Master + Stok&Harga per channel, native input, no @json literal, POST+csrf → T6. ✓
- Rute + izin manage_marketplace_stock → T6. ✓
- Migrasi dari Fase 1/1.5 (tambah tabel; migrasi data; drop lama) → T1 (tambah) + T7 (data-migrate+drop). ✓
- Zero-dep, PHPUnit class-style, Product langsung → seluruh task. ✓
- Out of scope (grouping parent-varian, master-ikut-HQ, promo terjadwal, multi-gudang) → tidak dibangun. ✓

**2. Placeholder scan:** Tidak ada TBD/TODO. Tempat yang mengandalkan "baca file lama lalu adaptasi" (resolve paging T3, OrderService mirror T6, OrderMirrorMasterTest T6) diberi rujukan file+method spesifik + invarian yang wajib dipertahankan — bukan placeholder, tapi instruksi adaptasi terarah karena kode sumbernya sudah ada & terverifikasi di repo.

**3. Type consistency:**
- `MarketplaceMasterService` method names/sig konsisten antar task (effectiveStock/effectivePrice/setMaster*/setChannel*/ikutMaster T2; resolveListings/findOrCreateMaster/tautkanListing T3; pushListing→array/pushMaster/pushDirty/pushAll T4; seedFromTiktok/applyOrderDelta(listing,delta,Carbon) T5). Controller (T6) & OrderService (T6) & cron (T6) memakai sig itu. ✓
- Nama rute konsisten controller↔routes↔view (T6). ✓
- `applyOrderDelta` di engine baru bersignature (MarketplaceListing,int,Carbon) — BEDA dari lama (Product,...); OrderService cutover (T6) memakai versi listing. ✓
- Kolom listing baru (`master_id`, `last_pushed_price`, `last_price_status`, `last_price_error`, `last_price_pushed_at`) dipakai konsisten (T1 model/migrasi, T4 push, T6 view). ✓

---

## Catatan desain untuk direview user (sebelum SDD)

1. **Sinkron antar-channel saat order:** rencana ini mengikuti pola Fase 1 — `applyOrderDelta` hanya menyesuaikan bucket master; **push ke channel lain dilakukan cron `marketplace:push-stock` tiap 5 menit** (bukan push inline saat order sync, agar sinkronisasi order inti tak melambat). Spec §6.6 menyebut "lalu push master" — kalau kamu mau anti-oversell lintas-channel **instan** (push inline tiap order), bilang; aku tambah push best-effort di mirror.
2. **`update_price` format:** endpoint TikTok `POST /product/202309/products/{id}/prices/update` (amount string, IDR) & Shopee `POST /api/v2/product/update_price` (`original_price`) sesuai spec; **diverifikasi live saat deploy** (butuh scope Product yg sama — tak perlu re-auth). Kalau channel menolak, kita sesuaikan path/body.
3. **Deploy:** butuh `git pull` + `migrate --force` (000142 tambah tabel, 000143 migrasi-data+drop lama) + `optimize:clear`. Aman baik tabel lama sudah ada di prod (data dimigrasi) maupun belum (dilewati).
