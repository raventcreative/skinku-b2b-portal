# Onboarding / Paket Join Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin mendaftarkan reseller baru lewat Paket Join (Bronze/Gold) — sekali submit membuat akun reseller, memotong stok HQ sesuai isi paket, mencatat transaksi, dan memberi bonus join 10% ke saldo komisi upline.

**Architecture:** 3 tabel baru (katalog paket + item + transaksi). Sebuah `OnboardingService` membungkus semua efek dalam 1 DB transaction (atomik). Bonus join = baris `Commission` type `join` (append-only, masuk saldo → cair lewat alur penarikan existing). Maksimal reuse: `PartnerHierarchyService`, `InventoryService::adjustHqStock`, `CommissionService`, `AppSetting`, `AuditService`.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Eloquent. Zero-dependency.

## Global Constraints

- **Zero-dependency:** tak ada paket composer/npm baru.
- **Runner:** `C:\php83\php.exe artisan test` (filter `--filter=Nama`). Pint sebelum commit: `C:\php83\php.exe vendor/bin/pint --dirty`.
- **Migrasi:** terakhir 000083 → pakai **000084, 000085, 000086**, format `2026_01_01_0000XX_*.php`.
- **commissions APPEND-ONLY:** join bonus hanya `Commission::create` (type `join`, status `saldo`); JANGAN update/delete/flip. TIDAK auto-cair — cair lewat alur penarikan existing (mitra ajukan → HQ proses).
- **Atomik:** semua efek onboarding (user + stok + transaksi + komisi) dalam 1 `DB::transaction`. Stok HQ kurang → rollback total.
- **Rate join:** `AppSetting::float('komisi_persen_join', CommissionService::RATE_DEFAULTS['komisi_persen_join'])` (default 10). JANGAN hardcode 10.
- **Role reseller dari paket:** kolom `join_packages.target_role` (reseller_bronze / reseller_gold). Bukan tebak dari nama.
- **Scope:** onboarding-via-paket khusus Reseller (Bronze/Gold). Distributor/Grand tetap via Kelola Anggota biasa.
- **Stok reseller TIDAK dicatat** — cukup potong stok HQ (movementType `paket_join`).
- **Dormant-safe:** tak ada paket → onboarding tak bisa jalan; nol efek sampai dipakai.

---

## Task 1: Data foundation — migrasi + model

**Files:**
- Create: `database/migrations/2026_01_01_000084_create_join_packages_table.php`
- Create: `database/migrations/2026_01_01_000085_create_join_package_items_table.php`
- Create: `database/migrations/2026_01_01_000086_create_join_transactions_table.php`
- Create: `app/Models/JoinPackage.php`, `app/Models/JoinPackageItem.php`, `app/Models/JoinTransaction.php`
- Test: `tests/Feature/JoinPackageModelTest.php`

**Interfaces:**
- Produces: `JoinPackage` (fillable name/target_role/price/is_active; casts price decimal:2, is_active boolean; relasi `items()` hasMany JoinPackageItem), `JoinPackageItem` (fillable join_package_id/product_id/qty; relasi `product()`, `joinPackage()`), `JoinTransaction` (fillable user_id/join_package_id/inviter_id/price/created_by; casts price decimal:2; relasi `member()`, `package()`, `inviter()`).

- [ ] **Step 1: Migrasi join_packages**

`2026_01_01_000084_create_join_packages_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('target_role', 50); // reseller_bronze | reseller_gold
            $table->decimal('price', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_packages');
    }
};
```

- [ ] **Step 2: Migrasi join_package_items**

`2026_01_01_000085_create_join_package_items_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('join_package_id')->constrained('join_packages')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
            $table->index('join_package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_package_items');
    }
};
```

- [ ] **Step 3: Migrasi join_transactions**

`2026_01_01_000086_create_join_transactions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('join_package_id')->nullable()->constrained('join_packages')->nullOnDelete();
            $table->foreignId('inviter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('price', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('user_id');
            $table->index('inviter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_transactions');
    }
};
```

- [ ] **Step 4: Model JoinPackage**

