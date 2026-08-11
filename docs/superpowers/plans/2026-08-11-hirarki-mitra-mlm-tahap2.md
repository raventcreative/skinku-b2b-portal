# MLM Hirarki Mitra — Tahap 2 (Akses Bertingkat / Jaringan Saya) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Beri mitra upline halaman read-only "Jaringan Saya" untuk memantau ringkasan performa seluruh subtree-nya (omzet, tren, aktivitas) tanpa mengekspos data customer downline.

**Architecture:** Satu method service `descendants()` mengambil seluruh turunan; `JaringanSayaController` menghitung metrik agregat dari `PartnerSale` (grouping di PHP, bukan SQL date-func), menyusun pohon nested, dan merender via Blade rekursif. Nol mutasi, nol migrasi, nol sentuh harga/stok/PO.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind (CDN, palet `stone`), Eloquent. Zero-dependency.

**Spec:** `docs/superpowers/specs/2026-08-11-hirarki-mitra-mlm-tahap2-design.md`
**Branch:** `feat/hirarki-mitra-mlm-tahap2` (spec sudah di-commit di sini)

## Global Constraints

- **Zero-dependency**: tidak menambah paket composer/npm apa pun.
- **Tidak ada migrasi**: Tahap 2 tidak menambah kolom DB.
- **Read-only total**: halaman ini nol mutasi (tak ada edit/hapus/pindah/ubah tier).
- **Privasi**: query untuk halaman ini **tidak pernah** menyeleksi `partner_sales.customer_name` maupun `notes`. Hanya kolom agregat (`user_id`, `total_amount`, `sold_at`).
- **Portabilitas DB**: agregasi bulan **di PHP**, jangan pakai `MONTH()` / `strftime` / `DATE_FORMAT` (test SQLite, prod MySQL).
- **Scope aman**: data selalu dihitung dari `descendants(Auth::user())`. Tidak ada id node dari client.
- **Runner**: `C:\php83\php.exe artisan test`. Jalankan `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Ikut pola existing**: controller tipis, Blade `@extends('layouts.app')` + `@section('title'|'heading'|'content')`, palet `stone`.

---

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Services/PartnerHierarchyService.php` (modify) | + `descendants(User): Collection` — seluruh turunan, BFS, aman-loop |
| `app/Http/Controllers/JaringanSayaController.php` (create) | Gate isPartner; hitung metrik subtree; susun pohon + roll-up; render view |
| `routes/web.php` (modify) | + route `GET /jaringan-saya` → `jaringan-saya.index` (grup `auth`,`role`) |
| `resources/views/jaringan_saya/index.blade.php` (create) | Kartu roll-up + kontainer pohon + empty state |
| `resources/views/jaringan_saya/_node.blade.php` (create) | Baris 1 node (ringkasan kaya) + rekursi ke `children` |
| `resources/views/layouts/app.blade.php` (modify) | + nav item "Jaringan Saya" (gate `isPartner && downlines exists`) |
| `tests/Feature/PartnerDescendantsTest.php` (create) | Unit-ish test `descendants()` |
| `tests/Feature/JaringanSayaTest.php` (create) | Feature test halaman (isolasi, metrik, privasi, 403, empty) |
| `tests/Feature/JaringanSayaNavTest.php` (create) | Test visibilitas menu sidebar |

**Fakta kode (terverifikasi):**
- `User::descendants` belum ada. `User::downlines()` = `hasMany(User,'upline_id')`. `User::upline()` ada. `User::isPartner()` = `in_array(role, PARTNER_ROLES)`. `User::STATUS_ACTIVE` ada. Kolom: `fullname`, `member_id`, `region`, `upline_id`, `status`, `role`.
- `PartnerHierarchy::label(role)`, `::levelOf(role)` ada (grand=1, distributor=2, bronze/gold=3).
- `PartnerSale` fillable: `sale_number, user_id, customer_name, total_amount, notes, sold_at, created_by`; cast `sold_at`→date, `total_amount`→decimal:2.
- Route group partner = `Route::middleware(['auth','role'])->group(...)`; `partner-sales.index` ada di sana tanpa `permission:` (gate isPartner di controller). Taruh route baru sejajar (setelah baris `partner-sales.store`, ~baris 129–130).
- Sidebar helper: `{!! navItem('route.name','Label','active-pattern') !!}`. Blok partner ada di `layouts/app.blade.php` sekitar baris 84–91 (Dashboard, Riwayat PO). Variabel user = `$u`.

