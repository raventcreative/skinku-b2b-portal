# Kontrol Stok Marketplace (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Satu angka "Stok Marketplace" per produk (terpisah dari stok HQ) yang dijaga sama di TikTok & Shopee — turun saat pesanan masuk di channel mana pun, bisa di-set manual, didorong ke kedua channel via API (auto cron + manual).

**Architecture:** Tabel baru `marketplace_stocks` (pool per produk) + `marketplace_listings` (cache ID channel + status push). `MarketplaceStockService` menghitung "siap jual" per listing dari pool × peta SKU yang sudah ada, lalu push lewat `ShopeeClient::updateStock`/`TikTokClient::updateStock`. Pool berkurang di titik ingest order (`*OrderService::store()`, best-effort). Cron `marketplace:push-stock` mengirim diff tiap 5 menit. HQ (`stock_movements`) tidak disentuh.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + vanilla JS (zero-dep), Laravel Http (via client yang ada).

**Spec:** `docs/superpowers/specs/2026-09-19-kontrol-stok-marketplace-design.md`

## Global Constraints

- PHP 8.3 / Laravel 13. Runner lokal tes: `C:\php83\php.exe artisan test`. Format: `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Zero-dependency**: TANPA composer package baru. Reuse `ShopeeClient`, `TikTokClient`, peta SKU (`tiktok_sku_maps`/`shopee_sku_maps`) + UI-nya, koneksi (`ShopeeConnection`/`TiktokConnection`).
- **JANGAN menyentuh** `stock_movements`, `InventoryService::adjustHqStock`, atau Laporan Stok HQ. Fitur ini pool terpisah.
- Buffer = 0 (tak ada stok cadangan di Fase 1). Seed awal dari TikTok.
- Mirror pool di order sync bersifat **best-effort** (try/catch + log) — TAK BOLEH menggagalkan sinkron order inti.
- **Jangan push listing sebelum pool produk-nya di-seed/di-set** (`available` null → dilewati) agar tak tak-sengaja mengirim 0.
- Migrasi additive (tabel baru). Nomor migrasi = lanjut dari terakhir di `database/migrations` (cek saat Task 1).
- Blade: JANGAN pakai `@json([...])` dengan array literal (500). Pakai data dari controller / `json_encode()`. Tambah tes render untuk halaman baru.
- Pola & gaya UI mengikuti halaman Integrasi TikTok/Shopee yang ada.

---

### Task 1: Migrasi + Model `marketplace_stocks` & `marketplace_listings`

**Files:**
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_create_marketplace_stock_tables.php`
- Create: `app/Models/MarketplaceStock.php`
- Create: `app/Models/MarketplaceListing.php`
- Test: `tests/Feature/MarketplaceStock/MarketplaceModelTest.php`

**Interfaces:**
- Produces: `MarketplaceStock` (`product_id` unique, `quantity` int, `seeded_at` ?datetime; `belongsTo(Product)`); `MarketplaceListing` (`channel`, `seller_sku`, `item_id`, `variation_id`, `warehouse_id`, `title`, `last_pushed_qty`, `last_status`, `last_error`, `last_pushed_at`, `resolved_at`).

- [ ] **Step 1: Tulis tes gagal**

```php
// tests/Feature/MarketplaceStock/MarketplaceModelTest.php
use App\Models\{Product, MarketplaceStock, MarketplaceListing};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('menyimpan pool per produk dan listing channel', function () {
    $p = Product::factory()->create();
    $pool = MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 50, 'seeded_at' => now()]);
    expect($pool->product->id)->toBe($p->id);

    $l = MarketplaceListing::create([
        'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => '111', 'variation_id' => '222',
        'warehouse_id' => 'W1', 'title' => 'Face Mist', 'last_status' => 'ok', 'last_pushed_qty' => 50,
    ]);
    expect($l->channel)->toBe('tiktok');
});
```
> Jika `Product::factory()` tak ada / beda, buat Product langsung dgn field wajib (cek `Product` fillable & factory yang ada; ikuti pola tes lain di repo).

- [ ] **Step 2: Jalankan tes — harus GAGAL** (`C:\php83\php.exe artisan test --filter=MarketplaceModelTest`). Expected: kelas model belum ada.

- [ ] **Step 3: Migrasi**

```php
Schema::create('marketplace_stocks', function (Blueprint $t) {
    $t->id();
    $t->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
    $t->integer('quantity')->default(0);
    $t->timestamp('seeded_at')->nullable();
    $t->timestamps();
});
Schema::create('marketplace_listings', function (Blueprint $t) {
    $t->id();
    $t->string('channel', 16);            // tiktok | shopee
    $t->string('seller_sku');
    $t->string('item_id')->nullable();    // shopee item_id / tiktok product_id
    $t->string('variation_id')->nullable(); // shopee model_id / tiktok sku_id
    $t->string('warehouse_id')->nullable();
    $t->string('title')->nullable();
    $t->integer('last_pushed_qty')->nullable();
    $t->string('last_status', 16)->nullable(); // ok | failed | unmapped
    $t->text('last_error')->nullable();
    $t->timestamp('last_pushed_at')->nullable();
    $t->timestamp('resolved_at')->nullable();
    $t->timestamps();
    $t->unique(['channel', 'seller_sku']);
});
```
(down(): drop kedua tabel.)

- [ ] **Step 4: Model**

```php
// app/Models/MarketplaceStock.php
class MarketplaceStock extends Model
{
    protected $fillable = ['product_id', 'quantity', 'seeded_at'];
    protected $casts = ['quantity' => 'integer', 'seeded_at' => 'datetime'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
// app/Models/MarketplaceListing.php
class MarketplaceListing extends Model
{
    protected $fillable = ['channel','seller_sku','item_id','variation_id','warehouse_id','title','last_pushed_qty','last_status','last_error','last_pushed_at','resolved_at'];
    protected $casts = ['last_pushed_qty' => 'integer', 'last_pushed_at' => 'datetime', 'resolved_at' => 'datetime'];
}
```

- [ ] **Step 5: Jalankan tes — harus LULUS.**
- [ ] **Step 6: Pint + commit** (`git add` migrasi + model + tes; `git commit -m "feat(stok-mp): tabel & model marketplace_stocks + marketplace_listings"`).

---

### Task 2: Izin `manage_marketplace_stock` + rute + controller stub + gate

