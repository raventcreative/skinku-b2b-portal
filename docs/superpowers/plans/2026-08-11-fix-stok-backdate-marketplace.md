# Fix Backdate Gerakan Stok Marketplace (TikTok & Shopee) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gerakan stok-keluar HQ dari penjualan TikTok & Shopee tercatat pada tanggal order (`order_created_at`), bukan tanggal operator klik "Potong Stok" (`now()`), supaya Laporan Mutasi Stok HQ akurat per hari — plus backfill data lama.

**Architecture:** Teruskan `occurredAt = $order->order_created_at` ke `InventoryService::adjustHqStock(...)` pada `deduct()` (OUT) & `reverse()` (IN) di kedua service marketplace (param `occurredAt` sudah mengalir ke `writeMovement`: `created_at = occurredAt ?? now()`). Tambah support class `MarketplaceMovementDateBackfill` + migrasi `000079` untuk membetulkan tanggal gerakan existing, di-*floor* ke titik-nol opname (`deduct_from`) per-platform.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, PHPUnit. Zero-dependency.

**Design:** `docs/superpowers/specs/2026-08-11-fix-stok-backdate-marketplace-design.md`

**Branch:** `fix/stok-backdate-marketplace` (sudah dibuat dari main).

## Global Constraints

- **Zero-dependency**: tak menambah paket composer/npm.
- **Runner**: `C:\php83\php.exe artisan test`. `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Migrasi**: terakhir `000078` → gunakan `000079`.
- **Saldo stok TIDAK boleh berubah** — hanya TANGGAL gerakan yang digeser. Running balance netral terhadap tanggal.
- **Portabilitas DB**: backfill pakai loop PHP + `DB::table` (SQLite test / MySQL prod), bukan JOIN-UPDATE SQL.
- **Tanpa dampak akuntansi**: hanya `stock_movements.created_at`; `deducted_at` & `acc_journals` tak disentuh.
- **Retur (`tiktok_return`) di luar lingkup** — alur & tanggal terpisah, tak masuk kolom tiktok/shopee laporan.

---

## Fakta Kode Terverifikasi

- `InventoryService::adjustHqStock(..., ?\DateTimeInterface $occurredAt = null)` → `writeMovement(created_at: $occurredAt ?? now())`. Plumbing SUDAH ada.
- `TikTokOrderService::deduct()` panggil `adjustHqStock(...)` di ~baris 242 (OUT, `'tiktok_order', $order->id`); `reverse()` di ~baris 303 (IN).
- `ShopeeOrderService::deduct()` di ~baris 207 (OUT, `'shopee_order', $order->id`); `reverse()` di ~baris 269 (IN).
- `TiktokOrder`/`ShopeeOrder`: `order_created_at` cast `datetime` (fillable). Carbon implements `DateTimeInterface`. Null → fallback `now()`.
- `StockMovement`: `$timestamps = false`; `created_at` fillable & cast `datetime`. `TYPE_IN = 'IN'`, `TYPE_OUT = 'OUT'`.
- `HqStockReportService::bucketize()`: `tiktok_order` → kolom `tiktok` (`+= -delta`); `shopee_order` → `shopee`. Bucket by `created_at`.
- Tabel koneksi `tiktok_connections` & `shopee_connections` punya kolom `deduct_from` (cast `date`) = titik-nol opname per-platform.
- `adjustHqStock` dipanggil positional (6 arg) di keempat titik; `occurredAt:` ditambah sebagai named arg (PHP 8 boleh positional lalu named).

---

## File Structure

| File | Aksi | Tanggung jawab |
|---|---|---|
| `app/Services/TikTokOrderService.php` | modify | `occurredAt` di `deduct()` (OUT) & `reverse()` (IN) |
| `app/Services/ShopeeOrderService.php` | modify | `occurredAt` di `deduct()` (OUT) & `reverse()` (IN) |
| `app/Support/MarketplaceMovementDateBackfill.php` | create | geser `created_at` gerakan marketplace ke tgl order + clamp per-platform |
| `database/migrations/2026_01_01_000079_backfill_marketplace_movement_dates.php` | create | panggil `MarketplaceMovementDateBackfill::run()` |
| `tests/Feature/MarketplaceBackdateMovementTest.php` | create | forward fix (deduct/reverse, TikTok+Shopee, fallback null, level-laporan) |
| `tests/Feature/MarketplaceMovementDateBackfillTest.php` | create | backfill (kedua kaki, clamp, null, idempoten) |

---

## Task 1: Forward fix — deduct() & reverse() teruskan tanggal order

**Files:**
- Modify: `app/Services/TikTokOrderService.php` (`deduct()` ~b.242, `reverse()` ~b.303)
- Modify: `app/Services/ShopeeOrderService.php` (`deduct()` ~b.207, `reverse()` ~b.269)
- Test: `tests/Feature/MarketplaceBackdateMovementTest.php` (create)

**Interfaces:**
- Consumes: `InventoryService::adjustHqStock(..., occurredAt: ?\DateTimeInterface)` (sudah ada).
- Produces: gerakan `tiktok_order`/`shopee_order` ber-`created_at` = `order.order_created_at` (fallback `now()` bila null).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/MarketplaceBackdateMovementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeOrder;
use App\Models\StockMovement;
use App\Models\TiktokOrder;
use App\Services\HqStockReportService;
use App\Services\ShopeeOrderService;
use App\Services\TikTokOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarketplaceBackdateMovementTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $sku, int $stock = 100): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => $sku, 'hq_stock' => $stock,
            'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    public function test_tiktok_deduct_movement_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT1', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 3]],
        ]);

        app(TikTokOrderService::class)->deduct($order, null);

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());   // bukan 08-11
        $this->assertSame(97, (int) $p->fresh()->hq_stock);                 // saldo tetap benar
    }

    public function test_tiktok_reverse_leg_also_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT2', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 3]],
        ]);
        $svc = app(TikTokOrderService::class);

        $svc->deduct($order, null);
        $svc->reverse($order->fresh());

        $mvs = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->get();
        $this->assertCount(2, $mvs);                            // OUT + IN
        foreach ($mvs as $mv) {
            $this->assertSame('2026-08-05', $mv->created_at->toDateString());  // keduanya di tgl order
        }
        $this->assertSame(100, (int) $p->fresh()->hq_stock);   // net-nol
    }

    public function test_tiktok_sale_lands_on_order_day_in_report_not_deduct_day(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT3', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 3]],
        ]);
        app(TikTokOrderService::class)->deduct($order, null);

        $svc = app(HqStockReportService::class);
        $orderDay = collect($svc->report('harian', Carbon::parse('2026-08-05'))['rows'])->firstWhere('product.id', $p->id);
        $deductDay = collect($svc->report('harian', Carbon::parse('2026-08-11'))['rows'])->firstWhere('product.id', $p->id);

        $this->assertSame(3, $orderDay['tiktok']);      // penjualan muncul 5 Agu
        $this->assertSame(100, $orderDay['awal']);
        $this->assertSame(97, $orderDay['akhir']);
        $this->assertSame(0, $deductDay['tiktok']);     // TIDAK muncul 11 Agu (hari potong)
    }

    public function test_tiktok_deduct_falls_back_to_now_when_order_date_missing(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTNULL', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => null,
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 1]],
        ]);

        app(TikTokOrderService::class)->deduct($order, null);

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-11', $mv->created_at->toDateString());   // fallback now()
    }

    public function test_shopee_deduct_movement_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-B');
        $order = ShopeeOrder::create([
            'order_sn' => 'SP1', 'status' => 'COMPLETED',
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-B', 'name' => 'x', 'qty' => 4]],
        ]);

        app(ShopeeOrderService::class)->deduct($order, null);

        $mv = StockMovement::where('reference_type', 'shopee_order')->where('reference_id', $order->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        $this->assertSame(96, (int) $p->fresh()->hq_stock);
    }

    public function test_shopee_reverse_leg_also_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-B');
        $order = ShopeeOrder::create([
            'order_sn' => 'SP2', 'status' => 'COMPLETED',
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-B', 'name' => 'x', 'qty' => 4]],
        ]);
        $svc = app(ShopeeOrderService::class);

        $svc->deduct($order, null);
        $svc->reverse($order->fresh());

        $mvs = StockMovement::where('reference_type', 'shopee_order')->where('reference_id', $order->id)->get();
        $this->assertCount(2, $mvs);
        foreach ($mvs as $mv) {
            $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        }
        $this->assertSame(100, (int) $p->fresh()->hq_stock);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=MarketplaceBackdateMovementTest`
