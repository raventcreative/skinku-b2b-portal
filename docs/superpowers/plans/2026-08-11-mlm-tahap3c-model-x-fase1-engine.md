# MLM Tahap 3c Model X — Fase 1 (Engine) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PO dapat konsep penjual (`seller_id`); saat PO selesai, kalau seller = mitra (upline), stok berpindah upline→downline (bukan dari HQ) — engine rantai pasok Model X, dormant-safe.

**Architecture:** Kolom `purchase_orders.seller_id` (null=HQ). `createForPartner` set seller = upline pembeli. `complete()` bercabang: seller null → jalur HQ existing (nol perubahan); seller = mitra → potong stok upline + tambah stok downline (`adjustPartnerStock` dua sisi, atomik, guard stok-kurang). Reuse mesin PO/stok existing.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent. Zero-dependency.

**Spec:** `docs/superpowers/specs/2026-08-11-hirarki-mitra-mlm-tahap3c-model-x-design.md`
**Branch:** `feat/mlm-tahap3c-model-x` (spec sudah di-commit)

## Global Constraints

- **Zero-dependency**: tak menambah paket.
- **Runner**: `C:\php83\php.exe artisan test`. `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Migrasi 000080** (terakhir 000079).
- **Dormant-safe**: `seller_id` null = HQ = perilaku existing. Jaringan prod kosong (`upline_id` semua null) → semua PO seller null → nol perubahan. Alur PO↔HQ existing WAJIB tetap hijau.
- **Atomik**: transfer antar-mitra dua sisi dalam SATU `DB::transaction`; stok tak pernah minus (guard `adjustPartnerStock`).
- **Akuntansi**: `complete()` existing TIDAK auto-journal (sudah diverifikasi: nol referensi jurnal di `PurchaseOrderService`; tak ada PO observer). Jadi tak perlu guard jurnal di fase ini.

## Konteks kode (terverifikasi)

- `PurchaseOrderService::createForPartner(User $buyer, array $lines, ?string $shippingAddress, ?string $notes, array $priceOverrides=[])` → buat PO status PENDING. Blok `PurchaseOrder::create([...])` berisi `'user_id' => $buyer->id`, `'user_role' => $buyer->role`, dll.
- `PurchaseOrderService::complete(PurchaseOrder $po, ?string $notes=null)`: `DB::transaction`, lock PO, guard double-complete, lalu **`if ($this->isBeforeStockCutoff($po))`** (PO pra-opname → tandai completed + `stock_skipped`, TANPA sentuh stok, return). Kalau tidak: `foreach ($po->items as $item)` → lock product → **cek `$product->hq_stock < $item->qty` (throw)** → `adjustHqStock(product, -qty, TYPE_OUT, 'Pemenuhan PO {po_number}', 'purchase_order', po->id)` → `adjustPartnerStock(userId: po->user_id, productId, +qty, TYPE_PO_FULFILLMENT, 'Penerimaan dari PO {po_number}', 'purchase_order', po->id)`. Lalu set status completed + completed_at + save + audit.
- `InventoryService::adjustPartnerStock(int $userId, int $productId, int $delta, string $movementType, ?string $notes=null, ?string $referenceType=null, ?int $referenceId=null): Inventory` — buat baris bila belum ada; **lempar `RuntimeException` bila saldo jadi negatif**.
- `StockMovement::TYPE_OUT`, `TYPE_PO_FULFILLMENT` ada. `User::upline_id` (Tahap 1) ada.

## ⚠️ Companion WAJIB (BUKAN fase ini — sebelum jaringan diisi)

`ReportService` menghitung **omzet HQ** dari `PurchaseOrder::query()->where('status', STATUS_COMPLETED)->sum('total_amount')` (baris ~68, 219, 313, 349-352) via `scopePo($query,$viewer)`. Inter-partner PO (seller≠HQ) akan **menggelembungkan omzet HQ**. **Sebelum jaringan diisi**, omzet HQ harus dikecualikan inter-partner (`whereNull('seller_id')`) di ReportService + sweep `DashboardController`, `OkrBusinessSnapshotService`, `Ai/Tools/RingkasDashboardTool`. **Dormant sekarang** (jaringan kosong = nol inter-partner PO). Jadikan plan terpisah setelah engine. (Dicatat di sini biar tak lupa.)

---

## Task 1: Kolom `seller_id` + model

**Files:**
- Create: `database/migrations/2026_01_01_000080_add_seller_id_to_purchase_orders.php`
- Modify: `app/Models/PurchaseOrder.php`
- Test: `tests/Feature/PurchaseOrderSellerTest.php`

**Interfaces:**
- Produces: kolom `purchase_orders.seller_id` (nullable, FK users, null=HQ); `PurchaseOrder::$fillable` += `seller_id`; `PurchaseOrder::seller(): BelongsTo`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/PurchaseOrderSellerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderSellerTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    public function test_seller_id_bisa_diisi_dan_relasinya_jalan(): void
    {
        $seller = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $po = PurchaseOrder::create([
            'po_number' => 'SKN-PO-TEST-1', 'created_by' => $seller->id, 'user_id' => $seller->id,
            'seller_id' => $seller->id, 'company_name' => 'CV X', 'user_role' => 'distributor',
            'status' => PurchaseOrder::STATUS_PENDING, 'subtotal' => 0, 'total_amount' => 0,
            'payment_status' => PurchaseOrder::PAYMENT_UNPAID,
        ]);

        $this->assertSame($seller->id, (int) $po->fresh()->seller_id);
        $this->assertSame($seller->id, $po->seller->id);
    }

    public function test_seller_id_boleh_null_hq(): void
    {
        $buyer = $this->user(User::ROLE_DISTRIBUTOR);
        $po = PurchaseOrder::create([
            'po_number' => 'SKN-PO-TEST-2', 'created_by' => $buyer->id, 'user_id' => $buyer->id,
            'company_name' => 'CV Y', 'user_role' => 'distributor',
            'status' => PurchaseOrder::STATUS_PENDING, 'subtotal' => 0, 'total_amount' => 0,
            'payment_status' => PurchaseOrder::PAYMENT_UNPAID,
        ]);

        $this->assertNull($po->fresh()->seller_id);
        $this->assertNull($po->seller);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=PurchaseOrderSellerTest`