---

## Task 1: `PartnerHierarchyService::descendants()`

**Files:**
- Modify: `app/Services/PartnerHierarchyService.php`
- Test: `tests/Feature/PartnerDescendantsTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (kolom `upline_id`), `Illuminate\Support\Collection`.
- Produces: `PartnerHierarchyService::descendants(User $root): Collection` — `Collection<User>` seluruh turunan (semua level) di bawah `$root`, **tidak** termasuk `$root`. Aman dari loop (batas kedalaman 20).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/PartnerDescendantsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PartnerHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PartnerDescendantsTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $name, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $name, 'fullname' => strtoupper($name), 'username' => $name,
            'email' => "{$name}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function service(): PartnerHierarchyService
    {
        return app(PartnerHierarchyService::class);
    }

    public function test_descendants_kembalikan_seluruh_subtree_multi_level(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('dist', User::ROLE_DISTRIBUTOR, $grand->id);
        $bronze = $this->mk('bronze', User::ROLE_RESELLER_BRONZE, $dist->id);
        $gold = $this->mk('gold', User::ROLE_RESELLER_GOLD, $dist->id);

        $ids = $this->service()->descendants($grand)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$dist->id, $bronze->id, $gold->id], $ids);
        $this->assertNotContains($grand->id, $ids);
    }

    public function test_descendants_kosong_untuk_daun(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('dist', User::ROLE_DISTRIBUTOR, $grand->id);

        $this->assertTrue($this->service()->descendants($dist)->isEmpty());
    }

    public function test_descendants_terisolasi_antar_grand(): void
    {
        $grandA = $this->mk('ga', User::ROLE_GRAND_DISTRIBUTOR);
        $grandB = $this->mk('gb', User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->mk('da', User::ROLE_DISTRIBUTOR, $grandA->id);
        $distB = $this->mk('db', User::ROLE_DISTRIBUTOR, $grandB->id);

        $idsA = $this->service()->descendants($grandA)->pluck('id')->all();

        $this->assertContains($distA->id, $idsA);
        $this->assertNotContains($distB->id, $idsA);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=PartnerDescendantsTest`
Expected: FAIL — `Call to undefined method App\Services\PartnerHierarchyService::descendants()`.

- [ ] **Step 3: Implementasi minimal**

Di `app/Services/PartnerHierarchyService.php`, tambahkan method (setelah `hasActiveDownline()`):

```php
    /** Semua turunan (semua level) di bawah $root, BFS per tingkat, aman-loop. Tidak termasuk $root. */
    public function descendants(User $root): Collection
    {
        $all = collect();
        $frontierIds = collect([$root->id]);
        $depthGuard = 0;

        while ($frontierIds->isNotEmpty() && $depthGuard++ < 20) {
            $children = User::whereIn('upline_id', $frontierIds->all())
                ->orderBy('fullname')
                ->get();
            if ($children->isEmpty()) {
                break;
            }
            $all = $all->concat($children);
            $frontierIds = $children->pluck('id');
        }

        return $all;
    }
```