**Files:**
- Modify: `app/Support/Permissions.php` (DEFINITIONS + DEFAULTS)
- Create: `app/Http/Controllers/MarketplaceStockController.php` (stub `index`)
- Create: `resources/views/marketplace-stock/index.blade.php` (placeholder minimal)
- Modify: `routes/web.php` (grup baru)
- Test: `tests/Feature/MarketplaceStock/MarketplaceAccessTest.php`

**Interfaces:**
- Produces: rute `marketplace-stock.index` (GET `/marketplace-stock`) di grup `permission:manage_marketplace_stock`; izin `manage_marketplace_stock`.

- [ ] **Step 1: Tes akses (gagal dulu)**

```php
use App\Models\User;
uses(RefreshDatabase::class);

it('super_admin bisa buka, role tanpa izin ditolak', function () {
    $this->seed(); // jika ada seeder role; kalau tidak, buat user role super_admin & role lain manual spt tes lain
    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    $this->actingAs($admin)->get('/marketplace-stock')->assertOk();

    $mitra = User::factory()->create(['role' => User::ROLE_RESELLER]);
    $this->actingAs($mitra)->get('/marketplace-stock')->assertForbidden();
});
```
> Ikuti pola tes akses yang sudah ada (mis. `tests/Feature` untuk halaman TikTok/Shopee) untuk cara bikin user + role + middleware `internal` bila perlu.

- [ ] **Step 2: Jalankan — GAGAL** (rute belum ada).

- [ ] **Step 3: Tambah izin** di `app/Support/Permissions.php`:
  - DEFINITIONS: tambah `'manage_marketplace_stock' => 'Kontrol Stok Marketplace',`
  - DEFAULTS: tambah `'manage_marketplace_stock' => [User::ROLE_ADMIN],`

- [ ] **Step 4: Controller stub + view minimal**

```php
// MarketplaceStockController.php
class MarketplaceStockController extends Controller
{
    public function index() { return view('marketplace-stock.index', ['rows' => [], 'unmapped' => []]); }
}
```
```blade
{{-- resources/views/marketplace-stock/index.blade.php --}}
@extends('layouts.app')
@section('title','Stok Marketplace')
@section('heading','Stok Marketplace')
@section('content')<div>Stok Marketplace</div>@endsection
```

- [ ] **Step 5: Rute** — di `routes/web.php`, grup baru sejajar grup shopee/tiktok (di dalam middleware auth+internal yang sama):

```php
Route::middleware('permission:manage_marketplace_stock')->group(function () {
    Route::get('/marketplace-stock', [MarketplaceStockController::class, 'index'])->name('marketplace-stock.index');
});
```
> Pastikan `use App\Http\Controllers\MarketplaceStockController;` ada. Tempatkan grup di dalam pembungkus yang sama dengan grup `manage_shopee` (middleware `internal` bila grup itu memakainya).

- [ ] **Step 6: Jalankan tes — LULUS.**
- [ ] **Step 7: Pint + commit** (`feat(stok-mp): izin + rute + halaman kerangka`).

---

### Task 3: `MarketplaceStockService` — hitung siap-jual + pool set/adjust

**Files:**
- Create: `app/Services/MarketplaceStockService.php`
- Test: `tests/Unit/MarketplaceStock/AvailabilityTest.php`

**Interfaces:**
- Consumes: `MarketplaceStock`, `MarketplaceListing`, `TiktokSkuMap`, `ShopeeSkuMap`, `Product`.
- Produces:
  - `availableForListing(MarketplaceListing $l): ?int`
  - `adjustPool(Product $p, int $delta): void`
  - `setPool(Product $p, int $qty): MarketplaceStock`
  - `applyOrderDelta(Product $p, int $delta, \Illuminate\Support\Carbon $orderCreatedAt): void`
  - `componentsFor(string $channel, string $sku): array` (→ `[['product_id'=>int,'qty'=>int], ...]`, `[]` bila tak terpetakan)

- [ ] **Step 1: Tes unit (gagal dulu)**

```php
use App\Models\{Product, MarketplaceStock, MarketplaceListing, TiktokSkuMap};
use App\Services\MarketplaceStockService;
uses(RefreshDatabase::class);

function svc(): MarketplaceStockService { return app(MarketplaceStockService::class); }

it('1:1 → available = pool', function () {
    $p = Product::factory()->create(['sku' => 'FM-1']);
    TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
    MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 50, 'seeded_at' => now()]);
    $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1']);
    expect(svc()->availableForListing($l))->toBe(50);
});

it('bundle → min atas komponen ⌊pool/qty⌋', function () {
    $a = Product::factory()->create(); $b = Product::factory()->create();
    TiktokSkuMap::create(['tiktok_sku' => 'BND', 'product_id' => $a->id, 'qty' => 2]); // butuh 2 A
    TiktokSkuMap::create(['tiktok_sku' => 'BND', 'product_id' => $b->id, 'qty' => 1]); // + 1 B
    MarketplaceStock::create(['product_id' => $a->id, 'quantity' => 10, 'seeded_at' => now()]); // ⌊10/2⌋=5
    MarketplaceStock::create(['product_id' => $b->id, 'quantity' => 3,  'seeded_at' => now()]); // ⌊3/1⌋=3
    $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'BND']);
    expect(svc()->availableForListing($l))->toBe(3); // min(5,3)
});

it('null bila belum dipetakan atau pool belum ada (jangan push)', function () {
    $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'GHOST']);
    expect(svc()->availableForListing($l))->toBeNull();

    $p = Product::factory()->create(['sku' => 'X']);
    TiktokSkuMap::create(['tiktok_sku' => 'X', 'product_id' => $p->id, 'qty' => 1]);
    $l2 = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'X']); // ada map, TAPI pool belum ada
    expect(svc()->availableForListing($l2))->toBeNull();
});

it('applyOrderDelta menghormati seeded_at & clamp 0', function () {
    $p = Product::factory()->create();
    MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 5, 'seeded_at' => now()]);
    svc()->applyOrderDelta($p, -2, now()->addMinute());   // sesudah seed → 3
    expect(MarketplaceStock::where('product_id',$p->id)->value('quantity'))->toBe(3);
    svc()->applyOrderDelta($p, -1, now()->subDay());      // sebelum seed → diabaikan
    expect(MarketplaceStock::where('product_id',$p->id)->value('quantity'))->toBe(3);
    svc()->applyOrderDelta($p, -99, now()->addMinute());  // clamp
    expect(MarketplaceStock::where('product_id',$p->id)->value('quantity'))->toBe(0);
});
```

