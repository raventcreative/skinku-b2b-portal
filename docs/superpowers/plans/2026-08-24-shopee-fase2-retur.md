# Shopee Fase 2 (Retur) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retur Shopee parity dengan TikTok — tarik retur dari API otomatis, review manual, restock (layak jual → +stok) / reject (cacat → no stok). Stok saja, tanpa jurnal.

**Architecture:** Mirror `TikTokReturnService` 1:1 di atas backend Shopee Fase 1. Reuse `ShopeeOrderService::resolve` (resep SKU), `InventoryService::adjustHqStock`, `ShopeeSyncService::freshToken`, izin `manage_shopee`. Marketplace = channel HQ → stok masuk HQ.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Eloquent. Runner: `C:/php83/php.exe artisan test`. Formatter: `C:/php83/php.exe vendor/bin/pint --dirty` sebelum tiap commit.

## Global Constraints

- **Zero-dependency**: tanpa composer/npm baru; HTTP pakai `ShopeeClient` (Laravel Http).
- **Mirror TikTok**: nama method/alur/UI ikut `TikTokReturnService` + `tiktok/returns.blade.php`. Referensi lengkap: `docs/superpowers/research/tiktok-fase2-4-map.md`.
- **Jangan ubah yang jalan**: `ShopeeOrderService` (kecuali `skusNeedingMap`), `ShopeeClient` (kecuali TAMBAH 2 method), model Fase 1 dipakai apa adanya.
- **Retur = stok saja**: TIDAK ada `AccJournal` di fase ini (identik TikTok).
- **Idempoten**: restock guard `REVIEW_RESTOCKED`.
- **Deploy = git pull**: 1 migrasi baru `000093`.

---

### Task 1: Model `ShopeeReturn` + migrasi `000093`

**Files:**
- Create: `database/migrations/2026_01_01_000093_create_shopee_returns_table.php`
- Create: `app/Models/ShopeeReturn.php`
- Test: `tests/Feature/ShopeeReturnTest.php`

**Interfaces:**
- Produces: model `App\Models\ShopeeReturn` dengan konstanta `REVIEW_PENDING='pending'`, `REVIEW_RESTOCKED='restocked'`, `REVIEW_REJECTED='rejected'`; kolom `shopee_return_sn`, `shopee_order_sn`, `status`, `return_reason`, `line_items` (array), `review_status`, `review_note`, `return_created_at`, `reviewed_at`, `reviewed_by`.

- [ ] **Step 1: Write the failing test**

Buat `tests/Feature/ShopeeReturnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ShopeeReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_menyimpan_dan_cast_line_items(): void
    {
        $r = ShopeeReturn::create([
            'shopee_return_sn' => 'R-1',
            'shopee_order_sn' => 'S-1',
            'status' => 'ACCEPTED',
            'return_reason' => 'Rusak',
            'line_items' => [['sku' => 'A', 'name' => 'Produk A', 'qty' => 2]],
            'review_status' => ShopeeReturn::REVIEW_PENDING,
        ]);

        $this->assertSame('R-1', $r->shopee_return_sn);
        $this->assertSame('pending', $r->review_status);
        $this->assertIsArray($r->fresh()->line_items);
        $this->assertSame(2, $r->fresh()->line_items[0]['qty']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php83/php.exe artisan test --filter=ShopeeReturnTest`
Expected: FAIL — "Class ShopeeReturn not found" / tabel `shopee_returns` tak ada.

- [ ] **Step 3: Write the migration**

Buat `database/migrations/2026_01_01_000093_create_shopee_returns_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_returns', function (Blueprint $table) {
            $table->id();
            $table->string('shopee_return_sn')->unique();
            $table->string('shopee_order_sn')->nullable()->index();
            $table->string('status')->nullable();
            $table->string('return_reason')->nullable();
            $table->json('line_items')->nullable();
            $table->string('review_status', 20)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamp('return_created_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_returns');
    }
};
```

- [ ] **Step 4: Write the model**

Buat `app/Models/ShopeeReturn.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeReturn extends Model
{
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_RESTOCKED = 'restocked';   // layak jual → stok ditambah

    public const REVIEW_REJECTED = 'rejected';     // cacat → tidak masuk stok

    protected $fillable = [
        'shopee_return_sn', 'shopee_order_sn', 'status', 'return_reason', 'line_items',
        'review_status', 'review_note', 'return_created_at', 'reviewed_at', 'reviewed_by',
    ];

    protected $casts = [
        'line_items' => 'array',
        'return_created_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `C:/php83/php.exe artisan test --filter=ShopeeReturnTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Models/ShopeeReturn.php database/migrations/2026_01_01_000093_create_shopee_returns_table.php tests/Feature/ShopeeReturnTest.php
