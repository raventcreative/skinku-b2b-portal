# Integrasi Shopee Fase 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nyalakan integrasi Shopee level ORDER (connect toko → sync order → potong stok HQ + UI + cron), meniru integrasi TikTok, di atas backend Shopee yang sudah ada & ter-tes.

**Architecture:** Lapisan wiring tipis. Reuse `ShopeeClient` (auth+order API) & `ShopeeOrderService` (store/deduct/reverse/cutoff/SKU-map — sudah 10 tes hijau di `ShopeeTest`). Tambah `ShopeeSyncService` (orchestrator), `ShopeeController`, `shopee:sync` command + cron, views (niru `tiktok/`), izin `manage_shopee` + menu. Dashboard/laporan Shopee sudah ke-wire di `ReportService::channelSales` → tak disentuh.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + vanilla JS, HTTP client bawaan Laravel. Runner `C:/php83/php.exe artisan test`; Pint `C:/php83/php.exe vendor/bin/pint --dirty`.

## Global Constraints

- **Zero-dependency**: tanpa composer/npm baru. Fake API di test pakai anonymous class yang extend `ShopeeClient` (bukan Mockery baru).
- **Mirror TikTok**: struktur, nama method, alur, UI mengikuti TikTok. Jangan mengarang beda.
- **Jangan ubah yang sudah jalan**: `ShopeeClient`, `ShopeeOrderService`, model, `channelSales` dipakai apa adanya.
- **Idempoten**: sync & potong stok aman diulang (guard sudah ada di `ShopeeOrderService`).
- **Deploy = git pull**: Fase 1 TANPA migrasi (tabel Shopee sudah ada dari migrasi 000042).
- Pint `--dirty` sebelum tiap commit. Branch: `feat/shopee-fase1`.

## Perbedaan kunci Shopee vs TikTok (WAJIB diperhatikan)

1. **Token akses Shopee ~4 JAM** (TikTok 7 hari) → refresh sering. Cek pakai `$conn->accessExpiringSoon()`.
2. **Method `ShopeeClient` butuh `shopId`**: `getToken(code, shopId)`, `refreshToken(refreshToken, shopId)`, `getOrderList(access, shopId, from, to, cursor, pageSize)`, `getOrderDetail(access, shopId, orderSns)`.
3. **Callback Shopee** membawa `code` **dan** `shop_id` di query string.
4. **`getOrderList` WAJIB rentang waktu (maks 15 hari)** — tak ada mode "ambil semua". Sync membangun window `from`/`to` + paginasi `cursor`.
5. **Alur order**: `getOrderList` → kumpulkan `order_sn` → `getOrderDetail(sns)` (maks 50/panggilan, di sinilah `item_list`) → `ShopeeOrderService::store($detailOrders)`.
6. **Respons token** Shopee: `{access_token, refresh_token, expire_in}` (`expire_in` = detik untuk access, 14400). `access_expires_at = now()+expire_in dtk`. `refresh_expires_at` tak dikirim → set `now()+30 hari` saat connect, biarkan saat refresh.

## File Structure

- Create `app/Services/ShopeeSyncService.php` — orchestrator: `connection()`, `freshToken()`, `syncOrders()`, `toTime()`.
- Create `app/Http/Controllers/ShopeeController.php` — index/connect/callback/syncOrders/orderList/stockFunnel/saveSkuMap/removeSkuMap/deductStock/deductAll/settings.
- Create `app/Console/Commands/ShopeeSyncCommand.php` — `shopee:sync {--full}`.
- Create `resources/views/shopee/index.blade.php`, `orders.blade.php`, `stock.blade.php`.
- Create `tests/Feature/ShopeeSyncTest.php`, `tests/Feature/ShopeeWiringTest.php`.
- Modify `app/Support/Permissions.php` — tambah `manage_shopee`.
- Modify `routes/web.php` — grup route `permission:manage_shopee`.
- Modify `routes/console.php` — schedule `shopee:sync` tiap 30 menit.
- Modify `resources/views/layouts/app.blade.php` — menu "Integrasi Shopee" + ikon.