- [ ] **Step 2: Jalankan — GAGAL.**

- [ ] **Step 3: Implementasi (bagian hitung/pool saja; method push/resolve/seed = task berikut)**

```php
class MarketplaceStockService
{
    public function __construct(private ShopeeClient $shopee, private TikTokClient $tiktok) {}

    /** @return array<int, array{product_id:int, qty:int}> */
    public function componentsFor(string $channel, string $sku): array
    {
        $maps = $channel === 'tiktok'
            ? TiktokSkuMap::where('tiktok_sku', $sku)->get(['product_id', 'qty'])
            : ShopeeSkuMap::where('shopee_sku', $sku)->get(['product_id', 'qty']);
        if ($maps->isNotEmpty()) {
            return $maps->map(fn ($m) => ['product_id' => (int) $m->product_id, 'qty' => max(1, (int) $m->qty)])->all();
        }
        $p = Product::where('sku', $sku)->first(); // fallback: SKU = Product.sku (×1)
        return $p ? [['product_id' => $p->id, 'qty' => 1]] : [];
    }

    public function availableForListing(MarketplaceListing $l): ?int
    {
        $components = $this->componentsFor($l->channel, $l->seller_sku);
        if (! $components) return null;
        $avail = null;
        foreach ($components as $c) {
            $pool = MarketplaceStock::where('product_id', $c['product_id'])->first();
            if (! $pool) return null; // pool belum di-seed/di-set → jangan push (pengaman anti-0)
            $canMake = intdiv(max(0, (int) $pool->quantity), $c['qty']);
            $avail = $avail === null ? $canMake : min($avail, $canMake);
        }
        return $avail;
    }

    public function adjustPool(Product $product, int $delta): void
    {
        $row = MarketplaceStock::where('product_id', $product->id)->first();
        if (! $row) return; // pool belum ada → mirror tak berlaku (jangan buat via delta)
        $row->update(['quantity' => max(0, (int) $row->quantity + $delta)]);
    }

    public function setPool(Product $product, int $qty): MarketplaceStock
    {
        return MarketplaceStock::updateOrCreate(
            ['product_id' => $product->id],
            ['quantity' => max(0, $qty), 'seeded_at' => now()],
        );
    }

    public function applyOrderDelta(Product $product, int $delta, \Illuminate\Support\Carbon $orderCreatedAt): void
    {
        $row = MarketplaceStock::where('product_id', $product->id)->first();
        if (! $row || $row->seeded_at === null || $orderCreatedAt->lt($row->seeded_at)) return;
        $row->update(['quantity' => max(0, (int) $row->quantity + $delta)]);
    }
}
```
> `use` yang diperlukan: `App\Models\{MarketplaceListing, MarketplaceStock, Product, TiktokSkuMap, ShopeeSkuMap}`, `App\Services\{ShopeeClient, TikTokClient}`.

- [ ] **Step 4: Jalankan — LULUS.**
- [ ] **Step 5: Pint + commit** (`feat(stok-mp): service hitung siap-jual + pool set/adjust`).

---

### Task 4: Method tulis stok di client (Shopee & TikTok)

**Files:**
- Modify: `app/Services/ShopeeClient.php` (`updateStock`, `getItemList`, `getModelList`)
- Modify: `app/Services/TikTokClient.php` (`updateStock`, `searchProducts`, `getWarehouses`)
- Test: `tests/Feature/MarketplaceStock/ClientUpdateStockTest.php`

**Interfaces:**
- Produces:
  - `ShopeeClient::updateStock(string $accessToken, string $shopId, int $itemId, int $modelId, int $qty): array`
  - `ShopeeClient::getItemList(string $accessToken, string $shopId, int $offset = 0, int $pageSize = 50): array`
  - `ShopeeClient::getModelList(string $accessToken, string $shopId, int $itemId): array`
  - `TikTokClient::updateStock(string $accessToken, string $shopCipher, string $productId, string $skuId, string $warehouseId, int $qty): array`
  - `TikTokClient::searchProducts(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = ''): array`
  - `TikTokClient::getWarehouses(string $accessToken, string $shopCipher): array`

- [ ] **Step 1: Tes payload (gagal dulu)** — pakai `Http::fake()` + `Http::assertSent`:

```php
use App\Services\{ShopeeClient, TikTokClient};
use Illuminate\Support\Facades\Http;

it('Shopee updateStock kirim item_id + model_id + stock benar', function () {
    config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k', 'services.shopee.api_base' => 'https://partner.shopeemobile.com']);
    Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);
    app(ShopeeClient::class)->updateStock('tok', '123', 555, 0, 42);
    Http::assertSent(function ($req) {
        $b = $req->data();
        return str_contains($req->url(), '/api/v2/product/update_stock')
            && $b['item_id'] === 555
            && $b['stock_list'][0]['model_id'] === 0
            && $b['stock_list'][0]['seller_stock'][0]['stock'] === 42;
    });
});

it('TikTok updateStock kirim sku id + warehouse + quantity benar', function () {
    config(['services.tiktok.api_base' => 'https://open-api.tiktokglobalshop.com', 'services.tiktok.app_key' => 'a', 'services.tiktok.app_secret' => 's']);
    Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
    app(TikTokClient::class)->updateStock('tok', 'cipher', 'PROD1', 'SKU1', 'WH1', 7);
    Http::assertSent(function ($req) {
        $b = $req->data();
        return str_contains($req->url(), '/product/202309/products/PROD1/inventory/update')
            && $b['skus'][0]['id'] === 'SKU1'
            && $b['skus'][0]['inventory'][0]['warehouse_id'] === 'WH1'
            && $b['skus'][0]['inventory'][0]['quantity'] === 7;
    });
});
```
> Sesuaikan nama config (`services.shopee.*`, `services.tiktok.*`) dengan yang dipakai client saat ini (cek `config/services.php`). Jika `sign()` butuh key tertentu, set di `config([...])`.

- [ ] **Step 2: Jalankan — GAGAL.**

- [ ] **Step 3: Tambah method Shopee** (pakai `shopCall` yang ada):