`app/Models/JoinPackage.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JoinPackage extends Model
{
    protected $fillable = ['name', 'target_role', 'price', 'is_active'];

    protected $casts = ['price' => 'decimal:2', 'is_active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(JoinPackageItem::class);
    }
}
```

- [ ] **Step 5: Model JoinPackageItem**

`app/Models/JoinPackageItem.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JoinPackageItem extends Model
{
    protected $fillable = ['join_package_id', 'product_id', 'qty'];

    protected $casts = ['qty' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function joinPackage(): BelongsTo
    {
        return $this->belongsTo(JoinPackage::class);
    }
}
```

- [ ] **Step 6: Model JoinTransaction**

`app/Models/JoinTransaction.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JoinTransaction extends Model
{
    protected $fillable = ['user_id', 'join_package_id', 'inviter_id', 'price', 'created_by'];

    protected $casts = ['price' => 'decimal:2'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(JoinPackage::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
```

- [ ] **Step 7: Tes model + relasi (gagal dulu)**

`tests/Feature/JoinPackageModelTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JoinPackageModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_paket_punya_item_produk(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'price_distributor' => 24000,
            'price_reseller' => 29000, 'price_retail' => 39000, 'cogs' => 10000, 'hq_stock' => 100,
            'status' => Product::STATUS_ACTIVE]);
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE,
            'price' => 149000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 3]);

        $this->assertCount(1, $paket->items);
        $this->assertSame(3, $paket->items->first()->qty);
        $this->assertSame('Sabun', $paket->items->first()->product->name);
        $this->assertTrue($paket->is_active);
    }

    public function test_transaksi_join_relasi(): void
    {
        $mitra = User::create(['name' => 'm', 'fullname' => 'M', 'username' => 'm1', 'email' => 'm1@t.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER_BRONZE, 'status' => User::STATUS_ACTIVE]);
        $inviter = User::create(['name' => 'd', 'fullname' => 'D', 'username' => 'd1', 'email' => 'd1@t.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE]);
        $trx = JoinTransaction::create(['user_id' => $mitra->id, 'join_package_id' => null,
            'inviter_id' => $inviter->id, 'price' => 149000, 'created_by' => null]);

        $this->assertSame($mitra->id, $trx->member->id);
        $this->assertSame($inviter->id, $trx->inviter->id);
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=JoinPackageModelTest`
Expected: FAIL (tabel/model belum ada) → setelah Step 1-6, PASS.

> Verifikasi kolom `Product` wajib (sku/price_*/cogs/hq_stock/status) dengan melihat `tests/Feature/CommissionEngineTest.php` helper `product()` — sudah dipakai di contoh atas; sesuaikan kalau skema beda.

- [ ] **Step 8: Jalankan tes — hijau, lalu Pint + commit**

```bash
C:\php83\php.exe artisan test --filter=JoinPackageModelTest
C:\php83\php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_00008*_*.php app/Models/JoinPackage.php app/Models/JoinPackageItem.php app/Models/JoinTransaction.php tests/Feature/JoinPackageModelTest.php
git commit -m "feat(mlm): tabel + model join_packages/items/transactions (Onboarding)"
```

---

## Task 2: CommissionService::recordJoinBonus

**Files:**
- Modify: `app/Services/CommissionService.php`
- Test: `tests/Feature/JoinBonusTest.php` (Create)

**Interfaces:**
- Consumes: `AppSetting::float`, `CommissionService::RATE_DEFAULTS['komisi_persen_join']` (=10.0), `Commission::create`, `User::isPartner()`.
- Produces: `public function recordJoinBonus(User $inviter, User $member, float $paketPrice): void`.

- [ ] **Step 1: Tes bonus join (gagal dulu)**