Expected: FAIL — gerakan bertanggal `2026-08-11` (now), bukan `2026-08-05`.

- [ ] **Step 3: TikTokOrderService teruskan occurredAt (deduct + reverse)**

Di `app/Services/TikTokOrderService.php`, pada `deduct()` (blok `DB::transaction`, pemanggilan `adjustHqStock` ~b.242), tambah argumen `occurredAt`:

```php
                    $this->inventory->adjustHqStock(
                        $c['product'], -1 * (int) $c['deduct'], StockMovement::TYPE_OUT,
                        "Penjualan TikTok {$order->tiktok_order_id}", 'tiktok_order', $order->id,
                        occurredAt: $order->order_created_at,
                    );
```

Pada `reverse()` (pemanggilan `adjustHqStock` ~b.303), tambah argumen yang sama:

```php
                    $this->inventory->adjustHqStock(
                        $c['product'], (int) $c['deduct'], StockMovement::TYPE_IN,
                        "Batal penjualan TikTok {$order->tiktok_order_id}", 'tiktok_order', $order->id,
                        occurredAt: $order->order_created_at,
                    );
```

- [ ] **Step 4: ShopeeOrderService teruskan occurredAt (deduct + reverse)**

Di `app/Services/ShopeeOrderService.php`, pada `deduct()` (pemanggilan `adjustHqStock` ~b.207):