```php
public function updateStock(string $accessToken, string $shopId, int $itemId, int $modelId, int $qty): array
{
    return $this->shopCall('POST', '/api/v2/product/update_stock', $accessToken, $shopId, [
        'item_id' => $itemId,
        'stock_list' => [[ 'model_id' => $modelId, 'seller_stock' => [['stock' => $qty]] ]],
    ]);
}
public function getItemList(string $accessToken, string $shopId, int $offset = 0, int $pageSize = 50): array
{
    return $this->shopCall('GET', '/api/v2/product/get_item_list', $accessToken, $shopId, [
        'offset' => $offset, 'page_size' => $pageSize, 'item_status' => 'NORMAL',
    ]);
}
public function getModelList(string $accessToken, string $shopId, int $itemId): array
{
    return $this->shopCall('GET', '/api/v2/product/get_model_list', $accessToken, $shopId, ['item_id' => $itemId]);
}
```

- [ ] **Step 4: Tambah method TikTok** (pakai `request` yang ada):

```php
public function updateStock(string $accessToken, string $shopCipher, string $productId, string $skuId, string $warehouseId, int $qty): array
{
    return $this->request('POST', "/product/202309/products/{$productId}/inventory/update", $accessToken, $shopCipher, [], [
        'skus' => [[ 'id' => $skuId, 'inventory' => [[ 'warehouse_id' => $warehouseId, 'quantity' => $qty ]] ]],
    ]);
}
public function searchProducts(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = ''): array
{
    $q = ['page_size' => $pageSize];
    if ($pageToken !== '') { $q['page_token'] = $pageToken; }
    return $this->request('POST', '/product/202309/products/search', $accessToken, $shopCipher, $q, ['status' => 'ACTIVATE']);
}
public function getWarehouses(string $accessToken, string $shopCipher): array
{
    return $this->request('GET', '/logistics/202309/warehouses', $accessToken, $shopCipher);
}
```
> **Verifikasi versi/endpoint** terhadap docs channel saat implement (Shopee `update_stock` v2; TikTok `202309` product inventory/search + warehouse). Sesuaikan bila docs beda; struktur payload di atas adalah bentuk resmi umumnya.

- [ ] **Step 5: Jalankan — LULUS.**
- [ ] **Step 6: Pint + commit** (`feat(stok-mp): method tulis stok + list produk di ShopeeClient/TikTokClient`).

---

### Task 5: `resolveListings` — isi ID channel ke `marketplace_listings`

**Files:**
- Modify: `app/Services/MarketplaceStockService.php` (`resolveListings`, helper token)
- Test: `tests/Feature/MarketplaceStock/ResolveListingsTest.php`

**Interfaces:**
- Consumes: `ShopeeConnection`, `TiktokConnection`, client list/warehouse methods (Task 4).
- Produces: `resolveListings(string $channel): array{found:int, mapped:int, unmapped:int}` — upsert baris `marketplace_listings` per `(channel, seller_sku)`; `last_status='unmapped'` bila `seller_sku` tak ada di peta SKU & bukan `Product.sku`.

- [ ] **Step 1: Tes (gagal dulu)** — fake Http untuk daftar produk TikTok, assert baris `marketplace_listings` terisi + status.

```php
it('resolve TikTok mengisi item_id/variation_id dan menandai unmapped', function () {
    // koneksi TikTok dummy
    \App\Models\TiktokConnection::create(['shop_id'=>'s','shop_cipher'=>'c','access_token'=>'t','refresh_token'=>'r','access_expires_at'=>now()->addDay()]);
    $p = \App\Models\Product::factory()->create(['sku'=>'FM-1']);
    \App\Models\TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    Http::fake([
        '*/product/202309/products/search*' => Http::response(['code'=>0,'data'=>['products'=>[
            ['id'=>'PID1','title'=>'Face Mist','skus'=>[['id'=>'SID1','seller_sku'=>'FM-1']]],
            ['id'=>'PID2','title'=>'Lain','skus'=>[['id'=>'SID2','seller_sku'=>'ZZZ']]],
        ],'total_count'=>2]]),
        '*/logistics/202309/warehouses*' => Http::response(['code'=>0,'data'=>['warehouses'=>[['id'=>'WH1']]]]),
    ]);
    $res = app(\App\Services\MarketplaceStockService::class)->resolveListings('tiktok');
    expect(\App\Models\MarketplaceListing::where('seller_sku','FM-1')->value('item_id'))->toBe('PID1');
    expect(\App\Models\MarketplaceListing::where('seller_sku','FM-1')->value('variation_id'))->toBe('SID1');
    expect(\App\Models\MarketplaceListing::where('seller_sku','ZZZ')->value('last_status'))->toBe('unmapped');
});
```
> Sesuaikan bentuk respons (`data.products[].skus[]`, `data.warehouses[]`) dgn docs TikTok saat implement.

- [ ] **Step 2: Jalankan — GAGAL.**

- [ ] **Step 3: Implementasi** — tambah helper token (SALIN logika dari `TikTokSyncService::freshToken` ~baris 229-240 & `ShopeeSyncService::freshToken` ~baris 30-42 untuk hindari siklus DI) + `resolveListings`:

