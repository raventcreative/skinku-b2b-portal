# Hirarki Mitra ala MLM — Tahap 1 (Pondasi) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ubah struktur mitra SKINKU dari datar menjadi pohon upline–downline (tier = role), lengkap dengan Member ID + login pakai ID dan halaman Struktur Jaringan — tanpa mengubah kelakuan izin/harga/stok yang sudah jalan.

**Architecture:** Aditif. Tambah 2 kolom di `users` (`upline_id`, `member_id`) + 3 role baru (seed). Registry `App\Support\PartnerHierarchy` jadi sumber kebenaran tier (level, induk sah, holds_stock). Semua klasifikasi mitra tetap nyalur lewat `User::isPartner()`/`priceField()` + `Product::priceForRole()` — cukup diperluas. Integritas pohon + generate Member ID di `App\Services\PartnerHierarchyService`. Login diperluas terima Member ID. Halaman Struktur Jaringan = pohon indentasi (Blade rekursif, zero-dep).

**Tech Stack:** Laravel, PHP 8.3, Blade, Eloquent, vanilla JS, Tailwind (CDN). Tanpa paket baru.

## Global Constraints

- **Zero-dependency**: tidak menambah paket composer/npm apa pun. Blade + Eloquent + vanilla JS.
- **Aditif / no regression**: Tahap 1 TIDAK mengubah harga, izin, atau alur stok yang sudah ada. Efek ekonomi = nol.
- **tier = role**: tidak ada kolom `tier`. Role baru: `grand_distributor`, `reseller_bronze`, `reseller_gold`.
- **Migrasi**: `2026_01_01_000074_add_hierarchy_to_users.php` (000073 sudah dipakai report-bot di main).
- **Member ID**: format `SKN-000123` (prefix `SKN-` + 6 digit urut, zero-pad). Netral, TIDAK meng-encode tier, tetap walau tier berubah.
- **Test runner (lokal)**: `/c/php83/php.exe artisan test` (di PowerShell: `C:\php83\php.exe artisan test`).
- **Pint** `--dirty` sebelum tiap commit.
- **Data lama kosong dulu**: tidak ada migrasi paksa mitra lama.

## File Structure

| File | Tanggung jawab | Aksi |
|---|---|---|
| `database/migrations/2026_01_01_000074_add_hierarchy_to_users.php` | kolom `upline_id` + `member_id`; seed 3 role | Create |
| `app/Models/User.php` | konstanta role baru, `PARTNER_ROLES`, relasi `upline()`/`downlines()`, fillable, `isPartner()`/`priceField()` | Modify |
| `app/Models/Product.php` | `priceForRole()` kenal role baru | Modify |
| `app/Support/PartnerHierarchy.php` | registry tier (level, induk sah, holds_stock, label) | Create |
| `app/Services/PartnerHierarchyService.php` | integritas upline, generate Member ID, kandidat upline, cek downline | Create |
| `app/Support/Permissions.php` | DEFAULTS role baru = seperti basisnya | Modify |
| `app/Http/Controllers/AuthController.php` | login terima Member ID / username / email | Modify |
| `app/Http/Controllers/UserController.php` | form Anggota set upline + generate Member ID | Modify |
| `resources/views/users/index.blade.php` | dropdown tier + pemilih upline + tampil Member ID | Modify |
| `app/Http/Controllers/PartnerHierarchyController.php` | halaman Struktur Jaringan | Create |
| `resources/views/struktur_jaringan/index.blade.php` | pohon + panel belum-ditempatkan | Create |
| `resources/views/struktur_jaringan/_node.blade.php` | partial node rekursif | Create |
| `resources/views/layouts/app.blade.php` | menu sidebar "Struktur Jaringan" | Modify |
| `routes/web.php` | route struktur-jaringan | Modify |

---

### Task 1: Pondasi data — migrasi, kolom, role, model

**Files:**
- Create: `database/migrations/2026_01_01_000074_add_hierarchy_to_users.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/PartnerHierarchyDataTest.php`