(`use Illuminate\Support\Collection;` sudah ada di file.)

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=PartnerDescendantsTest`
Expected: PASS (3 test).

- [ ] **Step 5: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/PartnerHierarchyService.php tests/Feature/PartnerDescendantsTest.php
git commit -m "feat(mlm): PartnerHierarchyService::descendants() untuk subtree" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Halaman "Jaringan Saya" (route + controller + view)

**Files:**
- Create: `app/Http/Controllers/JaringanSayaController.php`
- Modify: `routes/web.php` (dalam grup `['auth','role']`, setelah route `partner-sales.store`)
- Create: `resources/views/jaringan_saya/index.blade.php`
- Create: `resources/views/jaringan_saya/_node.blade.php`
- Test: `tests/Feature/JaringanSayaTest.php`

**Interfaces:**
- Consumes: `PartnerHierarchyService::descendants(User): Collection` (Task 1); `PartnerSale` (kolom `user_id,total_amount,sold_at`); `PartnerHierarchy::label/levelOf`; `User::isPartner/STATUS_ACTIVE`.
- Produces: route bernama `jaringan-saya.index` (GET `/jaringan-saya`); view menerima `$tree` (array node nested), `$totalMembers` (int), `$activeCount` (int), `$networkOmzet` (float), `$periode` (string), `$trenLabels` (array<string> 3 label bulan). Tiap node array: `id,name,member_id,tier,level,region,nonaktif,omzet,trx,tren(array<float>[3]),tren_arah('naik'|'turun'|'datar'),aktif(bool),downline_count,children(array)`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/JaringanSayaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PartnerSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JaringanSayaTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function mk(string $name, string $role, ?int $upline = null, ?string $region = null): User
    {
        return User::create([
            'name' => $name, 'fullname' => strtoupper($name), 'username' => $name,
            'email' => "{$name}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline, 'region' => $region,
        ]);
    }

    private function sale(User $u, string $soldAt, int $amount, string $customer = 'CUSTOMER RAHASIA'): void
    {
        PartnerSale::create([
            'sale_number' => 'PS-'.(++$this->seq),
            'user_id' => $u->id, 'customer_name' => $customer,
            'total_amount' => $amount, 'sold_at' => $soldAt, 'created_by' => $u->id,
        ]);
    }

    public function test_grand_melihat_seluruh_subtree(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->mk('bronzie', User::ROLE_RESELLER_BRONZE, $dist->id);

        $this->actingAs($grand)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('DISTRI')->assertSee('BRONZIE');
    }

    public function test_distributor_tak_lihat_jaringan_lain(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->mk('dista', User::ROLE_DISTRIBUTOR, $grand->id);
        $distB = $this->mk('distb', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->mk('resela', User::ROLE_RESELLER_BRONZE, $distA->id);
        $this->mk('reselb', User::ROLE_RESELLER_BRONZE, $distB->id);

        $this->actingAs($distA)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('RESELA')
            ->assertDontSee('RESELB')->assertDontSee('DISTB');
    }

    public function test_metrik_omzet_dan_transaksi_bulan_ini(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-08-05', 170_000);
        $this->sale($dist, '2026-08-08', 30_000);
        $this->sale($dist, '2026-06-01', 999_000); // bulan lain

        $this->actingAs($grand)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('200.000')->assertSee('2 transaksi');
    }

    public function test_status_aktif_dan_pasif(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $aktif = $this->mk('rajin', User::ROLE_DISTRIBUTOR, $grand->id);
        $pasif = $this->mk('malas', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($aktif, '2026-08-06', 50_000);   // 5 hari lalu → aktif
        $this->sale($pasif, '2026-06-20', 50_000);   // >30 hari → pasif

        $res = $this->actingAs($grand)->get(route('jaringan-saya.index'))->assertOk();
        $res->assertSee('Aktif');
        $res->assertSee('Pasif');
    }

    public function test_tren_3_bulan_tampil(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-06-10', 11_000);
        $this->sale($dist, '2026-07-10', 22_000);
        $this->sale($dist, '2026-08-10', 33_000);

        $this->actingAs($grand)->get(route('jaringan-saya.index'))->assertOk()
            ->assertSee('Jun')->assertSee('Jul')->assertSee('Agu')
            ->assertSee('11.000')->assertSee('22.000')->assertSee('33.000');
    }

    public function test_reseller_tanpa_downline_lihat_empty_state(): void
    {
        $reseller = $this->mk('sendiri', User::ROLE_RESELLER_BRONZE);

        $this->actingAs($reseller)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('belum punya jaringan');
    }

    public function test_non_partner_dilarang(): void
    {
        $admin = $this->mk('admin', User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('jaringan-saya.index'))->assertForbidden();
    }

    public function test_nama_customer_downline_tidak_bocor(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-08-05', 50_000, 'BUDI SANGAT RAHASIA');

        $this->actingAs($grand)->get(route('jaringan-saya.index'))
            ->assertOk()->assertDontSee('BUDI SANGAT RAHASIA');
    }

    public function test_rollup_omzet_jaringan_benar(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $resel = $this->mk('resel', User::ROLE_RESELLER_BRONZE, $dist->id);
        $this->sale($dist, '2026-08-05', 100_000);
        $this->sale($resel, '2026-08-06', 25_000);

        $this->actingAs($grand)->get(route('jaringan-saya.index'))->assertOk()
            ->assertSee('125.000');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=JaringanSayaTest`