```php
private function tiktokConn(): ?TiktokConnection { return TiktokConnection::latest('id')->first(); }
private function shopeeConn(): ?ShopeeConnection { return ShopeeConnection::latest('id')->first(); }

private function tiktokToken(TiktokConnection $c): string
{
    if (! $c->accessExpiringSoon()) return (string) $c->access_token;
    $t = $this->tiktok->refreshToken($c->refresh_token);           // bentuk balasan = spt TikTokSyncService
    $c->update(['access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
                'access_expires_at' => now()->addSeconds((int) ($t['access_token_expire_in'] ?? 604800))]);
    return (string) $t['access_token'];
}
private function shopeeToken(ShopeeConnection $c): string
{
    if (! $c->accessExpiringSoon()) return (string) $c->access_token;
    $t = $this->shopee->refreshToken($c->refresh_token, $c->shop_id);
    $c->update(['access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
                'access_expires_at' => now()->addSeconds((int) ($t['expire_in'] ?? 14400))]);
    return (string) $t['access_token'];
}

public function resolveListings(string $channel): array
{
    return $channel === 'tiktok' ? $this->resolveTiktok() : $this->resolveShopee();
}

private function resolveTiktok(): array
{
    $c = $this->tiktokConn();
    if (! $c) return ['found' => 0, 'mapped' => 0, 'unmapped' => 0];
    $tok = $this->tiktokToken($c);
    $wh = data_get($this->tiktok->getWarehouses($tok, $c->shop_cipher), 'data.warehouses.0.id');
    $found = $mapped = $unmapped = 0; $pageToken = '';
    do {
        $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
        foreach (data_get($res, 'data.products', []) as $prod) {
            foreach ($prod['skus'] ?? [] as $sku) {
                $sellerSku = (string) ($sku['seller_sku'] ?? '');
                if ($sellerSku === '') continue;
                $found++;
                $isMapped = $this->componentsFor('tiktok', $sellerSku) !== [];
                MarketplaceListing::updateOrCreate(
                    ['channel' => 'tiktok', 'seller_sku' => $sellerSku],
                    ['item_id' => (string) $prod['id'], 'variation_id' => (string) $sku['id'],
                     'warehouse_id' => $wh, 'title' => $prod['title'] ?? null,
                     'last_status' => $isMapped ? MarketplaceListing::where('channel','tiktok')->where('seller_sku',$sellerSku)->value('last_status') ?? null : 'unmapped',
                     'resolved_at' => now()],
                );
                $isMapped ? $mapped++ : $unmapped++;
            }
        }
        $pageToken = (string) data_get($res, 'data.next_page_token', '');
    } while ($pageToken !== '');
    return compact('found', 'mapped', 'unmapped');
}
```
> `resolveShopee()` serupa: `getItemList` → per item `getItemBaseInfo` (ambil `item_sku`, `has_model`) → bila `has_model`, `getModelList` (per `model_id` + `model_sku`); upsert `(channel='shopee', seller_sku=model_sku|item_sku)` dengan `item_id`, `variation_id`=model_id (0 bila tanpa varian), `warehouse_id`=null. Jangan timpa `last_status='ok'` yang sudah ada bila masih mapped.

- [ ] **Step 4: Tulis + jalankan tes serupa untuk Shopee** (fake `get_item_list`/`get_item_base_info`/`get_model_list`).
- [ ] **Step 5: Jalankan semua — LULUS.**
- [ ] **Step 6: Pint + commit** (`feat(stok-mp): resolve listing → cache ID channel + tandai unmapped`).

---

### Task 6: Push (pushListing/pushProduct/pushDirty/pushAll) + catat status

**Files:**
- Modify: `app/Services/MarketplaceStockService.php`
- Test: `tests/Feature/MarketplaceStock/PushStockTest.php`

**Interfaces:**
- Produces:
  - `pushListing(MarketplaceListing $l, bool $force = false): string` (→ `ok|skip|failed|unmapped`)
  - `pushProduct(Product $p, bool $force = true): array`
  - `pushDirty(): array{pushed:int, skipped:int, failed:int}`
  - `pushAll(): array`

- [ ] **Step 1: Tes (gagal dulu)** — pool di-set, listing ter-resolve, fake Http sukses → `pushDirty` mengirim yang beda; tidak mengirim lagi bila `last_pushed_qty` sama; `failed` tercatat saat Http error; `unmapped`/pool-null dilewati.

```php
it('pushDirty hanya kirim yang berubah lalu catat', function () {
    $p = \App\Models\Product::factory()->create(['sku'=>'FM-1']);
    \App\Models\TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    app(\App\Services\MarketplaceStockService::class)->setPool($p, 40);
    \App\Models\TiktokConnection::create(['shop_id'=>'s','shop_cipher'=>'c','access_token'=>'t','refresh_token'=>'r','access_expires_at'=>now()->addDay()]);
    \App\Models\MarketplaceListing::create(['channel'=>'tiktok','seller_sku'=>'FM-1','item_id'=>'PID1','variation_id'=>'SID1','warehouse_id'=>'WH1','resolved_at'=>now()]);
    Http::fake(['*/inventory/update*' => Http::response(['code'=>0,'data'=>[]])]);

    $r1 = app(\App\Services\MarketplaceStockService::class)->pushDirty();
    expect($r1['pushed'])->toBe(1);
    expect(\App\Models\MarketplaceListing::first()->last_pushed_qty)->toBe(40);

    $r2 = app(\App\Services\MarketplaceStockService::class)->pushDirty(); // tak berubah
    expect($r2['pushed'])->toBe(0);
    expect($r2['skipped'])->toBe(1);
});
```

- [ ] **Step 2: Jalankan — GAGAL.**

- [ ] **Step 3: Implementasi**

```php
public function pushListing(MarketplaceListing $l, bool $force = false): string
{
    $avail = $this->availableForListing($l);
    if ($avail === null) { $l->update(['last_status' => 'unmapped']); return 'unmapped'; }
    if (! $l->item_id) { return 'skip'; } // belum ter-resolve
    if (! $force && $l->last_pushed_qty === $avail) { return 'skip'; }
    try {
        if ($l->channel === 'tiktok') {
            $c = $this->tiktokConn(); if (! $c) throw new \RuntimeException('TikTok belum terhubung');
            $this->tiktok->updateStock($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, (string) $l->warehouse_id, $avail);
        } else {
            $c = $this->shopeeConn(); if (! $c) throw new \RuntimeException('Shopee belum terhubung');
            $this->shopee->updateStock($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $avail);
        }
        $l->update(['last_pushed_qty' => $avail, 'last_status' => 'ok', 'last_error' => null, 'last_pushed_at' => now()]);
        return 'ok';
    } catch (\Throwable $e) {
        $l->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
        return 'failed';
    }
}

public function pushProduct(Product $product, bool $force = true): array
{
    // semua listing yang seller_sku-nya memuat produk ini sbg komponen
    $skus = collect();
    foreach (MarketplaceListing::all() as $l) {
        foreach ($this->componentsFor($l->channel, $l->seller_sku) as $c) {
            if ($c['product_id'] === $product->id) { $skus->push($l); break; }
        }
    }
    $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
    foreach ($skus as $l) { $this->tally($out, $this->pushListing($l, $force)); }
    return $out;
}

public function pushDirty(): array { return $this->pushEach(false); }
public function pushAll(): array   { return $this->pushEach(true); }

private function pushEach(bool $force): array
{
    $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
    foreach (MarketplaceListing::whereNotNull('item_id')->get() as $l) {
        $this->tally($out, $this->pushListing($l, $force));
    }
    return $out;
}
private function tally(array &$o, string $r): void
{
    if ($r === 'ok') $o['pushed']++; elseif ($r === 'failed') $o['failed']++; else $o['skipped']++;
}
```