**Reference files (baca, jangan ubah):** `app/Http/Controllers/TikTokController.php`, `app/Services/TikTokSyncService.php`, `app/Console/Commands/TikTokSyncCommand.php`, `resources/views/tiktok/{index,orders,stock}.blade.php`, `routes/web.php` (grup `permission:manage_tiktok`, ±baris 402-418), `resources/views/layouts/app.blade.php` (item `tiktok.index`, ±baris 263-265 + ikon map).

---

### Task 1: Izin `manage_shopee` + route + menu + halaman index minimal

**Files:**
- Modify: `app/Support/Permissions.php` (DEFINITIONS + DEFAULTS)
- Create: `app/Http/Controllers/ShopeeController.php` (baru — method `index` saja dulu)
- Modify: `routes/web.php` (grup baru)
- Modify: `resources/views/layouts/app.blade.php` (menu + ikon)
- Create: `resources/views/shopee/index.blade.php` (minimal: status koneksi + tombol Hubungkan)
- Test: `tests/Feature/ShopeeWiringTest.php`

**Interfaces:**
- Produces: route `shopee.index` (GET `/shopee`), izin `manage_shopee`, `ShopeeController::index()`.

- [ ] **Step 1: Tes akses + render (gagal dulu)**

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopeeWiringTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => 'u', 'fullname' => 'U', 'username' => 'u'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_admin_bisa_buka_shopee_index(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('shopee.index'))
            ->assertOk()->assertSee('Integrasi Shopee');
    }

    public function test_mitra_tak_boleh_akses(): void
    {
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR))->get('/shopee')->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan → FAIL** (`route [shopee.index] not defined`)

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`

- [ ] **Step 3: Tambah izin** di `app/Support/Permissions.php`

Di `DEFINITIONS` (setelah baris `'manage_tiktok' => 'Integrasi TikTok Shop',`):
```php
        'manage_shopee' => 'Integrasi Shopee',
```
Di `DEFAULTS` (setelah baris `'manage_tiktok' => [User::ROLE_ADMIN],`):
```php
        'manage_shopee' => [User::ROLE_ADMIN],
```

- [ ] **Step 4: Controller `index`** — Create `app/Http/Controllers/ShopeeController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ShopeeConnection;
use App\Services\ShopeeClient;
use App\Services\ShopeeOrderService;

class ShopeeController extends Controller
{
    public function __construct(
        private ShopeeClient $shopee,
        private ShopeeOrderService $orders,
    ) {}

    public function index()
    {
        return view('shopee.index', [
            'configured' => $this->shopee->configured(),
            'connection' => ShopeeConnection::latest('id')->first(),
            'needMap' => $this->orders->skusNeedingMap(),
        ]);
    }
}
```

- [ ] **Step 5: Route** — di `routes/web.php`, TEPAT setelah grup `permission:manage_tiktok` (blok `Route::middleware('permission:manage_tiktok')->group(...)`), tambah:

```php
    Route::middleware('permission:manage_shopee')->group(function () {
        Route::get('/shopee', [ShopeeController::class, 'index'])->name('shopee.index');
    });
```
Tambah import di atas: `use App\Http\Controllers\ShopeeController;`

- [ ] **Step 6: View minimal** — Create `resources/views/shopee/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Integrasi Shopee')
@section('heading', 'Integrasi Shopee')

@section('content')
<div class="bg-white rounded-2xl border border-stone-200 p-6 text-sm">
    <h3 class="text-base font-bold text-stone-800 mb-2">Koneksi Shopee</h3>
    @if(!$configured)
        <p class="text-rose-600">Kredensial Shopee belum diisi di <code>.env</code> server (SHOPEE_PARTNER_ID / SHOPEE_PARTNER_KEY).</p>
    @elseif($connection)
        <p class="text-emerald-700">Terhubung: <b>{{ $connection->shop_name ?? $connection->shop_id }}</b>.</p>
    @else
        <p class="text-stone-500">Belum terhubung ke toko Shopee.</p>
    @endif
