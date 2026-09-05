# Dormansi Member — Auto-Freeze Akun Tak Aktif Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bekukan otomatis akun member MLM yang tak ada pergerakan sekian bulan (per-role, bisa disetting), reuse mekanisme status/login-block yang ada, plus pelacakan last-online & panel HQ untuk aktifkan kembali (manual).

**Architecture:** Tabel aturan per role-slug (`member_dormancy_rules`) yang diedit dari halaman Setelan → `MemberDormancyService` menghitung "aktivitas efektif" (dengan masa tenggang anti beku-massal) → command harian `members:auto-freeze` set `status=inactive` (login otomatis ketolak oleh AuthController yang sudah ada) → panel HQ untuk atur aturan + lihat beku/akan-beku + aktifkan lagi. Kolom `users.last_login_at` distempel saat login asli.

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + Tailwind (vanilla, zero-dependency), Eloquent, MySQL (prod) / SQLite (tes).

## Global Constraints

- **Zero-dependency:** JANGAN tambah paket composer/npm apa pun. Tulis helper minimal bila perlu.
- **Runner tes:** `/c/php83/php.exe artisan test` (filter: `--filter=MemberDormancyTest`). Pint sebelum commit: `/c/php83/php.exe vendor/bin/pint --dirty`.
- **Commit trailer:** setiap commit diakhiri `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- **Aturan HARUS dari DB (bisa disetting), TIDAK di-hardcode** — role/bulan/basis semua di tabel `member_dormancy_rules`.
- **Beku = `status='inactive'` + `disabled_at=now()`** (login sudah otomatis ditolak di `AuthController@login` bila status ≠ active). Reaktivasi HANYA manual dari HQ.
- **Staff (`super_admin`,`admin`,`gudang`) tak pernah dibekukan.**
- **Migrasi baru mulai `2026_01_01_000123`** (terakhir = `000122`). Kolom `period`-style tak relevan di sini.
- **Anti beku-massal:** aktivitas efektif = paling baru dari [aktivitas basis, `rule.activated_at`, `user.created_at`].
- **Scope Fase 1:** role `grand_distributor, distributor, reseller, reseller_bronze, reseller_gold, sponsor`. Affiliator/role custom nyusul lewat setting (tanpa koding).

---

### Task 1: Tabel aturan + model + seed default

**Files:**
- Create: `database/migrations/2026_01_01_000123_create_member_dormancy_rules.php`
- Create: `app/Models/MemberDormancyRule.php`
- Create: `tests/Feature/MemberDormancyTest.php`

**Interfaces:**
- Produces: tabel `member_dormancy_rules(id, role unique, enabled bool, inactive_months smallint, basis string, activated_at datetime null, updated_by fk null, timestamps)`; model `App\Models\MemberDormancyRule` dgn konstanta `BASIS_ORDER='order'`, `BASIS_LOGIN='login'`, `BASIS_RECRUIT='recruit'`, `BASES=[...]`, fillable `['role','enabled','inactive_months','basis','activated_at','updated_by']`, cast `enabled=>bool, inactive_months=>int, activated_at=>datetime`. Seed 6 baris default (enabled=false).

- [ ] **Step 1: Tulis tes gagal**

Buat `tests/Feature/MemberDormancyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MemberDormancyRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberDormancyTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, string $u, array $attrs = []): User
    {
        $user = User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
        // forceFill: supaya bisa set kolom yang tak fillable (created_at, disabled_at)
        // + last_login_at/sponsor_id/status untuk skenario tes. save() saat update TIDAK
        // menimpa created_at yang sudah kita set.
        if ($attrs !== []) {
            $user->forceFill($attrs)->save();
        }

        return $user;
    }

    public function test_migrasi_seed_6_aturan_default_nonaktif(): void
    {
        $this->assertSame(6, MemberDormancyRule::count());
        $grand = MemberDormancyRule::where('role', 'grand_distributor')->first();
        $this->assertNotNull($grand);
        $this->assertSame('order', $grand->basis);
        $this->assertSame(6, $grand->inactive_months);
        $this->assertFalse($grand->enabled);

        $sponsor = MemberDormancyRule::where('role', 'sponsor')->first();
        $this->assertSame('login', $sponsor->basis);
        $this->assertSame(3, $sponsor->inactive_months);
    }
}
```

- [ ] **Step 2: Jalankan tes — pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: FAIL ("Class MemberDormancyRule not found" atau tabel tak ada).

- [ ] **Step 3: Buat migrasi**

`database/migrations/2026_01_01_000123_create_member_dormancy_rules.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan dormansi per role (bisa disetting dari Setelan): role mana, aktif/tidak,
 * berapa bulan tanpa aktivitas, dan sinyal aktifnya (order/login/recruit).
 * activated_at = kapan aturan di-ON-kan → dasar masa tenggang (anti beku massal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_dormancy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('role', 50)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('inactive_months')->default(3);
            $table->string('basis', 20)->default('login'); // order | login | recruit
            $table->dateTime('activated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['role' => 'grand_distributor', 'basis' => 'order', 'inactive_months' => 6],
            ['role' => 'distributor', 'basis' => 'order', 'inactive_months' => 3],
            ['role' => 'reseller', 'basis' => 'login', 'inactive_months' => 3],
            ['role' => 'reseller_bronze', 'basis' => 'login', 'inactive_months' => 3],
            ['role' => 'reseller_gold', 'basis' => 'login', 'inactive_months' => 3],
            ['role' => 'sponsor', 'basis' => 'login', 'inactive_months' => 3],
        ];
        foreach ($defaults as $d) {
            DB::table('member_dormancy_rules')->insert($d + [
                'enabled' => false, 'activated_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_dormancy_rules');
    }
};
```

- [ ] **Step 4: Buat model**

`app/Models/MemberDormancyRule.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberDormancyRule extends Model
{
    public const BASIS_ORDER = 'order';

    public const BASIS_LOGIN = 'login';

    public const BASIS_RECRUIT = 'recruit';

    public const BASES = [self::BASIS_ORDER, self::BASIS_LOGIN, self::BASIS_RECRUIT];

    protected $fillable = ['role', 'enabled', 'inactive_months', 'basis', 'activated_at', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'inactive_months' => 'integer',
            'activated_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: Jalankan tes — pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000123_create_member_dormancy_rules.php app/Models/MemberDormancyRule.php tests/Feature/MemberDormancyTest.php
git commit -m "feat(member): tabel aturan dormansi per-role + seed default"
```

---

### Task 2: Kolom last_login_at + stempel saat login

**Files:**
- Create: `database/migrations/2026_01_01_000124_add_last_login_at_to_users.php`
- Modify: `app/Models/User.php` (fillable + cast)
- Modify: `app/Http/Controllers/AuthController.php` (stempel setelah `Auth::login`)
- Modify: `tests/Feature/MemberDormancyTest.php` (tambah tes)

**Interfaces:**
- Consumes: model `User`.
- Produces: kolom `users.last_login_at` (datetime null); distempel saat login asli via `AuthController@login`, TIDAK saat impersonasi.

- [ ] **Step 1: Tulis tes gagal**

Tambahkan ke `tests/Feature/MemberDormancyTest.php`:

```php
    public function test_login_asli_menstempel_last_login_at(): void
    {
        $u = $this->member(User::ROLE_RESELLER, 'lg1');
        $this->assertNull($u->last_login_at);

        $this->post('/login', ['login' => 'lg1', 'password' => 'secret123'])->assertRedirect();

        $this->assertNotNull($u->fresh()->last_login_at);
    }

    public function test_impersonasi_tidak_menstempel_last_login(): void
    {
        $admin = $this->member(User::ROLE_SUPER_ADMIN, 'sa1');
        $target = $this->member(User::ROLE_DISTRIBUTOR, 'tg1');

        $this->actingAs($admin)->post(route('users.impersonate', $target))->assertRedirect();

        $this->assertNull($target->fresh()->last_login_at);
    }
```

- [ ] **Step 2: Jalankan tes — pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: FAIL (kolom `last_login_at` belum ada / null setelah login).

- [ ] **Step 3: Buat migrasi kolom**

`database/migrations/2026_01_01_000124_add_last_login_at_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('last_login_at')->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
```

- [ ] **Step 4: Tambah fillable + cast di User**

Di `app/Models/User.php`, tambah `'last_login_at'` ke `$fillable` (setelah `'disabled_at'`), dan tambah cast:

```php
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
```

- [ ] **Step 5: Stempel di AuthController@login**

Di `app/Http/Controllers/AuthController.php`, tepat setelah `Auth::login($user, $request->boolean('remember'));` (baris ~76):

```php
        Auth::login($user, $request->boolean('remember'));
        $user->update(['last_login_at' => now()]); // last-online (login asli, bukan impersonasi)
        $request->session()->regenerate();
```

(JANGAN tambahkan stempel ini di `app/Services/ImpersonationService.php`.)

- [ ] **Step 6: Jalankan tes — pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: PASS. (Jika `test_impersonasi...` gagal karena guard route impersonate, periksa `ImpersonationController@start` — target harus impersonatable; super_admin selalu boleh.)

- [ ] **Step 7: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000124_add_last_login_at_to_users.php app/Models/User.php app/Http/Controllers/AuthController.php tests/Feature/MemberDormancyTest.php
git commit -m "feat(member): kolom last_login_at + stempel saat login (bukan impersonasi)"
```

---

### Task 3: MemberDormancyService (logika inti)

**Files:**
- Create: `app/Services/MemberDormancyService.php`
- Modify: `tests/Feature/MemberDormancyTest.php`

**Interfaces:**
- Consumes: `User`, `MemberDormancyRule`, `PurchaseOrder` (const `STATUS_CANCELLED='cancelled'`, `STATUS_DELETED='deleted'`, kolom `user_id`, `status`, `created_at`), `AuditService::log(...)`.
- Produces: `App\Services\MemberDormancyService` dgn method:
  - `lastActivityDate(User $user, string $basis): ?Carbon`
  - `effectiveActivityDate(User $user, MemberDormancyRule $rule): Carbon`
  - `isDormant(User $user, MemberDormancyRule $rule, ?Carbon $now = null): bool`
  - `atRiskDays(User $user, MemberDormancyRule $rule, ?Carbon $now = null): int`
  - `freeze(User $user, MemberDormancyRule $rule): void`
  - `reactivate(User $user): void`

- [ ] **Step 1: Tulis tes gagal**

Tambahkan ke `tests/Feature/MemberDormancyTest.php` (import di atas file: `use App\Services\MemberDormancyService;`, `use Illuminate\Support\Carbon;`, `use Illuminate\Support\Facades\DB;`):

```php
    private function rule(string $role, string $basis, int $months, ?Carbon $activatedAt = null): MemberDormancyRule
    {
        return MemberDormancyRule::updateOrCreate(['role' => $role], [
            'enabled' => true, 'basis' => $basis, 'inactive_months' => $months, 'activated_at' => $activatedAt,
        ]);
    }

    public function test_last_activity_per_basis(): void
    {
        $svc = app(MemberDormancyService::class);

        // login
        $u = $this->member(User::ROLE_RESELLER, 'b1', ['last_login_at' => Carbon::parse('2026-01-10')]);
        $this->assertSame('2026-01-10', $svc->lastActivityDate($u, 'login')->toDateString());

        // order (PO non-cancelled terbaru) — DB::table insert supaya bisa set created_at
        // langsung & lepas dari daftar fillable PurchaseOrder.
        $d = $this->member(User::ROLE_DISTRIBUTOR, 'b2');
        DB::table('purchase_orders')->insert([
            ['user_id' => $d->id, 'po_number' => 'PO-1', 'status' => 'completed', 'total_amount' => 0, 'created_at' => '2026-02-01 00:00:00', 'updated_at' => now()],
            ['user_id' => $d->id, 'po_number' => 'PO-2', 'status' => 'cancelled', 'total_amount' => 0, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => now()],
        ]);
        $this->assertSame('2026-02-01', $svc->lastActivityDate($d, 'order')->toDateString()); // cancelled diabaikan

        // recruit (downline/rekrut terbaru)
        $s = $this->member(User::ROLE_SPONSOR, 'b3');
        $this->member(User::ROLE_RESELLER, 'b3a', ['sponsor_id' => $s->id, 'created_at' => Carbon::parse('2026-02-20')]);
        $this->assertSame('2026-02-20', $svc->lastActivityDate($s, 'recruit')->toDateString());
    }

    public function test_effective_date_lindungi_masa_tenggang_dan_member_baru(): void
    {
        $svc = app(MemberDormancyService::class);
        // Aturan baru dinyalakan hari ini; member lama tanpa aktivitas → efektif = activated_at (bukan beku).
        $rule = $this->rule(User::ROLE_RESELLER, 'login', 3, now());
        $old = $this->member(User::ROLE_RESELLER, 'old1', ['created_at' => Carbon::parse('2024-01-01')]);
        $this->assertFalse($svc->isDormant($old, $rule, now()));
    }

    public function test_is_dormant_di_batas(): void
    {
        $svc = app(MemberDormancyService::class);
        $rule = $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));
        $now = Carbon::parse('2026-06-01');

        $aktif = $this->member(User::ROLE_RESELLER, 'a1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => Carbon::parse('2026-04-01')]);
        $this->assertFalse($svc->isDormant($aktif, $rule, $now)); // 2 bln lalu < 3 bln

        $dorman = $this->member(User::ROLE_RESELLER, 'd1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => Carbon::parse('2026-01-01')]);
        $this->assertTrue($svc->isDormant($dorman, $rule, $now)); // 5 bln lalu > 3 bln
        $this->assertSame(0, $svc->atRiskDays($dorman, $rule, $now));
    }
```

- [ ] **Step 2: Jalankan tes — pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: FAIL ("Class MemberDormancyService not found").

- [ ] **Step 3: Buat service**

`app/Services/MemberDormancyService.php`:

```php
<?php

namespace App\Services;

use App\Models\MemberDormancyRule;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Logika dormansi member: hitung aktivitas terakhir per basis, aktivitas efektif
 * (dengan masa tenggang anti beku-massal), status dorman, sisa hari, serta aksi
 * beku/aktifkan. Murni memakai data DB — aturan datang dari MemberDormancyRule.
 */
class MemberDormancyService
{
    /** Tanggal aktivitas terakhir sesuai basis; null bila tak ada. */
    public function lastActivityDate(User $user, string $basis): ?Carbon
    {
        $ts = match ($basis) {
            MemberDormancyRule::BASIS_ORDER => PurchaseOrder::where('user_id', $user->id)
                ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DELETED])
                ->max('created_at'),
            MemberDormancyRule::BASIS_LOGIN => $user->last_login_at,
            MemberDormancyRule::BASIS_RECRUIT => User::where(fn ($q) => $q
                ->where('sponsor_id', $user->id)->orWhere('upline_id', $user->id))
                ->max('created_at'),
            default => null,
        };

        return $ts ? Carbon::parse($ts) : null;
    }

    /** Aktivitas efektif = PALING BARU dari [aktivitas basis, activated_at, created_at]. */
    public function effectiveActivityDate(User $user, MemberDormancyRule $rule): Carbon
    {
        $candidates = array_filter([
            $this->lastActivityDate($user, $rule->basis),
            $rule->activated_at,
            $user->created_at,
        ]);

        $max = null;
        foreach ($candidates as $d) {
            $c = Carbon::parse($d);
            if ($max === null || $c->greaterThan($max)) {
                $max = $c;
            }
        }

        return $max ?? now();
    }

    public function isDormant(User $user, MemberDormancyRule $rule, ?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->effectiveActivityDate($user, $rule)
            ->lessThan($now->copy()->subMonths($rule->inactive_months));
    }

    /** Sisa hari sebelum beku (0 bila sudah lewat). */
    public function atRiskDays(User $user, MemberDormancyRule $rule, ?Carbon $now = null): int
    {
        $now ??= now();
        $freezeOn = $this->effectiveActivityDate($user, $rule)->copy()->addMonths($rule->inactive_months);
        $days = (int) ceil(($freezeOn->getTimestamp() - $now->getTimestamp()) / 86400);

        return max(0, $days);
    }

    /** Bekukan: nonaktif + disabled_at + audit. Login otomatis ketolak (AuthController). */
    public function freeze(User $user, MemberDormancyRule $rule): void
    {
        $last = optional($this->lastActivityDate($user, $rule->basis))->toDateString();
        $user->update(['status' => User::STATUS_INACTIVE, 'disabled_at' => now()]);

        AuditService::log(
            action: 'auto_freeze', targetType: 'user', targetId: $user->id,
            targetUserId: $user->id, targetEmail: $user->email,
            after: ['role' => $user->role, 'basis' => $rule->basis, 'inactive_months' => $rule->inactive_months, 'last_activity' => $last],
        );
    }

    /** Aktifkan kembali (manual dari HQ). */
    public function reactivate(User $user): void
    {
        $user->update(['status' => User::STATUS_ACTIVE, 'disabled_at' => null]);

        AuditService::log(
            action: 'reactivate_member', targetType: 'user', targetId: $user->id,
            targetUserId: $user->id, targetEmail: $user->email,
        );
    }
}
```

- [ ] **Step 4: Jalankan tes — pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MemberDormancyService.php tests/Feature/MemberDormancyTest.php
git commit -m "feat(member): MemberDormancyService (aktivitas efektif + dorman + beku/aktif)"
```

---

### Task 4: Command auto-freeze + jadwal cron

**Files:**
- Create: `app/Console/Commands/MembersAutoFreezeCommand.php`
- Modify: `routes/console.php` (jadwal)
- Modify: `tests/Feature/MemberDormancyTest.php`

**Interfaces:**
- Consumes: `MemberDormancyService`, `MemberDormancyRule`, `User`.
- Produces: command `members:auto-freeze {--dry-run} {--limit=0}`.

- [ ] **Step 1: Tulis tes gagal**

Tambahkan ke `tests/Feature/MemberDormancyTest.php`:

```php
    public function test_command_bekukan_dorman_lewati_aktif_dan_staff(): void
    {
        $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));

        $dorman = $this->member(User::ROLE_RESELLER, 'cf1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subMonths(5)]);
        $aktif = $this->member(User::ROLE_RESELLER, 'cf2', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subDays(3)]);
        // Admin punya last_login lama TAPI tak boleh kena (staff + tak ada rule role admin).
        $admin = $this->member(User::ROLE_ADMIN, 'cf3', ['last_login_at' => now()->subYears(2)]);

        $this->artisan('members:auto-freeze')->assertSuccessful();

        $this->assertSame(User::STATUS_INACTIVE, $dorman->fresh()->status);
        $this->assertNotNull($dorman->fresh()->disabled_at);
        $this->assertSame(User::STATUS_ACTIVE, $aktif->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
    }

    public function test_command_hormati_enabled_dan_dry_run(): void
    {
        // Aturan NONAKTIF → tak boleh ada yang dibekukan.
        MemberDormancyRule::updateOrCreate(['role' => User::ROLE_RESELLER], ['enabled' => false, 'basis' => 'login', 'inactive_months' => 3, 'activated_at' => Carbon::parse('2020-01-01')]);
        $u = $this->member(User::ROLE_RESELLER, 'df1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subMonths(9)]);
        $this->artisan('members:auto-freeze')->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $u->fresh()->status);

        // Aktifkan + dry-run → tetap tak berubah.
        $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));
        $this->artisan('members:auto-freeze', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $u->fresh()->status);
    }

    public function test_member_beku_tak_bisa_login(): void
    {
        // Rangkaian: dorman → command bekukan → login ditolak (mekanisme AuthController).
        $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));
        $u = $this->member(User::ROLE_RESELLER, 'fl1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subMonths(9)]);

        $this->artisan('members:auto-freeze')->assertSuccessful();
        $this->assertSame(User::STATUS_INACTIVE, $u->fresh()->status);

        $this->post('/login', ['login' => 'fl1', 'password' => 'secret123'])
            ->assertSessionHasErrors('login'); // ditolak karena status != active
        $this->assertGuest();
    }