git commit -m "feat(shopee) Fase 2: model ShopeeReturn + migrasi shopee_returns"
```

---

### Task 2: `ShopeeReturnService` (logika stok retur)

**Files:**
- Create: `app/Services/ShopeeReturnService.php`
- Test: `tests/Feature/ShopeeReturnTest.php` (tambah)

**Interfaces:**
- Consumes: `ShopeeOrderService::resolve(?string $sku): array` (return `[['product'=>Product,'qty'=>int]]`); `InventoryService::adjustHqStock(Product $p, int $qty, string $type, string $note, string $refType, int $refId): void`; `StockMovement::TYPE_IN`/`TYPE_OUT`; `App\Models\ShopeeReturn`.
- Produces: `ShopeeReturnService` dengan `store(array): int`, `normalizeItems(array): array`, `preview(ShopeeReturn): array` (`{lines, all_matched}`), `restock(ShopeeReturn,int,?string): void`, `reject(ShopeeReturn,int,?string): void`, `resetReview(ShopeeReturn): void`.

- [ ] **Step 1: Write the failing test** (tambah ke `ShopeeReturnTest.php`)

Tambah `use` dan helper + test (mirror TikTok `test_return_restock_adds_stock_reject_does_not_reverse_pulls_back`):

```php
use App\Models\Product;
use App\Models\ShopeeSkuMap;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ShopeeReturnService;
use Illuminate\Support\Facades\Hash;
```

```php
private function admin(): User
{
    return User::create([
        'name' => 'Admin Retur', 'fullname' => 'Admin Retur', 'username' => 'shopeereturnadmin',
        'email' => 'shopeereturnadmin@skinku.test', 'password' => Hash::make('secret123'),
        'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
    ]);
}

private function product(int $stock = 100): Product
{
    return Product::create([
        'name' => 'Produk Retur', 'sku' => 'RTR-1', 'hq_stock' => $stock, 'status' => 'active',
        'cogs' => 30000, 'price_distributor' => 1, 'price_reseller' => 1,
    ]);
}

private function returnFor(Product $p, int $qty = 2, string $sn = 'R-10'): ShopeeReturn
{
    ShopeeSkuMap::firstOrCreate(['shopee_sku' => 'RSKU', 'product_id' => $p->id], ['qty' => 1]);

    return ShopeeReturn::create([
        'shopee_return_sn' => $sn, 'shopee_order_sn' => 'S-10', 'status' => 'ACCEPTED',
        'line_items' => [['sku' => 'RSKU', 'name' => 'Produk Retur', 'qty' => $qty]],
        'review_status' => ShopeeReturn::REVIEW_PENDING,
    ]);
}

