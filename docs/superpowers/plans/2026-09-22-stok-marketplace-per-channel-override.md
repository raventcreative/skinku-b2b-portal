# Stok Marketplace Fase 1.5 — Override Per-Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Tambah lapis kontrol per-channel di atas pool Master Fase 1: tiap channel bisa di-override (punya stok sendiri, tetap auto turun saat order di channel itu) tanpa mengubah channel lain; tautkan SKU belum terpetakan langsung dari halaman Stok; menu sidebar terpisah (Master / TikTok / Shopee).

**Architecture:** Tabel baru `marketplace_channel_overrides` (product+channel). Stok efektif channel = override ?? Master. `MarketplaceStockService` di-rework jadi channel-aware (`channelStock`, `availableForListing`, `applyOrderDelta`). Hook order kirim channel-nya. UI 3 halaman + inline SKU linking. HQ tak disentuh.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + vanilla JS (zero-dep).

**Spec:** `docs/superpowers/specs/2026-09-22-stok-marketplace-per-channel-override-design.md`. Lanjutan Fase 1 (`...2026-09-19-kontrol-stok-marketplace-design.md`, LIVE).

## Global Constraints

- PHP 8.3 / Laravel 13. Tes: `C:\php83\php.exe artisan test`. Format: `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Zero-dependency** (tanpa composer package). Reuse service/model/clients Fase 1.
- **Tes PHPUnit class-style (repo TIDAK pakai Pest); `Product` dibuat langsung (tanpa factory)** — pola: `tests/Feature/MarketplaceStock/*`.
- **JANGAN sentuh** `stock_movements` / `InventoryService::adjustHqStock` / HQ.
- **Jangan rusak perilaku Fase 1:** channel tanpa override = persis Fase 1 (ikut Master). Pengaman anti-push-0 (`availableForListing` null → tak push) tetap.
- `channel` selalu divalidasi ∈ {`tiktok`,`shopee`} (abort 404 kalau lain).
- Picker produk = **native `<select>`** (bukan datalist), label pakai **nama produk** (pola yang disukai user).
- Sidebar gate pakai `$u->canDo('manage_marketplace_stock')` — **BUKAN `@can`** (codebase tak pakai Gate).
- Migrasi additive; nomor = lanjut dari terakhir (`000139` → `000140`; verifikasi saat Task 1).
- Blade: tanpa `@json([...])` literal.

---

### Task 1: Migrasi + model `marketplace_channel_overrides`

**Files:**
- Create: `database/migrations/XXXX_..._create_marketplace_channel_overrides_table.php`
- Create: `app/Models/MarketplaceChannelOverride.php`
- Test: `tests/Feature/MarketplaceStock/ChannelOverrideModelTest.php`

**Interfaces — Produces:** `MarketplaceChannelOverride` (`product_id`, `channel`, `quantity` int, `seeded_at` ?datetime; `belongsTo(Product)`); unique `(product_id, channel)`.

- [ ] **Step 1: Tes gagal**

```php
use App\Models\{Product, MarketplaceChannelOverride};
use Illuminate\Foundation\Testing\RefreshDatabase;
// class ChannelOverrideModelTest extends Tests\TestCase { use RefreshDatabase;
public function test_menyimpan_override_per_channel(): void
{
    $p = Product::create(['name'=>'X','sku'=>'X-1','status'=>'active','price_distributor'=>1,'price_reseller'=>1]);
    $o = MarketplaceChannelOverride::create(['product_id'=>$p->id,'channel'=>'tiktok','quantity'=>7,'seeded_at'=>now()]);
    $this->assertSame($p->id, $o->product->id);
    $this->assertSame(7, MarketplaceChannelOverride::where('product_id',$p->id)->where('channel','tiktok')->value('quantity'));
}
public function test_unik_per_produk_channel(): void
{
    $p = Product::create(['name'=>'X','sku'=>'X-1','status'=>'active','price_distributor'=>1,'price_reseller'=>1]);
    MarketplaceChannelOverride::create(['product_id'=>$p->id,'channel'=>'tiktok','quantity'=>1]);
    $this->expectException(\Illuminate\Database\QueryException::class);
    MarketplaceChannelOverride::create(['product_id'=>$p->id,'channel'=>'tiktok','quantity'=>2]);
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`--filter=ChannelOverrideModelTest`).
- [ ] **Step 3: Migrasi**

```php
Schema::create('marketplace_channel_overrides', function (Blueprint $t) {
    $t->id();
    $t->foreignId('product_id')->constrained()->cascadeOnDelete();
    $t->string('channel', 16); // tiktok | shopee
    $t->integer('quantity')->default(0);
    $t->timestamp('seeded_at')->nullable();
    $t->timestamps();
    $t->unique(['product_id', 'channel']);
});
```
(down: drop table.)

- [ ] **Step 4: Model**

```php
class MarketplaceChannelOverride extends Model
{
    protected $fillable = ['product_id', 'channel', 'quantity', 'seeded_at'];
    protected $casts = ['quantity' => 'integer', 'seeded_at' => 'datetime'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
```

- [ ] **Step 5: LULUS.**  **Step 6: Pint + commit** (`feat(stok-mp): tabel & model marketplace_channel_overrides`).

---

### Task 2: Service channel-aware (stok efektif + set/clear override)

**Files:**
- Modify: `app/Services/MarketplaceStockService.php`
- Modify (reconcile): `tests/Unit/MarketplaceStock/AvailabilityTest.php` (signature `applyOrderDelta` berubah — lihat Task 3; di Task 2 cukup tambah channelStock + rework availableForListing tanpa mengubah signature applyOrderDelta dulu **JANGAN** — kerjakan applyOrderDelta di Task 3)
- Test: `tests/Unit/MarketplaceStock/ChannelStockTest.php`

**Interfaces — Produces:**
- `channelStock(int $productId, string $channel): ?int` — override ?? master ?? null.
- `setChannelOverride(Product $p, string $channel, int $qty): MarketplaceChannelOverride` (updateOrCreate + `seeded_at=now()`, clamp ≥0).
- `clearChannelOverride(Product $p, string $channel): void`.
- `availableForListing(MarketplaceListing $l): ?int` — **rework** jadi channel-aware (pakai `channelStock` per komponen, channel = `$l->channel`). Signature TETAP.

- [ ] **Step 1: Tes gagal**

```php
// ChannelStockTest (unit), pola sama AvailabilityTest
public function test_channel_stock_override_menang_atas_master(): void
{
    $p = Product::create([...]);                       // helper spt tes lain
    app(MarketplaceStockService::class)->setPool($p, 50);           // master 50
    $this->assertSame(50, app(MarketplaceStockService::class)->channelStock($p->id,'tiktok'));
    app(MarketplaceStockService::class)->setChannelOverride($p,'tiktok',8);
    $this->assertSame(8, app(MarketplaceStockService::class)->channelStock($p->id,'tiktok')); // tiktok override
    $this->assertSame(50, app(MarketplaceStockService::class)->channelStock($p->id,'shopee')); // shopee tetap master
}
public function test_available_for_listing_channel_aware(): void
{
    $p = Product::create([...'sku'=>'FM-1']);
    TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    ShopeeSkuMap::create(['shopee_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    $svc = app(MarketplaceStockService::class);
    $svc->setPool($p, 20);
    $svc->setChannelOverride($p, 'tiktok', 5);
    $tt = MarketplaceListing::create(['channel'=>'tiktok','seller_sku'=>'FM-1']);
    $sp = MarketplaceListing::create(['channel'=>'shopee','seller_sku'=>'FM-1']);
    $this->assertSame(5, $svc->availableForListing($tt));   // pakai override tiktok
    $this->assertSame(20, $svc->availableForListing($sp));  // shopee tetap master
}
public function test_clear_override_balik_ikut_master(): void
{
    $p = Product::create([...]); $svc = app(MarketplaceStockService::class);
    $svc->setPool($p, 30); $svc->setChannelOverride($p,'tiktok',3);
    $svc->clearChannelOverride($p,'tiktok');
    $this->assertSame(30, $svc->channelStock($p->id,'tiktok'));
    $this->assertSame(0, MarketplaceChannelOverride::where('product_id',$p->id)->count());
}
```

- [ ] **Step 2: GAGAL.**
- [ ] **Step 3: Implementasi** (tambah method + rework availableForListing)

```php
public function channelStock(int $productId, string $channel): ?int
{
    $ov = MarketplaceChannelOverride::where('product_id', $productId)->where('channel', $channel)->first();
    if ($ov) return (int) $ov->quantity;
    $master = MarketplaceStock::where('product_id', $productId)->first();
    return $master ? (int) $master->quantity : null;
}

public function setChannelOverride(Product $product, string $channel, int $qty): MarketplaceChannelOverride
{
    return MarketplaceChannelOverride::updateOrCreate(
        ['product_id' => $product->id, 'channel' => $channel],
        ['quantity' => max(0, $qty), 'seeded_at' => now()],
    );
}

public function clearChannelOverride(Product $product, string $channel): void
{
    MarketplaceChannelOverride::where('product_id', $product->id)->where('channel', $channel)->delete();
}
```
Rework `availableForListing` — ganti lookup master jadi `channelStock(component, $l->channel)`:
```php
public function availableForListing(MarketplaceListing $l): ?int
{
    $components = $this->componentsFor($l->channel, $l->seller_sku);
    if (! $components) return null;
    $avail = null;
    foreach ($components as $c) {
        $stock = $this->channelStock($c['product_id'], $l->channel);
        if ($stock === null) return null; // master & override dua-duanya kosong → anti-push-0
        $canMake = intdiv(max(0, $stock), $c['qty']);
        $avail = $avail === null ? $canMake : min($avail, $canMake);
    }
    return $avail;
}
```
> `use App\Models\MarketplaceChannelOverride;`. `setPool`/`adjustPool` (master) TETAP. Jangan ubah `applyOrderDelta` di task ini (Task 3).

- [ ] **Step 4: LULUS** — jalankan `--filter=ChannelStockTest` DAN `--filter=AvailabilityTest` (yang lama harus tetap hijau: tanpa override, `channelStock`=master → `availableForListing` sama seperti Fase 1).
- [ ] **Step 5: Pint + commit** (`feat(stok-mp): stok efektif channel-aware + set/clear override`).

---

### Task 3: Cermin order channel-aware (`applyOrderDelta` + hook)

**Files:**
- Modify: `app/Services/MarketplaceStockService.php` (`applyOrderDelta`)
- Modify: `app/Services/TikTokOrderService.php`, `app/Services/ShopeeOrderService.php` (kirim channel)
- Reconcile: `tests/Unit/MarketplaceStock/AvailabilityTest.php` (panggilan langsung `applyOrderDelta` lama → tambah channel)
- Test: `tests/Feature/MarketplaceStock/ChannelOrderMirrorTest.php`

**Interfaces — Produces:** `applyOrderDelta(Product $p, string $channel, int $delta, \Illuminate\Support\Carbon $orderCreatedAt): void`.

- [ ] **Step 1: Tes gagal**

```php
// ChannelOrderMirrorTest
public function test_order_di_channel_override_turunin_override_bukan_master(): void
{
    $p = Product::create([...'sku'=>'FM-1']);
    TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    $svc = app(MarketplaceStockService::class);
    $svc->setPool($p, 20);                 // master 20, seeded
    $svc->setChannelOverride($p,'tiktok',8); // override tiktok 8, seeded
    app(\App\Services\TikTokOrderService::class)->store([[
        'id'=>'O1','status'=>'AWAITING_SHIPMENT','create_time'=>now()->addMinute()->timestamp,
        'payment'=>['total_amount'=>1,'currency'=>'IDR'],
        'line_items'=>[['seller_sku'=>'FM-1','quantity'=>3,'product_name'=>'FM']],
    ]]);
    $this->assertSame(5, MarketplaceChannelOverride::where('product_id',$p->id)->where('channel','tiktok')->value('quantity')); // 8-3
    $this->assertSame(20, MarketplaceStock::where('product_id',$p->id)->value('quantity')); // master TETAP
}
public function test_order_channel_ikut_master_turunin_master(): void
{
    // sama, TANPA setChannelOverride → order tiktok turunin MASTER (20-3=17); HQ tetap
    // assert StockMovement::count() tak berubah
}
```

- [ ] **Step 2: GAGAL.**
- [ ] **Step 3: Rework `applyOrderDelta`**

```php
public function applyOrderDelta(Product $product, string $channel, int $delta, \Illuminate\Support\Carbon $orderCreatedAt): void
{
    $ov = MarketplaceChannelOverride::where('product_id', $product->id)->where('channel', $channel)->first();
    if ($ov) {
        if ($ov->seeded_at === null || $orderCreatedAt->lt($ov->seeded_at)) return;
        $ov->update(['quantity' => max(0, (int) $ov->quantity + $delta)]);
        return;
    }
    // ikut master → persis Fase 1
    $row = MarketplaceStock::where('product_id', $product->id)->first();
    if (! $row || $row->seeded_at === null || $orderCreatedAt->lt($row->seeded_at)) return;
    $row->update(['quantity' => max(0, (int) $row->quantity + $delta)]);
}
```

- [ ] **Step 4: Update hook order** — di `TikTokOrderService::mirrorMarketplace`, ubah panggilan jadi:
```php
$this->marketplace->applyOrderDelta($c['product'], 'tiktok', $sign * $c['qty'] * (int) $it['qty'], $createdAt);
```
Di `ShopeeOrderService::mirrorMarketplace` → channel `'shopee'`.

- [ ] **Step 5: Reconcile tes lama** — `AvailabilityTest` memanggil `applyOrderDelta($p, -2, now())` (3 arg). Ubah jadi 4 arg dgn channel, mis. `applyOrderDelta($p, 'tiktok', -2, now())`; karena produk itu tak punya override → jatuh ke master → hasil sama seperti sebelumnya (tes tetap valid).

- [ ] **Step 6: LULUS** — `--filter=ChannelOrderMirrorTest`, `--filter=AvailabilityTest`, `--filter=OrderMirrorTest` (yang Fase 1 lewat store() harus tetap hijau: tanpa override, order → master turun). Pastikan `StockMovement` (HQ) tak berubah.
- [ ] **Step 7: Pint + commit** (`feat(stok-mp): cermin order channel-aware (override vs master), HQ tetap`).

---

### Task 4: Menu sidebar 3 item + halaman channel (override + Ikut Master) + status di Master

**Files:**
- Modify: `app/Http/Controllers/MarketplaceStockController.php`
- Modify: `routes/web.php`
- Create: `resources/views/marketplace-stock/channel.blade.php`
- Modify: `resources/views/marketplace-stock/index.blade.php` (kolom status: Ikut Master / Override: N)
- Modify: `resources/views/layouts/app.blade.php` (sidebar 3 item)
- Test: `tests/Feature/MarketplaceStock/ChannelPageTest.php`

**Interfaces — Produces:** rute `marketplace-stock.channel` (GET `/marketplace-stock/{channel}`), `marketplace-stock.override` (POST `/marketplace-stock/{channel}/override/{product}`), `marketplace-stock.ikut-master` (POST `/marketplace-stock/{channel}/ikut-master/{product}`).

- [ ] **Step 1: Tes gagal**

```php
// ChannelPageTest (pakai admin() + product() helper spt MarketplaceUiTest)
public function test_halaman_channel_render_dan_set_override_hanya_channel_itu(): void
{
    $p = $this->product('FM-1','Face Mist');
    TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    MarketplaceListing::create(['channel'=>'tiktok','seller_sku'=>'FM-1','item_id'=>'PID1','variation_id'=>'SID1','warehouse_id'=>'WH1','resolved_at'=>now()]);
    TiktokConnection::create(['shop_id'=>'s','shop_cipher'=>'c','access_token'=>'t','refresh_token'=>'r','access_expires_at'=>now()->addDay()]);
    app(MarketplaceStockService::class)->setPool($p, 20);
    Http::fake(['*'=>Http::response(['code'=>0,'data'=>[]])]);

    $this->actingAs($this->admin())->get('/marketplace-stock/tiktok')->assertOk()->assertSee('Face Mist');
    $this->actingAs($this->admin())->post("/marketplace-stock/tiktok/override/{$p->id}", ['quantity'=>5])->assertRedirect();
    $this->assertSame(5, MarketplaceChannelOverride::where('product_id',$p->id)->where('channel','tiktok')->value('quantity'));
    $this->assertSame(20, MarketplaceStock::where('product_id',$p->id)->value('quantity')); // master tak berubah
}
public function test_ikut_master_hapus_override(): void
{
    $p=$this->product('FM-1','Face Mist'); TiktokSkuMap::create(['tiktok_sku'=>'FM-1','product_id'=>$p->id,'qty'=>1]);
    app(MarketplaceStockService::class)->setChannelOverride($p,'tiktok',5);
    Http::fake();
    $this->actingAs($this->admin())->post("/marketplace-stock/tiktok/ikut-master/{$p->id}")->assertRedirect();
    $this->assertSame(0, MarketplaceChannelOverride::where('product_id',$p->id)->count());
}
public function test_channel_invalid_404(): void
{
    $this->actingAs($this->admin())->get('/marketplace-stock/lazada')->assertNotFound();
}
```

- [ ] **Step 2: GAGAL.**
- [ ] **Step 3: Controller** — tambah:
```php
public function channel(string $channel, MarketplaceStockService $svc): View
{
    abort_unless(in_array($channel, ['tiktok','shopee'], true), 404);
    $mapModel = $channel === 'tiktok' ? TiktokSkuMap::class : ShopeeSkuMap::class;
    $productIds = $mapModel::distinct()->pluck('product_id')->unique()->values();
    $products = Product::whereIn('id', $productIds)->orderBy('name')->get();
    $overrides = MarketplaceChannelOverride::where('channel', $channel)->whereIn('product_id', $productIds)->get()->keyBy('product_id');
    $rows = $products->map(fn (Product $p) => [
        'product' => $p,
        'override' => $overrides->has($p->id) ? (int) $overrides[$p->id]->quantity : null,
        'effective' => $svc->channelStock($p->id, $channel),
        'listing' => $this->listingFor($svc, $channel, $p),
    ]);
    return view('marketplace-stock.channel', [
        'channel' => $channel, 'rows' => $rows,
        'unmapped' => MarketplaceListing::where('channel', $channel)->where('last_status', 'unmapped')->get(),
        'products' => Product::orderBy('name')->get(['id', 'name', 'sku']),
    ]);
}
public function setOverride(Request $r, string $channel, Product $product, MarketplaceStockService $svc): RedirectResponse
{
    abort_unless(in_array($channel, ['tiktok','shopee'], true), 404);
    $r->validate(['quantity' => ['required','integer','min:0']]);
    $svc->setChannelOverride($product, $channel, (int) $r->quantity);
    $svc->pushProduct($product);
    return back()->with('status', "Stok {$channel} — {$product->name} disetel sendiri.");
}
public function ikutMaster(string $channel, Product $product, MarketplaceStockService $svc): RedirectResponse
{
    abort_unless(in_array($channel, ['tiktok','shopee'], true), 404);
    $svc->clearChannelOverride($product, $channel);
    $svc->pushProduct($product);
    return back()->with('status', "Stok {$channel} — {$product->name} kembali ikut Master.");
}
```
> `pushProduct` bisa lempar? Tidak — `pushListing` sudah try/catch internal (Fase 1). Aman.

- [ ] **Step 4: Rute** (grup `permission:manage_marketplace_stock`):
```php
Route::get('/marketplace-stock/{channel}', [MarketplaceStockController::class, 'channel'])->name('marketplace-stock.channel');
Route::post('/marketplace-stock/{channel}/override/{product}', [MarketplaceStockController::class, 'setOverride'])->name('marketplace-stock.override');
Route::post('/marketplace-stock/{channel}/ikut-master/{product}', [MarketplaceStockController::class, 'ikutMaster'])->name('marketplace-stock.ikut-master');
```
> Taruh SEBELUM rute lain yang bisa bentrok; `{channel}` string, aman karena `/marketplace-stock/set/{product}` dll pakai segmen literal berbeda. Pastikan tak bentrok dgn `marketplace-stock.index` (`/marketplace-stock`).

- [ ] **Step 5: View `channel.blade.php`** — mirip index tapi: judul "Stok {{ ucfirst($channel) }}"; tabel per produk: nama+SKU, **stok efektif** channel, input set override (POST `.override`) + badge **Override**/**Ikut Master**, tombol **Ikut Master** (POST `.ikut-master`, muncul kalau sedang override), listing seller_sku+status, per-baris **Sinkron** (POST `.push` yg sudah ada). Header: Refresh listing, Sinkron semua. Bagian "Listing belum terpetakan" channel ini (dengan inline linking — Task 5). Flash status/error. Tanpa `@json([...])`.