```

- [ ] **Step 2: Jalankan tes — pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: FAIL ("command members:auto-freeze not defined").

- [ ] **Step 3: Buat command**

`app/Console/Commands/MembersAutoFreezeCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\MemberDormancyRule;
use App\Models\User;
use App\Services\MemberDormancyService;
use Illuminate\Console\Command;

/**
 * Bekukan otomatis akun member dorman sesuai aturan per-role yang aktif.
 * Staff tak pernah kena. Idempoten (yang sudah nonaktif otomatis terlewati).
 */
class MembersAutoFreezeCommand extends Command
{
    protected $signature = 'members:auto-freeze {--dry-run : Laporkan tanpa mengubah} {--limit=0 : Batasi jumlah (0 = semua)}';

    protected $description = 'Bekukan otomatis akun member yang dorman (per-role, sesuai aturan yang aktif).';

    public function handle(MemberDormancyService $svc): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $now = now();
        $frozen = 0;

        foreach (MemberDormancyRule::where('enabled', true)->get() as $rule) {
            $users = User::where('role', $rule->role)
                ->where('status', User::STATUS_ACTIVE)
                ->whereNotIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_GUDANG])
                ->get();

            foreach ($users as $user) {
                if (! $svc->isDormant($user, $rule, $now)) {
                    continue;
                }
                if ($dry) {
                    $this->line("  [dry] @{$user->username} ({$rule->role}) → akan dibekukan.");
                } else {
                    $svc->freeze($user, $rule);
                    $this->line("  \u{2713} @{$user->username} ({$rule->role}) dibekukan.");
                }
                $frozen++;

                if ($limit > 0 && $frozen >= $limit) {
                    $this->info(($dry ? '[dry] ' : '')."Batas {$limit} tercapai. Total: {$frozen}.");

                    return self::SUCCESS;
                }
            }
        }

        $this->info(($dry ? '[dry] ' : '')."Selesai. {$frozen} akun ".($dry ? 'akan dibekukan' : 'dibekukan').'.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Jadwalkan cron**

Di `routes/console.php`, tambahkan (setelah blok jadwal affiliate/tiktok yang ada):

```php
// Dormansi member: bekukan akun tak aktif sesuai aturan per-role (aturan default
// OFF → nol efek sampai HQ nyalakan). Harian, di luar jam sync berat.
Schedule::command('members:auto-freeze')->dailyAt('03:00')->withoutOverlapping(60);
```

- [ ] **Step 5: Jalankan tes — pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Console/Commands/MembersAutoFreezeCommand.php routes/console.php tests/Feature/MemberDormancyTest.php
git commit -m "feat(member): command members:auto-freeze + jadwal harian"
```

---

### Task 5: Izin + controller + rute (aturan & reaktivasi)

**Files:**
- Modify: `app/Support/Permissions.php` (DEFINITIONS + DEFAULTS)
- Create: `app/Http/Controllers/MemberDormancyController.php`
- Modify: `routes/web.php` (import + grup rute)
- Modify: `tests/Feature/MemberDormancyTest.php`

**Interfaces:**
- Consumes: `MemberDormancyService`, `MemberDormancyRule`, `User`.
- Produces: permission `manage_member_dormancy`; rute `member-dormancy.index` (GET), `member-dormancy.rules` (POST), `member-dormancy.reactivate` (POST, `{user}`); controller method `index`, `saveRules`, `reactivate`; view data `rules` (keyed by role), `managedRoles`, `bases`, `frozen`, `atRisk`.

- [ ] **Step 1: Tulis tes gagal**

Tambahkan ke `tests/Feature/MemberDormancyTest.php`:

```php
    public function test_gate_izin_dan_render(): void
    {
        // reseller (mitra) tak punya izin → 403.
        $this->actingAs($this->member(User::ROLE_RESELLER, 'g1'))->get(route('member-dormancy.index'))->assertForbidden();
        // admin (default punya manage_member_dormancy) → OK.
        $this->actingAs($this->member(User::ROLE_ADMIN, 'g2'))->get(route('member-dormancy.index'))
            ->assertOk()->assertSee('Dormansi Member');
    }

    public function test_save_rules_set_activated_at_saat_dinyalakan(): void
    {
        $admin = $this->member(User::ROLE_ADMIN, 'sr1');
        $this->actingAs($admin)->post(route('member-dormancy.rules'), [
            'rules' => [
                'grand_distributor' => ['enabled' => '1', 'inactive_months' => 6, 'basis' => 'order'],
                'distributor' => ['inactive_months' => 3, 'basis' => 'order'], // enabled tak dicentang
                'reseller' => ['inactive_months' => 3, 'basis' => 'login'],
                'reseller_bronze' => ['inactive_months' => 3, 'basis' => 'login'],
                'reseller_gold' => ['inactive_months' => 3, 'basis' => 'login'],
                'sponsor' => ['inactive_months' => 3, 'basis' => 'login'],
            ],
        ])->assertRedirect();

        $grand = MemberDormancyRule::where('role', 'grand_distributor')->first();
        $this->assertTrue($grand->enabled);
        $this->assertNotNull($grand->activated_at); // masa tenggang mulai
        $this->assertFalse(MemberDormancyRule::where('role', 'distributor')->first()->enabled);
    }

    public function test_reactivate_balikin_aktif(): void
    {
        $admin = $this->member(User::ROLE_ADMIN, 'ra1');
        $beku = $this->member(User::ROLE_RESELLER, 'ra2', ['status' => User::STATUS_INACTIVE, 'disabled_at' => now()]);

        $this->actingAs($admin)->post(route('member-dormancy.reactivate', $beku))->assertRedirect();

        $this->assertSame(User::STATUS_ACTIVE, $beku->fresh()->status);
        $this->assertNull($beku->fresh()->disabled_at);
    }
```

- [ ] **Step 2: Jalankan tes — pastikan GAGAL**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: FAIL (route/permission belum ada).

- [ ] **Step 3: Tambah permission**

Di `app/Support/Permissions.php` — tambah baris ke `DEFINITIONS` (setelah `'manage_join_packages'`):

```php
        'manage_join_packages' => 'Kelola Paket Join',
        'manage_member_dormancy' => 'Kelola Dormansi Member',
    ];