Expected: FAIL — kolom `seller_id` belum ada / bukan fillable / relasi `seller()` belum ada.

- [ ] **Step 3: Buat migrasi 000080**

Buat `database/migrations/2026_01_01_000080_add_seller_id_to_purchase_orders.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // null = HQ (penjual default); terisi = penjual mitra (upline pembeli).
            $table->foreignId('seller_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_id');
        });
    }
};
```

- [ ] **Step 4: Update model `PurchaseOrder`**

Di `app/Models/PurchaseOrder.php`:

1. Tambah `'seller_id'` ke `$fillable` (setelah `'user_id',`).
2. Tambah relasi (dekat relasi `user()`):
```php
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=PurchaseOrderSellerTest`
Expected: PASS (2 test).

- [ ] **Step 6: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000080_add_seller_id_to_purchase_orders.php app/Models/PurchaseOrder.php tests/Feature/PurchaseOrderSellerTest.php
git commit -m "feat(mlm): kolom purchase_orders.seller_id (null=HQ) + relasi seller" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `createForPartner` set seller = upline pembeli

**Files:**
- Modify: `app/Services/PurchaseOrderService.php` (blok `PurchaseOrder::create([...])` di `createForPartner`)
- Test: `tests/Feature/PurchaseOrderSellerRoutingTest.php`

**Interfaces:**
- Consumes: `seller_id` kolom (Task 1), `User::upline_id`.
- Produces: PO baru punya `seller_id = buyer->upline_id` (null bila tak ada upline).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/PurchaseOrderSellerRoutingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderSellerRoutingTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    public function test_po_seller_id_adalah_upline_pembeli(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id); // upline = grand
        $p = $this->product();

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 2]], null, null);

        $this->assertSame($grand->id, (int) $po->seller_id);
    }

    public function test_po_tanpa_upline_seller_null_hq(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null
        $p = $this->product();

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 1]], null, null);

        $this->assertNull($po->seller_id);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=PurchaseOrderSellerRoutingTest`
Expected: FAIL — `test_po_seller_id_adalah_upline_pembeli` dapat null (seller_id belum diset).

- [ ] **Step 3: Set seller_id di createForPartner**

Di `app/Services/PurchaseOrderService.php`, method `createForPartner`, di dalam `PurchaseOrder::create([...])`, tambah baris `'seller_id'` tepat setelah `'user_id' => $buyer->id,`:

```php
                'user_id' => $buyer->id,
                'seller_id' => $buyer->upline_id, // null = HQ; terisi = upline pembeli