`tests/Feature/JoinBonusTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JoinBonusTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(['name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_bonus_join_10_persen_ke_inviter(): void
    {
        $inviter = $this->user(User::ROLE_DISTRIBUTOR);
        $member = $this->user(User::ROLE_RESELLER_BRONZE);

        app(CommissionService::class)->recordJoinBonus($inviter, $member, 149000);

        $row = Commission::where('user_id', $inviter->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('join', $row->type);
        $this->assertSame('saldo', $row->status);
        $this->assertSame($member->id, $row->source_user_id);
        $this->assertNull($row->source_po_id);
        $this->assertEqualsWithDelta(14900, (float) $row->amount, 0.01); // 10% dari 149rb
    }

    public function test_inviter_bukan_partner_nol_bonus(): void
    {
        $admin = $this->user(User::ROLE_ADMIN); // bukan partner
        $member = $this->user(User::ROLE_RESELLER_BRONZE);

        app(CommissionService::class)->recordJoinBonus($admin, $member, 149000);

        $this->assertSame(0, Commission::count());
    }

    public function test_rate_join_dari_appsetting(): void
    {
        AppSetting::put('komisi_persen_join', '5');
        $inviter = $this->user(User::ROLE_DISTRIBUTOR);
        $member = $this->user(User::ROLE_RESELLER_BRONZE);

        app(CommissionService::class)->recordJoinBonus($inviter, $member, 200000);

        $this->assertEqualsWithDelta(10000, (float) Commission::where('user_id', $inviter->id)->value('amount'), 0.01); // 5%
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=JoinBonusTest`
Expected: FAIL (method belum ada).

- [ ] **Step 2: Implementasi recordJoinBonus**

Di `app/Services/CommissionService.php`, tambah method (dekat `write()`):
```php
/**
 * Bonus join: saat member baru daftar via paket, upline LANGSUNG (inviter)
 * dapat `komisi_persen_join`% dari nilai paket → saldo komisi (append-only,
 * TIDAK auto-cair). 1 tingkat, tanpa PO (source_po_id null).
 */
public function recordJoinBonus(User $inviter, User $member, float $paketPrice): void
{
    $rate = AppSetting::float('komisi_persen_join', self::RATE_DEFAULTS['komisi_persen_join']);
    if (! $inviter->isPartner() || $rate <= 0 || $paketPrice <= 0) {
        return;
    }

    Commission::create([
        'user_id' => $inviter->id, 'source_po_id' => null, 'source_user_id' => $member->id,
        'type' => 'join', 'level' => 1, 'rate' => $rate, 'base_amount' => $paketPrice,
        'amount' => round($paketPrice * $rate / 100, 2), 'status' => 'saldo',
    ]);
}
```

- [ ] **Step 3: Jalankan tes — hijau + regresi Commission**

```bash
C:\php83\php.exe artisan test --filter=JoinBonusTest
C:\php83\php.exe artisan test --filter=Commission
```
Expected: PASS semua (engine override tak terpengaruh).

