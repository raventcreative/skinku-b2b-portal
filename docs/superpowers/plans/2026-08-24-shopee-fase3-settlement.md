# Shopee Fase 3 (Settlement/Escrow) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Tarik penyelesaian escrow per-order Shopee (income + fee pasti per order) → simpan → tampil. Data valid untuk fondasi jurnal Fase 4.

**Architecture:** Meniru PERAN `TikTokSettlementService` (simpan data pencairan) tapi struktur per-order escrow (bukan per-statement + kind-guess). Reuse pola `ShopeeSyncService`/token/paginasi + struktur kontroler/view retur Fase 2 (baru merged). Field escrow SUDAH tervalidasi ke sandbox (order `2608247FYHUBMG`).

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Eloquent. Runner: `C:/php83/php.exe artisan test`. Pint: `C:/php83/php.exe vendor/bin/pint --dirty` sebelum tiap commit.

## Global Constraints

- **Zero-dependency**; HTTP lewat `ShopeeClient::shopCall`.
- **Simpan saja, BELUM jurnal**: kolom `posting_status`/`journal_id`/`posted_at`/`posted_by` disiapkan, tak dipakai di Fase 3.
- **Field escrow tervalidasi sandbox** (lihat spec §Verifikasi): nama `order_income.*` persis. Tetap map defensif (`?? 0`) + `raw` simpan penuh.
- **Idempoten**: `updateOrCreate` by `order_sn`; jangan reset `posting_status`.
- **Referensi mirror**: `app/Services/ShopeeReturnService.php` + `app/Http/Controllers/ShopeeController.php` (aksi retur) + `resources/views/shopee/returns.blade.php` (Fase 2, baru merged) + `docs/superpowers/research/tiktok-fase2-4-map.md` §3.2.
- **Deploy = git pull**: 1 migrasi baru `000094`.

---

### Task 1: Model `ShopeeSettlement` + migrasi `000094`

**Files:** Create `database/migrations/2026_01_01_000094_create_shopee_settlements_table.php`, `app/Models/ShopeeSettlement.php`; Test `tests/Feature/ShopeeSettlementTest.php`.

**Interfaces — Produces:** model `App\Models\ShopeeSettlement` dengan `POST_PENDING='pending'`, `POST_POSTED='posted'`, `isPosted():bool`, `feeTotal():float`; kolom per spec.

- [ ] **Step 1: Write failing test** — `tests/Feature/ShopeeSettlementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ShopeeSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_simpan_dan_hitung_fee_total(): void
    {
        $s = ShopeeSettlement::create([
            'order_sn' => 'S-1', 'currency' => 'IDR',
            'escrow_amount' => 64675, 'buyer_total_amount' => 77665,
            'commission_fee' => 1000, 'service_fee' => 500, 'campaign_fee' => 0,
            'seller_transaction_fee' => 0, 'actual_shipping_fee' => 11765,
            'buyer_paid_shipping_fee' => 11765, 'shopee_shipping_rebate' => 0,
            'escrow_tax' => 0, 'withholding_tax' => 0, 'total_adjustment_amount' => 0,
            'posting_status' => ShopeeSettlement::POST_PENDING,
        ]);

        $this->assertFalse($s->isPosted());
        $this->assertEquals('64675.00', $s->fresh()->escrow_amount);
        $this->assertEquals(1500.0, $s->feeTotal()); // commission + service + campaign + seller_txn + tax
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`--filter=ShopeeSettlementTest`) — class/tabel tak ada.

- [ ] **Step 3: Migration**:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('order_sn')->unique();
            $table->string('currency', 8)->nullable();
            $table->decimal('escrow_amount', 16, 2)->default(0);
            $table->decimal('buyer_total_amount', 16, 2)->default(0);
            $table->decimal('commission_fee', 16, 2)->default(0);
            $table->decimal('service_fee', 16, 2)->default(0);
            $table->decimal('campaign_fee', 16, 2)->default(0);
            $table->decimal('seller_transaction_fee', 16, 2)->default(0);
            $table->decimal('actual_shipping_fee', 16, 2)->default(0);
            $table->decimal('buyer_paid_shipping_fee', 16, 2)->default(0);
            $table->decimal('shopee_shipping_rebate', 16, 2)->default(0);
            $table->decimal('escrow_tax', 16, 2)->default(0);
            $table->decimal('withholding_tax', 16, 2)->default(0);
            $table->decimal('total_adjustment_amount', 16, 2)->default(0);
            $table->dateTime('escrow_release_time')->nullable();
            $table->json('raw')->nullable();
            $table->string('posting_status', 20)->default('pending')->index();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_settlements');
    }
};
```