- [ ] **Step 6: Master page** (`index.blade.php`) — di kolom TikTok/Shopee, tampilkan penanda **"Override: N"** kalau produk itu punya override channel tsb (biar kelihatan mana yang mandiri). Controller `index()` sudah punya listing; tambahkan lookup override per baris (mis. `MarketplaceChannelOverride` di-key per product+channel) — tambah ke `$rows`.

- [ ] **Step 7: Sidebar** (`layouts/app.blade.php`) — ganti 1 item "Stok Marketplace" jadi **3 item** di grup Integrasi, tiap gate `@if($u->canDo('manage_marketplace_stock'))` (ikut pola item existing):
  - "Stok Master" → `route('marketplace-stock.index')`, aktif `routeIs('marketplace-stock.index')`
  - "Stok TikTok" → `route('marketplace-stock.channel','tiktok')`, aktif saat di halaman channel tiktok
  - "Stok Shopee" → `route('marketplace-stock.channel','shopee')`
  (Salin markup/kelas item sibling persis. Grup accordion Integrasi sudah meng-OR `manage_marketplace_stock`.)

- [ ] **Step 8: LULUS** (`--filter=ChannelPageTest` + `--filter=MarketplaceStock`). **Step 9: Pint + commit** (`feat(stok-mp): halaman channel (override + Ikut Master) + sidebar Master/TikTok/Shopee`).