```php
                    $this->inventory->adjustHqStock(
                        $c['product'], -1 * (int) $c['deduct'], StockMovement::TYPE_OUT,
                        "Penjualan Shopee {$order->order_sn}", 'shopee_order', $order->id,
                        occurredAt: $order->order_created_at,
                    );
```

Pada `reverse()` (pemanggilan `adjustHqStock` ~b.269):

```php
                    $this->inventory->adjustHqStock(
                        $c['product'], (int) $c['deduct'], StockMovement::TYPE_IN,
                        "Batal penjualan Shopee {$order->order_sn}", 'shopee_order', $order->id,
                        occurredAt: $order->order_created_at,
                    );
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=MarketplaceBackdateMovementTest`
Expected: PASS (6 test).

- [ ] **Step 6: Pint + Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/TikTokOrderService.php app/Services/ShopeeOrderService.php tests/Feature/MarketplaceBackdateMovementTest.php
git commit -m "fix(stok): penjualan TikTok & Shopee catat gerakan di tanggal order" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Backfill tanggal gerakan marketplace existing (migrasi 000079)

**Files:**
- Create: `app/Support/MarketplaceMovementDateBackfill.php`
- Create: `database/migrations/2026_01_01_000079_backfill_marketplace_movement_dates.php`
- Test: `tests/Feature/MarketplaceMovementDateBackfillTest.php` (create)