```

dan ke `DEFAULTS` (setelah baris `manage_join_packages`):

```php
        'manage_join_packages' => [User::ROLE_ADMIN],
        'manage_member_dormancy' => [User::ROLE_ADMIN],
    ];
```

- [ ] **Step 4: Buat controller**

`app/Http/Controllers/MemberDormancyController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\MemberDormancyRule;
use App\Models\User;
use App\Services\MemberDormancyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Panel HQ Dormansi Member: atur aturan per-role, lihat member beku & akan-beku,
 * dan aktifkan kembali (manual). Gate manage_member_dormancy.
 */
class MemberDormancyController extends Controller
{
    /** Role member yang diatur dormansinya (Fase 1). */
    public const MANAGED_ROLES = [
        User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_DISTRIBUTOR,
        User::ROLE_RESELLER, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD,
        User::ROLE_SPONSOR,
    ];

    public function __construct(private MemberDormancyService $svc) {}

    public function index()
    {
        $rules = MemberDormancyRule::whereIn('role', self::MANAGED_ROLES)->get()->keyBy('role');
        $now = now();

        $frozen = User::whereIn('role', self::MANAGED_ROLES)
            ->where('status', User::STATUS_INACTIVE)->whereNotNull('disabled_at')
            ->orderByDesc('disabled_at')->get();

        $atRisk = collect();
        foreach ($rules->where('enabled', true) as $rule) {
            User::where('role', $rule->role)->where('status', User::STATUS_ACTIVE)->get()
                ->each(function (User $u) use ($rule, $now, $atRisk) {
                    if (! $this->svc->isDormant($u, $rule, $now)) {
                        $days = $this->svc->atRiskDays($u, $rule, $now);
                        if ($days <= 14) {
                            $atRisk->push(['user' => $u, 'days' => $days, 'basis' => $rule->basis]);
                        }
                    }
                });
        }

        return view('member_dormancy.index', [
            'rules' => $rules,
            'managedRoles' => self::MANAGED_ROLES,
            'bases' => MemberDormancyRule::BASES,
            'frozen' => $frozen,
            'atRisk' => $atRisk->sortBy('days')->values(),
        ]);
    }