---

### Task 5: Tautkan SKU belum terpetakan langsung dari halaman (inline)

**Files:**
- Modify: `app/Services/MarketplaceStockService.php` (`linkSku`)
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (`tautkan`)
- Modify: `routes/web.php`
- Modify: `resources/views/marketplace-stock/index.blade.php` + `channel.blade.php` (form tautkan di seksi "Listing belum terpetakan")
- Test: `tests/Feature/MarketplaceStock/LinkSkuTest.php`

**Interfaces — Produces:** `MarketplaceStockService::linkSku(string $channel, string $sellerSku, int $productId, int $qty): void`; rute `marketplace-stock.tautkan` (POST `/marketplace-stock/{channel}/tautkan`).

- [ ] **Step 1: Tes gagal**

```php
public function test_tautkan_membuat_peta_sku_dan_hilang_dari_unmapped(): void
{
    $p = $this->product('FM-1','Face Mist');
    MarketplaceListing::create(['channel'=>'tiktok','seller_sku'=>'ZZZ','item_id'=>'PID9','variation_id'=>'S9','last_status'=>'unmapped','resolved_at'=>now()]);

    $this->actingAs($this->admin())->post('/marketplace-stock/tiktok/tautkan', [
        'seller_sku'=>'ZZZ','product_id'=>$p->id,'qty'=>1,
    ])->assertRedirect();

    $this->assertSame($p->id, \App\Models\TiktokSkuMap::where('tiktok_sku','ZZZ')->value('product_id'));
    // listing tak lagi 'unmapped'
    $this->assertNotSame('unmapped', MarketplaceListing::where('seller_sku','ZZZ')->value('last_status'));
}
public function test_tautkan_channel_invalid_404(): void
{
    $p=$this->product('FM-1','Face Mist');
    $this->actingAs($this->admin())->post('/marketplace-stock/lazada/tautkan',['seller_sku'=>'X','product_id'=>$p->id,'qty'=>1])->assertNotFound();
}
```