Expected: FAIL — route `jaringan-saya.index` belum terdefinisi (`Route [jaringan-saya.index] not defined`).

- [ ] **Step 3: Buat controller**

Buat `app/Http/Controllers/JaringanSayaController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\PartnerSale;
use App\Models\User;
use App\Services\PartnerHierarchyService;
use App\Support\PartnerHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Jaringan Saya" — mitra upline memantau ringkasan performa subtree-nya.
 * Read-only, agregat; TANPA nama/kontak customer downline (privasi antar-mitra).
 * HQ tak memakai halaman ini (punya god-view di Struktur Jaringan + laporan).
 */
class JaringanSayaController extends Controller
{
    private const BULAN_PENDEK = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];

    private const BULAN_PANJANG = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

    public function __construct(private PartnerHierarchyService $hierarchy) {}

    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->isPartner(), 403, 'Hanya mitra yang memiliki halaman jaringan.');

        $members = $this->hierarchy->descendants($me); // Collection<User>, tanpa $me
        $ids = $members->pluck('id')->all();

        // Jendela 3 bulan (bulan ini + 2 sebelumnya). Menutup juga cek aktif-30-hari.
        $today = Carbon::today();
        $windowStart = $today->copy()->startOfMonth()->subMonthsNoOverflow(2);
        $activeSince = $today->copy()->subDays(30);
        $thisMonthKey = $today->format('Y-m');
        $monthKeys = [
            $today->copy()->subMonthsNoOverflow(2)->format('Y-m'),
            $today->copy()->subMonthsNoOverflow(1)->format('Y-m'),
            $thisMonthKey,
        ];

        // Satu query untuk seluruh subtree. Hanya kolom agregat — TIDAK menyeleksi
        // customer_name / notes (privasi). Agregasi di PHP (portabel SQLite/MySQL).
        $metrics = [];
        if ($ids) {
            $rows = PartnerSale::query()
                ->whereIn('user_id', $ids)
                ->where('sold_at', '>=', $windowStart->toDateString())
                ->get(['user_id', 'total_amount', 'sold_at']);

            foreach ($rows as $row) {
                $uid = (int) $row->user_id;
                $key = $row->sold_at->format('Y-m');
                $amt = (float) $row->total_amount;

                if (! isset($metrics[$uid])) {
                    $metrics[$uid] = ['omzet' => 0.0, 'trx' => 0, 'tren' => array_fill_keys($monthKeys, 0.0), 'aktif' => false];
                }
                if (array_key_exists($key, $metrics[$uid]['tren'])) {
                    $metrics[$uid]['tren'][$key] += $amt;
                }
                if ($key === $thisMonthKey) {
                    $metrics[$uid]['omzet'] += $amt;
                    $metrics[$uid]['trx']++;
                }
                if ($row->sold_at->gte($activeSince)) {
                    $metrics[$uid]['aktif'] = true;
                }
            }
        }

        // Jumlah downline langsung per anggota (dari koleksi, bukan query baru).
        $childCount = $members->groupBy('upline_id')->map->count();
        $childrenOf = $members->groupBy('upline_id');

        // View-model per node (tanpa children).
        $nodeOf = function (User $u) use ($metrics, $childCount, $monthKeys) {
            $m = $metrics[$u->id] ?? ['omzet' => 0.0, 'trx' => 0, 'tren' => array_fill_keys($monthKeys, 0.0), 'aktif' => false];
            $tren = array_values($m['tren']);
            $arah = $tren[2] > $tren[1] ? 'naik' : ($tren[2] < $tren[1] ? 'turun' : 'datar');

            return [
                'id' => $u->id,
                'name' => $u->fullname ?: $u->name,
                'member_id' => $u->member_id,
                'tier' => PartnerHierarchy::label($u->role),
                'level' => PartnerHierarchy::levelOf($u->role) ?? 9,
                'region' => $u->region,
                'nonaktif' => $u->status !== User::STATUS_ACTIVE,
                'omzet' => $m['omzet'],
                'trx' => $m['trx'],
                'tren' => $tren,
                'tren_arah' => $arah,
                'aktif' => $m['aktif'],
                'downline_count' => $childCount[$u->id] ?? 0,
            ];
        };

        // Bangun pohon nested rekursif mulai dari anak langsung $me.
        $build = function ($parentId) use (&$build, $childrenOf, $nodeOf) {
            return $childrenOf->get($parentId, collect())
                ->sortBy(fn (User $u) => sprintf('%d-%s', PartnerHierarchy::levelOf($u->role) ?? 9, $u->fullname))
                ->map(fn (User $u) => $nodeOf($u) + ['children' => $build($u->id)])
                ->values()
                ->all();
        };
        $tree = $build($me->id);

        // Roll-up jaringan.
        $activeCount = 0;
        $networkOmzet = 0.0;
        foreach ($members as $u) {
            $networkOmzet += $metrics[$u->id]['omzet'] ?? 0.0;
            if ($metrics[$u->id]['aktif'] ?? false) {
                $activeCount++;
            }
        }

        return view('jaringan_saya.index', [
            'tree' => $tree,
            'totalMembers' => $members->count(),
            'activeCount' => $activeCount,
            'networkOmzet' => $networkOmzet,
            'periode' => self::BULAN_PANJANG[(int) $today->format('n')].' '.$today->format('Y'),
            'trenLabels' => array_map(fn ($k) => self::BULAN_PENDEK[(int) substr($k, 5, 2)], $monthKeys),
        ]);
    }
}
```