    public function saveRules(Request $request): RedirectResponse
    {
        $request->validate([
            'rules' => ['array'],
            'rules.*.inactive_months' => ['required', 'integer', 'min:1', 'max:60'],
            'rules.*.basis' => ['required', Rule::in(MemberDormancyRule::BASES)],
        ]);

        foreach (self::MANAGED_ROLES as $role) {
            $enabled = $request->boolean("rules.{$role}.enabled");
            $rule = MemberDormancyRule::firstOrNew(['role' => $role]);
            if ($enabled && ! $rule->enabled) {
                $rule->activated_at = now(); // mulai masa tenggang saat OFF→ON
            }
            $rule->fill([
                'enabled' => $enabled,
                'inactive_months' => (int) $request->input("rules.{$role}.inactive_months", 3),
                'basis' => (string) $request->input("rules.{$role}.basis", MemberDormancyRule::BASIS_LOGIN),
                'updated_by' => $request->user()->id,
            ])->save();
        }

        return back()->with('status', 'Aturan dormansi disimpan.');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->svc->reactivate($user);

        return back()->with('status', "@{$user->username} diaktifkan kembali.");
    }
}
```

- [ ] **Step 5: Daftarkan rute**

Di `routes/web.php` — tambah import di blok `use` (dekat controller lain):

```php
use App\Http\Controllers\MemberDormancyController;
```

dan tambah grup rute di dalam grup yang sudah ter-`auth` (setelah blok `permission:manage_users` / user management, sekitar baris 640):

```php
    /* ---------------- Dormansi Member ---------------- */
    Route::middleware('permission:manage_member_dormancy')->group(function () {
        Route::get('/member-dormancy', [MemberDormancyController::class, 'index'])->name('member-dormancy.index');
        Route::post('/member-dormancy/rules', [MemberDormancyController::class, 'saveRules'])->name('member-dormancy.rules');
        Route::post('/member-dormancy/{user}/reactivate', [MemberDormancyController::class, 'reactivate'])->name('member-dormancy.reactivate');
    });