- [ ] **Step 4: Jalankan — LULUS** (tambah tes: Http error → `failed` + `last_error`; pool belum di-set → `unmapped`/skip, tak ada Http terkirim).
- [ ] **Step 5: Pint + commit** (`feat(stok-mp): push stok (diff + paksa) + catat status/last_error`).

---

### Task 7: Cron `marketplace:push-stock` + jadwal

**Files:**
- Create: `app/Console/Commands/MarketplacePushStockCommand.php`
- Modify: `routes/console.php` (schedule)
- Test: `tests/Feature/MarketplaceStock/PushCommandTest.php`

**Interfaces:**
- Consumes: `MarketplaceStockService::pushDirty`.
- Produces: command signature `marketplace:push-stock`.

- [ ] **Step 1: Tes (gagal dulu)**

```php
it('command memanggil pushDirty', function () {
    $this->mock(\App\Services\MarketplaceStockService::class, function ($m) {
        $m->shouldReceive('pushDirty')->once()->andReturn(['pushed'=>0,'skipped'=>0,'failed'=>0]);
    });
    $this->artisan('marketplace:push-stock')->assertOk();
});
```

- [ ] **Step 2: Jalankan — GAGAL.**
- [ ] **Step 3: Command**

```php
class MarketplacePushStockCommand extends Command
{
    protected $signature = 'marketplace:push-stock';
    protected $description = 'Dorong stok marketplace (diff) ke TikTok & Shopee';
    public function handle(MarketplaceStockService $svc): int
    {
        $r = $svc->pushDirty();
        $this->info("Push stok: {$r['pushed']} terkirim · {$r['skipped']} dilewati · {$r['failed']} gagal.");
        return self::SUCCESS;
    }
}
```
- [ ] **Step 4: Jadwal** di `routes/console.php`: `Schedule::command('marketplace:push-stock')->everyFiveMinutes()->withoutOverlapping();`
- [ ] **Step 5: Jalankan — LULUS.**
- [ ] **Step 6: Pint + commit** (`feat(stok-mp): cron marketplace:push-stock tiap 5 menit`).

---

### Task 8: Seed dari TikTok

**Files:**
- Modify: `app/Services/MarketplaceStockService.php` (`seedFromTiktok`)
- Test: `tests/Feature/MarketplaceStock/SeedFromTiktokTest.php`

**Interfaces:**
- Produces: `seedFromTiktok(): array{seeded:int, skipped:int}` — set pool untuk listing TikTok 1:1 (satu komponen, qty 1) = stok TikTok saat ini; stamp `seeded_at`; lewati bundle. Lalu jalankan `pushAll()` agar Shopee menyamakan.

- [ ] **Step 1: Tes (gagal dulu)** — fake product search TikTok yang membawa stok/inventory; assert pool ter-set utk 1:1, bundle dilewati.

```php
it('seed set pool dari stok TikTok utk listing 1:1', function () {
    \App\Models\TiktokConnection::create(['shop_id'=>'s','shop_cipher'=>'c','access_token'=>'t','refresh_token'=>'r','access_expires_at'=>now()->addDay()]);
    $p = \App\Models\Product::factory()->create(['sku'=>'FM-1']);
    \App\Models\TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    Http::fake([
        '*/product/202309/products/search*' => Http::response(['code'=>0,'data'=>['products'=>[
            ['id'=>'PID1','title'=>'Face Mist','skus'=>[['id'=>'SID1','seller_sku'=>'FM-1','inventory'=>[['quantity'=>33]]]]],
        ]]]),
        '*' => Http::response(['code'=>0,'data'=>[]]),
    ]);
    $res = app(\App\Services\MarketplaceStockService::class)->seedFromTiktok();
    expect(\App\Models\MarketplaceStock::where('product_id',$p->id)->value('quantity'))->toBe(33);
    expect(\App\Models\MarketplaceStock::where('product_id',$p->id)->value('seeded_at'))->not->toBeNull();
});
```
> Bentuk stok di respons produk TikTok (`skus[].inventory[].quantity`) — **verifikasi** ke docs; sesuaikan path `data_get`.

- [ ] **Step 2: Jalankan — GAGAL.**
- [ ] **Step 3: Implementasi**

```php
public function seedFromTiktok(): array
{
    $c = $this->tiktokConn();
    if (! $c) return ['seeded' => 0, 'skipped' => 0];
    $tok = $this->tiktokToken($c);
    $seeded = $skipped = 0; $pageToken = '';
    do {
        $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
        foreach (data_get($res, 'data.products', []) as $prod) {
            foreach ($prod['skus'] ?? [] as $sku) {
                $sellerSku = (string) ($sku['seller_sku'] ?? '');
                if ($sellerSku === '') continue;
                $components = $this->componentsFor('tiktok', $sellerSku);
                // hanya 1:1 (satu komponen, qty 1) yang di-seed otomatis
                if (count($components) !== 1 || $components[0]['qty'] !== 1) { $skipped++; continue; }
                $qty = (int) data_get($sku, 'inventory.0.quantity', 0);
                $product = Product::find($components[0]['product_id']);
                if (! $product) { $skipped++; continue; }
                $this->setPool($product, $qty);
                $seeded++;
            }
        }
        $pageToken = (string) data_get($res, 'data.next_page_token', '');
    } while ($pageToken !== '');
    $this->pushAll(); // samakan Shopee dgn nilai awal
    return compact('seeded', 'skipped');
}
```
- [ ] **Step 4: Jalankan — LULUS.**
- [ ] **Step 5: Pint + commit** (`feat(stok-mp): seed pool dari stok TikTok (listing 1:1)`).

---

### Task 9: Cermin pool saat order masuk (hook `store()` TikTok & Shopee)

**Files:**
- Modify: `app/Services/TikTokOrderService.php` (inject service + hook di `store()`)
- Modify: `app/Services/ShopeeOrderService.php` (idem)
- Test: `tests/Feature/MarketplaceStock/OrderMirrorTest.php`

**Interfaces:**
- Consumes: `MarketplaceStockService::applyOrderDelta`, `resolve()` (sudah ada di order service).

- [ ] **Step 1: Tes (gagal dulu)**