- [ ] **Step 4: Daftarkan route**

Di `routes/web.php`, di dalam grup `Route::middleware(['auth','role'])->group(...)`, tepat setelah baris `Route::post('/inventory/sales', ...)->name('partner-sales.store');` (~baris 129), tambahkan:

```php
    // "Jaringan Saya" — mitra upline pantau subtree (read-only). Gate isPartner di controller.
    Route::get('/jaringan-saya', [\App\Http\Controllers\JaringanSayaController::class, 'index'])->name('jaringan-saya.index');
```

- [ ] **Step 5: Buat view utama**

Buat `resources/views/jaringan_saya/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Jaringan Saya')
@section('heading', 'Jaringan Saya')

@section('content')
<div class="space-y-4">
    {{-- Ringkasan jaringan --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="bg-white rounded-xl border border-stone-200 p-3">
            <div class="text-[11px] text-stone-500">Total anggota jaringan</div>
            <div class="text-xl font-bold text-stone-800">{{ $totalMembers }}</div>
        </div>
        <div class="bg-white rounded-xl border border-stone-200 p-3">
            <div class="text-[11px] text-stone-500">Aktif (≤30 hari)</div>
            <div class="text-xl font-bold text-emerald-600">{{ $activeCount }}</div>
        </div>
        <div class="bg-white rounded-xl border border-stone-200 p-3">
            <div class="text-[11px] text-stone-500">Omzet jaringan · {{ $periode }}</div>
            <div class="text-xl font-bold text-stone-800">Rp {{ number_format($networkOmzet, 0, ',', '.') }}</div>
        </div>
    </div>

    <p class="text-xs text-stone-500">💡 Ringkasan performa jaringan bawahanmu (read-only). Nama customer downline sengaja tidak ditampilkan demi privasi.</p>

    {{-- Pohon jaringan --}}
    <div class="bg-white rounded-xl border border-stone-200 divide-y divide-stone-100">
        @forelse($tree as $node)
            @include('jaringan_saya._node', ['node' => $node, 'depth' => 0, 'trenLabels' => $trenLabels])
        @empty
            <div class="p-8 text-center text-sm text-stone-400">Kamu belum punya jaringan. Anggota yang ditempatkan sebagai downline-mu akan muncul di sini.</div>
        @endforelse
    </div>
</div>
@endsection
```