```

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=PurchaseOrderSellerRoutingTest`
Expected: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/PurchaseOrderService.php tests/Feature/PurchaseOrderSellerRoutingTest.php
git commit -m "feat(mlm): PO seller = upline pembeli (fallback HQ)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: `complete()` bercabang — transfer stok upline→downline

**Files:**
- Modify: `app/Services/PurchaseOrderService.php` (method `complete()`)
- Test: `tests/Feature/InterPartnerFulfillmentTest.php`

**Interfaces:**
- Consumes: `seller_id` (Task 1/2), `InventoryService::adjustPartnerStock`, `StockMovement::TYPE_OUT`/`TYPE_PO_FULFILLMENT`.
- Produces: `complete()` seller=mitra memindah stok upline→downline; seller=HQ tetap seperti existing.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/InterPartnerFulfillmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class InterPartnerFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function product(int $hqStock = 100): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => $hqStock, 'status' => 'active',
        ]);
    }

    private function stock(User $u, Product $p, int $qty): void
    {
        Inventory::create(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => $qty]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function qty(User $u, Product $p): int
    {
        return (int) Inventory::where('user_id', $u->id)->where('product_id', $p->id)->value('quantity');
    }

    public function test_seller_mitra_potong_stok_upline_tambah_downline(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product(hqStock: 100);
        $this->stock($grand, $p, 50); // upline punya stok

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 10]], null, null);
        $this->svc()->complete($po);

        $this->assertSame(40, $this->qty($grand, $p));   // upline turun 10
        $this->assertSame(10, $this->qty($dist, $p));    // downline naik 10
        $this->assertSame(100, (int) $p->fresh()->hq_stock); // stok HQ TAK tersentuh
    }

    public function test_stok_upline_kurang_complete_gagal_dan_rollback(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->stock($grand, $p, 5); // cuma 5

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 10]], null, null);

        try {
            $this->svc()->complete($po);
            $this->fail('Seharusnya melempar karena stok upline kurang.');
        } catch (RuntimeException $e) {
            // diharapkan
        }

        $this->assertSame(5, $this->qty($grand, $p));                 // tak berubah
        $this->assertSame(0, $this->qty($dist, $p));                  // tak nambah
        $this->assertNull($po->fresh()->completed_at);                // PO tak jadi selesai
    }

    public function test_seller_hq_tetap_potong_stok_hq_regresi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null → seller HQ
        $p = $this->product(hqStock: 100);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 10]], null, null);
        $this->svc()->complete($po);

        $this->assertSame(90, (int) $p->fresh()->hq_stock);  // HQ turun 10 (jalur existing)
        $this->assertSame(10, $this->qty($grand, $p));       // pembeli naik 10
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=InterPartnerFulfillmentTest`
Expected: FAIL — `test_seller_mitra_...` gagal: jalur inter-partner belum ada, `complete()` masih coba potong stok HQ (bisa error "stok pusat cukup tapi upline tak berubah" / hasil salah).

- [ ] **Step 3: Bercabang di `complete()`**

Di `app/Services/PurchaseOrderService.php`, method `complete()`:

**3a.** Guard opname hanya untuk seller HQ — ubah `if ($this->isBeforeStockCutoff($po))` jadi:
```php
            if ($po->seller_id === null && $this->isBeforeStockCutoff($po)) {
```
(Inter-partner tak kena titik-nol opname HQ — opname = stok HQ.)

**3b.** Di dalam `foreach ($po->items as $item) { ... }`, GANTI blok yang: cek `hq_stock < qty` + `adjustHqStock(...)` + `adjustPartnerStock(buyer, +qty, ...)` menjadi bercabang di `$po->seller_id`. Bentuk akhirnya:

```php
            foreach ($po->items as $item) {
                $product = Product::lockForUpdate()->find($item->product_id);
                if (! $product) {
                    throw new RuntimeException("Produk untuk item '{$item->product_name}' tidak ditemukan.");
                }

                if ($po->seller_id === null) {
                    // Jalur HQ (existing) — nol perubahan perilaku.
                    if ((int) $product->hq_stock < (int) $item->qty) {
                        throw new RuntimeException(
                            "Stok pusat untuk {$product->name} tidak mencukupi (tersedia {$product->hq_stock}, dibutuhkan {$item->qty}). Penyelesaian PO dibatalkan."
                        );
                    }
                    $this->inventory->adjustHqStock(
                        product: $product,
                        delta: -1 * (int) $item->qty,
                        movementType: StockMovement::TYPE_OUT,
                        notes: "Pemenuhan PO {$po->po_number}",
                        referenceType: 'purchase_order',
                        referenceId: $po->id,
                    );
                } else {
                    // Inter-partner: potong stok UPLINE (seller). adjustPartnerStock
                    // melempar bila saldo negatif → stok upline tak cukup → rollback.
                    $this->inventory->adjustPartnerStock(
                        userId: $po->seller_id,
                        productId: $product->id,
                        delta: -1 * (int) $item->qty,
                        movementType: StockMovement::TYPE_OUT,
                        notes: "Kirim ke downline — PO {$po->po_number}",
                        referenceType: 'purchase_order',
                        referenceId: $po->id,
                    );
                }

                // Tambah stok PEMBELI (sama untuk kedua jalur).
                $this->inventory->adjustPartnerStock(
                    userId: $po->user_id,
                    productId: $product->id,
                    delta: (int) $item->qty,
                    movementType: StockMovement::TYPE_PO_FULFILLMENT,
                    notes: "Penerimaan dari PO {$po->po_number}",
                    referenceType: 'purchase_order',
                    referenceId: $po->id,
                );
            }
```

(Sisa `complete()` — set status completed, completed_at, save, audit — TAK berubah. `RuntimeException`/`StockMovement`/`Product` sudah di-`use` di file.)

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=InterPartnerFulfillmentTest`
Expected: PASS (3 test).

- [ ] **Step 5: Jalankan SELURUH suite (regresi PO/stok)**

Run: `C:\php83\php.exe artisan test`
Expected: PASS semua (existing + baru). Alur PO↔HQ existing WAJIB tetap hijau. Perbaiki bila ada yang merah sebelum commit.

- [ ] **Step 6: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/PurchaseOrderService.php tests/Feature/InterPartnerFulfillmentTest.php
git commit -m "feat(mlm): complete() transfer stok upline→downline utk PO inter-partner" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian

Setelah 3 task & suite hijau:
- **REQUIRED SUB-SKILL:** superpowers:finishing-a-development-branch.
- **Deploy prod (ADA migrasi 000080):** `git pull origin main && /opt/alt/php83/usr/bin/php artisan migrate --force && /opt/alt/php83/usr/bin/php artisan optimize:clear`. **Dormant** — jaringan kosong = semua PO seller null = perilaku existing.
- **Fase berikut (plan terpisah):** (1) **Companion WAJIB** — kecualikan inter-partner dari omzet HQ di ReportService/Dashboard/OKR/AI (sebelum jaringan diisi). (2) Workflow "Pesanan Downline" (upline proses PO). (3) Pembayaran (seller verifikasi).

---

## Self-Review (penulis rencana)

**1. Cakupan (Fase 1):** kolom seller_id + model → Task 1 ✅ · createForPartner set seller → Task 2 ✅ · complete() bercabang + guard stok-kurang + skip-opname-inter-partner → Task 3 ✅ · regresi HQ → Task 3 Step 5 ✅. Akuntansi-hold: no-auto-journal terverifikasi (tak perlu guard). Companion omzet-HQ → flagged sebagai plan terpisah.

**2. Placeholder scan:** semua langkah kode nyata. Companion omzet-HQ jelas ditandai plan-terpisah (bukan placeholder di fase ini).

**3. Konsistensi tipe:** `seller_id` (nullable FK) konsisten migrasi→model→create→complete. `adjustPartnerStock(userId,productId,delta,movementType,...)` sesuai signature existing. `PurchaseOrder::STATUS_*`/`PAYMENT_*` sesuai model. Migrasi 000080 unik.