public function test_restock_menambah_stok_reject_tidak_reset_menarik_kembali(): void
{
    $p = $this->product(100);
    $admin = $this->admin();
    $svc = app(ShopeeReturnService::class);

    $ret = $this->returnFor($p, 2, 'R-A');
    $svc->restock($ret, $admin->id);
    $this->assertEquals(102, $p->fresh()->hq_stock);
    $this->assertDatabaseHas('stock_movements', [
        'reference_type' => 'shopee_return', 'reference_id' => $ret->id,
    ]);
    $this->assertSame(ShopeeReturn::REVIEW_RESTOCKED, $ret->fresh()->review_status);

    // restock lagi = idempoten (tak dobel)
    $svc->restock($ret->fresh(), $admin->id);
    $this->assertEquals(102, $p->fresh()->hq_stock);

    // reject retur lain (pending) tak ubah stok
    $ret2 = $this->returnFor($p, 5, 'R-B');
    $svc->reject($ret2, $admin->id);
    $this->assertEquals(102, $p->fresh()->hq_stock);
    $this->assertSame(ShopeeReturn::REVIEW_REJECTED, $ret2->fresh()->review_status);

    // reset yang sudah restocked → tarik stok lagi
    $svc->resetReview($ret->fresh());
    $this->assertEquals(100, $p->fresh()->hq_stock);
    $this->assertSame(ShopeeReturn::REVIEW_PENDING, $ret->fresh()->review_status);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php83/php.exe artisan test --filter=ShopeeReturnTest`
Expected: FAIL — "Class ShopeeReturnService not found".

- [ ] **Step 3: Write `ShopeeReturnService`**

Buat `app/Services/ShopeeReturnService.php` (mirror `TikTokReturnService`):

```php
<?php

namespace App\Services;

use App\Models\ShopeeReturn;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Retur Shopee: tarik dari API (otomatis), lalu review MANUAL sebelum stok ditambah —
 * cuma barang yang masih layak jual yang di-restock; yang cacat ditolak (tidak masuk stok).
 * Pakai "resep SKU" yang sama (ShopeeOrderService::resolve) untuk konversi ke produk SKINKU.
 * Stok saja — retur TIDAK menyentuh akuntansi (identik pola TikTok).
 */
class ShopeeReturnService
{
    public function __construct(
        private ShopeeOrderService $orders,
        private InventoryService $inventory,
    ) {}

    public function store(array $apiReturns): int
    {
        $n = 0;
        foreach ($apiReturns as $r) {
            $sn = $r['return_sn'] ?? ($r['return_id'] ?? null);
            if (! $sn) {
                continue;
            }
            $existing = ShopeeReturn::where('shopee_return_sn', $sn)->first();

            ShopeeReturn::updateOrCreate(
                ['shopee_return_sn' => (string) $sn],
                [
                    'shopee_order_sn' => $r['order_sn'] ?? null,
                    'status' => $r['status'] ?? ($r['return_status'] ?? null),
                    'return_reason' => $r['reason'] ?? ($r['return_reason'] ?? null),
                    'line_items' => $this->normalizeItems($r),
                    'return_created_at' => isset($r['create_time']) ? Carbon::createFromTimestamp((int) $r['create_time']) : null,
                    // jangan reset hasil review yang sudah diputuskan
                    'review_status' => $existing->review_status ?? ShopeeReturn::REVIEW_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** item retur Shopee → [{sku, name, qty}] (agregasi per SKU). */
    public function normalizeItems(array $ret): array
    {
        $items = $ret['item'] ?? ($ret['return_line_items'] ?? ($ret['line_items'] ?? []));
        $agg = [];
        foreach ($items as $li) {
            $sku = $li['model_sku'] ?? null;
            if (! $sku) {
                $sku = $li['item_sku'] ?? ($li['item_name'] ?? '—');
            }
            $qty = (int) ($li['amount'] ?? ($li['quantity'] ?? ($li['return_quantity'] ?? 1)));
            $agg[$sku] ??= ['sku' => (string) $sku, 'name' => $li['item_name'] ?? '', 'qty' => 0];
            $agg[$sku]['qty'] += $qty;
        }

        return array_values($agg);
    }

    /** Pratinjau: tiap item retur → komponen produk & qty (pakai resep SKU). */
    public function preview(ShopeeReturn $return): array
    {
        $lines = [];
        $allMatched = true;
        foreach ($return->line_items ?? [] as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $comps = $this->orders->resolve($item['sku'] ?? null);
            if (! $comps) {
                $allMatched = false;
            }
            $lines[] = [
                'sku' => $item['sku'] ?? '—',
                'qty' => $qty,
                'components' => array_map(fn ($c) => ['product' => $c['product'], 'add' => $c['qty'] * $qty], $comps),
            ];
        }

        return ['lines' => $lines, 'all_matched' => $allMatched && count($lines) > 0];
    }

    /** APPROVE layak jual → tambah stok. Idempoten (skip kalau sudah restocked). */
    public function restock(ShopeeReturn $return, int $userId, ?string $note = null): void
    {
        if ($return->review_status === ShopeeReturn::REVIEW_RESTOCKED) {
            return;
        }
        $pv = $this->preview($return);
        if (! $pv['all_matched']) {
            throw new RuntimeException('Ada SKU retur yang belum dipetakan ke produk.');
        }

        DB::transaction(function () use ($return, $pv, $userId, $note) {
            foreach ($pv['lines'] as $l) {
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], (int) $c['add'], StockMovement::TYPE_IN,
                        "Retur Shopee {$return->shopee_return_sn} (layak jual)", 'shopee_return', $return->id,
                    );
                }
            }
            $return->update([
                'review_status' => ShopeeReturn::REVIEW_RESTOCKED,
                'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $userId,
            ]);
        });
    }

    /** TOLAK (cacat/tidak layak) → tidak menambah stok. */
    public function reject(ShopeeReturn $return, int $userId, ?string $note = null): void
    {
        if ($return->review_status === ShopeeReturn::REVIEW_RESTOCKED) {
            $this->pullBack($return);
        }
        $return->update([
            'review_status' => ShopeeReturn::REVIEW_REJECTED,
            'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $userId,
        ]);
    }

    /** Kembalikan ke "pending" (batalkan keputusan); kalau restocked, tarik stok lagi. */
    public function resetReview(ShopeeReturn $return): void
    {
        if ($return->review_status === ShopeeReturn::REVIEW_RESTOCKED) {
            $this->pullBack($return);
        }
        $return->update(['review_status' => ShopeeReturn::REVIEW_PENDING, 'review_note' => null, 'reviewed_at' => null, 'reviewed_by' => null]);
    }

    private function pullBack(ShopeeReturn $return): void
    {
        $pv = $this->preview($return);
        DB::transaction(function () use ($return, $pv) {
            foreach ($pv['lines'] as $l) {
                foreach ($l['components'] as $c) {
                    $this->inventory->adjustHqStock(
                        $c['product'], -1 * (int) $c['add'], StockMovement::TYPE_OUT,
                        "Koreksi retur Shopee {$return->shopee_return_sn}", 'shopee_return', $return->id,
                    );
                }
            }
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php83/php.exe artisan test --filter=ShopeeReturnTest`
Expected: PASS (2 tes).

> **Catatan:** kalau `adjustHqStock` ternyata butuh argumen `occurredAt` posisional/opsional, cek tanda tangan aslinya di `app/Services/InventoryService.php` — TikTok retur memanggilnya TANPA `occurredAt`, jadi harusnya opsional. Sesuaikan bila perlu.

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/ShopeeReturnService.php tests/Feature/ShopeeReturnTest.php
git commit -m "feat(shopee) Fase 2: ShopeeReturnService (restock/reject/pullBack, resep SKU)"
```

---

### Task 3: `ShopeeClient` — API retur (`getReturnList` + `getReturnDetail`)

**Files:**
- Modify: `app/Services/ShopeeClient.php` (tambah 2 method setelah `getOrderDetail`, sebelum `// ---- internal ----`)
- Test: `tests/Feature/ShopeeReturnTest.php` (tambah)

**Interfaces:**
- Consumes: `ShopeeClient::shopCall(string $method, string $path, string $accessToken, string $shopId, array $params=[]): array` (sudah ada).
- Produces: `getReturnList(string $accessToken, string $shopId, int $from, int $to, int $pageNo=0, int $pageSize=50): array`; `getReturnDetail(string $accessToken, string $shopId, string $returnSn): array`.

- [ ] **Step 1: Write the failing test** (tambah ke `ShopeeReturnTest.php`)

```php
use App\Services\ShopeeClient;
use Illuminate\Support\Facades\Http;
```

```php
public function test_client_getreturnlist_kirim_sign_dan_path_benar(): void
{
    config([
        'services.shopee.partner_id' => '123',
        'services.shopee.partner_key' => 'secret',
        'services.shopee.api_base' => 'https://partner.example.com',
    ]);
    Http::fake([
        '*get_return_list*' => Http::response(['response' => ['return' => [], 'more' => false]]),
    ]);

    app(ShopeeClient::class)->getReturnList('ACCESS', 'SHOP', 100, 200, 0, 50);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/returns/get_return_list')
        && str_contains($req->url(), 'create_time_from=100')
        && str_contains($req->url(), 'sign=')
        && str_contains($req->url(), 'access_token=ACCESS'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php83/php.exe artisan test --filter=test_client_getreturnlist`
Expected: FAIL — "Method getReturnList does not exist".

- [ ] **Step 3: Add the client methods**

Di `app/Services/ShopeeClient.php`, setelah method `getOrderDetail` (tepat sebelum baris `// ---- internal ----`), tambah:

```php
/** Daftar retur dalam rentang waktu (batas ~15 hari, sama seperti order). */
public function getReturnList(string $accessToken, string $shopId, int $from, int $to, int $pageNo = 0, int $pageSize = 50): array
{
    return $this->shopCall('GET', '/api/v2/returns/get_return_list', $accessToken, $shopId, [
        'create_time_from' => $from,
        'create_time_to' => $to,
        'page_no' => $pageNo,
        'page_size' => $pageSize,
    ]);
}

/** Detail retur (per return_sn) — di sinilah item & alasannya. */
public function getReturnDetail(string $accessToken, string $shopId, string $returnSn): array
{
    return $this->shopCall('GET', '/api/v2/returns/get_return_detail', $accessToken, $shopId, [
        'return_sn' => $returnSn,
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php83/php.exe artisan test --filter=test_client_getreturnlist`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/ShopeeClient.php tests/Feature/ShopeeReturnTest.php
git commit -m "feat(shopee) Fase 2: ShopeeClient getReturnList + getReturnDetail"
```

---

### Task 4: `ShopeeSyncService::syncReturns` + command `--returns` + cron

**Files:**
- Modify: `app/Services/ShopeeSyncService.php` (tambah dep `ShopeeReturnService` + method `syncReturns`)
- Modify: `app/Console/Commands/ShopeeSyncCommand.php` (opsi `--returns`)
- Modify: `routes/console.php` (jadwal `shopee:sync --returns`)
- Test: `tests/Feature/ShopeeReturnTest.php` (tambah)

**Interfaces:**
- Consumes: `ShopeeClient::getReturnList/getReturnDetail` (Task 3); `ShopeeReturnService::store` (Task 2); `ShopeeSyncService::freshToken(ShopeeConnection): string` + property `$this->client` (ShopeeClient) (sudah ada dari Fase 1).
- Produces: `ShopeeSyncService::syncReturns(ShopeeConnection $conn): int`.

> **Baca dulu** `app/Services/ShopeeSyncService.php` untuk konstruktor & nama properti klien (mis. `$this->client`) dan `freshToken`. Tambah `ShopeeReturnService $returns` ke konstruktor mengikuti pola dep yang sudah ada.

- [ ] **Step 1: Write the failing test** (tambah ke `ShopeeReturnTest.php`)

```php
use App\Models\ShopeeConnection;
use App\Services\ShopeeSyncService;
```

```php
public function test_syncreturns_menyimpan_dari_client_fake(): void
{
    // Fake client: getReturnList 1 halaman, getReturnDetail balikin item.
    $client = new class extends ShopeeClient
    {
        public function __construct() {}

        public function getReturnList(string $a, string $s, int $f, int $t, int $p = 0, int $ps = 50): array
        {
            return ['response' => ['return' => [['return_sn' => 'RS-1', 'order_sn' => 'S-1']], 'more' => false]];
        }

        public function getReturnDetail(string $a, string $s, string $sn): array
        {
            return ['response' => [
                'return_sn' => 'RS-1', 'status' => 'ACCEPTED', 'reason' => 'DAMAGED',
                'item' => [['item_sku' => 'RSKU', 'item_name' => 'Produk Retur', 'amount' => 1]],
            ]];
        }
    };
    $this->app->instance(ShopeeClient::class, $client);

    $conn = ShopeeConnection::create([
        'shop_id' => '9', 'access_token' => 'A', 'refresh_token' => 'R',
        'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(30),
    ]);

    $n = app(ShopeeSyncService::class)->syncReturns($conn);

    $this->assertSame(1, $n);
    $this->assertDatabaseHas('shopee_returns', ['shopee_return_sn' => 'RS-1', 'status' => 'ACCEPTED']);
    $this->assertSame('DAMAGED', ShopeeReturn::where('shopee_return_sn', 'RS-1')->value('return_reason'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php83/php.exe artisan test --filter=test_syncreturns`
Expected: FAIL — "Method syncReturns does not exist".

- [ ] **Step 3: Add `syncReturns` to `ShopeeSyncService`**

Tambah `use App\Services\ShopeeReturnService;` + `use Illuminate\Support\Facades\Log;` (bila belum), tambah `private ShopeeReturnService $returns` ke konstruktor, lalu tambah method:

```php
/**
 * Tarik retur dari Shopee → store. Paginasi page_no (guard 40 halaman).
 * Merge field dari list + detail (detail punya item & alasan).
 */
public function syncReturns(ShopeeConnection $conn): int
{
    $access = $this->freshToken($conn);
    $to = now()->timestamp;
    $from = now()->subDays(14)->timestamp; // batas ~15 hari Shopee
    $all = [];
    $pageNo = 0;

    for ($guard = 0; $guard < 40; $guard++) {
        $res = $this->client->getReturnList($access, $conn->shop_id, $from, $to, $pageNo, 50);
        $list = $res['response']['return'] ?? [];
        foreach ($list as $r) {
            $sn = $r['return_sn'] ?? null;
            if (! $sn) {
                continue;
            }
            $detail = $this->client->getReturnDetail($access, $conn->shop_id, $sn);
            // detail lebih lengkap → menang; field list (order_sn dsb) jadi fallback
            $all[] = ($detail['response'] ?? []) + $r;
        }
        if (empty($res['response']['more'])) {
            break;
        }
        $pageNo++;
        if ($guard === 39) {
            Log::warning('[shopee] getReturnList mentok 40 halaman — data retur mungkin belum lengkap.');
        }
    }

    return $this->returns->store($all);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php83/php.exe artisan test --filter=test_syncreturns`
Expected: PASS.

- [ ] **Step 5: Add `--returns` to the command**

Di `app/Console/Commands/ShopeeSyncCommand.php`, ubah signature jadi:

```php
protected $signature = 'shopee:sync {--full : Abaikan filter waktu, sapu 14 hari terakhir} {--returns : Sekalian tarik retur}';
```

Di `handle()`, SETELAH blok sync order sukses (sebelum `return self::SUCCESS;` terakhir), tambah:

```php
if ($this->option('returns')) {
    try {
        $rn = $sync->syncReturns($conn);
        $this->info("Retur: {$rn} tersimpan.");
        Log::info("[shopee:sync] Retur: {$rn} tersimpan.");
    } catch (\Throwable $e) {
        $this->error('Gagal tarik retur: '.$e->getMessage());
        Log::error('[shopee:sync] retur gagal: '.$e->getMessage());

        return self::FAILURE;
    }
}
```

(Pastikan `use Illuminate\Support\Facades\Log;` ada — sudah ada di command Fase 1.)

- [ ] **Step 6: Add cron schedule**

Di `routes/console.php`, dekat entri `shopee:sync` yang sudah ada, tambah:

```php
// Retur Shopee cukup sekali sehari (jarang berubah, hemat kuota API).
Schedule::command('shopee:sync --returns')->dailyAt('01:15')->withoutOverlapping(30);
```

- [ ] **Step 7: Write cron + command test** (tambah ke `ShopeeReturnTest.php`)

```php
public function test_cron_menjadwalkan_shopee_sync_returns(): void
{
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command ?? '')
        ->filter(fn ($c) => str_contains($c, 'shopee:sync --returns'));

    $this->assertTrue($events->isNotEmpty(), 'shopee:sync --returns harus terjadwal');
}
```

- [ ] **Step 8: Run tests + Pint + commit**

Run: `C:/php83/php.exe artisan test --filter=ShopeeReturnTest`
Expected: PASS.

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/ShopeeSyncService.php app/Console/Commands/ShopeeSyncCommand.php routes/console.php tests/Feature/ShopeeReturnTest.php
git commit -m "feat(shopee) Fase 2: syncReturns + shopee:sync --returns + cron harian"
```

---

### Task 5: Controller + routes + view + `skusNeedingMap` (retur-only SKU)

**Files:**
- Modify: `app/Http/Controllers/ShopeeController.php` (dep `ShopeeReturnService` + 5 aksi retur)
- Modify: `routes/web.php` (5 route retur)
- Modify: `app/Services/ShopeeOrderService.php` (`skusNeedingMap` ikutkan `ShopeeReturn`)
- Create: `resources/views/shopee/returns.blade.php`
- Modify: `resources/views/shopee/index.blade.php` (link "Retur")
- Test: `tests/Feature/ShopeeReturnTest.php` (tambah)

**Interfaces:**
- Consumes: `ShopeeReturnService` (Task 2), `ShopeeSyncService::syncReturns` (Task 4), `ShopeeReturnService::preview`, `AuditService::log(...)` (sudah dipakai ShopeeController Fase 1), `ShopeeOrderService::isAutoMatched`.

> **Baca dulu** `app/Http/Controllers/ShopeeController.php` (konstruktor & cara `AuditService` dipanggil di Fase 1), `app/Services/ShopeeOrderService.php` (`skusNeedingMap` versi sekarang), dan `resources/views/tiktok/returns.blade.php` (template view).

- [ ] **Step 1: Write the failing test** (tambah ke `ShopeeReturnTest.php`)

```php
public function test_halaman_retur_render_dan_reseller_ditolak(): void
{
    $admin = $this->admin();
    $this->actingAs($admin)->get('/shopee/returns')->assertOk();

    $reseller = User::create([
        'name' => 'R', 'fullname' => 'R', 'username' => 'reseller_rtr',
        'email' => 'reseller_rtr@skinku.test', 'password' => Hash::make('secret123'),
        'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
    ]);
    $this->actingAs($reseller)->get('/shopee/returns')->assertForbidden();
}

public function test_restock_via_route_menambah_stok(): void
{
    $p = $this->product(100);
    $admin = $this->admin();
    $ret = $this->returnFor($p, 3, 'R-RT');

    $this->actingAs($admin)
        ->post("/shopee/returns/{$ret->id}/restock", ['note' => 'ok'])
        ->assertRedirect();

    $this->assertEquals(103, $p->fresh()->hq_stock);
    $this->assertSame(ShopeeReturn::REVIEW_RESTOCKED, $ret->fresh()->review_status);
}

public function test_sku_hanya_di_retur_muncul_di_skus_needing_map(): void
{
    // SKU 'ONLYRET' tak ada di produk & tak ada order; cuma muncul di retur.
    ShopeeReturn::create([
        'shopee_return_sn' => 'R-ONLY', 'status' => 'ACCEPTED',
        'line_items' => [['sku' => 'ONLYRET', 'name' => 'Cuma Retur', 'qty' => 1]],
        'review_status' => ShopeeReturn::REVIEW_PENDING,
    ]);

    $need = app(\App\Services\ShopeeOrderService::class)->skusNeedingMap();
    $this->assertArrayHasKey('ONLYRET', $need);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php83/php.exe artisan test --filter=ShopeeReturnTest`
Expected: FAIL — route `/shopee/returns` tak ada (404) + `ONLYRET` tak ada di skusNeedingMap.

- [ ] **Step 3: Extend `skusNeedingMap` to include returns**

Di `app/Services/ShopeeOrderService.php`, tambah `use App\Models\ShopeeReturn;` dan `use Illuminate\Support\Facades\Schema;` (bila belum), lalu ubah awal method `skusNeedingMap()` supaya menggabung sumber order + retur:

```php
public function skusNeedingMap(): array
{
    $out = [];
    $sources = ShopeeOrder::pluck('line_items');
    if (Schema::hasTable('shopee_returns')) {
        $sources = $sources->concat(ShopeeReturn::pluck('line_items'));
    }
    foreach ($sources as $items) {
        foreach ((array) $items as $it) {
            $sku = $it['sku'] ?? null;
            if (! $sku || $sku === '—' || isset($out[$sku]) || $this->isAutoMatched($sku)) {
                continue;
            }
            $out[$sku] = [
                'name' => $it['name'] ?? '',
                'components' => \App\Models\ShopeeSkuMap::with('product')->where('shopee_sku', $sku)->get(),
            ];
        }
    }

    return $out;
}
```

(Struktur perulangan bagian dalam TETAP sama seperti versi Fase 1 — cuma sumbernya digabung.)

- [ ] **Step 4: Add controller actions**

Di `app/Http/Controllers/ShopeeController.php`: tambah `use App\Models\ShopeeReturn;` + `use App\Services\ShopeeReturnService;`, tambahkan `ShopeeReturnService $returns` ke konstruktor (ikuti pola dep Fase 1 — simpan ke properti `$this->returns`), lalu tambah aksi:

```php
public function returnList()
{
    $returns = ShopeeReturn::latest('return_created_at')->latest('id')->paginate(25);
    $previews = [];
    foreach ($returns as $r) {
        $previews[$r->id] = $this->returns->preview($r);
    }

    return view('shopee.returns', compact('returns', 'previews'));
}

public function syncReturns(Request $request): \Illuminate\Http\RedirectResponse
{
    $conn = $this->sync->connection();
    if (! $conn) {
        return back()->with('error', 'Belum terhubung ke Shopee.');
    }
    try {
        $n = $this->sync->syncReturns($conn);

        return redirect()->route('shopee.returns')->with('status', "Retur ditarik: {$n}.");
    } catch (\Throwable $e) {
        return back()->with('error', 'Gagal tarik retur: '.$e->getMessage().' (cek izin scope Return di app Shopee).');
    }
}

public function restockReturn(Request $request, ShopeeReturn $ret): \Illuminate\Http\RedirectResponse
{
    try {
        $this->returns->restock($ret, $request->user()->id, $request->input('note'));
        $this->audit->log('shopee_return_restock', 'ShopeeReturn', $ret->id, ['sn' => $ret->shopee_return_sn]);

        return back()->with('status', 'Retur di-restock (stok ditambah).');
    } catch (\Throwable $e) {
        return back()->with('error', $e->getMessage());
    }
}

public function rejectReturn(Request $request, ShopeeReturn $ret): \Illuminate\Http\RedirectResponse
{
    $this->returns->reject($ret, $request->user()->id, $request->input('note'));
    $this->audit->log('shopee_return_reject', 'ShopeeReturn', $ret->id, ['sn' => $ret->shopee_return_sn]);

    return back()->with('status', 'Retur ditolak (tidak masuk stok).');
}

public function resetReturn(ShopeeReturn $ret): \Illuminate\Http\RedirectResponse
{
    $this->returns->resetReview($ret);

    return back()->with('status', 'Review retur direset.');
}
```

> **Cek** cara `AuditService::log` dipanggil di aksi Fase 1 (mis. `shopee_deduct_stock`) dan samakan urutan argumennya. Kalau tanda tangannya beda, sesuaikan pemanggilan di atas.

- [ ] **Step 5: Add routes**

Di `routes/web.php`, di dalam grup `permission:manage_shopee` (dekat route shopee lain), tambah:

```php
Route::get('/shopee/returns', [ShopeeController::class, 'returnList'])->name('shopee.returns');
Route::post('/shopee/returns/sync', [ShopeeController::class, 'syncReturns'])->name('shopee.returns.sync');
Route::post('/shopee/returns/{ret}/restock', [ShopeeController::class, 'restockReturn'])->name('shopee.returns.restock');
Route::post('/shopee/returns/{ret}/reject', [ShopeeController::class, 'rejectReturn'])->name('shopee.returns.reject');
Route::post('/shopee/returns/{ret}/reset', [ShopeeController::class, 'resetReturn'])->name('shopee.returns.reset');
```

- [ ] **Step 6: Create the view**

Buat `resources/views/shopee/returns.blade.php` (mirror `tiktok/returns.blade.php`; pakai layout & komponen yang sama dengan view shopee Fase 1). Struktur:

```blade
@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Retur Shopee</h4>
        <form method="POST" action="{{ route('shopee.returns.sync') }}">
            @csrf
            <button class="btn btn-outline-primary btn-sm">↻ Tarik Retur dari Shopee</button>
        </form>
    </div>

    @foreach ($returns as $r)
        @php $pv = $previews[$r->id]; @endphp
        <div class="card mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <strong>{{ $r->shopee_return_sn }}</strong>
                        <span class="text-muted">· order {{ $r->shopee_order_sn ?? '—' }}</span>
                        <span class="badge bg-secondary">{{ $r->status }}</span>
                        @if ($r->review_status === \App\Models\ShopeeReturn::REVIEW_RESTOCKED)
                            <span class="badge bg-success">Sudah restock</span>
                        @elseif ($r->review_status === \App\Models\ShopeeReturn::REVIEW_REJECTED)
                            <span class="badge bg-danger">Ditolak</span>
                        @else
                            <span class="badge bg-warning text-dark">Perlu review</span>
                        @endif
                    </div>
                </div>

                <ul class="mt-2 mb-2">
                    @foreach ($pv['lines'] as $l)
                        <li>
                            {{ $l['sku'] }} × {{ $l['qty'] }}
                            @if (count($l['components']))
                                @foreach ($l['components'] as $c)
                                    → +{{ $c['add'] }} {{ $c['product']->name }}
                                @endforeach
                            @else
                                <span class="text-danger">(SKU belum ada resep — petakan dulu)</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($r->review_status === \App\Models\ShopeeReturn::REVIEW_PENDING)
                    <form method="POST" action="{{ route('shopee.returns.restock', $r) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-success btn-sm" {{ $pv['all_matched'] ? '' : 'disabled' }}>
                            ✓ Terima &amp; Tambah Stok
                        </button>
                    </form>
                    <form method="POST" action="{{ route('shopee.returns.reject', $r) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm">✗ Tolak (cacat)</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('shopee.returns.reset', $r) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-outline-secondary btn-sm">↺ Ubah / batalkan</button>
                    </form>
                @endif
            </div>
        </div>
    @endforeach

    {{ $returns->links() }}
</div>
@endsection
```

> Samakan `@extends`/`@section` dan class CSS dengan view Shopee Fase 1 (`resources/views/shopee/orders.blade.php`) supaya konsisten. Kalau layout-nya beda, ikuti yang di Fase 1.

- [ ] **Step 7: Add sidebar/index link**

Di `resources/views/shopee/index.blade.php`, dekat link "Orders"/"Stok", tambah link ke `route('shopee.returns')` berlabel "Retur" (ikuti gaya link yang sudah ada di halaman itu).

- [ ] **Step 8: Run tests to verify they pass**

Run: `C:/php83/php.exe artisan test --filter=ShopeeReturnTest`
Expected: PASS (semua).

- [ ] **Step 9: Run full suite (pastikan Fase 1 tak rusak)**

Run: `C:/php83/php.exe artisan test`
Expected: PASS semua (skusNeedingMap yang diubah tak boleh mematahkan tes Fase 1).

- [ ] **Step 10: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/ShopeeController.php routes/web.php app/Services/ShopeeOrderService.php resources/views/shopee/returns.blade.php resources/views/shopee/index.blade.php tests/Feature/ShopeeReturnTest.php
git commit -m "feat(shopee) Fase 2: UI retur (list/review/restock/reject) + route + skusNeedingMap retur"
```

---

## Self-Review (plan vs spec)

**Spec coverage:**
- Model `ShopeeReturn` + migrasi 000093 → Task 1 ✓
- `ShopeeClient` getReturnList/getReturnDetail → Task 3 ✓
- `ShopeeReturnService` (store/normalize/preview/restock/reject/resetReview/pullBack) → Task 2 ✓
- `ShopeeSyncService::syncReturns` → Task 4 ✓
- `shopee:sync --returns` + cron → Task 4 ✓
- Controller returnList/syncReturns/restock/reject/reset → Task 5 ✓
- Route grup manage_shopee → Task 5 ✓
- View returns.blade + link → Task 5 ✓
- `skusNeedingMap` ikut retur → Task 5 ✓
- Tests (restock/reject/pullback, retur-only-sku, sync-stores, render+reseller-403) → Task 2/4/5 ✓
- Retur TANPA jurnal → dipenuhi (tak ada AccJournal di mana pun) ✓

**Placeholder scan:** tak ada TBD/TODO; tiap step berisi kode nyata. Titik "verifikasi ke sandbox" untuk nama field API adalah langkah verifikasi build (bukan placeholder) — service memetakan defensif sehingga tetap jalan.

**Type consistency:** `ShopeeReturn::REVIEW_*`, `shopee_return_sn`, `line_items` `[{sku,name,qty}]`, `reference_type='shopee_return'`, signature `restock/reject/resetReview` konsisten di Task 2/5. `syncReturns(ShopeeConnection): int` konsisten Task 4/5.

## Catatan verifikasi sandbox (dikerjakan saat build)
Setelah semua task hijau di lokal: jalankan `SHOPEE_INSECURE=true SHOPEE_API_BASE=https://openplatform.sandbox.test-stable.shopee.sg C:/php83/php.exe artisan shopee:sync --returns` terhadap koneksi sandbox yang sudah ada. Verifikasi `getReturnList` diterima Shopee (sign OK; kemungkinan 0 retur karena test order belum ada retur). Kalau bentuk field respons beda dari asumsi (`response.return`, `item[].item_sku`, dll), sesuaikan `normalizeItems`/`syncReturns` + tambah/koreksi tes. Ini menutup risiko "bentuk API beda" seperti di Fase 1.