- [ ] **Step 2: GAGAL.**
- [ ] **Step 3: Service**

```php
public function linkSku(string $channel, string $sellerSku, int $productId, int $qty): void
{
    if ($channel === 'tiktok') {
        TiktokSkuMap::firstOrCreate(['tiktok_sku' => $sellerSku, 'product_id' => $productId], ['qty' => max(1, $qty)]);
    } else {
        ShopeeSkuMap::firstOrCreate(['shopee_sku' => $sellerSku, 'product_id' => $productId], ['qty' => max(1, $qty)]);
    }
    MarketplaceListing::where('channel', $channel)->where('seller_sku', $sellerSku)
        ->where('last_status', 'unmapped')->update(['last_status' => null]);
}
```
> `firstOrCreate` biar aman kalau komponen sudah ada (bundle multi-komponen = beberapa kali tautkan produk berbeda utk seller_sku sama).

- [ ] **Step 4: Controller**

```php
public function tautkan(Request $request, string $channel, MarketplaceStockService $svc): RedirectResponse
{
    abort_unless(in_array($channel, ['tiktok','shopee'], true), 404);
    $data = $request->validate([
        'seller_sku' => ['required','string'],
        'product_id' => ['required','integer','exists:products,id'],
        'qty' => ['required','integer','min:1'],
    ]);
    $svc->linkSku($channel, $data['seller_sku'], (int) $data['product_id'], (int) $data['qty']);
    return back()->with('status', "SKU {$data['seller_sku']} ditautkan ke produk.");
}
```