```

- [ ] **Step 6: Jalankan tes — pastikan LULUS** (view dibuat Task 6; sementara `test_gate_izin_dan_render` bisa gagal di `assertSee` sampai view ada — jalankan dulu tes non-render)

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: `test_save_rules...` & `test_reactivate...` PASS; `test_gate_izin_dan_render` LULUS setelah Task 6 (view). Jika gagal hanya karena view belum ada, lanjut Task 6 lalu jalankan lagi.

- [ ] **Step 7: Pint + commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Support/Permissions.php app/Http/Controllers/MemberDormancyController.php routes/web.php tests/Feature/MemberDormancyTest.php
git commit -m "feat(member): izin + controller + rute Dormansi Member (aturan & reaktivasi)"
```

---

### Task 6: Halaman HQ + item navigasi

**Files:**
- Create: `resources/views/member_dormancy/index.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (item nav)

**Interfaces:**
- Consumes: data dari `MemberDormancyController@index` (`rules`, `managedRoles`, `bases`, `frozen`, `atRisk`); rute `member-dormancy.rules`, `member-dormancy.reactivate`.

- [ ] **Step 1: Buat view**

`resources/views/member_dormancy/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Dormansi Member')
@section('heading', 'Dormansi Member')

@section('content')
@php
    $roleLabel = [
        'grand_distributor' => 'Grand Distributor', 'distributor' => 'Distributor',
        'reseller' => 'Reseller', 'reseller_bronze' => 'Reseller Bronze',
        'reseller_gold' => 'Reseller Gold', 'sponsor' => 'Sponsor',
    ];
    $basisLabel = ['order' => 'Order / RO', 'login' => 'Login (last-online)', 'recruit' => 'Rekrut baru'];