- [ ] **Step 4: Model** `app/Models/ShopeeSettlement.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeSettlement extends Model
{
    public const POST_PENDING = 'pending';

    public const POST_POSTED = 'posted';

    protected $fillable = [
        'order_sn', 'currency', 'escrow_amount', 'buyer_total_amount',
        'commission_fee', 'service_fee', 'campaign_fee', 'seller_transaction_fee',
        'actual_shipping_fee', 'buyer_paid_shipping_fee', 'shopee_shipping_rebate',
        'escrow_tax', 'withholding_tax', 'total_adjustment_amount',
        'escrow_release_time', 'raw',
        'posting_status', 'journal_id', 'posted_at', 'posted_by',
    ];

    protected $casts = [
        'escrow_amount' => 'decimal:2', 'buyer_total_amount' => 'decimal:2',
        'commission_fee' => 'decimal:2', 'service_fee' => 'decimal:2',
        'campaign_fee' => 'decimal:2', 'seller_transaction_fee' => 'decimal:2',
        'actual_shipping_fee' => 'decimal:2', 'buyer_paid_shipping_fee' => 'decimal:2',
        'shopee_shipping_rebate' => 'decimal:2', 'escrow_tax' => 'decimal:2',
        'withholding_tax' => 'decimal:2', 'total_adjustment_amount' => 'decimal:2',
        'raw' => 'array', 'escrow_release_time' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function isPosted(): bool
    {
        return $this->posting_status === self::POST_POSTED;
    }

    /** Total potongan platform (komisi+layanan+campaign+txn seller+pajak). Ongkir ditampilkan terpisah. */
    public function feeTotal(): float
    {
        return (float) $this->commission_fee + (float) $this->service_fee
            + (float) $this->campaign_fee + (float) $this->seller_transaction_fee
            + (float) $this->escrow_tax + (float) $this->withholding_tax;
    }
}
```

- [ ] **Step 5: Run — expect PASS.**
- [ ] **Step 6: Pint + commit** `feat(shopee) Fase 3: model ShopeeSettlement + migrasi shopee_settlements`.

---

### Task 2: `ShopeeClient` — 3 method escrow

**Files:** Modify `app/Services/ShopeeClient.php` (tambah setelah `getReturnDetail`, sebelum `getShopsByPartner`); Test `tests/Feature/ShopeeSettlementTest.php` (tambah).

**Interfaces — Consumes:** `ShopeeClient::shopCall`. **Produces:** `getEscrowList`, `getEscrowDetail`, `getEscrowDetailBatch`.

- [ ] **Step 1: Failing test** (tambah):

```php
use App\Services\ShopeeClient;
use Illuminate\Support\Facades\Http;
```

```php
public function test_client_escrow_list_kirim_path_dan_sign(): void
{
    config(['services.shopee.partner_id' => '123', 'services.shopee.partner_key' => 'secret',
        'services.shopee.api_base' => 'https://partner.example.com']);
    Http::fake(['*get_escrow_list*' => Http::response(['response' => ['escrow_list' => [], 'more' => false]])]);

    app(ShopeeClient::class)->getEscrowList('ACCESS', 'SHOP', 100, 200, 1, 100);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v2/payment/get_escrow_list')
        && str_contains($r->url(), 'release_time_from=100') && str_contains($r->url(), 'sign='));
}
```

- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Add methods** (setelah `getReturnDetail`):

```php
/** Daftar order yang escrow-nya dirilis dalam rentang waktu (discovery ringan). */
public function getEscrowList(string $accessToken, string $shopId, int $releaseFrom, int $releaseTo, int $pageNo = 1, int $pageSize = 100): array
{
    return $this->shopCall('GET', '/api/v2/payment/get_escrow_list', $accessToken, $shopId, [
        'release_time_from' => $releaseFrom,
        'release_time_to' => $releaseTo,
        'page_no' => $pageNo,
        'page_size' => $pageSize,
    ]);
}

/** Rincian income/fee 1 order. */
public function getEscrowDetail(string $accessToken, string $shopId, string $orderSn): array
{
    return $this->shopCall('GET', '/api/v2/payment/get_escrow_detail', $accessToken, $shopId, [
        'order_sn' => $orderSn,
    ]);
}

/** Rincian income/fee ≤50 order sekaligus (POST). */
public function getEscrowDetailBatch(string $accessToken, string $shopId, array $orderSns): array
{
    return $this->shopCall('POST', '/api/v2/payment/get_escrow_detail_batch', $accessToken, $shopId, [
        'order_sn_list' => array_slice(array_values($orderSns), 0, 50),
    ]);
}
```

- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Pint + commit** `feat(shopee) Fase 3: ShopeeClient getEscrowList/Detail/DetailBatch`.

---

### Task 3: `ShopeeSettlementService` (store + mapIncome)

**Files:** Create `app/Services/ShopeeSettlementService.php`; Test `tests/Feature/ShopeeSettlementTest.php` (tambah).

**Interfaces — Produces:** `store(array $apiDetails): int`, `mapIncome(array $orderIncome): array`.

- [ ] **Step 1: Failing test** — fixture dari data escrow ASLI order `2608247FYHUBMG`:

```php
public function test_store_peta_income_dari_escrow_detail(): void
{
    $svc = app(\App\Services\ShopeeSettlementService::class);
    // bentuk = elemen response get_escrow_detail_batch (order_sn + order_income + escrow_release_time gabungan)
    $detail = [
        'order_sn' => '2608247FYHUBMG',
        'escrow_release_time' => now()->timestamp,
        'order_income' => [
            'escrow_amount' => 64675, 'buyer_total_amount' => 77665,
            'commission_fee' => 0, 'service_fee' => 0, 'campaign_fee' => 0,
            'seller_transaction_fee' => 0, 'actual_shipping_fee' => 11765,
            'buyer_paid_shipping_fee' => 11765, 'shopee_shipping_rebate' => 0,
            'escrow_tax' => 0, 'withholding_tax' => 0, 'total_adjustment_amount' => 0,
        ],
    ];

    $n = $svc->store([$detail]);

    $this->assertSame(1, $n);
    $row = \App\Models\ShopeeSettlement::where('order_sn', '2608247FYHUBMG')->first();
    $this->assertEquals('64675.00', $row->escrow_amount);
    $this->assertEquals('11765.00', $row->actual_shipping_fee);
    $this->assertSame(\App\Models\ShopeeSettlement::POST_PENDING, $row->posting_status);
    $this->assertNotNull($row->escrow_release_time);
    $this->assertIsArray($row->raw);

    // idempoten: posting_status tak reset kalau sudah posted
    $row->update(['posting_status' => \App\Models\ShopeeSettlement::POST_POSTED]);
    $svc->store([$detail]);
    $this->assertSame(\App\Models\ShopeeSettlement::POST_POSTED, $row->fresh()->posting_status);
}
```

- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Service** `app/Services/ShopeeSettlementService.php`:

```php
<?php

namespace App\Services;

use App\Models\ShopeeSettlement;
use Illuminate\Support\Carbon;

/**
 * Simpan penyelesaian escrow per-order Shopee. Field order_income tervalidasi ke
 * sandbox (order 2608247FYHUBMG). Simpan yang relevan-ID ke kolom + raw penuh.
 * BELUM ada jurnal — itu Fase 4 (baca posting_status).
 */
class ShopeeSettlementService
{
    /** @param array $apiDetails elemen = {order_sn, order_income{...}, escrow_release_time?} */
    public function store(array $apiDetails): int
    {
        $n = 0;
        foreach ($apiDetails as $d) {
            $sn = $d['order_sn'] ?? null;
            if (! $sn) {
                continue;
            }
            $existing = ShopeeSettlement::where('order_sn', $sn)->first();
            $rt = $d['escrow_release_time'] ?? null;

            ShopeeSettlement::updateOrCreate(
                ['order_sn' => (string) $sn],
                array_merge($this->mapIncome($d['order_income'] ?? []), [
                    'currency' => $d['currency'] ?? (($d['order_income']['currency'] ?? null)),
                    'escrow_release_time' => $rt ? Carbon::createFromTimestamp((int) $rt) : null,
                    'raw' => $d,
                    'posting_status' => $existing->posting_status ?? ShopeeSettlement::POST_PENDING,
                ]),
            );
            $n++;
        }

        return $n;
    }

    /** order_income Shopee → kolom kita (defensif, nol bila absen). */
    public function mapIncome(array $income): array
    {
        $num = fn (string $k) => (float) ($income[$k] ?? 0);

        return [
            'escrow_amount' => $num('escrow_amount'),
            'buyer_total_amount' => $num('buyer_total_amount'),
            'commission_fee' => $num('commission_fee'),
            'service_fee' => $num('service_fee'),
            'campaign_fee' => $num('campaign_fee'),
            'seller_transaction_fee' => $num('seller_transaction_fee'),
            'actual_shipping_fee' => $num('actual_shipping_fee'),
            'buyer_paid_shipping_fee' => $num('buyer_paid_shipping_fee'),
            'shopee_shipping_rebate' => $num('shopee_shipping_rebate'),
            'escrow_tax' => $num('escrow_tax'),
            'withholding_tax' => $num('withholding_tax'),
            'total_adjustment_amount' => $num('total_adjustment_amount'),
        ];
    }
}
```

- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Pint + commit** `feat(shopee) Fase 3: ShopeeSettlementService store + mapIncome`.

---

### Task 4: `ShopeeSyncService::syncSettlements` + command `--settlements` + cron

**Files:** Modify `app/Services/ShopeeSyncService.php` (dep `ShopeeSettlementService` + method), `app/Console/Commands/ShopeeSyncCommand.php` (opsi), `routes/console.php` (cron); Test `tests/Feature/ShopeeSettlementTest.php` (tambah).

**Interfaces — Consumes:** `ShopeeClient::getEscrowList/getEscrowDetailBatch` (Task 2), `ShopeeSettlementService::store` (Task 3), `ShopeeSyncService::freshToken` + property `$this->shopee` (ShopeeClient) + `$this->returns` pola dep (Fase 2). **Produces:** `syncSettlements(ShopeeConnection): array`.

> **Baca dulu** `app/Services/ShopeeSyncService.php` (konstruktor: `$this->shopee` = ShopeeClient; `freshToken`; pola `syncReturns` Fase 2 untuk paginasi+chunk). Tambah `private ShopeeSettlementService $settlements` ke konstruktor.

- [ ] **Step 1: Failing test** — fake client (escrow_list 1 order + detail batch):

```php
use App\Models\ShopeeConnection;
use App\Services\ShopeeSyncService;
```

```php
public function test_syncsettlements_dari_client_fake(): void
{
    $client = new class extends ShopeeClient
    {
        public function __construct() {}

        public function getEscrowList(string $a, string $s, int $f, int $t, int $p = 1, int $ps = 100): array
        {
            return ['response' => ['escrow_list' => [
                ['order_sn' => 'S-9', 'escrow_release_time' => 1787000000, 'payout_amount' => 64675],
            ], 'more' => false]];
        }

        public function getEscrowDetailBatch(string $a, string $s, array $sns): array
        {
            return ['response' => [
                ['order_sn' => 'S-9', 'order_income' => ['escrow_amount' => 64675, 'buyer_total_amount' => 77665, 'actual_shipping_fee' => 11765]],
            ]];
        }
    };
    $this->app->instance(ShopeeClient::class, $client);

    $conn = ShopeeConnection::create(['shop_id' => '9', 'access_token' => 'A', 'refresh_token' => 'R',
        'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(30)]);

    $r = app(ShopeeSyncService::class)->syncSettlements($conn);

    $this->assertSame(1, $r['count']);
    $row = ShopeeSettlement::where('order_sn', 'S-9')->first();
    $this->assertEquals('64675.00', $row->escrow_amount);
    $this->assertNotNull($row->escrow_release_time); // digabung dari escrow_list
}
```

- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Add `syncSettlements`** (tambah `use App\Services\ShopeeSettlementService;` + `use Illuminate\Support\Facades\Log;` bila belum, + `private ShopeeSettlementService $settlements` di konstruktor):

```php
/**
 * Tarik escrow (settlement) per-order. get_escrow_list (discovery by release
 * time) → chunk ≤50 → get_escrow_detail_batch → gabung release_time → store.
 *
 * @return array{count:int}
 */
public function syncSettlements(ShopeeConnection $conn): array
{
    $access = $this->freshToken($conn);
    $to = now()->timestamp;
    $from = now()->subDays(14)->timestamp;

    $released = []; // order_sn => release_time
    $pageNo = 1;
    for ($guard = 0; $guard < 40; $guard++) {
        $res = $this->shopee->getEscrowList($access, $conn->shop_id, $from, $to, $pageNo, 100);
        foreach ($res['response']['escrow_list'] ?? [] as $e) {
            if (! empty($e['order_sn'])) {
                $released[$e['order_sn']] = $e['escrow_release_time'] ?? null;
            }
        }
        if (empty($res['response']['more'])) {
            break;
        }
        $pageNo++;
        if ($guard === 39) {
            Log::warning('[shopee] get_escrow_list mentok 40 halaman — data escrow mungkin belum lengkap.');
        }
    }

    $all = [];
    foreach (array_chunk(array_keys($released), 50) as $chunk) {
        try {
            $batch = $this->shopee->getEscrowDetailBatch($access, $conn->shop_id, $chunk);
            foreach ($batch['response'] ?? [] as $d) {
                $sn = $d['order_sn'] ?? null;
                if ($sn && array_key_exists($sn, $released)) {
                    $d['escrow_release_time'] = $released[$sn];
                }
                $all[] = $d;
            }
        } catch (\Throwable $e) {
            Log::warning('[shopee] batch escrow gagal: '.$e->getMessage());
        }
    }

    return ['count' => $this->settlements->store($all)];
}
```

- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Command** — di `ShopeeSyncCommand.php` signature tambah `{--settlements : Sekalian tarik pencairan/escrow}`; di `handle()` setelah blok retur:

```php
if ($this->option('settlements')) {
    try {
        $r = $sync->syncSettlements($conn);
        $this->info("Pencairan: {$r['count']} tersimpan.");
        Log::info("[shopee:sync] Pencairan: {$r['count']} tersimpan.");
    } catch (\Throwable $e) {
        $this->error('Gagal tarik pencairan: '.$e->getMessage());
        Log::error('[shopee:sync] pencairan gagal: '.$e->getMessage());

        return self::FAILURE;
    }
}
```

- [ ] **Step 6: Cron** — di `routes/console.php` dekat `shopee:sync --returns`:

```php
// Escrow/pencairan Shopee sekali sehari (jarang berubah setelah rilis).
Schedule::command('shopee:sync --settlements')->dailyAt('01:30')->withoutOverlapping(30);
```

- [ ] **Step 7: Cron test** (tambah):

```php
public function test_cron_menjadwalkan_shopee_sync_settlements(): void
{
    $found = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->contains(fn ($e) => str_contains($e->command ?? '', 'shopee:sync --settlements'));
    $this->assertTrue($found, 'shopee:sync --settlements harus terjadwal');
}
```

- [ ] **Step 8: Run + Pint + commit** `feat(shopee) Fase 3: syncSettlements + shopee:sync --settlements + cron`.

---

### Task 5: Controller + routes + view + tests wiring

**Files:** Modify `app/Http/Controllers/ShopeeController.php` (dep `ShopeeSettlementService` + 3 aksi), `routes/web.php` (3 route), `resources/views/shopee/index.blade.php` (link); Create `resources/views/shopee/settlements.blade.php`, `resources/views/shopee/settlement_detail.blade.php`; Test `tests/Feature/ShopeeSettlementTest.php` (tambah).

**Interfaces — Consumes:** `ShopeeSyncService::syncSettlements` (Task 4), `ShopeeSettlement` (Task 1), `AuditService::log` (pola Fase 2).

> **Baca dulu** aksi retur di `ShopeeController.php` (konstruktor dep + cara `AuditService::log` dipanggil — statik, `targetType`/`targetId`), route retur di `routes/web.php`, view `resources/views/shopee/returns.blade.php` (layout Tailwind + pola), dan `resources/views/tiktok/settlements.blade.php` (tampilan settlement TikTok). Mirror strukturnya.