```php
use App\Models\{Product, MarketplaceStock, TiktokSkuMap, TiktokOrder, StockMovement};
uses(RefreshDatabase::class);

it('order TikTok baru mengurangi pool (bukan HQ), sesudah seed', function () {
    $p = Product::factory()->create(['sku'=>'FM-1']);
    TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    app(\App\Services\MarketplaceStockService::class)->setPool($p, 10); // seeded_at=now
    $hqBefore = StockMovement::count();

    app(\App\Services\TikTokOrderService::class)->store([[
        'id'=>'O1','status'=>'AWAITING_SHIPMENT','create_time'=>now()->addMinute()->timestamp,
        'payment'=>['total_amount'=>1,'currency'=>'IDR'],
        'line_items'=>[['seller_sku'=>'FM-1','quantity'=>3,'product_name'=>'Face Mist']],
    ]]);

    expect(MarketplaceStock::where('product_id',$p->id)->value('quantity'))->toBe(7); // 10-3
    expect(StockMovement::count())->toBe($hqBefore);                                   // HQ TIDAK berubah
});

it('re-sync order sama tak mengurangi dua kali; order sebelum seed diabaikan', function () { /* store() dua kali → tetap 7; order create_time < seeded_at → tetap */ });

it('transisi ke CANCELLED mengembalikan pool', function () { /* store O1 (turun), lalu store O1 status CANCELLED → naik lagi */ });
```

- [ ] **Step 2: Jalankan — GAGAL.**

- [ ] **Step 3: Hook di `TikTokOrderService`** — tambah dependency & pola mirror. Konstruktor:

```php
public function __construct(private InventoryService $inventory, private MarketplaceStockService $marketplace) {}
```
Di `store()`, `$existing` sudah ditangkap sebelum `updateOrCreate`. Setelah `updateOrCreate(...)` untuk tiap order, sisipkan:

```php
try {
    $this->mirrorMarketplace($existing, $o, (string) $id);
} catch (\Throwable $e) {
    Log::warning("[tiktok] mirror stok marketplace gagal order {$id}: ".$e->getMessage());
}
```
Tambah method:

```php
private function mirrorMarketplace(?TiktokOrder $existing, array $o, string $id): void
{
    $status = $o['status'] ?? null;
    $nowCancelled = in_array($status, TiktokOrder::CANCELLED_STATUSES, true);
    $wasCancelled = $existing && in_array($existing->status, TiktokOrder::CANCELLED_STATUSES, true);
    $createdAt = isset($o['create_time']) ? Carbon::createFromTimestamp((int) $o['create_time']) : now();

    $sign = 0;
    if ($existing === null && ! $nowCancelled) { $sign = -1; }        // order baru → kurangi
    elseif ($existing && ! $wasCancelled && $nowCancelled) { $sign = 1; } // batal → kembalikan
    if ($sign === 0) { return; }

    foreach ($this->normalizeItems($o) as $it) {
        foreach ($this->resolve($it['sku']) as $c) {
            $this->marketplace->applyOrderDelta($c['product'], $sign * $c['qty'] * (int) $it['qty'], $createdAt);
        }
    }
}
```
> Tambah `use App\Services\MarketplaceStockService;`. `resolve()`/`normalizeItems()` sudah ada. `$id` = `$o['id']` (sudah dipakai di store).

- [ ] **Step 4: Hook di `ShopeeOrderService`** — sama persis, sesuaikan: konstruktor tambah `MarketplaceStockService`; di `store()` setelah `updateOrCreate`, panggil `mirrorMarketplace($existing, $o, (string) $sn)`; gunakan `ShopeeOrder::CANCELLED_STATUSES` dan `$o['order_status']` untuk status (Shopee pakai `order_status`), `create_time` untuk tanggal, `normalizeItems($o)` + `resolve()` (sudah ada).

- [ ] **Step 5: Jalankan semua tes (TikTok & Shopee mirror) — LULUS.** Pastikan tes menegaskan `StockMovement` (HQ) tak berubah.
- [ ] **Step 6: Pint + commit** (`feat(stok-mp): cermin pool saat order masuk (best-effort, HQ tak disentuh)`).

---

### Task 10: UI halaman "Stok Marketplace" + menu sidebar

**Files:**
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (index penuh + aksi)
- Modify: `resources/views/marketplace-stock/index.blade.php` (tabel + tombol)
- Modify: `routes/web.php` (rute aksi)
- Modify: `resources/views/layouts/app.blade.php` (item menu Integrasi)
- Test: `tests/Feature/MarketplaceStock/MarketplaceUiTest.php`

**Interfaces:**
- Produces rute (grup `permission:manage_marketplace_stock`): `marketplace-stock.set` (POST `/marketplace-stock/set/{product}`), `.push` (POST `/marketplace-stock/push/{product}`), `.push-all` (POST `/marketplace-stock/push-all`), `.resolve` (POST `/marketplace-stock/resolve`), `.seed` (POST `/marketplace-stock/seed-tiktok`).

- [ ] **Step 1: Tes (gagal dulu)**

```php
it('render tabel + set stok memanggil setPool lalu push', function () {
    $admin = \App\Models\User::factory()->create(['role'=>\App\Models\User::ROLE_SUPER_ADMIN]);
    $p = \App\Models\Product::factory()->create(['sku'=>'FM-1','name'=>'Face Mist']);
    \App\Models\TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    \App\Models\MarketplaceListing::create(['channel'=>'tiktok','seller_sku'=>'FM-1','item_id'=>'PID1','variation_id'=>'SID1','warehouse_id'=>'WH1','resolved_at'=>now()]);
    \App\Models\TiktokConnection::create(['shop_id'=>'s','shop_cipher'=>'c','access_token'=>'t','refresh_token'=>'r','access_expires_at'=>now()->addDay()]);
    \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['code'=>0,'data'=>[]])]);

    $this->actingAs($admin)->get('/marketplace-stock')->assertOk()->assertSee('Face Mist');
    $this->actingAs($admin)->post("/marketplace-stock/set/{$p->id}", ['quantity'=>25])->assertRedirect();
    expect(\App\Models\MarketplaceStock::where('product_id',$p->id)->value('quantity'))->toBe(25);
});
```

- [ ] **Step 2: Jalankan — GAGAL.**