</div>
@endsection
```

- [ ] **Step 7: Menu + ikon** di `resources/views/layouts/app.blade.php`.

Di fungsi `navIcon($key)` (blok `match`), setelah arm `'tiktok.index' => '...'` tambah (ikon keranjang Shopee):
```php
                            'shopee.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/>',
```
Setelah baris menu TikTok (`@if($u->canDo('manage_tiktok')) ... navItem('tiktok.index', 'Integrasi TikTok', 'tiktok.*') ... @endif`) tambah:
```blade
            @if($u->canDo('manage_shopee'))
                {!! navItem('shopee.index', 'Integrasi Shopee', 'shopee.*') !!}
            @endif
```

- [ ] **Step 8: Jalankan → PASS**

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`
Expected: 2 tes hijau.

- [ ] **Step 9: Pint + Commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add -A && git commit -m "feat(shopee) Fase 1: izin manage_shopee + route + menu + index"
```

---

### Task 2: `ShopeeSyncService` (orchestrator: freshToken + syncOrders)

**Files:**
- Create: `app/Services/ShopeeSyncService.php`
- Test: `tests/Feature/ShopeeSyncTest.php`

**Interfaces:**
- Consumes: `ShopeeClient` (getOrderList/getOrderDetail/refreshToken), `ShopeeOrderService::store(array):int` & `deductAllReady(?int):array`, `ShopeeConnection::accessExpiringSoon():bool`.
- Produces:
  - `ShopeeSyncService::connection(): ?ShopeeConnection`
  - `ShopeeSyncService::freshToken(ShopeeConnection $conn): string`
  - `ShopeeSyncService::syncOrders(ShopeeConnection $conn, ?int $userId = null, bool $full = false): array` → `['count' => int, 'deducted' => ?array]`
  - `ShopeeSyncService::toTime(mixed $expireIn): ?Carbon`

- [ ] **Step 1: Tes sync (gagal dulu)** — Create `tests/Feature/ShopeeSyncTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Services\ShopeeClient;
use App\Services\ShopeeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Fake client: tak memanggil API asli, balikin data kaleng. */
    private function fakeClient(array $list, array $detail): ShopeeClient
    {
        return new class($list, $detail) extends ShopeeClient
        {
            public function __construct(private array $list, private array $detail) {}
            public function refreshToken(string $refreshToken, string $shopId): array
            {
                return ['access_token' => 'FRESH', 'refresh_token' => 'R2', 'expire_in' => 14400];
            }
            public function getOrderList(string $a, string $s, int $f, int $t, string $cursor = '', int $p = 50): array
            {
                return $this->list;
            }
            public function getOrderDetail(string $a, string $s, array $sns): array
            {
                return $this->detail;
            }
        };
    }

    private function conn(array $extra = []): ShopeeConnection
    {
        return ShopeeConnection::create(array_merge([
            'shop_id' => '999', 'access_token' => 'OLD', 'refresh_token' => 'R1',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(30),
        ], $extra));
    }

    public function test_sync_menyimpan_order(): void
    {
        // list → response.order_list[].order_sn ; detail → response.order_list[] (dgn item)
        $list = ['response' => ['order_list' => [['order_sn' => 'S1']], 'more' => false, 'next_cursor' => '']];
        $detail = ['response' => ['order_list' => [[
            'order_sn' => 'S1', 'order_status' => 'COMPLETED', 'total_amount' => 50000,
            'create_time' => now()->timestamp, 'item_list' => [],
        ]]]];
        $this->app->instance(ShopeeClient::class, $this->fakeClient($list, $detail));

        $sync = app(ShopeeSyncService::class);
        $r = $sync->syncOrders($this->conn());

        $this->assertSame(1, $r['count']);
        $this->assertSame('COMPLETED', ShopeeOrder::where('order_sn', 'S1')->value('status'));
    }

    public function test_freshtoken_refresh_saat_hampir_kadaluarsa(): void
    {
        $this->app->instance(ShopeeClient::class, $this->fakeClient([], []));
        $conn = $this->conn(['access_expires_at' => now()->addMinutes(2)]); // < ambang → refresh
        $token = app(ShopeeSyncService::class)->freshToken($conn);
        $this->assertSame('FRESH', $token);
        $this->assertSame('FRESH', $conn->fresh()->access_token);
    }
}
```

- [ ] **Step 2: Jalankan → FAIL** (`Class ShopeeSyncService not found`)

Run: `C:/php83/php.exe artisan test --filter=ShopeeSyncTest`

- [ ] **Step 3: Implement `ShopeeSyncService`** — Create `app/Services/ShopeeSyncService.php`:

```php
<?php