**Interfaces:**
- Produces: kolom `users.upline_id` (nullable FK→users, nullOnDelete), `users.member_id` (nullable unique). Role `grand_distributor`, `reseller_bronze`, `reseller_gold`. `User` const `ROLE_GRAND_DISTRIBUTOR='grand_distributor'`, `ROLE_RESELLER_BRONZE='reseller_bronze'`, `ROLE_RESELLER_GOLD='reseller_gold'`; `User::PARTNER_ROLES` (array); relasi `upline()` (belongsTo), `downlines()` (hasMany).

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartnerHierarchyDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_dan_role_baru_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'upline_id'));
        $this->assertTrue(Schema::hasColumn('users', 'member_id'));
        foreach (['grand_distributor', 'reseller_bronze', 'reseller_gold'] as $role) {
            $this->assertTrue(Role::where('name', $role)->exists(), "role {$role} harus di-seed");
        }
    }

    public function test_relasi_upline_downline(): void
    {
        $grand = $this->mk('grand', 'grand_distributor');
        $dist = $this->mk('dist', 'distributor', $grand->id);

        $this->assertSame($grand->id, $dist->upline->id);
        $this->assertTrue($grand->downlines->contains('id', $dist->id));
    }

    private function mk(string $u, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=PartnerHierarchyDataTest`
Expected: FAIL (kolom/relasi belum ada).

- [ ] **Step 3: Buat migrasi**

`database/migrations/2026_01_01_000074_add_hierarchy_to_users.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('upline_id')->nullable()->after('region')->constrained('users')->nullOnDelete();
            $table->string('member_id')->nullable()->unique()->after('upline_id');
        });

        $now = now();
        DB::table('roles')->insertOrIgnore([
            ['name' => 'grand_distributor', 'label' => 'Grand Distributor', 'is_system' => false, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'reseller_bronze', 'label' => 'Reseller Bronze', 'is_system' => false, 'sort_order' => 21, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'reseller_gold', 'label' => 'Reseller Gold', 'is_system' => false, 'sort_order' => 22, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['upline_id']);
            $table->dropColumn(['upline_id', 'member_id']);
        });
        $names = ['grand_distributor', 'reseller_bronze', 'reseller_gold'];
        DB::table('role_permissions')->whereIn('role', $names)->delete();
        DB::table('roles')->whereIn('name', $names)->where('is_system', false)->delete();
    }
};
```

- [ ] **Step 4: Ubah `app/Models/User.php`**

Tambah konstanta setelah `ROLE_RESELLER`:
```php
    public const ROLE_GRAND_DISTRIBUTOR = 'grand_distributor';

    public const ROLE_RESELLER_BRONZE = 'reseller_bronze';

    public const ROLE_RESELLER_GOLD = 'reseller_gold';

    /** Semua role yang dianggap "mitra" (lama + tier MLM). */
    public const PARTNER_ROLES = [
        self::ROLE_DISTRIBUTOR, self::ROLE_RESELLER,
        self::ROLE_GRAND_DISTRIBUTOR, self::ROLE_RESELLER_BRONZE, self::ROLE_RESELLER_GOLD,
    ];
```

Tambah `'upline_id', 'member_id'` ke `$fillable`.

Tambah relasi (di blok Relationships):
```php
    public function upline()
    {
        return $this->belongsTo(User::class, 'upline_id');
    }

    public function downlines()
    {
        return $this->hasMany(User::class, 'upline_id');
    }
```

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=PartnerHierarchyDataTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000074_add_hierarchy_to_users.php app/Models/User.php tests/Feature/PartnerHierarchyDataTest.php
git commit -m "feat(mlm): pondasi data hirarki - upline_id, member_id, 3 role tier"
```

---

### Task 2: Registry `PartnerHierarchy`

**Files:**
- Create: `app/Support/PartnerHierarchy.php`
- Test: `tests/Unit/PartnerHierarchyTest.php`

**Interfaces:**
- Produces (semua static): `isTierRole(string): bool`, `levelOf(string): ?int`, `holdsStock(string): bool`, `label(string): string`, `allowedParentRoles(string): array`, const `TIERS`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Unit;

use App\Models\User;
use App\Support\PartnerHierarchy;
use PHPUnit\Framework\TestCase;

class PartnerHierarchyTest extends TestCase
{
    public function test_level_dan_tier(): void
    {
        $this->assertSame(1, PartnerHierarchy::levelOf('grand_distributor'));
        $this->assertSame(2, PartnerHierarchy::levelOf('distributor'));
        $this->assertSame(3, PartnerHierarchy::levelOf('reseller_bronze'));
        $this->assertSame(3, PartnerHierarchy::levelOf('reseller_gold'));
        $this->assertNull(PartnerHierarchy::levelOf('admin'));
        $this->assertTrue(PartnerHierarchy::isTierRole('reseller_gold'));
        $this->assertFalse(PartnerHierarchy::isTierRole('reseller')); // reseller generik bukan tier
    }

    public function test_induk_yang_sah(): void
    {
        $this->assertSame([], PartnerHierarchy::allowedParentRoles('grand_distributor'));
        $this->assertSame(['grand_distributor'], PartnerHierarchy::allowedParentRoles('distributor'));
        $this->assertSame(['distributor'], PartnerHierarchy::allowedParentRoles('reseller_bronze'));
        $this->assertSame(['distributor'], PartnerHierarchy::allowedParentRoles('reseller_gold'));
    }