- [ ] **Step 3: Controller** — `index()` kumpulkan produk yang punya peta SKU (distinct `product_id` dari `tiktok_sku_maps` ∪ `shopee_sku_maps`), muat `Product` + pool + listing per channel; `unmapped` = `MarketplaceListing::where('last_status','unmapped')`. Aksi:

```php
public function index()
{
    $productIds = TiktokSkuMap::distinct()->pluck('product_id')
        ->merge(ShopeeSkuMap::distinct()->pluck('product_id'))->unique()->values();
    $products = Product::whereIn('id', $productIds)->orderBy('name')->get();
    $pools = MarketplaceStock::whereIn('product_id', $productIds)->get()->keyBy('product_id');
    $listings = MarketplaceListing::whereIn('last_status', ['ok','failed'])->orWhereNull('last_status')->get();
    $rows = $products->map(fn ($p) => [
        'product' => $p,
        'pool' => $pools[$p->id]->quantity ?? null,
        'tiktok' => $this->listingFor($listings, 'tiktok', $p),
        'shopee' => $this->listingFor($listings, 'shopee', $p),
    ]);
    return view('marketplace-stock.index', ['rows' => $rows, 'unmapped' => MarketplaceListing::where('last_status','unmapped')->get()]);
}

public function setStock(Request $r, Product $product, MarketplaceStockService $svc)
{
    $r->validate(['quantity' => ['required','integer','min:0']]);
    $svc->setPool($product, (int) $r->quantity);
    $svc->pushProduct($product);          // set manual → push langsung
    return back()->with('status', "Stok {$product->name} disetel & disinkron.");
}
public function push(Product $product, MarketplaceStockService $svc) { $svc->pushProduct($product); return back()->with('status','Disinkron.'); }
public function pushAll(MarketplaceStockService $svc) { $r=$svc->pushAll(); return back()->with('status',"Sinkron semua: {$r['pushed']} terkirim, {$r['failed']} gagal."); }
public function resolve(MarketplaceStockService $svc) { $svc->resolveListings('tiktok'); $svc->resolveListings('shopee'); return back()->with('status','Daftar listing diperbarui.'); }
public function seed(MarketplaceStockService $svc) { $r=$svc->seedFromTiktok(); return back()->with('status',"Seed dari TikTok: {$r['seeded']} produk."); }
```
> `listingFor($listings,$channel,$product)` = helper: cari `MarketplaceListing` channel itu yang `seller_sku`-nya memetakan ke `$product` (pakai `MarketplaceStockService::componentsFor`); kembalikan listing (atau null → "belum dipetakan").

- [ ] **Step 4: View** — tabel baris per produk: nama + SKU, input angka `quantity` (form POST `.set`), kolom TikTok/Shopee (seller_sku + `last_pushed_qty` + `last_status`/waktu, atau "belum dipetakan"), tombol Sinkron per baris. Header: tombol **Tarik stok awal dari TikTok** (`.seed`), **Refresh listing** (`.resolve`), **Sinkron semua** (`.push-all`). Bagian "Listing belum terpetakan" (list `$unmapped`) + tautan ke `route('tiktok.index')` / `route('shopee.index')` untuk memetakan. Semua tombol = form POST + `@csrf`. Tanpa `@json([...])` literal.

- [ ] **Step 5: Rute aksi** (dalam grup `permission:manage_marketplace_stock`):

```php
Route::post('/marketplace-stock/set/{product}', [MarketplaceStockController::class, 'setStock'])->name('marketplace-stock.set');
Route::post('/marketplace-stock/push/{product}', [MarketplaceStockController::class, 'push'])->name('marketplace-stock.push');
Route::post('/marketplace-stock/push-all', [MarketplaceStockController::class, 'pushAll'])->name('marketplace-stock.push-all');
Route::post('/marketplace-stock/resolve', [MarketplaceStockController::class, 'resolve'])->name('marketplace-stock.resolve');
Route::post('/marketplace-stock/seed-tiktok', [MarketplaceStockController::class, 'seed'])->name('marketplace-stock.seed');
```

- [ ] **Step 6: Menu sidebar** — di `resources/views/layouts/app.blade.php`, grup Integrasi, tambah setelah item Shopee:

```blade
@can('manage_marketplace_stock')
    <a href="{{ route('marketplace-stock.index') }}" class="{{ request()->routeIs('marketplace-stock.*') ? '...aktif...' : '...' }}">Stok Marketplace</a>
@endcan
```
> Ikuti markup/kelas item menu Integrasi yang ada persis (salin pola item Shopee).

- [ ] **Step 7: Jalankan tes — LULUS.**
- [ ] **Step 8: Pint + commit** (`feat(stok-mp): halaman Stok Marketplace + menu Integrasi`).

---

## Self-Review (dilakukan penulis rencana)

**1. Cakupan spec:** semua bagian spec tercakup — pool & listing (T1), izin/rute (T2), hitung/pool (T3), client write (T4), resolve ID (T5), push diff+status (T6), cron (T7), seed TikTok (T8), cermin order (T9), UI+menu (T10). HQ tak disentuh (ditegaskan di tes T9).

**2. Placeholder:** endpoint/versi channel & bentuk respons ditandai "verifikasi ke docs" (wajar untuk integrasi eksternal; bukan placeholder logika). Token helper = SALIN dari `*SyncService::freshToken` (rujukan baris eksplisit) demi hindari siklus DI.

**3. Konsistensi tipe:** `availableForListing(): ?int`, `componentsFor(): array<{product_id,qty}>`, `applyOrderDelta(Product,int,Carbon)`, `setPool(): MarketplaceStock`, `pushListing(): string` — dipakai konsisten lintas task. `MarketplaceListing.item_id` = product_id(TikTok)/item_id(Shopee); `variation_id` = sku_id(TikTok)/model_id(Shopee) — dipakai konsisten di T4/T5/T6.

**4. Ambiguitas:** pengaman anti-0 (pool null → tak push) ditegakkan di `availableForListing` + dites (T3/T6). `seeded_at` mencegah dobel-hitung histori (T3/T9). Mirror best-effort (try/catch) agar tak ganggu sinkron order (T9).

**Risiko utama saat eksekusi:** (a) bentuk payload/paginasi API produk TikTok/Shopee — verifikasi ke docs di T4/T5/T8; (b) siklus DI bila service order meng-inject service yang menarik SyncService — DIHINDARI dengan token helper mandiri (bukan inject SyncService). 