namespace App\Services;

use App\Models\ShopeeConnection;
use Illuminate\Support\Carbon;

/**
 * Orchestrator sync Shopee — meniru TikTokSyncService (bagian order).
 * Token Shopee cuma ~4 jam, jadi freshToken() dipanggil sebelum tiap tarik.
 * getOrderList wajib rentang waktu (maks 15 hari) → window dibangun di sini.
 */
class ShopeeSyncService
{
    public function __construct(
        private ShopeeClient $shopee,
        private ShopeeOrderService $orders,
    ) {}

    public function connection(): ?ShopeeConnection
    {
        return ShopeeConnection::latest('id')->first();
    }

    /** Pastikan access token valid (refresh kalau hampir habis — token 4 jam). */
    public function freshToken(ShopeeConnection $conn): string
    {
        if (! $conn->accessExpiringSoon()) {
            return (string) $conn->access_token;
        }
        $t = $this->shopee->refreshToken($conn->refresh_token, $conn->shop_id);
        $conn->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $conn->refresh_token,
            'access_expires_at' => $this->toTime($t['expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    /**
     * Tarik order dalam window waktu → simpan → (opsi) auto-potong stok.
     * $full = window lebih lebar (14 hari); selain itu sejak last_synced_at−2 jam.
     */
    public function syncOrders(ShopeeConnection $conn, ?int $userId = null, bool $full = false): array
    {
        $access = $this->freshToken($conn);
        $startedAt = now();

        $to = now()->timestamp;
        $from = $full || ! $conn->last_synced_at
            ? now()->subDays(14)->timestamp                       // Shopee batas 15 hari
            : $conn->last_synced_at->copy()->subHours(2)->timestamp;

        // 1) kumpulkan order_sn berhalaman (cursor)
        $sns = [];
        $cursor = '';
        for ($guard = 0; $guard < 50; $guard++) {
            $res = $this->shopee->getOrderList($access, $conn->shop_id, $from, $to, $cursor)['response'] ?? [];
            foreach (($res['order_list'] ?? []) as $row) {
                if (! empty($row['order_sn'])) {
                    $sns[] = $row['order_sn'];
                }
            }
            if (empty($res['more']) || empty($res['next_cursor'])) {
                break;
            }
            $cursor = $res['next_cursor'];
        }

        // 2) tarik detail per 50 → kumpulkan
        $detailOrders = [];
        foreach (array_chunk($sns, 50) as $chunk) {
            $res = $this->shopee->getOrderDetail($access, $conn->shop_id, $chunk)['response'] ?? [];
            foreach (($res['order_list'] ?? []) as $o) {
                $detailOrders[] = $o;
            }
        }

        // 3) simpan + catat waktu sync
        $count = $this->orders->store($detailOrders);
        $conn->update(['last_synced_at' => $startedAt]);

        // 4) auto-potong bila diaktifkan
        $deducted = $conn->auto_deduct ? $this->orders->deductAllReady($userId) : null;

        return ['count' => $count, 'deducted' => $deducted];
    }

    /** Shopee kirim expire_in sbg DETIK-dari-sekarang. */
    public function toTime(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }
}
```

- [ ] **Step 4: Jalankan → PASS**

Run: `C:/php83/php.exe artisan test --filter=ShopeeSyncTest`
Expected: 2 tes hijau.

- [ ] **Step 5: Pint + Commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add -A && git commit -m "feat(shopee) Fase 1: ShopeeSyncService (freshToken + syncOrders window)"
```

---

### Task 3: Connect + Callback (OAuth) di `ShopeeController`

**Files:**
- Modify: `app/Http/Controllers/ShopeeController.php` (tambah `connect`, `callback`)
- Modify: `routes/web.php` (route connect/callback)
- Test: `tests/Feature/ShopeeWiringTest.php` (tambah tes callback)

**Interfaces:**
- Consumes: `ShopeeClient::authorizeUrl(string $redirect)`, `ShopeeClient::getToken(string $code, string $shopId)`, `ShopeeSyncService::toTime()`.
- Produces: route `shopee.connect`, `shopee.callback`; `ShopeeConnection` tersimpan.

- [ ] **Step 1: Tes callback (gagal dulu)** — tambah ke `ShopeeWiringTest`:

```php
    public function test_callback_menyimpan_koneksi(): void
    {
        // fake ShopeeClient: getToken balikin token kaleng
        $fake = new class extends \App\Services\ShopeeClient {
            public function __construct() {}
            public function configured(): bool { return true; }
            public function getToken(string $code, string $shopId): array
            {
                return ['access_token' => 'A', 'refresh_token' => 'R', 'expire_in' => 14400];
            }
        };
        $this->app->instance(\App\Services\ShopeeClient::class, $fake);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get(route('shopee.callback', ['code' => 'xyz', 'shop_id' => '777']))
            ->assertRedirect(route('shopee.index'));

        $this->assertDatabaseHas('shopee_connections', ['shop_id' => '777', 'access_token' => 'A']);
    }
```

- [ ] **Step 2: Jalankan → FAIL** (`route [shopee.callback] not defined`)

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`

- [ ] **Step 3: Tambah `connect` + `callback`** ke `ShopeeController`.

Tambah import: `use App\Models\User;` tidak perlu; tambah `use App\Services\AuditService;`, `use App\Services\ShopeeSyncService;`, `use Illuminate\Http\RedirectResponse;`, `use Illuminate\Http\Request;`.
Suntik `ShopeeSyncService $sync` di constructor (buat `toTime`). Tambah method:

```php
    public function connect(): RedirectResponse
    {
        abort_unless($this->shopee->configured(), 400, 'Kredensial Shopee belum diisi di .env server.');

        return redirect()->away($this->shopee->authorizeUrl(route('shopee.callback')));
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code');
        $shopId = (string) $request->query('shop_id');
        if ($code === '' || $shopId === '') {
            return redirect()->route('shopee.index')->with('error', 'Otorisasi dibatalkan / kode Shopee tidak lengkap.');
        }

        try {
            $t = $this->shopee->getToken($code, $shopId);

            ShopeeConnection::updateOrCreate(
                ['shop_id' => $shopId],
                [
                    'access_token' => $t['access_token'],
                    'refresh_token' => $t['refresh_token'],
                    'access_expires_at' => $this->sync->toTime($t['expire_in'] ?? null),
                    'refresh_expires_at' => now()->addDays(30),
                    'connected_by' => $request->user()->id,
                ],
            );

            AuditService::log(action: 'connect_shopee', targetType: 'shopee', after: ['shop_id' => $shopId]);

            return redirect()->route('shopee.index')->with('status', 'Toko Shopee berhasil terhubung.');
        } catch (\Throwable $e) {
            return redirect()->route('shopee.index')->with('error', 'Gagal menghubungkan: '.$e->getMessage());
        }
    }
```

- [ ] **Step 4: Route** — di grup `permission:manage_shopee` (`routes/web.php`) tambah:

```php
        Route::get('/shopee/connect', [ShopeeController::class, 'connect'])->name('shopee.connect');
        Route::get('/shopee/callback', [ShopeeController::class, 'callback'])->name('shopee.callback');
```

- [ ] **Step 5: Jalankan → PASS**

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`

- [ ] **Step 6: Pint + Commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add -A && git commit -m "feat(shopee) Fase 1: OAuth connect + callback"
```

---

### Task 4: Sync/order/stok/SKU/potong/settings + command `shopee:sync` + cron

**Files:**
- Modify: `app/Http/Controllers/ShopeeController.php` (syncOrders, orderList, stockFunnel, saveSkuMap, removeSkuMap, deductStock, deductAll, settings)
- Create: `app/Console/Commands/ShopeeSyncCommand.php`
- Modify: `routes/web.php` (route sisa), `routes/console.php` (schedule)
- Test: `tests/Feature/ShopeeWiringTest.php` (tambah tes sync + deduct + command)

**Interfaces:**
- Consumes: `ShopeeSyncService::syncOrders()`, `ShopeeOrderService::{preview,deduct,deductAllReady,resolve,skusNeedingMap}`, `ShopeeSkuMap`, `ShopeeOrder`.
- Produces: route `shopee.sync-orders`, `shopee.orders`, `shopee.stock`, `shopee.sku-map`(+remove), `shopee.deduct`, `shopee.deduct-all`, `shopee.settings`; command `shopee:sync`.

- [ ] **Step 1: Tes (gagal dulu)** — tambah ke `ShopeeWiringTest`:

```php
    public function test_deduct_satu_order(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $p = \App\Models\Product::create(['name' => 'Sabun', 'sku' => 'SB1', 'price_grand' => 1,
            'price_distributor' => 1, 'price_reseller' => 1, 'price_retail' => 1, 'cogs' => 1,
            'hq_stock' => 100, 'status' => 'active']);
        \App\Models\ShopeeSkuMap::create(['shopee_sku' => 'SB1', 'product_id' => $p->id, 'qty' => 1]);
        $o = \App\Models\ShopeeOrder::create(['order_sn' => 'D1', 'status' => 'SHIPPED', 'total_amount' => 1,
            'line_items' => [['sku' => 'SB1', 'name' => 'Sabun', 'qty' => 3]], 'stock_status' => \App\Models\ShopeeOrder::STATUS_PENDING,
            'order_created_at' => now()]);

        $this->actingAs($admin)->post(route('shopee.deduct', $o))->assertRedirect();
        $this->assertSame(97, (int) $p->fresh()->hq_stock);
    }

    public function test_command_shopee_sync_tanpa_koneksi_aman(): void
    {
        $this->artisan('shopee:sync')->assertSuccessful();
    }
```
> Field terverifikasi dari `ShopeeSkuMap` (`shopee_sku`, `product_id`, `qty`) & `ShopeeOrder` (`order_sn`, `status`, `stock_status`, `order_created_at`, `line_items`=[`sku`,`name`,`qty`]).

- [ ] **Step 2: Jalankan → FAIL**

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`

- [ ] **Step 3: Method controller** — tambah ke `ShopeeController` (mirror `TikTokController` method senama; ganti `Tiktok*`→`Shopee*`, `tiktok.*`→`shopee.*`, `$this->tiktok`→`$this->shopee`). Tambah import `use App\Models\ShopeeOrder;`, `use App\Models\ShopeeSkuMap;`.

```php
    public function syncOrders(Request $request): RedirectResponse
    {
        $conn = ShopeeConnection::latest('id')->first();
        abort_unless($conn, 400, 'Belum terhubung ke toko Shopee.');
        try {
            $r = $this->sync->syncOrders($conn, $request->user()->id);
            $msg = "Berhasil tarik & simpan {$r['count']} order Shopee.";
            if ($r['deducted']) {
                $d = $r['deducted'];
                $msg .= " Auto-potong: {$d['done']} dipotong".($d['failed'] ? ", {$d['failed']} gagal" : '').'.';
            }

            return redirect()->route('shopee.orders')->with('status', $msg);
        } catch (\Throwable $e) {
            return redirect()->route('shopee.index')->with('error', 'Gagal tarik order: '.$e->getMessage());
        }
    }

    public function orderList()
    {
        $orders = ShopeeOrder::latest('order_created_at')->latest('id')->paginate(25);
        $previews = $orders->mapWithKeys(fn ($o) => [$o->id => $this->orders->preview($o)]);

        return view('shopee.orders', ['orders' => $orders, 'previews' => $previews, 'needMap' => $this->orders->skusNeedingMap()]);
    }

    public function stockFunnel()
    {
        return view('shopee.stock', ['needMap' => $this->orders->skusNeedingMap()]);
    }

    public function saveSkuMap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shopee_sku' => ['required', 'string', 'max:190'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);
        // Satu shopee_sku bisa memetakan ke BANYAK produk (resep) — kunci gabungan.
        ShopeeSkuMap::updateOrCreate(
            ['shopee_sku' => $data['shopee_sku'], 'product_id' => $data['product_id']],
            ['qty' => $data['qty']],
        );

        return back()->with('status', 'Pemetaan SKU disimpan.');
    }

    public function removeSkuMap(ShopeeSkuMap $map): RedirectResponse
    {
        $map->delete();

        return back()->with('status', 'Pemetaan SKU dihapus.');
    }

    public function deductStock(Request $request, ShopeeOrder $order): RedirectResponse
    {
        try {
            $this->orders->deduct($order, $request->user()->id);

            return back()->with('status', "Stok order {$order->order_sn} dipotong.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deductAll(Request $request): RedirectResponse
    {
        $d = $this->orders->deductAllReady($request->user()->id);

        return back()->with('status', "Potong massal: {$d['done']} dipotong, {$d['failed']} gagal, {$d['skipped']} dilewati.");
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_deduct' => ['nullable', 'boolean'],
            'deduct_from' => ['nullable', 'date'],
        ]);
        $conn = ShopeeConnection::latest('id')->first();
        abort_unless($conn, 400, 'Belum terhubung.');
        $conn->update(['auto_deduct' => (bool) ($data['auto_deduct'] ?? false), 'deduct_from' => $data['deduct_from'] ?? null]);

        return back()->with('status', 'Pengaturan Shopee disimpan.');
    }
```
> Field `ShopeeSkuMap`=`shopee_sku`,`product_id`,`qty` & `ShopeeOrder` sudah terverifikasi dari model.

- [ ] **Step 4: Route sisa** di grup `permission:manage_shopee`:

```php
        Route::post('/shopee/sync-orders', [ShopeeController::class, 'syncOrders'])->name('shopee.sync-orders');
        Route::get('/shopee/orders', [ShopeeController::class, 'orderList'])->name('shopee.orders');
        Route::get('/shopee/stok', [ShopeeController::class, 'stockFunnel'])->name('shopee.stock');
        Route::post('/shopee/sku-map', [ShopeeController::class, 'saveSkuMap'])->name('shopee.sku-map');
        Route::delete('/shopee/sku-map/{map}', [ShopeeController::class, 'removeSkuMap'])->name('shopee.sku-map.remove');
        Route::post('/shopee/orders/{order}/deduct', [ShopeeController::class, 'deductStock'])->name('shopee.deduct');
        Route::post('/shopee/deduct-all', [ShopeeController::class, 'deductAll'])->name('shopee.deduct-all');
        Route::post('/shopee/settings', [ShopeeController::class, 'settings'])->name('shopee.settings');
```

- [ ] **Step 5: Command** — Create `app/Console/Commands/ShopeeSyncCommand.php` (mirror `TikTokSyncCommand`, versi order saja):

```php
<?php

namespace App\Console\Commands;

use App\Services\ShopeeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ShopeeSyncCommand extends Command
{
    protected $signature = 'shopee:sync {--full : Abaikan filter waktu, sapu 14 hari terakhir}';

    protected $description = 'Tarik order Shopee (+auto-potong stok bila aktif)';

    public function handle(ShopeeSyncService $sync): int
    {
        $conn = $sync->connection();
        if (! $conn) {
            $this->warn('Belum terhubung ke Shopee — dilewati.');

            return self::SUCCESS;
        }
        try {
            $r = $sync->syncOrders($conn, null, (bool) $this->option('full'));
            $msg = "Order: {$r['count']} tersimpan.";
            if ($r['deducted']) {
                $d = $r['deducted'];
                $msg .= " Auto-potong: {$d['done']} dipotong, {$d['failed']} gagal, {$d['skipped']} dilewati.";
            }
            $this->info($msg);
            Log::info('[shopee:sync] '.$msg);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());
            Log::error('[shopee:sync] '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
```

- [ ] **Step 6: Schedule** — di `routes/console.php`, dekat jadwal TikTok, tambah:

```php
Schedule::command('shopee:sync')->everyThirtyMinutes()->withoutOverlapping(15);
```

- [ ] **Step 7: Jalankan → PASS**

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`

- [ ] **Step 8: Pint + Commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add -A && git commit -m "feat(shopee) Fase 1: sync/order/stok/SKU/potong/settings + shopee:sync cron"
```

---

### Task 5: UI lengkap (index, orders, stock) — niru `tiktok/`

**Files:**
- Modify: `resources/views/shopee/index.blade.php` (lengkapi)
- Create: `resources/views/shopee/orders.blade.php`, `resources/views/shopee/stock.blade.php`
- Test: `tests/Feature/ShopeeWiringTest.php` (render orders + stock)

**Interfaces:**
- Consumes: view data dari `ShopeeController::{index,orderList,stockFunnel}`.

- [ ] **Step 1: Tes render (gagal dulu)** — tambah ke `ShopeeWiringTest`:

```php
    public function test_halaman_orders_dan_stok_render(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('shopee.orders'))->assertOk()->assertSee('Order Shopee');
        $this->actingAs($admin)->get(route('shopee.stock'))->assertOk();
    }
```

- [ ] **Step 2: Jalankan → FAIL** (view `shopee.orders` tak ada)

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`

- [ ] **Step 3: Lengkapi `shopee/index.blade.php`** — tambah, di bawah kartu koneksi: tombol **Hubungkan Shopee** (`route('shopee.connect')`), tombol **Tarik Order** (`POST shopee.sync-orders`), form **Pengaturan** (toggle `auto_deduct` + tanggal `deduct_from` → `POST shopee.settings`), dan daftar **SKU belum ter-map** (`$needMap`) dengan form `POST shopee.sku-map`. Tiru struktur `resources/views/tiktok/index.blade.php` (ganti route `tiktok.*`→`shopee.*`, label TikTok→Shopee). Sertakan `@if(session('status'))`/`@if(session('error'))` banner.

- [ ] **Step 4: Buat `shopee/orders.blade.php`** — tabel order (`$orders`: `order_sn`, `status`, `total_amount`, `order_created_at`, `stock_status`), kolom pratinjau dari `$previews[$o->id]` (`lines` + `all_matched`), tombol **Potong** (`POST shopee.deduct`, disabled bila `!all_matched` atau sudah `deducted`), header **"Order Shopee"**, tombol **Potong Semua** (`POST shopee.deduct-all`), paginasi `{{ $orders->links() }}`. Tiru `resources/views/tiktok/orders.blade.php`.

- [ ] **Step 5: Buat `shopee/stock.blade.php`** — ringkas: daftar `$needMap` (SKU yang belum dipetakan) + form map cepat. Tiru bagian funnel/stok `resources/views/tiktok/stock.blade.php` (versi sederhana bila TikTok terlalu kaya).

- [ ] **Step 6: Jalankan → PASS**

Run: `C:/php83/php.exe artisan test --filter=ShopeeWiringTest`

- [ ] **Step 7: Full suite + Pint + Commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
C:/php83/php.exe artisan test
git add -A && git commit -m "feat(shopee) Fase 1: UI index/orders/stok + selesai Fase 1"
```

---

## Catatan verifikasi implementer

- **Bentuk respons TERVERIFIKASI** dari `ShopeeClient::handle()` (mengembalikan JSON mentah, `throw` bila `error`): `getOrderList`/`getOrderDetail` → `['response']['order_list']` (+ `['response']['more']`, `['response']['next_cursor']`); `getToken`/`refreshToken` → field top-level (`access_token`, `refresh_token`, `expire_in`). Kode `ShopeeSyncService` di plan sudah pakai bentuk ini.
- **`store()` menerima order API MENTAH** (`order_sn`, `order_status`, `total_amount`, `item_list`) lalu menormalkan ke `line_items`/`status` — makanya `ShopeeSyncService` meneruskan hasil `getOrderDetail` apa adanya.
- Setelah semua tes hijau: JANGAN deploy dulu — user yang deploy (isi `.env` SHOPEE_PARTNER_ID/KEY + daftar redirect URL callback di Shopee Open Platform, lalu connect toko sekali).