@endphp

<div class="space-y-5 max-w-4xl">
    <p class="text-sm text-stone-500 -mt-1">
        Akun member yang tak ada pergerakan sesuai batas di bawah akan <strong>otomatis dibekukan</strong>
        (tak bisa login). Menghidupkan kembali <strong>hanya manual dari sini</strong>. Aturan default <strong>mati</strong> —
        nyalakan per-role saat siap.
    </p>

    {{-- Aturan per-role --}}
    <form method="POST" action="{{ route('member-dormancy.rules') }}" class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        @csrf
        <div class="px-4 py-3 border-b border-stone-200 bg-stone-50"><p class="text-sm font-semibold text-stone-700">Aturan per Role</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                        <th class="px-4 py-2">Role</th>
                        <th class="px-4 py-2">Aktif</th>
                        <th class="px-4 py-2">Batas (bulan)</th>
                        <th class="px-4 py-2">Sinyal aktif</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($managedRoles as $role)
                        @php $r = $rules->get($role); @endphp
                        <tr>
                            <td class="px-4 py-2 font-medium text-stone-800">{{ $roleLabel[$role] ?? $role }}</td>
                            <td class="px-4 py-2">
                                <input type="checkbox" name="rules[{{ $role }}][enabled]" value="1" @checked($r?->enabled)>
                            </td>
                            <td class="px-4 py-2">
                                <input type="number" name="rules[{{ $role }}][inactive_months]" min="1" max="60" required
                                    value="{{ $r?->inactive_months ?? 3 }}" class="w-20 px-2 py-1 border border-stone-300 rounded-lg">
                            </td>
                            <td class="px-4 py-2">
                                <select name="rules[{{ $role }}][basis]" class="px-2 py-1 border border-stone-300 rounded-lg">
                                    @foreach($bases as $b)
                                        <option value="{{ $b }}" @selected(($r?->basis ?? 'login') === $b)>{{ $basisLabel[$b] ?? $b }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-stone-200 flex justify-end">
            <button class="rounded-xl bg-red-600 text-white px-5 py-2 text-sm font-semibold hover:bg-red-700">Simpan Aturan</button>
        </div>
    </form>

    {{-- Akan beku (≤ 14 hari) --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-amber-50"><p class="text-sm font-semibold text-amber-700">Akan dibekukan (≤ 14 hari) — {{ $atRisk->count() }}</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                    <th class="px-4 py-2">Member</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Sinyal</th><th class="px-4 py-2 text-right">Sisa hari</th>
                </tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($atRisk as $row)
                        <tr>
                            <td class="px-4 py-2 text-stone-800">{{ '@'.$row['user']->username }} <span class="text-xs text-stone-400">{{ $row['user']->fullname }}</span></td>
                            <td class="px-4 py-2 text-stone-600">{{ $roleLabel[$row['user']->role] ?? $row['user']->role }}</td>
                            <td class="px-4 py-2 text-stone-500">{{ $basisLabel[$row['basis']] ?? $row['basis'] }}</td>
                            <td class="px-4 py-2 text-right font-semibold text-amber-700">{{ $row['days'] }} hr</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Tak ada yang mendekati batas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Sudah beku --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-stone-50"><p class="text-sm font-semibold text-stone-700">Sudah dibekukan — {{ $frozen->count() }}</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                    <th class="px-4 py-2">Member</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Dibekukan</th><th class="px-4 py-2 text-right"></th>
                </tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($frozen as $u)
                        <tr>
                            <td class="px-4 py-2 text-stone-800">{{ '@'.$u->username }} <span class="text-xs text-stone-400">{{ $u->fullname }}</span></td>
                            <td class="px-4 py-2 text-stone-600">{{ $roleLabel[$u->role] ?? $u->role }}</td>
                            <td class="px-4 py-2 text-stone-500 text-xs">{{ optional($u->disabled_at)->translatedFormat('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('member-dormancy.reactivate', $u) }}" onsubmit="return confirm('Aktifkan kembali @{{ $u->username }}?')">
                                    @csrf
                                    <button class="text-xs font-semibold rounded-lg bg-emerald-600 text-white px-3 py-1.5 hover:bg-emerald-700">Aktifkan lagi</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Belum ada akun beku.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-stone-400">Riwayat login lengkap ada di Audit Log (aksi "login"). Beku otomatis jalan tiap hari 03:00.</p>
</div>
@endsection
```

- [ ] **Step 2: Tambah item navigasi**

Di `resources/views/layouts/app.blade.php`, cari item nav manajemen user (`navItem('users.index', ...)` / "Kelola Anggota"). Tepat setelahnya, tambah:

```blade
                @if($u->canDo('manage_member_dormancy'))
                    {!! navItem('member-dormancy.index', 'Dormansi Member', 'member-dormancy.*') !!}
                @endif
```

(Sesuaikan indentasi & nama helper `$u`/`navItem` dengan pola yang ada di sekitarnya.)

- [ ] **Step 3: Jalankan seluruh tes fitur — pastikan LULUS**

Run: `/c/php83/php.exe artisan test --filter=MemberDormancyTest`
Expected: PASS semua (termasuk `test_gate_izin_dan_render` yang tadi menunggu view).

- [ ] **Step 4: Bersihkan cache view + Pint + commit**

```bash
/c/php83/php.exe artisan view:clear
/c/php83/php.exe vendor/bin/pint --dirty
git add resources/views/member_dormancy/index.blade.php resources/views/layouts/app.blade.php
git commit -m "feat(member): halaman HQ Dormansi Member + item navigasi"
```

---

## Penutup

- [ ] **Jalankan SELURUH suite** sebelum selesai:

Run: `/c/php83/php.exe artisan test`
Expected: semua hijau (≈1080+ tes).

- [ ] **Push** setelah semua hijau: `git push origin HEAD`.

**Deploy (user, di prod):**
```bash
cd ~/domains/skinku.id/laravel-b2b && git pull && php artisan migrate --force && php artisan optimize:clear
```
Lalu buka **Dormansi Member**, tinjau daftar, dan **nyalakan** aturan per-role saat siap (default OFF → nol efek sampai dinyalakan).