    public function test_holds_stock(): void
    {
        $this->assertTrue(PartnerHierarchy::holdsStock('grand_distributor'));
        $this->assertTrue(PartnerHierarchy::holdsStock('distributor'));
        $this->assertFalse(PartnerHierarchy::holdsStock('reseller_bronze'));
        $this->assertFalse(PartnerHierarchy::holdsStock('reseller_gold'));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=PartnerHierarchyTest`
Expected: FAIL (class belum ada).

- [ ] **Step 3: Buat `app/Support/PartnerHierarchy.php`**

```php
<?php
namespace App\Support;

use App\Models\User;

/**
 * Sumber kebenaran tier mitra MLM (spine distribusi). tier = role.
 * level: makin kecil makin tinggi (1 = paling atas / langsung HQ).
 */
class PartnerHierarchy
{
    /** role => [level, label, holds_stock] — urutan = urutan tampil. */
    public const TIERS = [
        User::ROLE_GRAND_DISTRIBUTOR => ['level' => 1, 'label' => 'Grand Distributor', 'holds_stock' => true],
        User::ROLE_DISTRIBUTOR => ['level' => 2, 'label' => 'Distributor', 'holds_stock' => true],
        User::ROLE_RESELLER_BRONZE => ['level' => 3, 'label' => 'Reseller Bronze', 'holds_stock' => false],
        User::ROLE_RESELLER_GOLD => ['level' => 3, 'label' => 'Reseller Gold', 'holds_stock' => false],
    ];

    public static function isTierRole(string $role): bool
    {
        return array_key_exists($role, self::TIERS);
    }

    public static function levelOf(string $role): ?int
    {
        return self::TIERS[$role]['level'] ?? null;
    }

    public static function holdsStock(string $role): bool
    {
        return self::TIERS[$role]['holds_stock'] ?? false;
    }

    public static function label(string $role): string
    {
        return self::TIERS[$role]['label'] ?? $role;
    }

    /** Role induk yang sah = tepat 1 level di atas. Top tier → []. */
    public static function allowedParentRoles(string $role): array
    {
        $level = self::levelOf($role);
        if ($level === null || $level === 1) {
            return [];
        }

        return array_keys(array_filter(self::TIERS, fn ($meta) => $meta['level'] === $level - 1));
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=PartnerHierarchyTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Support/PartnerHierarchy.php tests/Unit/PartnerHierarchyTest.php
git commit -m "feat(mlm): registry PartnerHierarchy (level, induk sah, holds_stock)"
```

---

### Task 3: Klasifikasi & harga kenal role baru (regresi grand ≠ retail)

**Files:**
- Modify: `app/Models/User.php:104` (`isPartner`), `app/Models/User.php:131` (`priceField`)
- Modify: `app/Models/Product.php:58` (`priceForRole`)
- Test: `tests/Unit/PartnerRoleClassificationTest.php`

**Interfaces:**
- Consumes: `User::PARTNER_ROLES` (Task 1), konstanta role (Task 1).
- Produces: `isPartner()` true untuk 5 role mitra; `priceField()` grand→`price_distributor`; `priceForRole()` map role baru ke kolom benar.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class PartnerRoleClassificationTest extends TestCase
{
    private function user(string $role): User
    {
        $u = new User;
        $u->role = $role;

        return $u;
    }

    public function test_ispartner_mencakup_role_tier_baru(): void
    {
        foreach (['distributor', 'reseller', 'grand_distributor', 'reseller_bronze', 'reseller_gold'] as $role) {
            $this->assertTrue($this->user($role)->isPartner(), "{$role} harus mitra");
        }
        $this->assertFalse($this->user('admin')->isPartner());
    }

    public function test_pricefield_grand_ikut_distributor(): void
    {
        $this->assertSame('price_distributor', $this->user('grand_distributor')->priceField());
        $this->assertSame('price_distributor', $this->user('distributor')->priceField());
        $this->assertSame('price_reseller', $this->user('reseller_bronze')->priceField());
        $this->assertSame('price_reseller', $this->user('reseller_gold')->priceField());
    }

    public function test_priceforrole_role_baru_tidak_jatuh_ke_retail(): void
    {
        $p = new Product;
        $p->price_distributor = 100;
        $p->price_reseller = 150;
        $p->price_retail = 999;

        $this->assertSame(100.0, $p->priceForRole('grand_distributor')); // BUKAN 999
        $this->assertSame(150.0, $p->priceForRole('reseller_bronze'));
        $this->assertSame(150.0, $p->priceForRole('reseller_gold'));
        $this->assertSame(100.0, $p->priceForRole('distributor'));      // regresi lama
        $this->assertSame(150.0, $p->priceForRole('reseller'));         // regresi lama
        $this->assertSame(999.0, $p->priceForRole('admin'));            // default tetap retail
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=PartnerRoleClassificationTest`
Expected: FAIL (grand → retail 999).

- [ ] **Step 3: Ubah `User::isPartner()`**

```php
    public function isPartner(): bool
    {
        return in_array($this->role, self::PARTNER_ROLES, true);
    }
```

Ubah `User::priceField()`:
```php
    public function priceField(): string
    {
        return in_array($this->role, [self::ROLE_DISTRIBUTOR, self::ROLE_GRAND_DISTRIBUTOR], true)
            ? 'price_distributor' : 'price_reseller';
    }
```

Ubah `Product::priceForRole()`:
```php
    public function priceForRole(string $role): float
    {
        return match ($role) {
            User::ROLE_DISTRIBUTOR, User::ROLE_GRAND_DISTRIBUTOR => (float) $this->price_distributor,
            User::ROLE_RESELLER, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD => (float) $this->price_reseller,
            default => (float) $this->price_retail,
        };
    }
```

- [ ] **Step 4: Jalankan test, pastikan LULUS + suite penuh (regresi harga)**

Run: `/c/php83/php.exe artisan test --filter=PartnerRoleClassificationTest`
Expected: PASS.
Run: `/c/php83/php.exe artisan test 2>&1 | tail -4`
Expected: seluruh suite tetap hijau (harga PO lama tak berubah).

- [ ] **Step 5: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Models/User.php app/Models/Product.php tests/Unit/PartnerRoleClassificationTest.php
git commit -m "feat(mlm): isPartner/priceField/priceForRole kenal role tier (grand != retail)"
```

---

### Task 4: Izin default role baru = seperti basisnya

**Files:**
- Modify: `app/Support/Permissions.php` (const `DEFAULTS`)
- Test: `tests/Feature/PartnerTierPermissionTest.php`

**Interfaces:**
- Consumes: `Permissions::roleHas($role, $key)` (existing).
- Produces: grand_distributor = izin seperti distributor; reseller_bronze/gold = seperti reseller.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerTierPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_grand_seperti_distributor(): void
    {
        // create_po & view_reports dimiliki distributor → grand juga.
        $this->assertTrue(Permissions::roleHas('grand_distributor', 'create_po'));
        $this->assertTrue(Permissions::roleHas('grand_distributor', 'view_reports'));
    }

    public function test_reseller_tier_seperti_reseller(): void
    {
        // reseller punya create_po & view_learning, TIDAK view_reports.
        $this->assertTrue(Permissions::roleHas('reseller_bronze', 'create_po'));
        $this->assertTrue(Permissions::roleHas('reseller_gold', 'view_learning'));
        $this->assertFalse(Permissions::roleHas('reseller_bronze', 'view_reports'));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=PartnerTierPermissionTest`
Expected: FAIL.

- [ ] **Step 3: Ubah `Permissions::DEFAULTS`**

Di tiap entri yang memuat `User::ROLE_DISTRIBUTOR`, tambahkan `User::ROLE_GRAND_DISTRIBUTOR`. Di tiap entri yang memuat `User::ROLE_RESELLER`, tambahkan `User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD`. Yang terdampak:
```php
        'create_po' => [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER, User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD],
        'view_reports' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_GRAND_DISTRIBUTOR],
        'view_learning' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER, User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD],
        'use_ai_assistant' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER, User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD],
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=PartnerTierPermissionTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Support/Permissions.php tests/Feature/PartnerTierPermissionTest.php
git commit -m "feat(mlm): izin default role tier = seperti basisnya"
```

---

### Task 5: `PartnerHierarchyService` — integritas upline + generate Member ID

**Files:**
- Create: `app/Services/PartnerHierarchyService.php`
- Test: `tests/Feature/PartnerHierarchyServiceTest.php`

**Interfaces:**
- Consumes: `PartnerHierarchy::allowedParentRoles` (Task 2), `User` relasi (Task 1).
- Produces:
  - `assignUpline(User $user, ?int $uplineId): void` — validasi + set `$user->upline_id` (TIDAK save); lempar `ValidationException` (key `upline_id`) bila melanggar. `null` = boleh (belum ditempatkan).
  - `generateMemberId(): string` — `SKN-000123` berikutnya.
  - `ensureMemberId(User $user): void` — isi `member_id` bila mitra & masih kosong (TIDAK save).
  - `hasActiveDownline(User $user): bool`.
  - `eligibleUplines(string $role, ?string $region): \Illuminate\Support\Collection` — kandidat induk, region sama diutamakan.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\PartnerHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PartnerHierarchyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerHierarchyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PartnerHierarchyService;
    }

    private function mk(string $u, string $role, ?int $upline = null, ?string $region = null): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
            'upline_id' => $upline, 'region' => $region,
        ]);
    }

    public function test_assign_valid_dan_null_boleh(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $dist = $this->mk('d', 'distributor');

        $this->svc->assignUpline($dist, $grand->id);
        $this->assertSame($grand->id, $dist->upline_id);

        $this->svc->assignUpline($dist, null); // belum ditempatkan
        $this->assertNull($dist->upline_id);
    }

    public function test_tolak_level_salah(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $reseller = $this->mk('r', 'reseller_bronze');
        $this->expectException(ValidationException::class);
        $this->svc->assignUpline($reseller, $grand->id); // reseller induk harus distributor
    }

    public function test_tolak_diri_sendiri_dan_grand_tak_boleh_punya_upline(): void
    {
        $dist = $this->mk('d', 'distributor');
        try {
            $this->svc->assignUpline($dist, $dist->id);
            $this->fail('harus tolak diri sendiri');
        } catch (ValidationException $e) {
        }

        $grand = $this->mk('g', 'grand_distributor');
        $other = $this->mk('g2', 'grand_distributor');
        $this->expectException(ValidationException::class);
        $this->svc->assignUpline($grand, $other->id); // grand: allowedParents = [] → tolak
    }

    public function test_tolak_siklus(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $dist = $this->mk('d', 'distributor', $grand->id);
        // coba jadikan grand downline dari dist → siklus
        $grand->role = 'distributor'; // paksa agar level cocok utk uji siklus
        $this->expectException(ValidationException::class);
        $this->svc->assignUpline($grand, $dist->id);
    }

    public function test_member_id_urut_unik_dan_stabil(): void
    {
        $a = $this->mk('a', 'distributor');
        $this->svc->ensureMemberId($a);
        $a->save();
        $this->assertSame('SKN-000001', $a->member_id);

        $b = $this->mk('b', 'reseller_gold');
        $this->svc->ensureMemberId($b);
        $b->save();
        $this->assertSame('SKN-000002', $b->member_id);

        // stabil walau tier berubah + tak menimpa yang sudah ada
        $b->role = 'reseller_bronze';
        $this->svc->ensureMemberId($b);
        $this->assertSame('SKN-000002', $b->member_id);
    }

    public function test_ensure_member_id_lewati_non_mitra(): void
    {
        $admin = $this->mk('adm', 'admin');
        $this->svc->ensureMemberId($admin);
        $this->assertNull($admin->member_id);
    }

    public function test_eligible_uplines_utamakan_region_sama(): void
    {
        $farA = $this->mk('gA', 'grand_distributor', null, 'Jatim');
        $nearB = $this->mk('gB', 'grand_distributor', null, 'Jabar');
        $dist = $this->mk('d', 'distributor', null, 'Jabar');

        $list = $this->svc->eligibleUplines($dist->role, $dist->region);
        $this->assertSame($nearB->id, $list->first()->id); // region sama di urutan pertama
        $this->assertTrue($list->contains('id', $farA->id));
    }

    public function test_has_active_downline(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $this->assertFalse($this->svc->hasActiveDownline($grand));
        $this->mk('d', 'distributor', $grand->id);
        $this->assertTrue($this->svc->hasActiveDownline($grand->fresh()));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=PartnerHierarchyServiceTest`
Expected: FAIL (class belum ada).

- [ ] **Step 3: Buat `app/Services/PartnerHierarchyService.php`**

```php
<?php
namespace App\Services;

use App\Models\User;
use App\Support\PartnerHierarchy;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PartnerHierarchyService
{
    /** Validasi + set upline_id (TIDAK save). null = belum ditempatkan (boleh). */
    public function assignUpline(User $user, ?int $uplineId): void
    {
        if ($uplineId === null) {
            $user->upline_id = null;

            return;
        }

        if ($uplineId === $user->id) {
            $this->fail('Tidak bisa menjadikan diri sendiri sebagai upline.');
        }

        $upline = User::find($uplineId);
        if (! $upline) {
            $this->fail('Upline tidak ditemukan.');
        }

        $allowed = PartnerHierarchy::allowedParentRoles($user->role);
        if (! in_array($upline->role, $allowed, true)) {
            $this->fail('Upline harus tepat satu tingkat di atas ('.($allowed ? implode('/', $allowed) : 'tier ini tidak boleh punya upline').').');
        }

        // Cegah siklus: upline tak boleh keturunan dari user.
        $cursor = $upline;
        $guard = 0;
        while ($cursor !== null && $guard++ < 50) {
            if ($cursor->id === $user->id) {
                $this->fail('Susunan memutar (upline tidak boleh berada di bawah user ini).');
            }
            $cursor = $cursor->upline;
        }

        $user->upline_id = $upline->id;
    }

    public function generateMemberId(): string
    {
        $max = (int) User::whereNotNull('member_id')
            ->where('member_id', 'like', 'SKN-%')
            ->get()
            ->map(fn (User $u) => (int) substr($u->member_id, 4))
            ->max();

        return 'SKN-'.str_pad($max + 1, 6, '0', STR_PAD_LEFT);
    }

    /** Isi member_id bila user mitra & belum punya (TIDAK save). */
    public function ensureMemberId(User $user): void
    {
        if ($user->member_id === null && $user->isPartner()) {
            $user->member_id = $this->generateMemberId();
        }
    }

    public function hasActiveDownline(User $user): bool
    {
        return User::where('upline_id', $user->id)
            ->where('status', User::STATUS_ACTIVE)
            ->exists();
    }

    /** Kandidat upline utk role tertentu; region sama diutamakan. */
    public function eligibleUplines(string $role, ?string $region): Collection
    {
        $parents = PartnerHierarchy::allowedParentRoles($role);
        if (empty($parents)) {
            return collect();
        }

        return User::whereIn('role', $parents)
            ->where('status', User::STATUS_ACTIVE)
            ->orderByRaw('CASE WHEN region <=> ? THEN 0 ELSE 1 END', [$region])
            ->orderBy('fullname')
            ->get();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['upline_id' => $message]);
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=PartnerHierarchyServiceTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/PartnerHierarchyService.php tests/Feature/PartnerHierarchyServiceTest.php
git commit -m "feat(mlm): PartnerHierarchyService - integritas upline + generate Member ID"
```

---

### Task 6: Login pakai Member ID

**Files:**
- Modify: `app/Http/Controllers/AuthController.php:46-52`
- Test: `tests/Feature/LoginByMemberIdTest.php`

**Interfaces:**
- Consumes: kolom `member_id` (Task 1).
- Produces: field `login` menerima Member ID / username / email.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginByMemberIdTest extends TestCase
{
    use RefreshDatabase;

    private function mk(): User
    {
        return User::create([
            'name' => 'mit', 'fullname' => 'MITRA', 'username' => 'mitra1', 'email' => 'mitra1@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'distributor', 'status' => User::STATUS_ACTIVE,
            'member_id' => 'SKN-000123',
        ]);
    }

    public function test_login_pakai_member_id(): void
    {
        $this->mk();
        $this->post(route('login'), ['login' => 'SKN-000123', 'password' => 'secret123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_username_masih_jalan(): void
    {
        $this->mk();
        $this->post(route('login'), ['login' => 'mitra1', 'password' => 'secret123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_member_id_salah_ditolak(): void
    {
        $this->mk();
        $this->post(route('login'), ['login' => 'SKN-999999', 'password' => 'secret123'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_non_aktif_tetap_diblok(): void
    {
        $u = $this->mk();
        $u->update(['status' => User::STATUS_INACTIVE]);
        $this->post(route('login'), ['login' => 'SKN-000123', 'password' => 'secret123'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=LoginByMemberIdTest`
Expected: FAIL (login by member_id belum didukung).

- [ ] **Step 3: Ubah blok resolve di `AuthController::login()`**

Ganti:
```php
        if (str_contains($identifier, '@')) {
            $query->where('email', mb_strtolower($identifier));
        } else {
            $query->where('username', mb_strtolower($identifier));
        }
```
menjadi:
```php
        if (str_contains($identifier, '@')) {
            $query->where('email', mb_strtolower($identifier));
        } else {
            $query->where(function ($q) use ($identifier) {
                $q->where('username', mb_strtolower($identifier))
                    ->orWhere('member_id', strtoupper($identifier));
            });
        }
```
Ubah juga label atribut `'login' => 'Username/Email'` menjadi `'login' => 'Member ID/Username/Email'`.

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=LoginByMemberIdTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/AuthController.php tests/Feature/LoginByMemberIdTest.php
git commit -m "feat(mlm): login bisa pakai Member ID (selain username/email)"
```

---

### Task 7: Form Anggota — set upline + generate Member ID

**Files:**
- Modify: `app/Http/Controllers/UserController.php` (`store`, `update`, inject service; kirim `eligibleUplines`)
- Modify: `resources/views/users/index.blade.php` (dropdown role tier + pemilih upline + tampil Member ID)
- Test: `tests/Feature/MemberFormHierarchyTest.php`

**Interfaces:**
- Consumes: `PartnerHierarchyService` (Task 5).
- Produces: pembuatan/perubahan mitra menetapkan `upline_id` (tervalidasi) + auto `member_id`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberFormHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function grand(): User
    {
        return User::create([
            'name' => 'g', 'fullname' => 'GRAND', 'username' => 'grand', 'email' => 'grand@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'grand_distributor', 'status' => User::STATUS_ACTIVE,
            'member_id' => 'SKN-000001',
        ]);
    }

    public function test_buat_distributor_dengan_upline_dan_member_id(): void
    {
        $sa = $this->superAdmin();
        $grand = $this->grand();

        $this->actingAs($sa)->post(route('users.store'), [
            'fullname' => 'Distri Satu', 'email' => 'dist1@skinku.test', 'username' => 'dist1',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'distributor', 'upline_id' => $grand->id, 'status' => User::STATUS_ACTIVE,
        ])->assertRedirect();

        $dist = User::where('username', 'dist1')->firstOrFail();
        $this->assertSame($grand->id, $dist->upline_id);
        $this->assertNotNull($dist->member_id);
        $this->assertStringStartsWith('SKN-', $dist->member_id);
    }

    public function test_tolak_upline_level_salah_lewat_form(): void
    {
        $sa = $this->superAdmin();
        $grand = $this->grand();

        // reseller_bronze induknya harus distributor, bukan grand → ditolak
        $this->actingAs($sa)->post(route('users.store'), [
            'fullname' => 'Res Satu', 'email' => 'res1@skinku.test', 'username' => 'res1',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'reseller_bronze', 'upline_id' => $grand->id, 'status' => User::STATUS_ACTIVE,
        ])->assertSessionHasErrors('upline_id');

        $this->assertNull(User::where('username', 'res1')->first());
    }

    public function test_member_id_tampil_di_daftar(): void
    {
        $sa = $this->superAdmin();
        $this->grand();
        $this->actingAs($sa)->get(route('users.index'))->assertOk()->assertSee('SKN-000001');
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=MemberFormHierarchyTest`
Expected: FAIL.

- [ ] **Step 3: Ubah `UserController`**

Tambah import + constructor:
```php
use App\Services\PartnerHierarchyService;
```
```php
    public function __construct(private PartnerHierarchyService $hierarchy) {}
```

Di `index()`, kirim daftar tier + peta kandidat upline ke view:
```php
        return view('users.index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::ordered()->get(),
            'tierRoles' => array_keys(\App\Support\PartnerHierarchy::TIERS),
            'uplineCandidates' => User::whereIn('role', array_keys(\App\Support\PartnerHierarchy::TIERS))
                ->where('status', User::STATUS_ACTIVE)
                ->orderBy('fullname')
                ->get(['id', 'fullname', 'role', 'region', 'member_id']),
        ]);
```

Di `store()`: tambah `'upline_id' => ['nullable', 'integer', 'exists:users,id']` ke aturan validasi; setelah `User::create([...])` (tetap tanpa upline_id di array create), panggil service lalu save:
```php
        $this->hierarchy->assignUpline($user, $data['upline_id'] ?? null);
        $this->hierarchy->ensureMemberId($user);
        $user->save();
```
> Catatan: `assignUpline` melempar `ValidationException` (key `upline_id`) bila level salah → otomatis balik ke form dengan error. Bungkus create+assign dalam `DB::transaction` agar member_id konsisten.

Di `update()`: tambah aturan `'upline_id'` yang sama; sebelum `$user->save()`, panggil `assignUpline($user, $data['upline_id'] ?? null)` + `ensureMemberId($user)`.

- [ ] **Step 4: Ubah `resources/views/users/index.blade.php`**

- Tambahkan opsi role tier baru di `<select name="role">` (grand_distributor, reseller_bronze, reseller_gold) — atau iterasi `$roles` yang sudah memuatnya.
- Tambah field **Upline** di form create & edit:
```blade
<div data-upline-wrap>
  <label class="block text-sm">Upline (induk)</label>
  <select name="upline_id" class="w-full border rounded px-2 py-1">
    <option value="">— belum ditempatkan —</option>
    @foreach($uplineCandidates as $cand)
      <option value="{{ $cand->id }}" data-role="{{ $cand->role }}" data-region="{{ $cand->region }}">
        {{ $cand->fullname }} ({{ \App\Support\PartnerHierarchy::label($cand->role) }}{{ $cand->region ? ' · '.$cand->region : '' }})
      </option>
    @endforeach
  </select>
  <p class="text-xs text-stone-500">Induk otomatis disaring 1 tingkat di atas peran terpilih.</p>
</div>
```
- Vanilla JS: saat `role` berubah, filter opsi `upline_id` agar hanya menampilkan kandidat yang `data-role`-nya termasuk `allowedParentRoles(role)`. Map peta level dari `@json(\App\Support\PartnerHierarchy::TIERS)` (via `json_encode`, bukan `@json([...])` literal — lihat konvensi Blade `[[feedback-blade-json-array-literal]]`).
- Tampilkan kolom **Member ID** di tabel daftar anggota: `{{ $u->member_id ?? '—' }}`.

- [ ] **Step 5: Jalankan test + render, pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=MemberFormHierarchyTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/UserController.php resources/views/users/index.blade.php tests/Feature/MemberFormHierarchyTest.php
git commit -m "feat(mlm): form Anggota - pilih upline (tervalidasi) + auto Member ID"
```

---

### Task 8: Halaman Struktur Jaringan

**Files:**
- Create: `app/Http/Controllers/PartnerHierarchyController.php`
- Create: `resources/views/struktur_jaringan/index.blade.php`
- Create: `resources/views/struktur_jaringan/_node.blade.php`
- Modify: `routes/web.php` (route baru di grup internal + `permission:manage_users`)
- Modify: `resources/views/layouts/app.blade.php:187` (menu setelah Kelola Anggota)
- Test: `tests/Feature/StrukturJaringanTest.php`

**Interfaces:**
- Consumes: relasi `downlines()` (Task 1), `PartnerHierarchy` (Task 2).
- Produces: route bernama `struktur-jaringan.index`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StrukturJaringanTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $u, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
            'member_id' => strtoupper($u).'-ID',
        ]);
    }

    public function test_render_pohon_dan_panel_belum_ditempatkan(): void
    {
        $sa = User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $grand = $this->mk('grandx', 'grand_distributor');
        $dist = $this->mk('distx', 'distributor', $grand->id);
        $lepas = $this->mk('lepas', 'distributor'); // belum ditempatkan

        $res = $this->actingAs($sa)->get(route('struktur-jaringan.index'))->assertOk();
        $res->assertSee('GRANDX');   // root
        $res->assertSee('DISTX');    // child
        $res->assertSee('Belum ditempatkan');
        $res->assertSee('LEPAS');
    }

    public function test_mitra_tidak_boleh_akses(): void
    {
        $dist = $this->mk('d', 'distributor');
        $this->actingAs($dist)->get(route('struktur-jaringan.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=StrukturJaringanTest`
Expected: FAIL (route belum ada).

- [ ] **Step 3: Buat controller**

`app/Http/Controllers/PartnerHierarchyController.php`:
```php
<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PartnerHierarchy;

class PartnerHierarchyController extends Controller
{
    public function index()
    {
        $roots = User::where('role', User::ROLE_GRAND_DISTRIBUTOR)
            ->whereNull('upline_id')
            ->with('downlines.downlines') // distributor -> reseller
            ->orderBy('fullname')
            ->get();

        $unplaced = User::whereIn('role', [
            User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER,
            User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD,
        ])->whereNull('upline_id')->orderBy('fullname')->get();

        return view('struktur_jaringan.index', ['roots' => $roots, 'unplaced' => $unplaced]);
    }
}
```

- [ ] **Step 4: Buat view + partial**

`resources/views/struktur_jaringan/_node.blade.php`:
```blade
<li class="mt-1">
  <div class="inline-flex items-center gap-2 rounded border border-stone-200 bg-white px-2 py-1 text-sm">
    <span class="font-medium">{{ $node->fullname }}</span>
    <span class="text-xs text-stone-500">{{ $node->member_id ?? '—' }}</span>
    <span class="text-xs px-1.5 rounded bg-emerald-100 text-emerald-800">{{ \App\Support\PartnerHierarchy::label($node->role) }}</span>
    @if($node->region)<span class="text-xs text-stone-400">{{ $node->region }}</span>@endif
    <span class="text-xs text-stone-400">{{ $node->downlines->count() }} downline</span>
    <span class="text-xs px-1.5 rounded {{ \App\Support\PartnerHierarchy::holdsStock($node->role) ? 'bg-amber-100 text-amber-800' : 'bg-stone-100 text-stone-500' }}">
      {{ \App\Support\PartnerHierarchy::holdsStock($node->role) ? 'stockist' : 'non-stok' }}
    </span>
  </div>
  @if($node->downlines->isNotEmpty())
    <ul class="ml-6 border-l border-stone-200 pl-3">
      @foreach($node->downlines as $child)
        @include('struktur_jaringan._node', ['node' => $child])
      @endforeach
    </ul>
  @endif
</li>
```

`resources/views/struktur_jaringan/index.blade.php` (pakai layout & pola halaman lain di repo; inti):
```blade
@extends('layouts.app')
@section('content')
<div class="p-4 space-y-6">
  <h1 class="text-lg font-semibold">Struktur Jaringan</h1>

  @forelse($roots as $root)
    <ul class="list-none">@include('struktur_jaringan._node', ['node' => $root])</ul>
  @empty
    <p class="text-sm text-stone-500">Belum ada Grand Distributor yang ditempatkan. Mulai tempatkan mitra dari daftar di bawah.</p>
  @endforelse

  <div>
    <h2 class="text-sm font-semibold text-stone-600">Belum ditempatkan ({{ $unplaced->count() }})</h2>
    <ul class="mt-2 flex flex-wrap gap-2">
      @foreach($unplaced as $u)
        <li class="rounded border border-dashed border-stone-300 px-2 py-1 text-sm">
          {{ $u->fullname }} <span class="text-xs text-stone-400">{{ \App\Support\PartnerHierarchy::label($u->role) }}</span>
        </li>
      @endforeach
    </ul>
  </div>
</div>
@endsection
```
> Samakan header/pembungkus dengan halaman internal lain (mis. `users/index.blade.php`) bila strukturnya beda.

- [ ] **Step 5: Route + menu**

`routes/web.php` — di grup internal ber-`permission:manage_users` (dekat route `users`), tambah:
```php
Route::get('struktur-jaringan', [\App\Http\Controllers\PartnerHierarchyController::class, 'index'])->name('struktur-jaringan.index');
```
> Pastikan berada dalam grup middleware yang sama dengan `users.*` (internal + `permission:manage_users`) agar mitra otomatis 403.

`resources/views/layouts/app.blade.php` — setelah item Kelola Anggota (baris ~187):
```blade
                {!! navItem('struktur-jaringan.index', 'Struktur Jaringan', 'struktur-jaringan.index') !!}
```
(di dalam blok `@if($u->canDo('manage_users'))` yang sama).

- [ ] **Step 6: Jalankan test, pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=StrukturJaringanTest`
Expected: PASS.

- [ ] **Step 7: Suite penuh + Pint + commit**

Run: `/c/php83/php.exe artisan test 2>&1 | tail -4`
Expected: seluruh suite hijau.
```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/PartnerHierarchyController.php resources/views/struktur_jaringan/ routes/web.php resources/views/layouts/app.blade.php tests/Feature/StrukturJaringanTest.php
git commit -m "feat(mlm): halaman Struktur Jaringan (pohon indentasi + panel belum-ditempatkan)"
```

---

## Catatan integrasi (di luar task inti, opsional-follow-up)

- **Guard hapus/non-aktif upline**: di `UserController::destroy()` & `toggleStatus()`, sebelum menonaktifkan/menghapus, tolak bila `$this->hierarchy->hasActiveDownline($user)` dengan pesan "pindahkan downline dulu". Tambah 1 test. (Aman ditunda ke follow-up kecil; tidak memblokir Tahap 1.)
- **Tampil Member ID di dashboard mitra**: tambahkan `Auth::user()->member_id` di header dashboard mitra agar mereka tahu ID login-nya.

## Self-Review

- **Spec coverage:** 3.1 (Task 1) · 3.2 (Task 2) · 3.3 (Task 3) · 3.4 integritas (Task 5) + guard hapus (Catatan integrasi) · 3.5 UI form (Task 7) + halaman (Task 8) · 3.6 izin (Task 4) · 3.7 Member ID+login (Task 5 gen + Task 6 login + Task 7 form) · 3.8 tes (tiap task). ✔ Semua tercakup.
- **Placeholder scan:** tidak ada TBD/generik; semua step berisi kode nyata. ✔
- **Type consistency:** `assignUpline(User, ?int)`, `ensureMemberId(User)`, `eligibleUplines(string, ?string)`, `hasActiveDownline(User)`, `PartnerHierarchy::{levelOf,allowedParentRoles,isTierRole,holdsStock,label}`, `User::PARTNER_ROLES` — konsisten dipakai lintas Task 1/5/7/8. ✔