- [ ] **Step 1: Failing test** (tambah):

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;
```

```php
private function admin(): User
{
    return User::create(['name' => 'A', 'fullname' => 'A', 'username' => 'setadmin',
        'email' => 'setadmin@skinku.test', 'password' => Hash::make('secret123'),
        'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);
}

public function test_halaman_pencairan_render_dan_reseller_ditolak(): void
{
    ShopeeSettlement::create(['order_sn' => 'S-2', 'escrow_amount' => 100, 'buyer_total_amount' => 120,
        'posting_status' => ShopeeSettlement::POST_PENDING]);

    $this->actingAs($this->admin())->get('/shopee/settlements')->assertOk();

    $reseller = User::create(['name' => 'R', 'fullname' => 'R', 'username' => 'res_set',
        'email' => 'res_set@skinku.test', 'password' => Hash::make('secret123'),
        'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
    $this->actingAs($reseller)->get('/shopee/settlements')->assertForbidden();
}
```

- [ ] **Step 2: Run — expect FAIL** (route 404).

- [ ] **Step 3: Controller** — tambah `use App\Models\ShopeeSettlement;` + `use App\Services\ShopeeSettlementService;`, tambah `ShopeeSettlementService $settlements` ke konstruktor (ikuti pola dep Fase 2, simpan `$this->settlements`), aksi:

```php
public function settlementList()
{
    $settlements = ShopeeSettlement::latest('escrow_release_time')->latest('id')->paginate(25);

    return view('shopee.settlements', compact('settlements'));
}

public function syncSettlements(Request $request): \Illuminate\Http\RedirectResponse
{
    $conn = $this->sync->connection();
    if (! $conn) {
        return back()->with('error', 'Belum terhubung ke Shopee.');
    }
    try {
        $r = $this->sync->syncSettlements($conn);
        $this->audit->log('shopee_sync_settlements', 'ShopeeSettlement', null, ['count' => $r['count']]);

        return redirect()->route('shopee.settlements')->with('status', "Pencairan ditarik: {$r['count']}.");
    } catch (\Throwable $e) {
        return back()->with('error', 'Gagal tarik pencairan: '.$e->getMessage());
    }
}

public function settlementDetail(ShopeeSettlement $settlement)
{
    return view('shopee.settlement_detail', compact('settlement'));
}
```

> **Cek** signature `AuditService::log` yang dipakai aksi retur Fase 2 dan samakan (statik vs properti, urutan argumen). Sesuaikan pemanggilan di atas.

- [ ] **Step 4: Routes** (grup `permission:manage_shopee`):

```php
Route::get('/shopee/settlements', [ShopeeController::class, 'settlementList'])->name('shopee.settlements');
Route::post('/shopee/settlements/sync', [ShopeeController::class, 'syncSettlements'])->name('shopee.settlements.sync');
Route::get('/shopee/settlements/{settlement}/detail', [ShopeeController::class, 'settlementDetail'])->name('shopee.settlements.detail');
```

- [ ] **Step 5: View `settlements.blade.php`** — mirror `resources/views/shopee/returns.blade.php` layout (`@extends`/`@section`), tabel per baris: `order_sn`, tanggal `escrow_release_time`, `buyer_total_amount`, `feeTotal()` (total potongan), `actual_shipping_fee`, **`escrow_amount` (net cair)**, badge `posting_status`, link "Detail" ke `shopee.settlements.detail`. Tombol "↻ Tarik Pencairan" → form POST `shopee.settlements.sync`. TANPA UI jurnal.

- [ ] **Step 6: View `settlement_detail.blade.php`** — kartu ringkasan (buyer_total → dikurangi komisi/layanan/campaign/txn/ongkir/pajak → net escrow) + `@php dump raw @endphp` collapsible (`<pre>{{ json_encode($settlement->raw, JSON_PRETTY_PRINT) }}</pre>`) untuk audit bentuk field.

- [ ] **Step 7: index link** — di `shopee/index.blade.php` tambah link "Pencairan" ke `route('shopee.settlements')` (gaya sama link Retur/Orders).

- [ ] **Step 8: Run `--filter=ShopeeSettlementTest` — expect PASS.**
- [ ] **Step 9: Run FULL suite** `C:/php83/php.exe artisan test` — expect semua hijau (tak merusak Fase 1-2).
- [ ] **Step 10: Pint + commit** `feat(shopee) Fase 3: UI pencairan (list/detail/sync) + route`.

---

## Self-Review (plan vs spec)

- Model + migrasi 000094 → Task 1 ✓ · Client 3 method escrow → Task 2 ✓ · Service store+mapIncome → Task 3 ✓ · syncSettlements + command + cron → Task 4 ✓ · Controller/route/view → Task 5 ✓ · posting_status disiapkan tak dipakai (Fase 4) ✓ · TANPA jurnal ✓.
- **Placeholder scan:** kode nyata tiap step; field escrow dari data sandbox asli (tervalidasi), bukan placeholder.
- **Type consistency:** `ShopeeSettlement` kolom + `POST_*` + `escrow_amount`/`order_sn` + `syncSettlements(ShopeeConnection):array{count}` konsisten Task 1/3/4/5. `mapIncome(array):array` konsisten Task 3.

## Catatan verifikasi sandbox (saat build, setelah suite hijau)
`SHOPEE_INSECURE=true SHOPEE_API_BASE=...sandbox... shopee:sync --settlements` → `get_escrow_list` balik kosong (order test belum released) tapi sign kebukti. Untuk buktikan store dgn data nyata: panggil `getEscrowDetail('2608247FYHUBMG')` langsung → `store([$detail])` → cek row `escrow_amount=64675`. (Escrow field sudah tervalidasi via probe 2026-08-24.)