**Interfaces:**
- Produces: `MarketplaceMovementDateBackfill::run(): void` — set `stock_movements.created_at` = `order.order_created_at` (di-floor ke `deduct_from`) untuk gerakan `tiktok_order`/`shopee_order`. Lewati order tanpa `order_created_at`. Idempoten.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/MarketplaceMovementDateBackfillTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeOrder;
use App\Models\StockMovement;
use App\Models\TiktokConnection;
use App\Models\TiktokOrder;
use App\Support\MarketplaceMovementDateBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceMovementDateBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $sku): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => $sku, 'hq_stock' => 100,
            'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    private function movement(string $ref, int $refId, int $pid, string $type, int $qty, int $before, int $after, string $createdAt): void
    {
        StockMovement::create([
            'product_id' => $pid, 'user_id' => null, 'movement_type' => $type, 'quantity' => $qty,
            'before_qty' => $before, 'after_qty' => $after, 'reference_type' => $ref, 'reference_id' => $refId,
            'created_at' => $createdAt,
        ]);
    }

    public function test_moves_tiktok_movement_to_order_date(): void
    {
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT1', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 3]],
        ]);
        // gerakan lama dicap hari potong (SALAH)
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 3, 100, 97, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
    }

    public function test_moves_both_legs_to_order_date(): void
    {
        $p = $this->product('SKU-B');
        $order = ShopeeOrder::create([
            'order_sn' => 'SP1', 'status' => 'COMPLETED', 'stock_status' => ShopeeOrder::STATUS_PENDING,
            'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-B', 'name' => 'x', 'qty' => 4]],
        ]);
        // potong (OUT) 11 Agu + batal (IN) 12 Agu — keduanya salah tanggal
        $this->movement('shopee_order', $order->id, $p->id, 'OUT', 4, 100, 96, '2026-08-11 10:00:00');
        $this->movement('shopee_order', $order->id, $p->id, 'IN', 4, 96, 100, '2026-08-12 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mvs = StockMovement::where('reference_type', 'shopee_order')->where('reference_id', $order->id)->get();
        $this->assertCount(2, $mvs);
        foreach ($mvs as $mv) {
            $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        }
    }

    public function test_clamps_to_deduct_from(): void
    {
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'deduct_from' => '2026-07-15',
        ]);
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTOLD', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => '2026-07-10 09:00:00',   // SEBELUM titik-nol
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 1]],
        ]);
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 1, 100, 99, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-07-15', $mv->created_at->toDateString());  // di-floor ke cutoff, bukan 07-10
    }

    public function test_leaves_movement_when_order_date_null(): void
    {
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTNULL', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => null,
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 1]],
        ]);
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 1, 100, 99, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-11', $mv->created_at->toDateString());  // tak berubah
    }

    public function test_is_idempotent(): void
    {
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTIDEM', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 3]],
        ]);
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 3, 100, 97, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();
        MarketplaceMovementDateBackfill::run();   // dua kali

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=MarketplaceMovementDateBackfillTest`
Expected: FAIL — `Class "App\Support\MarketplaceMovementDateBackfill" not found`.

- [ ] **Step 3: Buat support class**

Buat `app/Support/MarketplaceMovementDateBackfill.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi satu-arah: geser created_at gerakan stok marketplace (tiktok_order /
 * shopee_order) ke TANGGAL order (order_created_at), di-floor ke titik-nol opname
 * (deduct_from) per-platform supaya tak ada yang mendarat sebelum opname.
 *
 * Perlu karena dulu deduct()/reverse() tak meneruskan tanggal order ke gerakan
 * stok, jadi gerakan lama dicap now() (hari potong). Saldo TIDAK berubah (running
 * balance netral terhadap tanggal) — hanya tanggal digeser supaya Laporan Stok HQ
 * akurat. Mengenai KEDUA kaki (potong OUT + batal IN). Idempoten. Pure DB::table
 * (portabel SQLite/MySQL, aman dijalankan dalam migrasi).
 */
class MarketplaceMovementDateBackfill
{
    public static function run(): void
    {
        self::backfill('tiktok_orders', 'tiktok_order', self::cutoff('tiktok_connections'));
        self::backfill('shopee_orders', 'shopee_order', self::cutoff('shopee_connections'));
    }

    private static function backfill(string $ordersTable, string $referenceType, ?Carbon $cutoff): void
    {
        foreach (DB::table($ordersTable)->select('id', 'order_created_at')->get() as $o) {
            if (! $o->order_created_at) {
                continue; // tanpa tanggal → biarkan gerakan apa adanya
            }
            $date = Carbon::parse($o->order_created_at);
            if ($cutoff && $date->lt($cutoff)) {
                $date = $cutoff->copy();   // floor ke titik-nol opname
            }
            DB::table('stock_movements')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $o->id)
                ->update(['created_at' => $date]);   // kedua kaki (OUT + IN)
        }
    }

    private static function cutoff(string $connectionsTable): ?Carbon
    {
        $c = DB::table($connectionsTable)->orderByDesc('id')->first();

        return isset($c->deduct_from) && $c->deduct_from
            ? Carbon::parse($c->deduct_from)->startOfDay()
            : null;
    }
}
```

- [ ] **Step 4: Buat migrasi 000079**

Buat `database/migrations/2026_01_01_000079_backfill_marketplace_movement_dates.php`:

```php
<?php