- [ ] **Step 6: Buat partial node rekursif**

Buat `resources/views/jaringan_saya/_node.blade.php`:

```blade
@php
    $badge = $node['aktif']
        ? ['Aktif', 'bg-emerald-100 text-emerald-700']
        : ($node['nonaktif'] ? ['Nonaktif', 'bg-stone-200 text-stone-500'] : ['Pasif', 'bg-amber-100 text-amber-700']);
    $arrow = ['naik' => '↑', 'turun' => '↓', 'datar' => '→'][$node['tren_arah']];
    $arrowColor = ['naik' => 'text-emerald-600', 'turun' => 'text-rose-600', 'datar' => 'text-stone-400'][$node['tren_arah']];
@endphp
<div class="p-3" style="padding-left: {{ 0.75 + $depth * 1.5 }}rem">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-[10rem]">
            <div class="flex items-center gap-2">
                @if($depth > 0)<span class="text-stone-300">└</span>@endif
                <span class="font-semibold text-sm text-stone-800">{{ $node['name'] }}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-stone-100 text-stone-600">{{ $node['tier'] }}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded {{ $badge[1] }}">{{ $badge[0] }}</span>
            </div>
            <div class="text-[11px] text-stone-400 mt-0.5">
                {{ $node['member_id'] ?? '—' }}@if($node['region']) · {{ $node['region'] }}@endif · {{ $node['downline_count'] }} downline
            </div>
        </div>
        <div class="flex items-center gap-4 text-right">
            <div>
                <div class="text-[10px] text-stone-400">Omzet bln ini</div>
                <div class="text-sm font-bold text-stone-800">Rp {{ number_format($node['omzet'], 0, ',', '.') }}</div>
                <div class="text-[10px] text-stone-400">{{ $node['trx'] }} transaksi</div>
            </div>
            <div class="hidden sm:block">
                <div class="text-[10px] text-stone-400">Tren 3 bln <span class="{{ $arrowColor }}">{{ $arrow }}</span></div>
                <div class="flex gap-2 text-[11px] text-stone-600">
                    @foreach($node['tren'] as $i => $v)
                        <span>{{ $trenLabels[$i] }}: {{ number_format($v, 0, ',', '.') }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@foreach($node['children'] as $child)
    @include('jaringan_saya._node', ['node' => $child, 'depth' => $depth + 1, 'trenLabels' => $trenLabels])
@endforeach
```

- [ ] **Step 7: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=JaringanSayaTest`
Expected: PASS (9 test).

- [ ] **Step 8: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/JaringanSayaController.php routes/web.php resources/views/jaringan_saya/ tests/Feature/JaringanSayaTest.php
git commit -m "feat(mlm): halaman Jaringan Saya (ringkasan subtree, read-only, tanpa PII customer)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

> **Catatan opsional (di luar MVP, JANGAN dikerjakan kecuali diminta):** "produk terlaris per downline" sengaja tidak diimplementasi (spec §5.4 menandainya opsional) untuk menjaga MVP ramping. Bila nanti diminta, agregasi `PartnerSaleItem.qty` per `product_id` lewat join `items` — tambah kolom di node view-model + baris di partial.

---

## Task 3: Nav item "Jaringan Saya" di sidebar

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (blok partner ~baris 84–91)
- Test: `tests/Feature/JaringanSayaNavTest.php`

**Interfaces:**
- Consumes: route `jaringan-saya.index` (Task 2); `$u->isPartner()`, `$u->downlines()` (existing).
- Produces: menu sidebar "Jaringan Saya" tampil hanya bila `$u->isPartner() && $u->downlines()->exists()`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/JaringanSayaNavTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JaringanSayaNavTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $name, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $name, 'fullname' => strtoupper($name), 'username' => $name,
            'email' => "{$name}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    public function test_menu_muncul_untuk_mitra_dengan_downline(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);

        $this->actingAs($grand)->get(route('dashboard'))->assertOk()->assertSee('Jaringan Saya');
    }

    public function test_menu_tak_muncul_untuk_mitra_tanpa_downline(): void
    {
        $reseller = $this->mk('solo', User::ROLE_RESELLER_BRONZE);

        $this->actingAs($reseller)->get(route('dashboard'))->assertOk()->assertDontSee('Jaringan Saya');
    }

    public function test_menu_tak_muncul_untuk_non_partner(): void
    {
        $admin = $this->mk('admin', User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('Jaringan Saya');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=JaringanSayaNavTest`