- [ ] **Step 4: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/CommissionService.php tests/Feature/JoinBonusTest.php
git commit -m "feat(mlm): CommissionService::recordJoinBonus (bonus join 10% ke saldo)"
```

---

## Task 3: OnboardingService::onboard (inti, atomik)

**Files:**
- Create: `app/Services/OnboardingService.php`
- Test: `tests/Feature/OnboardingServiceTest.php`

**Interfaces:**
- Consumes: `JoinPackage` (+ `items.product`, `target_role`, `price`), `PartnerHierarchyService::assignUpline`/`ensureMemberId`, `InventoryService::adjustHqStock(Product $product, int $delta, string $movementType, ?string $notes, ?string $referenceType, ?int $referenceId, ?DateTimeInterface $occurredAt)` (lempar `RuntimeException` kalau stok kurang), `CommissionService::recordJoinBonus`, `JoinTransaction::create`, `User::create`.
- Produces: `public function onboard(array $data, JoinPackage $paket, ?int $uplineId, int $adminId): User`. `$data` validated keys: `fullname, email, username, password, company_name?, phone?, address?, region?`.

- [ ] **Step 1: Tes onboarding (gagal dulu)**

`tests/Feature/OnboardingServiceTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(['name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function product(int $stock): Product
    {
        return Product::create(['name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => $stock, 'status' => Product::STATUS_ACTIVE]);
    }

    private function paket(Product $p, int $qty, int $stockNeededPrice = 149000): JoinPackage
    {
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE,
            'price' => $stockNeededPrice, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => $qty]);

        return $paket;
    }

    private function data(): array
    {
        $n = ++$this->seq;

        return ['fullname' => 'Reseller '.$n, 'email' => "res{$n}@t.test", 'username' => "res{$n}",
            'password' => 'secret123', 'company_name' => 'CV R'.$n];
    }

    public function test_onboard_sukses_potong_stok_dan_bonus(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $upline = $this->user(User::ROLE_DISTRIBUTOR);
        $p = $this->product(100);
        $paket = $this->paket($p, 3); // 3 unit per join

        $reseller = app(OnboardingService::class)->onboard($this->data(), $paket, $upline->id, $admin->id);

        // Reseller dibuat, role bronze, upline benar
        $this->assertSame(User::ROLE_RESELLER_BRONZE, $reseller->role);
        $this->assertSame($upline->id, $reseller->upline_id);
        // Stok HQ turun 3
        $this->assertSame(97, (int) $p->fresh()->hq_stock);
        // Transaksi paket tercatat
        $this->assertSame(1, JoinTransaction::where('user_id', $reseller->id)->count());
        // Bonus join 10% dari 149rb = 14.900 ke upline
        $this->assertEqualsWithDelta(14900, (float) Commission::where('user_id', $upline->id)->where('type', 'join')->sum('amount'), 0.01);
    }

    public function test_stok_hq_kurang_rollback_total(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $upline = $this->user(User::ROLE_DISTRIBUTOR);
        $p = $this->product(2);        // cuma 2
        $paket = $this->paket($p, 5);  // butuh 5 → gagal

        try {
            app(OnboardingService::class)->onboard($this->data(), $paket, $upline->id, $admin->id);
            $this->fail('harusnya lempar RuntimeException');
        } catch (RuntimeException $e) {
            // expected
        }

        // Rollback total: user tak dibuat, stok utuh, nol transaksi, nol komisi
        $this->assertSame(0, User::where('role', User::ROLE_RESELLER_BRONZE)->count());
        $this->assertSame(2, (int) $p->fresh()->hq_stock);
        $this->assertSame(0, JoinTransaction::count());
        $this->assertSame(0, Commission::count());
    }

    public function test_tanpa_upline_user_dibuat_tanpa_bonus(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $p = $this->product(100);
        $paket = $this->paket($p, 1);

        $reseller = app(OnboardingService::class)->onboard($this->data(), $paket, null, $admin->id);

        $this->assertNotNull($reseller->id);
        $this->assertNull($reseller->upline_id);
        $this->assertSame(0, Commission::count()); // tak ada inviter → nol bonus
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=OnboardingServiceTest`
Expected: FAIL (service belum ada).

- [ ] **Step 2: Implementasi OnboardingService**

`app/Services/OnboardingService.php`:
```php
<?php

namespace App\Services;

use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OnboardingService
{
    public function __construct(
        private PartnerHierarchyService $hierarchy,
        private InventoryService $inventory,
        private CommissionService $commissions,
    ) {}

    /**
     * Daftarkan reseller baru via paket join. 1 transaksi atomik: buat user +
     * potong stok HQ (isi paket) + catat transaksi + bonus join ke upline.
     * Stok HQ tak cukup → RuntimeException (rollback total).
     *
     * @param  array<string,mixed>  $data  validated: fullname,email,username,password,company_name?,phone?,address?,region?
     */
    public function onboard(array $data, JoinPackage $paket, ?int $uplineId, int $adminId): User
    {
        $paket->loadMissing('items.product');

        return DB::transaction(function () use ($data, $paket, $uplineId, $adminId) {
            // Pre-check stok HQ (pesan paket-level yang jelas; adjustHqStock tetap
            // jadi guard sungguhan dgn lockForUpdate saat memotong).
            foreach ($paket->items as $item) {
                if ((int) $item->product->hq_stock < $item->qty) {
                    throw new RuntimeException("Stok HQ tidak cukup untuk paket {$paket->name} (produk {$item->product->name}).");
                }
            }

            $user = User::create([
                'name' => $data['fullname'],
                'fullname' => $data['fullname'],
                'email' => mb_strtolower($data['email']),
                'username' => mb_strtolower($data['username']),
                'password' => Hash::make($data['password']),
                'role' => $paket->target_role,
                'company_name' => $data['company_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'region' => $data['region'] ?? null,
                'status' => User::STATUS_ACTIVE,
                'created_by' => $adminId,
            ]);
            $this->hierarchy->assignUpline($user, $uplineId);
            $this->hierarchy->ensureMemberId($user);
            $user->save();

            $trx = JoinTransaction::create([
                'user_id' => $user->id,
                'join_package_id' => $paket->id,
                'inviter_id' => $uplineId,
                'price' => $paket->price,
                'created_by' => $adminId,
            ]);

            foreach ($paket->items as $item) {
                $this->inventory->adjustHqStock(
                    product: $item->product,
                    delta: -$item->qty,
                    movementType: 'paket_join',
                    notes: "Paket join {$paket->name} untuk {$user->fullname}",
                    referenceType: 'join_transaction',
                    referenceId: $trx->id,
                    occurredAt: now(),
                );
            }

            if ($user->upline) {
                $this->commissions->recordJoinBonus($user->upline, $user, (float) $paket->price);
            }

            return $user;
        });
    }
}
```

- [ ] **Step 3: Jalankan tes — hijau**

Run: `C:\php83\php.exe artisan test --filter=OnboardingServiceTest`
Expected: PASS (sukses potong+bonus; stok kurang rollback; tanpa upline nol bonus).

- [ ] **Step 4: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/OnboardingService.php tests/Feature/OnboardingServiceTest.php
git commit -m "feat(mlm): OnboardingService::onboard (atomik: user+stok+transaksi+bonus)"
```

---

## Task 4: Katalog Paket (admin CRUD)

**Files:**
- Modify: `app/Support/Permissions.php` (izin `manage_join_packages`)
- Create: `app/Http/Controllers/JoinPackageController.php`
- Create: `resources/views/join_packages/index.blade.php`, `resources/views/join_packages/form.blade.php`
- Modify: `routes/web.php` (resource route gated), `resources/views/layouts/app.blade.php` (nav)
- Test: `tests/Feature/JoinPackageCrudTest.php`

**Interfaces:**
- Consumes: `JoinPackage`, `JoinPackageItem`, `Product`, `User` role consts, izin `manage_join_packages`.
- Produces: route `join-packages.*`; view untuk dipilih di Task 5 (paket aktif).

- [ ] **Step 1: Daftarkan izin `manage_join_packages`**

Di `app/Support/Permissions.php`: tambah ke DEFINITIONS `'manage_join_packages' => 'Kelola Paket Join',` dan ke DEFAULTS `'manage_join_packages' => [User::ROLE_ADMIN],`.

- [ ] **Step 2: Tes CRUD (gagal dulu)**

`tests/Feature/JoinPackageCrudTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\JoinPackage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JoinPackageCrudTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create(['name' => "u$n", 'fullname' => "U$n", 'username' => "u$n", 'email' => "u$n@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function product(): Product
    {
        static $n = 0;
        $n++;

        return Product::create(['name' => "P$n", 'sku' => "SKU-$n", 'price_distributor' => 24000,
            'price_reseller' => 29000, 'price_retail' => 39000, 'cogs' => 10000, 'hq_stock' => 100,
            'status' => Product::STATUS_ACTIVE]);
    }

    public function test_admin_buat_paket_dengan_item(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $p = $this->product();

        $this->actingAs($admin)->post(route('join-packages.store'), [
            'name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE, 'price' => 149000, 'is_active' => 1,
            'items' => [['product_id' => $p->id, 'qty' => 3]],
        ])->assertRedirect();

        $paket = JoinPackage::first();
        $this->assertSame('Bronze', $paket->name);
        $this->assertCount(1, $paket->items);
        $this->assertSame(3, $paket->items->first()->qty);
    }

    public function test_mitra_tak_bisa_akses_katalog(): void
    {
        $mitra = $this->user(User::ROLE_DISTRIBUTOR);
        $this->actingAs($mitra)->get(route('join-packages.index'))->assertForbidden();
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=JoinPackageCrudTest`
Expected: FAIL (route belum ada).

- [ ] **Step 3: Controller**

`app/Http/Controllers/JoinPackageController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\JoinPackage;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JoinPackageController extends Controller
{
    public function index()
    {
        $packages = JoinPackage::withCount('items')->orderBy('name')->get();

        return view('join_packages.index', ['packages' => $packages]);
    }

    public function create()
    {
        return view('join_packages.form', ['package' => new JoinPackage, 'products' => $this->products()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $paket = JoinPackage::create([
                'name' => $data['name'], 'target_role' => $data['target_role'],
                'price' => $data['price'], 'is_active' => (bool) ($data['is_active'] ?? false),
            ]);
            foreach ($data['items'] as $it) {
                $paket->items()->create(['product_id' => $it['product_id'], 'qty' => $it['qty']]);
            }
        });
        AuditService::log(action: 'create_join_package', targetType: 'join_package', after: ['name' => $data['name']]);

        return redirect()->route('join-packages.index')->with('status', 'Paket join dibuat.');
    }

    public function edit(JoinPackage $joinPackage)
    {
        $joinPackage->load('items');

        return view('join_packages.form', ['package' => $joinPackage, 'products' => $this->products()]);
    }

    public function update(Request $request, JoinPackage $joinPackage): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $joinPackage) {
            $joinPackage->update([
                'name' => $data['name'], 'target_role' => $data['target_role'],
                'price' => $data['price'], 'is_active' => (bool) ($data['is_active'] ?? false),
            ]);
            $joinPackage->items()->delete();
            foreach ($data['items'] as $it) {
                $joinPackage->items()->create(['product_id' => $it['product_id'], 'qty' => $it['qty']]);
            }
        });

        return redirect()->route('join-packages.index')->with('status', 'Paket join diperbarui.');
    }

    public function destroy(JoinPackage $joinPackage): RedirectResponse
    {
        $joinPackage->delete();

        return redirect()->route('join-packages.index')->with('status', 'Paket join dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'target_role' => ['required', Rule::in([User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD])],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);
    }

    private function products()
    {
        return Product::where('status', Product::STATUS_ACTIVE)->orderBy('name')->get();
    }
}
```

- [ ] **Step 4: Route (grup izin)**

Di `routes/web.php`, tambah di dalam grup auth (dekat resource lain):
```php
Route::middleware('permission:manage_join_packages')->group(function () {
    Route::resource('join-packages', \App\Http\Controllers\JoinPackageController::class)->except('show');
});
```

- [ ] **Step 5: View index + form**

`resources/views/join_packages/index.blade.php` — tabel paket (nama, tier, harga, #item, aktif) + tombol Tambah/Edit/Hapus. `resources/views/join_packages/form.blade.php` — form: name, target_role (select bronze/gold), price, is_active checkbox, + baris item dinamis (select produk + qty; vanilla JS tambah/hapus baris, pola sama form PO `purchase_orders/create.blade.php`). Escape output `{{ }}`; JANGAN `@json([...])` literal.

> Implementer: baca `resources/views/purchase_orders/create.blade.php` untuk pola baris item dinamis (add/remove row vanilla JS) dan `resources/views/products/*` untuk gaya form/tabel; ikut konvensi Tailwind existing.

- [ ] **Step 6: Nav item**

Di `resources/views/layouts/app.blade.php`, dekat "Kelola Anggota":
```blade
@if($u->canDo('manage_join_packages'))
    {!! navItem('join-packages.index', 'Paket Join', 'join-packages.*') !!}
@endif
```
Tambah arm icon `'join-packages.index'` di `navIcon()` (pakai path Heroicons `gift` atau reuse `archive-box`).

- [ ] **Step 7: Jalankan tes — hijau; Pint; commit**

```bash
C:\php83\php.exe artisan test --filter=JoinPackageCrudTest
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Support/Permissions.php app/Http/Controllers/JoinPackageController.php resources/views/join_packages routes/web.php resources/views/layouts/app.blade.php tests/Feature/JoinPackageCrudTest.php
git commit -m "feat(mlm): katalog Paket Join (CRUD admin, izin manage_join_packages)"
```

---

## Task 5: Form Onboarding (admin)

**Files:**
- Create: `app/Http/Controllers/OnboardingController.php`
- Create: `resources/views/onboarding/create.blade.php`
- Modify: `routes/web.php` (route gated `manage_users`), `resources/views/users/index.blade.php` (tombol)
- Test: `tests/Feature/OnboardingFlowTest.php`

**Interfaces:**
- Consumes: `OnboardingService::onboard`, `JoinPackage` (aktif), `PartnerHierarchyService::eligibleUplines(string $role, ?string $region)`, izin `manage_users`.

- [ ] **Step 1: Tes alur onboarding (gagal dulu)**

`tests/Feature/OnboardingFlowTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\JoinPackage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create(['name' => "u$n", 'fullname' => "U$n", 'username' => "u$n", 'email' => "u$n@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function bronzePaket(): JoinPackage
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'price_distributor' => 24000,
            'price_reseller' => 29000, 'price_retail' => 39000, 'cogs' => 10000, 'hq_stock' => 100,
            'status' => Product::STATUS_ACTIVE]);
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE,
            'price' => 149000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 2]);

        return $paket;
    }

    public function test_admin_onboard_reseller_baru(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $upline = $this->user(User::ROLE_DISTRIBUTOR);
        $paket = $this->bronzePaket();

        $this->actingAs($admin)->post(route('onboarding.store'), [
            'fullname' => 'Budi Reseller', 'email' => 'budi@t.test', 'username' => 'budi',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'join_package_id' => $paket->id, 'upline_id' => $upline->id, 'paid' => 1,
        ])->assertRedirect();

        $reseller = User::where('username', 'budi')->first();
        $this->assertNotNull($reseller);
        $this->assertSame(User::ROLE_RESELLER_BRONZE, $reseller->role);
        $this->assertSame($upline->id, $reseller->upline_id);
        // Bonus join ke upline
        $this->assertEqualsWithDelta(14900, (float) Commission::where('user_id', $upline->id)->where('type', 'join')->sum('amount'), 0.01);
    }

    public function test_mitra_tak_bisa_onboard(): void
    {
        $mitra = $this->user(User::ROLE_DISTRIBUTOR);
        $this->actingAs($mitra)->get(route('onboarding.create'))->assertForbidden();
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=OnboardingFlowTest`
Expected: FAIL (route belum ada).

- [ ] **Step 2: Controller**

`app/Http/Controllers/OnboardingController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\JoinPackage;
use App\Services\AuditService;
use App\Services\OnboardingService;
use App\Services\PartnerHierarchyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use RuntimeException;

class OnboardingController extends Controller
{
    public function __construct(private OnboardingService $onboarding, private PartnerHierarchyService $hierarchy) {}

    public function create()
    {
        return view('onboarding.create', [
            'packages' => JoinPackage::where('is_active', true)->orderBy('name')->get(),
            'hierarchy' => $this->hierarchy,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:100'],
            'join_package_id' => ['required', 'integer', 'exists:join_packages,id'],
            'upline_id' => ['nullable', 'integer', 'exists:users,id'],
            'paid' => ['accepted'], // admin konfirmasi sudah bayar
        ]);

        $paket = JoinPackage::where('is_active', true)->findOrFail($data['join_package_id']);

        try {
            $reseller = $this->onboarding->onboard($data, $paket, $data['upline_id'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        AuditService::log(action: 'onboard_reseller', targetType: 'user', targetId: $reseller->id,
            after: ['paket' => $paket->name, 'upline_id' => $data['upline_id'] ?? null]);

        return redirect()->route('users.index')->with('status', "Reseller {$reseller->fullname} berhasil didaftarkan via paket {$paket->name}.");
    }
}
```

> Catatan: `Illuminate\Support\Facades\Rule` bukan namespace yang tepat untuk `Rule::in`; pakai `Illuminate\Validation\Rule` bila perlu. Di controller ini `Rule` tidak dipakai (validasi pakai string) — implementer hapus import yang tak terpakai agar Pint bersih.

- [ ] **Step 3: Route (grup manage_users)**

Di `routes/web.php`, di grup `permission:manage_users` (atau tambah grup):
```php
Route::middleware('permission:manage_users')->group(function () {
    Route::get('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'store'])->name('onboarding.store');
});
```

- [ ] **Step 4: View form**

`resources/views/onboarding/create.blade.php` — form POST ke `onboarding.store`: field reseller (fullname, username, email, password + confirmation, company_name, phone, region), select **Paket** (`$packages`, tampil nama + harga), select **Upline** (dari `$hierarchy->eligibleUplines($paket->target_role, null)` — atau tampilkan semua distributor; JS ganti daftar upline saat paket berubah, atau cukup 1 daftar distributor karena reseller selalu di bawah distributor), checkbox wajib **"Konfirmasi sudah bayar"** (`name=paid`). Banner error `@if(session('error'))`. Escape `{{ }}`; jangan `@json([...])`.

> Implementer: reuse gaya form dari `resources/views/users/*` (form Tambah User) untuk field user + pemilih upline. Untuk daftar upline reseller, `eligibleUplines(User::ROLE_RESELLER_BRONZE, null)` mengembalikan distributor — cukup satu daftar (bronze & gold sama-sama di bawah distributor).

- [ ] **Step 5: Tombol di Kelola Anggota**

Di `resources/views/users/index.blade.php`, dekat tombol "+ Tambah User", tambah:
```blade
@if($u->canDo('manage_users') ?? auth()->user()->canDo('manage_users'))
    <a href="{{ route('onboarding.create') }}" class="px-4 py-2 text-sm bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 font-semibold">+ Onboarding via Paket Join</a>
@endif
```
(sesuaikan variabel user di view itu — cek apakah `$u` atau `auth()->user()`.)

- [ ] **Step 6: Jalankan tes — hijau; Pint; commit**

```bash
C:\php83\php.exe artisan test --filter=OnboardingFlowTest
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/OnboardingController.php resources/views/onboarding routes/web.php resources/views/users/index.blade.php tests/Feature/OnboardingFlowTest.php
git commit -m "feat(mlm): form Onboarding via Paket Join (admin) + tombol di Kelola Anggota"
```

---

## Verifikasi akhir (setelah semua task)

- [ ] Full suite hijau: `C:\php83\php.exe artisan test` (≥ 797 + tes onboarding baru).
- [ ] Pint bersih: `C:\php83\php.exe vendor/bin/pint --dirty`.
- [ ] **Deploy prod: `git pull origin main && /opt/alt/php83/usr/bin/php artisan migrate --force && /opt/alt/php83/usr/bin/php artisan optimize:clear`** (migrasi 000084-086) + hard-refresh.

## Self-Review (diisi saat menulis plan)

**Spec coverage vs `docs/superpowers/specs/2026-08-18-onboarding-paket-join-design.md`:**
- Admin input → Task 5 (OnboardingController + form). ✅
- Detail produk + potong stok HQ → Task 3 (adjustHqStock per item) + Task 1 (join_package_items) + Task 4 (isi paket). ✅
- Stok reseller tak dicatat → Task 3 (cuma adjustHqStock, tak ada adjustPartnerStock). ✅
- Bonus join 10% ke upline langsung → saldo, tak auto-cair → Task 2 (recordJoinBonus, status saldo) + Task 3 (dipanggil). ✅
- Rate dari komisi_persen_join → Task 2. ✅
- Role dari paket (target_role) → Task 1 (kolom) + Task 3 (dipakai). ✅
- 3 tabel 000084-086 → Task 1. ✅
- Atomik + rollback stok kurang → Task 3 (DB::transaction + pre-check + adjustHqStock throw). ✅
- Izin manage_join_packages (katalog) + manage_users (onboarding) → Task 4/5. ✅
- Audit log → Task 4/5. ✅

**Placeholder scan:** tak ada TBD; tiap step ada kode konkret. Bagian view (Task 4 Step 5, Task 5 Step 4) beri pola + rujukan file existing (bukan kode penuh) — wajar untuk Blade UI, tapi cukup spesifik (field, gate, escaping).

**Type consistency:** `recordJoinBonus(User,User,float)` sama di Task 2 (definisi) & Task 3 (pemakaian). `onboard(array,JoinPackage,?int,int):User` sama di Task 3 & Task 5. `JoinPackage->target_role/price/items` konsisten Task 1/3/4/5. `adjustHqStock(product,delta,movementType,notes,referenceType,referenceId,occurredAt)` sesuai signature asli.