use App\Support\MarketplaceMovementDateBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Betulkan tanggal gerakan marketplace lama = tanggal order (floor ke deduct_from).
        MarketplaceMovementDateBackfill::run();
    }

    public function down(): void
    {
        // Koreksi satu arah — timestamp now() asli tak disimpan, tak bisa dibalik.
    }
};
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=MarketplaceMovementDateBackfillTest`
Expected: PASS (5 test). (RefreshDatabase menjalankan migrasi 000079 saat tabel kosong = no-op; test memanggil `run()` langsung atas data yang di-seed.)

- [ ] **Step 6: Jalankan SELURUH suite (regresi)**

Run: `C:\php83\php.exe artisan test`
Expected: PASS semua (existing + 11 test baru). Perbaiki bila ada yang merah sebelum commit.

- [ ] **Step 7: Pint + Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Support/MarketplaceMovementDateBackfill.php database/migrations/2026_01_01_000079_backfill_marketplace_movement_dates.php tests/Feature/MarketplaceMovementDateBackfillTest.php
git commit -m "fix(stok): backfill tanggal gerakan marketplace lama ke tanggal order" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Perbaiki tooling yang mengasumsikan urutan-tulis = urutan-tanggal

**Kenapa:** `after_qty`/`before_qty` gerakan adalah snapshot saldo SAAT DITULIS. Setelah backdate (Task 1/2 + 000077 lama), `created_at` bisa lebih lampau dari urutan tulis, jadi tooling yang memilih gerakan by `created_at` terbesar salah menganggap snapshot lama = saldo sekarang. `stock:reconcile-hq --force` bisa MENIMPA `hq_stock` (mengembalikan penjualan nyata); `stock:holders --trace` (traceHq) memunculkan peringatan "perubahan tanpa jejak" PALSU + kartu stok tak nyambung. Perbaikan: pilih/urutkan berdasar `id` (urutan tulis; id auto-increment monoton dgn penulisan). `traceMovements` (stok MITRA, `user_id` diisi) SENGAJA tak diubah — gerakan mitra tak pernah di-backdate.

**Files:**
- Modify: `app/Console/Commands/StockReconcileHqCommand.php` (query `$terakhir`, ~b.64-69)
- Modify: `app/Console/Commands/StockHoldersCommand.php` (query `traceHq`, ~b.125-129)
- Test: `tests/Feature/StockReconcileHqTest.php` (append 2 test), `tests/Feature/StockHoldersCommandTest.php` (append 1 test)

**Interfaces:**
- Consumes: `stock_movements` (`id`, `after_qty`, `user_id`). Tak ada interface baru.

- [ ] **Step 1: Tulis test yang gagal (append ke 2 file test yang ADA)**

Tambah ke `tests/Feature/StockReconcileHqTest.php` (dalam class, pakai helper `superAdmin()` yang sudah ada):

```php
    /**
     * Backdate: gerakan bisa dicap created_at lampau (penjualan marketplace dipotong
     * hari ini tapi bertanggal order kemarin). Yang otoritatif = gerakan TERAKHIR DITULIS
     * (id terbesar), bukan created_at terbesar — after_qty itu snapshot saat tulis.
     */
    public function test_saldo_diambil_dari_gerakan_terakhir_ditulis_bukan_created_at_terbaru(): void
    {
        $p = Product::create([
            'name' => 'SABUN BACKDATE', 'sku' => 'SB-1', 'hq_stock' => 107,
            'status' => 'active', 'cogs' => 1000, 'price_distributor' => 2000, 'price_reseller' => 2500,
        ]);
        // Ditulis PERTAMA (id kecil), tanggal LEBIH BARU: produksi -> after 110.
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_IN,
            'quantity' => 10, 'before_qty' => 100, 'after_qty' => 110,
            'reference_type' => 'production', 'reference_id' => 1, 'created_at' => '2026-08-19 09:00:00',
        ]);
        // Ditulis KEDUA (id besar), tanggal BACKDATE lampau: penjualan -> after 107.
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_OUT,
            'quantity' => 3, 'before_qty' => 110, 'after_qty' => 107,
            'reference_type' => 'tiktok_order', 'reference_id' => 2, 'created_at' => '2026-08-18 09:00:00',
        ]);

        // Saldo 107 = after_qty gerakan terakhir DITULIS -> tak ada selisih palsu.
        $this->assertSame(0, Artisan::call('stock:reconcile-hq', ['cari' => 'SABUN BACKDATE']));
        $this->assertStringContainsString('sudah cocok', Artisan::output());
        $this->assertSame(107, (int) $p->fresh()->hq_stock);
    }

    /**
     * --force menyetel ke saldo gerakan terakhir DITULIS, bukan gerakan ber-created_at
     * terbesar — kalau salah, backdate bisa membuat --force mengembalikan penjualan nyata.
     */
    public function test_force_menyetel_ke_gerakan_terakhir_ditulis_bukan_created_at_terbaru(): void
    {
        // Ada perubahan tak berjejak: hq_stock 200. Gerakan terakhir ditulis after 107.
        $p = Product::create([
            'name' => 'SABUN DRIFT', 'sku' => 'SD-1', 'hq_stock' => 200,
            'status' => 'active', 'cogs' => 1000, 'price_distributor' => 2000, 'price_reseller' => 2500,
        ]);
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_IN,
            'quantity' => 10, 'before_qty' => 100, 'after_qty' => 110,
            'reference_type' => 'production', 'reference_id' => 1, 'created_at' => '2026-08-19 09:00:00',
        ]);
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_OUT,
            'quantity' => 3, 'before_qty' => 110, 'after_qty' => 107,
            'reference_type' => 'tiktok_order', 'reference_id' => 2, 'created_at' => '2026-08-18 09:00:00',
        ]);
        $sa = $this->superAdmin();

        Artisan::call('stock:reconcile-hq', ['cari' => 'SABUN DRIFT', '--force' => true, '--as' => $sa->username]);

        // Disetel ke 107 (write-order), BUKAN 110 (created_at terbaru).
        $this->assertSame(107, (int) $p->fresh()->hq_stock);
    }