Expected: FAIL pada `test_menu_muncul_untuk_mitra_dengan_downline` (assertSee 'Jaringan Saya' tidak ketemu).

- [ ] **Step 3: Tambah nav item**

Di `resources/views/layouts/app.blade.php`, di blok partner (setelah baris `{!! navItem('purchase-orders.index', ...) !!}` sekitar baris 91, sebelum blok `@if($u->canDo(...))` berikutnya), tambahkan:

```blade
            @if($u->isPartner() && $u->downlines()->exists())
                {!! navItem('jaringan-saya.index', 'Jaringan Saya', 'jaringan-saya.index') !!}
            @endif
```

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=JaringanSayaNavTest`
Expected: PASS (3 test).

- [ ] **Step 5: Jalankan seluruh suite (regresi)**

Run: `C:\php83\php.exe artisan test`
Expected: PASS semua (termasuk ~680 test existing + test baru). Bila ada yang merah, perbaiki sebelum commit.

- [ ] **Step 6: Commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add resources/views/layouts/app.blade.php tests/Feature/JaringanSayaNavTest.php
git commit -m "feat(mlm): nav Jaringan Saya untuk mitra yang punya downline" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian

Setelah 3 task selesai & seluruh suite hijau:
- **REQUIRED SUB-SKILL:** Gunakan superpowers:finishing-a-development-branch untuk verifikasi tes, pilih opsi integrasi (merge ke main / PR), lalu deploy.
- **Deploy prod:** `git pull origin main && /opt/alt/php83/usr/bin/php artisan optimize:clear` (tanpa migrasi) + hard-refresh browser.

---

## Self-Review (penulis rencana)

**1. Cakupan spec:**
- §5.1 `descendants()` → Task 1 ✅
- §5.2 controller (gate, metrik, pohon, roll-up) → Task 2 Step 3 ✅
- §5.3 route → Task 2 Step 4 ✅
- §5.4 view + partial rekursif (ringkasan kaya, tanpa customer) → Task 2 Step 5–6 ✅
- §5.5 nav (gate isPartner && downline) → Task 3 ✅
- §6 metrik dari PartnerSale, agregasi PHP portable → Task 2 Step 3 ✅
- §4 privasi (customer_name tak diseleksi/tampil) → controller `get([...])` tanpa customer_name + test `test_nama_customer_downline_tidak_bocor` ✅
- §7 edge cases (empty state, 403, nonaktif) → test empty + 403; badge Nonaktif di partial ✅
- §8 rencana uji → Task 1/2/3 test ✅

**2. Placeholder scan:** Tak ada TBD/TODO; semua langkah berisi kode nyata. "Produk terlaris" ditandai eksplisit sebagai opsional-di-luar-MVP dengan arahan konkret, bukan placeholder.

**3. Konsistensi tipe:** `descendants(User): Collection` dipakai konsisten di Task 2. Kunci node array (`omzet,trx,tren,tren_arah,aktif,downline_count,children,nonaktif,tier,level,region,member_id,name,id`) sama antara controller `$nodeOf` dan `_node.blade.php`. `$trenLabels` diteruskan ke tiap `@include`. Route name `jaringan-saya.index` konsisten controller/route/test/nav.