- [ ] **Step 5: Rute** (grup izin): `Route::post('/marketplace-stock/{channel}/tautkan', [MarketplaceStockController::class, 'tautkan'])->name('marketplace-stock.tautkan');`

- [ ] **Step 6: UI** — di seksi "Listing belum terpetakan" (index & channel), tiap baris tambah form POST `.tautkan`: hidden `seller_sku` + hidden/again `channel` (dari route param), **native `<select name="product_id">`** berisi `$products` (label `{{ $p->name }} ({{ $p->sku }})`), input `qty` (default 1), tombol **Tautkan**. `$products` sudah dikirim controller (index perlu ditambah `'products' => Product::orderBy('name')->get(['id','name','sku'])`). Tanpa `@json([...])`.

- [ ] **Step 7: LULUS** (`--filter=LinkSkuTest` + full `--filter=MarketplaceStock`). **Step 8: Pint + commit** (`feat(stok-mp): tautkan SKU belum terpetakan langsung dari halaman stok`).

---

## Self-Review (penulis rencana)

**1. Cakupan spec:** override model (T1 tabel, T2 stok efektif, T3 order routing), 3 menu + halaman channel + Ikut Master (T4), inline SKU linking (T5). Semua bagian spec tercakup.

**2. Placeholder:** migrasi `000140` (verifikasi Task 1). Tes helper `product()`/`admin()` = pola yang SUDAH ada di `MarketplaceUiTest`. Tak ada TODO.

**3. Konsistensi tipe:** `channelStock(int,string):?int`, `setChannelOverride(Product,string,int)`, `clearChannelOverride(Product,string)`, `applyOrderDelta(Product,string,int,Carbon)`, `linkSku(string,string,int,int)` — dipakai konsisten lintas task. `availableForListing(MarketplaceListing):?int` signature TETAP (hanya isi channel-aware). Hook order Fase 1 di-update untuk signature `applyOrderDelta` baru (T3).

**4. Ambiguitas/risiko:** (a) perubahan signature `applyOrderDelta` memaksa reconcile tes/hook Fase 1 — ditangani eksplisit di T3 Step 4-5. (b) `availableForListing` tanpa override = identik Fase 1 → tes Fase 1 tetap hijau (dicek T2 Step 4). (c) Anti-push-0 tetap (channelStock null → null). (d) HQ tak disentuh (T3 tes cek StockMovement). (e) Rute `{channel}` tak bentrok dgn segmen literal existing.