```

Tambah ke `tests/Feature/StockHoldersCommandTest.php` (dalam class, pakai helper `product()` yang sudah ada):

```php
    /**
     * Gerakan HQ bisa di-backdate (created_at lampau dari urutan tulis). Kartu stok &
     * cek "gerakan terakhir" harus pakai urutan TULIS (id), jadi saldo yang cocok tak
     * memicu peringatan "perubahan tanpa jejak" palsu.
     */
    public function test_trace_hq_tidak_salah_lapor_pada_gerakan_backdate(): void
    {
        $p = $this->product();
        // Ditulis pertama (id kecil) tanggal lebih baru; ditulis kedua (id besar) backdate.
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_IN,
            'quantity' => 10, 'before_qty' => 990, 'after_qty' => 1000,
            'reference_type' => 'production', 'reference_id' => 1, 'created_at' => '2026-08-19 09:00:00',
        ]);
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_OUT,
            'quantity' => 3, 'before_qty' => 1000, 'after_qty' => 997,
            'reference_type' => 'tiktok_order', 'reference_id' => 2, 'created_at' => '2026-08-18 09:00:00',
        ]);
        $p->hq_stock = 997;   // = after_qty gerakan terakhir DITULIS
        $p->save();

        $this->assertSame(0, Artisan::call('stock:holders', ['cari' => 'MIZU', '--trace' => true]));
        $this->assertStringNotContainsString('ada perubahan tanpa jejak', Artisan::output());
    }
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=StockReconcileHqTest` dan `--filter=StockHoldersCommandTest`
Expected: 3 test baru GAGAL — kode lama pilih gerakan by `created_at` terbesar (produksi after 110 / IN after 1000), jadi lapor selisih/peringatan palsu.

- [ ] **Step 3: Perbaiki StockReconcileHqCommand (urut by id)**

Di `app/Console/Commands/StockReconcileHqCommand.php`, ganti query `$terakhir`:

```php
            // Gerakan TERAKHIR DITULIS (id terbesar), BUKAN created_at terbesar:
            // after_qty adalah snapshot saldo saat gerakan ditulis, dan gerakan bisa
            // di-backdate (created_at lebih lampau dari urutan tulisnya, mis. penjualan
            // marketplace dipotong hari ini tapi bertanggal order kemarin). id auto-increment
            // = urutan tulis sebenarnya, jadi after_qty-nya = saldo sistem sekarang.
            $terakhir = StockMovement::query()
                ->whereNull('user_id')
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->first();
```

- [ ] **Step 4: Perbaiki StockHoldersCommand::traceHq (urut by id)**

Di `app/Console/Commands/StockHoldersCommand.php`, pada `traceHq()`, ganti query `$moves`:

```php
        // Urut berdasar id (urutan TULIS), bukan created_at: kolom saldo (before->after)
        // itu snapshot saat penulisan, dan gerakan HQ bisa di-backdate (created_at lampau).
        // Hanya urutan tulis yang membuat kartu stok nyambung & "$moves->last()" = gerakan
        // terakhir ditulis (saldo sistem sekarang).
        $moves = StockMovement::query()
            ->whereNull('user_id')
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get();
```

(JANGAN ubah `traceMovements()` — itu stok mitra, tak pernah di-backdate.)

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=StockReconcileHqTest` dan `--filter=StockHoldersCommandTest`
Expected: PASS semua (lama + 3 baru).

- [ ] **Step 6: Jalankan SELURUH suite (regresi)**

Run: `C:\php83\php.exe artisan test`
Expected: PASS semua.

- [ ] **Step 7: Pint + Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Console/Commands/StockReconcileHqCommand.php app/Console/Commands/StockHoldersCommand.php tests/Feature/StockReconcileHqTest.php tests/Feature/StockHoldersCommandTest.php
git commit -m "fix(stok): reconcile & trace HQ pakai urutan tulis (id), bukan created_at" -m "Gerakan yang di-backdate membuat after_qty (snapshot saat tulis) tak lagi selaras urutan created_at; --force reconcile bisa menimpa hq_stock. Pilih gerakan terakhir by id." -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian

Setelah 3 task selesai & suite hijau:
- **REQUIRED SUB-SKILL:** superpowers:finishing-a-development-branch.
- **Deploy prod (ADA 1 migrasi 000079):**
  ```
  git pull origin main
  /opt/alt/php83/usr/bin/php artisan migrate --force
  /opt/alt/php83/usr/bin/php artisan optimize:clear
  ```
  + hard-refresh. Setelah deploy: buka Laporan Stok HQ, cek penjualan TikTok/Shopee kini muncul di **tanggal order** (bukan tanggal potong).

---

## Self-Review (penulis rencana)

**1. Cakupan spec:**
- Forward fix deduct (OUT) TikTok+Shopee → Task 1 Step 3-4 ✅
- Forward fix reverse (IN) net-nol → Task 1 Step 3-4 + test reverse ✅
- Fallback null → Task 1 test `..._falls_back_to_now...` ✅
- Level-laporan (bukti tujuan) → Task 1 test `..._lands_on_order_day_in_report...` ✅
- Backfill kedua kaki → Task 2 support class + test `..._both_legs...` ✅
- Clamp ke deduct_from → Task 2 `cutoff()` + test `..._clamps_to_deduct_from` ✅
- Idempoten & null → Task 2 test `..._is_idempotent`, `..._leaves_movement_when_order_date_null` ✅
- Regresi penuh → Task 2 Step 6 ✅

**2. Placeholder scan:** Semua langkah berisi kode nyata; migrasi `down()` no-op diberi alasan (koreksi satu-arah).

**3. Konsistensi tipe:** `occurredAt: $order->order_created_at` (Carbon|null) cocok signature `?\DateTimeInterface`. `reference_type` `'tiktok_order'`/`'shopee_order'` konsisten forward↔backfill↔bucketize. `MarketplaceMovementDateBackfill::run()` dipakai konsisten migrasi↔test. `StockMovement::TYPE_OUT/TYPE_IN` = `'OUT'`/`'IN'` (terverifikasi). Migrasi `000079` unik (terakhir `000078`). `deduct_from` clamp pakai tabel & startOfDay() yang sama dengan `cutoff()` service.

**Catatan risiko (untuk reviewer/final):** Forward path SENGAJA tak di-clamp (guard `isBeforeCutoff` sudah menjamin `order_created_at >= deduct_from` saat pemotongan). Clamp hanya jaring pengaman historis di backfill. Bila `deduct_from` digeser MAJU setelah pemotongan, gerakan forward lama bisa < cutoff baru — dijalankan-ulang backfill akan meng-clamp-nya. Saldo tak pernah berubah. Non-blocking.
