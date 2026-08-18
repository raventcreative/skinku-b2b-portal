# Komisi Fase 3 — Laporan Komisi HQ + Atur Rate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Beri HQ laporan komisi (ringkasan + per-mitra + drill-down, filter periode) dan UI mengatur rate komisi di Pengaturan; verifikasi aturan tingkat MLM.

**Architecture:** Baca-saja di atas data komisi yang sudah ada (Fase 1 engine + Fase 2 withdraw). Aggregasi baru di `CommissionService` (satu sumber kebenaran rate + metode laporan), halaman laporan mengikuti pola `ReportController::omzetMitra` (controller → service → Blade, filter `bulan`), UI rate mengikuti pola `SettingController::saveAi`. TANPA migrasi, TANPA skema baru — commissions tetap append-only (laporan hanya membaca).

**Tech Stack:** Laravel 13, PHP 8.3, Blade + vanilla JS + Eloquent. Zero-dependency.

## Global Constraints

- **Zero-dependency:** tak ada paket composer/npm baru. Blade + vanilla JS + Eloquent saja.
- **Runner tes:** `C:\php83\php.exe artisan test` (filter: `--filter=NamaTest`). Pint sebelum commit: `C:\php83\php.exe vendor/bin/pint --dirty`.
- **TANPA migrasi.** Fase 3 tidak menambah tabel/kolom. Kalau sebuah task terasa butuh migrasi, itu sinyal salah — berhenti & tanya.
- **commissions APPEND-ONLY.** Kode laporan hanya `SELECT`/agregasi; JANGAN pernah `update`/`insert`/`delete` baris `commissions`. `status` komisi selalu `'saldo'` (tak pernah diflip).
- **availableBalance identitas:** `saldo = Σ commissions(status=saldo).amount`; `ditarik = Σ withdrawals(status != 'ditolak').amount`; `tersedia = saldo − ditarik`. Cocokkan persis dengan `CommissionService::availableBalance` yang sudah ada. Identitas laporan: `saldo = tersedia + tertahan + cair` (tertahan = withdrawals status∈{diajukan,disetujui}; cair = status='cair').
- **Rate validasi 0–100 (2 desimal).** Menutup residual overflow `decimal(5,2)` (rate absurd bisa me-rollback PO saat komisi dihitung). `['required','numeric','min:0','max:100']`.
- **Satu sumber rate:** `CommissionService::RATE_DEFAULTS` (key setting penuh → default) dipakai engine DAN UI. Key verbatim: `komisi_persen_grand_distributor`, `komisi_persen_distributor`, `komisi_persen_reseller_bronze`, `komisi_persen_reseller_gold`, `komisi_persen_reseller` (legacy), `komisi_persen_join`.
- **Reseller sinkron:** UI menampilkan SATU input "Reseller" tapi menyimpan ke KETIGA key reseller (`_bronze`, `_gold`, legacy) supaya tak ada fallback diam-diam ke default hardcoded.
- **Gating:** izin baru `view_commission_report` default `[User::ROLE_ADMIN]` (data payout = sensitif, admin-only; super_admin implisit). Route laporan digate `permission:view_commission_report`. Pengaturan tetap `permission:system_settings`.
- **Periode:** query param `bulan` format `YYYY-MM`, sentinel `'all'` = semua periode. Pakai ulang `ReportController::parseMonth()` (private, sudah ada) + markup filter dari `resources/views/reports/omzet_mitra.blade.php`. `commissions` TIDAK punya `order_date` → filter pakai `created_at` (bukan `COALESCE(order_date, ...)`).
- **Blade aman:** JANGAN `@json([...])` literal array (500 di codebase ini — pakai `json_encode` bila perlu). Semua output di-escape `{{ }}`. Jangan interpolasi string dinamis ke dalam JS inline `confirm(...)`.

---

## Task 1: CommissionService — sumber rate tunggal + metode agregasi laporan

**Files:**
- Modify: `app/Services/CommissionService.php`
- Test: `tests/Feature/CommissionReportTest.php` (Create)

**Interfaces:**
- Consumes: `Commission` (status `'saldo'`, kolom `user_id, amount, created_at, type, level, rate, source_user_id, source_po_id`), `Withdrawal` (status `diajukan|disetujui|ditolak|cair`, `user_id, amount`), `User` (`PARTNER_ROLES`, `role`, `name`), `App\Support\PartnerHierarchy::label(string $role)`, `AppSetting::float()`.
- Produces (dipakai Task 2 & 3):
  - `public const RATE_DEFAULTS` — map key-setting → float default.
  - `public function reportSummary(?\Carbon\Carbon $month = null): array` → `['komisi_periode'=>float,'total_saldo'=>float,'total_tersedia'=>float,'total_tertahan'=>float,'total_cair'=>float,'jumlah_mitra'=>int]`.
  - `public function reportPerMitra(?\Carbon\Carbon $month = null): array` → list of `['user'=>User,'tier'=>string,'komisi'=>float,'transaksi'=>int,'saldo'=>float,'tertahan'=>float,'tersedia'=>float]`, hanya mitra yang pernah dapat komisi, urut `user.name`.
  - `public function mitraCommissions(User $mitra, ?\Carbon\Carbon $month = null): \Illuminate\Support\Collection` — baris `Commission` (eager `downline`,`sourcePo`) mitra itu dalam periode, urut `created_at` desc.

- [ ] **Step 1: Ganti DEFAULT_RATES → RATE_DEFAULTS (satu sumber) + rapikan overrideRate/join**

Di `app/Services/CommissionService.php`, ganti konstanta lama `DEFAULT_RATES` (yang di-key oleh role) menjadi `RATE_DEFAULTS` (di-key oleh key-setting penuh), lalu arahkan `overrideRate()` dan pembacaan join ke sana. Perilaku (key + default) TIDAK berubah — hanya dirapikan jadi satu sumber.

```php
/** Rate komisi (persen) per key AppSetting → default. SATU sumber utk engine + UI Pengaturan. */
public const RATE_DEFAULTS = [
    'komisi_persen_grand_distributor' => 6.0,
    'komisi_persen_distributor' => 4.0,
    'komisi_persen_reseller_bronze' => 2.0,
    'komisi_persen_reseller_gold' => 2.0,
    'komisi_persen_reseller' => 2.0, // legacy generic reseller (masih assignable)
    'komisi_persen_join' => 10.0,
];
```

`overrideRate()` jadi:
```php
private function overrideRate(string $role): float
{
    $key = 'komisi_persen_'.$role;
    return AppSetting::float($key, self::RATE_DEFAULTS[$key] ?? 0.0);
}
```

Pembacaan join (di `recordForCompletedPo`, saat ini `AppSetting::float('komisi_persen_join', 10.0)`) jadi:
```php
AppSetting::float('komisi_persen_join', self::RATE_DEFAULTS['komisi_persen_join'])
```

- [ ] **Step 2: Jalankan tes komisi Fase 1 (regresi engine) — harus tetap hijau**

Run: `C:\php83\php.exe artisan test --filter=Commission`
Expected: PASS semua (rate/join/override tak berubah). Kalau merah → refactor Step 1 salah; perbaiki sebelum lanjut.

- [ ] **Step 3: Tulis tes agregasi laporan (gagal dulu)**

Create `tests/Feature/CommissionReportTest.php`. Pakai `RefreshDatabase`. Seed manual baris `Commission` (append-only, `status='saldo'`) + `Withdrawal` + `User` mitra, lalu assert angka. Contoh helper + tes:

```php
<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CommissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionReportTest extends TestCase
{
    use RefreshDatabase;

    private function mitra(string $name, string $role = User::ROLE_DISTRIBUTOR): User
    {
        return User::create([
            'name' => $name, 'email' => $name.'@t.test', 'password' => bcrypt('x'),
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function komisi(User $u, float $amount, string $when): void
    {
        Commission::create([
            'user_id' => $u->id, 'source_po_id' => null, 'source_user_id' => null,
            'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => $amount * 25,
            'amount' => $amount, 'status' => 'saldo', 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    public function test_summary_hitung_saldo_tertahan_cair(): void
    {
        $a = $this->mitra('A');
        $this->komisi($a, 300_000, '2026-08-10 09:00:00');
        $this->komisi($a, 200_000, '2026-07-05 09:00:00'); // bulan lain
        Withdrawal::create(['user_id' => $a->id, 'amount' => 100_000, 'status' => 'diajukan']);
        Withdrawal::create(['user_id' => $a->id, 'amount' => 50_000, 'status' => 'cair']);
        Withdrawal::create(['user_id' => $a->id, 'amount' => 99_000, 'status' => 'ditolak']); // tak dihitung

        $svc = app(CommissionService::class);
        $all = $svc->reportSummary(null);
        $this->assertEqualsWithDelta(500_000, $all['total_saldo'], 0.01);
        $this->assertEqualsWithDelta(150_000, $all['total_tertahan'] + $all['total_cair'], 0.01); // 100k tertahan + 50k cair
        $this->assertEqualsWithDelta(100_000, $all['total_tertahan'], 0.01);
        $this->assertEqualsWithDelta(50_000, $all['total_cair'], 0.01);
        // identitas: saldo = tersedia + tertahan + cair; ditarik(non-ditolak)=150k → tersedia 350k
        $this->assertEqualsWithDelta(350_000, $all['total_tersedia'], 0.01);
        $this->assertSame(1, $all['jumlah_mitra']);

        $agu = $svc->reportSummary(Carbon::create(2026, 8, 1));
        $this->assertEqualsWithDelta(300_000, $agu['komisi_periode'], 0.01); // cuma yg Agustus
        $this->assertEqualsWithDelta(500_000, $agu['total_saldo'], 0.01);     // saldo tetap all-time
    }

    public function test_per_mitra_hanya_yang_pernah_komisi_dan_kolom_benar(): void
    {
        $a = $this->mitra('Andi', User::ROLE_GRAND_DISTRIBUTOR);
        $b = $this->mitra('Budi'); // tak pernah komisi → tak muncul
        $this->komisi($a, 300_000, '2026-08-10 09:00:00');
        Withdrawal::create(['user_id' => $a->id, 'amount' => 100_000, 'status' => 'disetujui']);

        $rows = app(CommissionService::class)->reportPerMitra(null);
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows[0]['user']->id);
        $this->assertSame('Grand Distributor', $rows[0]['tier']);
        $this->assertEqualsWithDelta(300_000, $rows[0]['saldo'], 0.01);
        $this->assertEqualsWithDelta(100_000, $rows[0]['tertahan'], 0.01);
        $this->assertEqualsWithDelta(200_000, $rows[0]['tersedia'], 0.01);
    }

    public function test_mitra_commissions_ikut_periode(): void
    {
        $a = $this->mitra('Andi');
        $this->komisi($a, 300_000, '2026-08-10 09:00:00');
        $this->komisi($a, 200_000, '2026-07-05 09:00:00');

        $svc = app(CommissionService::class);
        $this->assertCount(2, $svc->mitraCommissions($a, null));
        $this->assertCount(1, $svc->mitraCommissions($a, Carbon::create(2026, 8, 1)));
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=CommissionReportTest`
Expected: FAIL (metode belum ada).

> Catatan: verifikasi nama kolom `is_active` & field wajib `User` dengan melihat factory/seeder atau `UserController::store` sebelum menulis helper `mitra()`; sesuaikan bila skema butuh kolom lain (mis. `username`). Jangan menebak — baca dulu.

- [ ] **Step 4: Implementasi metode agregasi**

Tambah `use App\Models\User; use App\Support\PartnerHierarchy; use Carbon\Carbon;` bila belum ada. Tambah metode:

```php
private function scopeMonth($query, ?Carbon $month, string $col = 'created_at')
{
    if (! $month) {
        return $query;
    }
    return $query->whereBetween($col, [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
}

public function reportSummary(?Carbon $month = null): array
{
    $periodQ = Commission::where('status', 'saldo');
    $this->scopeMonth($periodQ, $month);

    $totalSaldo = (float) Commission::where('status', 'saldo')->sum('amount');
    $totalDitarik = (float) Withdrawal::where('status', '!=', 'ditolak')->sum('amount');

    return [
        'komisi_periode' => (float) $periodQ->sum('amount'),
        'total_saldo' => $totalSaldo,
        'total_tersedia' => $totalSaldo - $totalDitarik,
        'total_tertahan' => (float) Withdrawal::whereIn('status', ['diajukan', 'disetujui'])->sum('amount'),
        'total_cair' => (float) Withdrawal::where('status', 'cair')->sum('amount'),
        'jumlah_mitra' => (int) Commission::where('status', 'saldo')->distinct('user_id')->count('user_id'),
    ];
}

public function reportPerMitra(?Carbon $month = null): array
{
    $saldo = Commission::where('status', 'saldo')
        ->selectRaw('user_id, SUM(amount) as total')->groupBy('user_id')->pluck('total', 'user_id');

    $periodQ = Commission::where('status', 'saldo');
    $this->scopeMonth($periodQ, $month);
    $period = $periodQ->selectRaw('user_id, SUM(amount) as komisi, COUNT(*) as transaksi')
        ->groupBy('user_id')->get()->keyBy('user_id');

    $ditarik = Withdrawal::where('status', '!=', 'ditolak')
        ->selectRaw('user_id, SUM(amount) as total')->groupBy('user_id')->pluck('total', 'user_id');
    $tertahan = Withdrawal::whereIn('status', ['diajukan', 'disetujui'])
        ->selectRaw('user_id, SUM(amount) as total')->groupBy('user_id')->pluck('total', 'user_id');

    $rows = [];
    foreach (User::whereIn('id', $saldo->keys())->orderBy('name')->get() as $u) {
        $s = (float) ($saldo[$u->id] ?? 0);
        $rows[] = [
            'user' => $u,
            'tier' => PartnerHierarchy::label($u->role),
            'komisi' => (float) ($period[$u->id]->komisi ?? 0),
            'transaksi' => (int) ($period[$u->id]->transaksi ?? 0),
            'saldo' => $s,
            'tertahan' => (float) ($tertahan[$u->id] ?? 0),
            'tersedia' => $s - (float) ($ditarik[$u->id] ?? 0),
        ];
    }
    return $rows;
}

public function mitraCommissions(User $mitra, ?Carbon $month = null)
{
    $q = Commission::where('user_id', $mitra->id)->where('status', 'saldo')->with(['downline', 'sourcePo']);
    $this->scopeMonth($q, $month);
    return $q->orderByDesc('created_at')->orderByDesc('id')->get();
}
```

- [ ] **Step 5: Jalankan tes agregasi — hijau**

Run: `C:\php83\php.exe artisan test --filter=CommissionReportTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/CommissionService.php tests/Feature/CommissionReportTest.php
git commit -m "feat(mlm): agregasi laporan komisi + RATE_DEFAULTS satu sumber (Fase 3)"
```

---

## Task 2: Izin + Halaman Laporan Komisi HQ (ringkasan + per-mitra + filter periode)

**Files:**
- Modify: `app/Support/Permissions.php` (izin `view_commission_report`)
- Modify: `app/Http/Controllers/ReportController.php` (inject `CommissionService` + method `komisi`)
- Modify: `routes/web.php` (grup route izin baru)
- Modify: `resources/views/layouts/app.blade.php` (nav item)
- Create: `resources/views/reports/komisi.blade.php`
- Test: `tests/Feature/CommissionReportPageTest.php` (Create)

**Interfaces:**
- Consumes: `CommissionService::reportSummary`, `reportPerMitra` (Task 1); `ReportController::parseMonth` (private, sudah ada); `ReportController::ALL_PERIODS` (`'all'`).
- Produces: route `reports.komisi`; izin `view_commission_report`; view `reports.komisi` (dipakai Task 3 untuk link drill-down `reports.komisi-detail`).

- [ ] **Step 1: Tulis tes halaman (gagal dulu)**

Create `tests/Feature/CommissionReportPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionReportPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Adm', 'email' => 'adm@t.test', 'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function mitra(): User
    {
        return User::create(['name' => 'Mit', 'email' => 'mit@t.test', 'password' => bcrypt('x'), 'role' => User::ROLE_DISTRIBUTOR, 'is_active' => true]);
    }

    public function test_admin_bisa_lihat_laporan_komisi(): void
    {
        $m = $this->mitra();
        Commission::create(['user_id' => $m->id, 'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => 1_000_000, 'amount' => 40_000, 'status' => 'saldo']);

        $this->actingAs($this->admin())->get('/reports/komisi')
            ->assertOk()->assertSee('Laporan Komisi')->assertSee('Mit');
    }

    public function test_mitra_ditolak_403(): void
    {
        $this->actingAs($this->mitra())->get('/reports/komisi')->assertForbidden();
    }

    public function test_filter_bulan_mempersempit(): void
    {
        $m = $this->mitra();
        Commission::create(['user_id' => $m->id, 'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => 1_000_000, 'amount' => 40_000, 'status' => 'saldo', 'created_at' => '2026-07-01 09:00:00', 'updated_at' => '2026-07-01 09:00:00']);

        // Agustus: komisi periode 0, tapi saldo total tetap kelihatan
        $this->actingAs($this->admin())->get('/reports/komisi?bulan=2026-08')
            ->assertOk()->assertSee('Mit');
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=CommissionReportPageTest`
Expected: FAIL (route belum ada → 404).

- [ ] **Step 2: Daftarkan izin `view_commission_report`**

Di `app/Support/Permissions.php`, tambah ke DEFINITIONS (dekat `view_reports`):
```php
'view_commission_report' => 'Lihat Laporan Komisi',
```
Tambah ke DEFAULTS:
```php
'view_commission_report' => [User::ROLE_ADMIN],
```

- [ ] **Step 3: Inject CommissionService + method `komisi` di ReportController**

Ubah konstruktor `ReportController` agar juga menerima `CommissionService`:
```php
public function __construct(private ReportService $reports, private CommissionService $commissions) {}
```
(tambah `use App\Services\CommissionService;`). Tambah method:
```php
public function komisi(Request $request)
{
    $bulan = $this->parseMonth($request->query('bulan'));

    return view('reports.komisi', [
        'summary' => $this->commissions->reportSummary($bulan),
        'rows' => $this->commissions->reportPerMitra($bulan),
        'bulan' => $bulan,
    ]);
}
```

- [ ] **Step 4: Route (grup izin baru)**

Di `routes/web.php`, tambah grup (dekat route reports lain, TAPI izinnya sendiri):
```php
Route::middleware('permission:view_commission_report')->group(function () {
    Route::get('/reports/komisi', [ReportController::class, 'komisi'])->name('reports.komisi');
});
```
(Route drill-down `reports.komisi-detail` ditambah di Task 3, ke grup yang sama.)

- [ ] **Step 5: View `reports/komisi.blade.php`**

Ikuti konvensi `resources/views/reports/omzet_mitra.blade.php` (filter periode identik + kartu + tabel). Helper rupiah + filter `bulan`:

```blade
@extends('layouts.app')
@section('title', 'Laporan Komisi')
@section('heading', 'Laporan Komisi')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<form method="GET" class="flex flex-wrap items-center gap-3 mb-4 text-sm">
    <span class="text-stone-500">Periode</span>
    <input type="month" name="bulan" value="{{ $bulan?->format('Y-m') }}" onchange="this.form.submit()"
        class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
    @if($bulan)
        <a href="{{ route('reports.komisi', ['bulan' => \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
            class="text-xs text-indigo-600 hover:underline">semua periode</a>
    @else
        <span class="text-xs text-stone-400">semua periode — pilih bulan untuk mempersempit</span>
    @endif
</form>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Komisi {{ $bulan ? $bulan->format('M Y') : '(semua)' }}</div>
        <div class="text-lg font-bold text-stone-800 mt-1">{{ $rp($summary['komisi_periode']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Total Saldo</div>
        <div class="text-lg font-bold text-stone-800 mt-1">{{ $rp($summary['total_saldo']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Tersedia</div>
        <div class="text-lg font-bold text-emerald-700 mt-1">{{ $rp($summary['total_tersedia']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Sedang Diproses</div>
        <div class="text-lg font-bold text-amber-700 mt-1">{{ $rp($summary['total_tertahan']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Sudah Cair</div>
        <div class="text-lg font-bold text-stone-800 mt-1">{{ $rp($summary['total_cair']) }}</div>
    </div>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">
        Komisi per Mitra <span class="text-stone-400 font-normal">({{ $summary['jumlah_mitra'] }} mitra)</span>
    </div>
    @if(count($rows))
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-2">Mitra</th>
                    <th class="text-left">Tier</th>
                    <th class="text-right">Komisi (periode)</th>
                    <th class="text-right">Transaksi</th>
                    <th class="text-right">Saldo</th>
                    <th class="text-right">Diproses</th>
                    <th class="text-right px-4">Tersedia</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2">
                            <a href="{{ route('reports.komisi-detail', ['mitra' => $r['user']->id, 'bulan' => $bulan ? $bulan->format('Y-m') : \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
                                class="text-indigo-600 hover:underline font-semibold">{{ $r['user']->name }}</a>
                        </td>
                        <td class="text-stone-500">{{ $r['tier'] }}</td>
                        <td class="text-right text-stone-700">{{ $rp($r['komisi']) }}</td>
                        <td class="text-right text-stone-500">{{ $r['transaksi'] }}</td>
                        <td class="text-right text-stone-700">{{ $rp($r['saldo']) }}</td>
                        <td class="text-right text-amber-700">{{ $rp($r['tertahan']) }}</td>
                        <td class="text-right px-4 font-semibold text-emerald-700">{{ $rp($r['tersedia']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @else
        <p class="px-4 py-8 text-center text-xs text-stone-400">Belum ada komisi tercatat.</p>
    @endif
</div>
@endsection
```

> Task 3 menambah route `reports.komisi-detail`. Link di atas sudah menunjuk ke sana; kalau Task 3 belum ada saat manual-render Task 2, link akan error — itu wajar, Task 3 melengkapinya. Tes Task 2 tidak mengklik link (hanya `assertSee`), jadi aman.

- [ ] **Step 6: Nav item**

Di `resources/views/layouts/app.blade.php`, di blok "Laporan" (dekat `reports.omzet-mitra`), tambah:
```blade
@if($u->canDo('view_commission_report'))
    {!! navItem('reports.komisi', 'Laporan Komisi', 'reports.komisi') !!}
@endif
```

- [ ] **Step 7: Jalankan tes halaman — hijau**

Run: `C:\php83\php.exe artisan test --filter=CommissionReportPageTest`
Expected: PASS (admin 200 + lihat mitra; mitra 403; filter bulan OK).

- [ ] **Step 8: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Support/Permissions.php app/Http/Controllers/ReportController.php routes/web.php resources/views/layouts/app.blade.php resources/views/reports/komisi.blade.php tests/Feature/CommissionReportPageTest.php
git commit -m "feat(mlm): halaman Laporan Komisi HQ + izin view_commission_report (Fase 3)"
```

---

## Task 3: Drill-down komisi per mitra

**Files:**
- Modify: `app/Http/Controllers/ReportController.php` (method `komisiDetail`)
- Modify: `routes/web.php` (route ke grup izin `view_commission_report`)
- Create: `resources/views/reports/komisi_detail.blade.php`
- Test: `tests/Feature/CommissionDetailPageTest.php` (Create)

**Interfaces:**
- Consumes: `CommissionService::mitraCommissions` (Task 1); route `reports.komisi` (Task 2, untuk link "kembali").
- Produces: route `reports.komisi-detail` (`{mitra}` bind `User`).

- [ ] **Step 1: Tes drill-down (gagal dulu)**

Create `tests/Feature/CommissionDetailPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Adm', 'email' => 'adm@t.test', 'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    public function test_admin_lihat_rincian_komisi_mitra(): void
    {
        $up = User::create(['name' => 'Upline', 'email' => 'up@t.test', 'password' => bcrypt('x'), 'role' => User::ROLE_DISTRIBUTOR, 'is_active' => true]);
        $down = User::create(['name' => 'Downline', 'email' => 'dn@t.test', 'password' => bcrypt('x'), 'role' => User::ROLE_RESELLER_BRONZE, 'is_active' => true]);
        Commission::create(['user_id' => $up->id, 'source_user_id' => $down->id, 'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => 1_000_000, 'amount' => 40_000, 'status' => 'saldo']);

        $this->actingAs($this->admin())->get(route('reports.komisi-detail', $up))
            ->assertOk()->assertSee('Upline')->assertSee('Downline')->assertSee('40.000');
    }

    public function test_mitra_ditolak_403(): void
    {
        $x = User::create(['name' => 'X', 'email' => 'x@t.test', 'password' => bcrypt('x'), 'role' => User::ROLE_DISTRIBUTOR, 'is_active' => true]);
        $this->actingAs($x)->get(route('reports.komisi-detail', $x))->assertForbidden();
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=CommissionDetailPageTest`
Expected: FAIL.

- [ ] **Step 2: Method `komisiDetail`**

Tambah `use App\Models\User;` bila belum ada di ReportController. Tambah:
```php
public function komisiDetail(Request $request, User $mitra)
{
    $bulan = $this->parseMonth($request->query('bulan'));
    $rows = $this->commissions->mitraCommissions($mitra, $bulan);

    return view('reports.komisi_detail', [
        'mitra' => $mitra,
        'rows' => $rows,
        'bulan' => $bulan,
        'totalKomisi' => (float) $rows->sum('amount'),
    ]);
}
```

- [ ] **Step 3: Route (grup yang sama)**

Di grup `permission:view_commission_report` (Task 2 Step 4), tambah:
```php
Route::get('/reports/komisi/{mitra}', [ReportController::class, 'komisiDetail'])->name('reports.komisi-detail');
```

- [ ] **Step 4: View `reports/komisi_detail.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'Rincian Komisi')
@section('heading', 'Rincian Komisi — '.$mitra->name)

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="mb-4">
    <a href="{{ route('reports.komisi', ['bulan' => $bulan ? $bulan->format('Y-m') : \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
        class="text-xs text-indigo-600 hover:underline">&larr; Laporan Komisi</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 p-5 mb-4 max-w-md">
    <div class="text-[11px] text-stone-500">Total Komisi {{ $bulan ? $bulan->format('M Y') : '(semua periode)' }}</div>
    <div class="text-2xl font-bold text-stone-800 mt-1">{{ $rp($totalKomisi) }}</div>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    @if(count($rows))
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-2">Tanggal</th>
                    <th class="text-left">Tipe</th>
                    <th class="text-left">Dari Downline</th>
                    <th class="text-left">Level</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Basis</th>
                    <th class="text-right px-4">Komisi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $c)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2 text-stone-600">{{ $c->created_at->format('d M Y') }}</td>
                        <td class="text-stone-600">{{ $c->type === 'join' ? 'Join' : 'Override' }}</td>
                        <td class="text-stone-600">{{ $c->downline?->name ?? '—' }}</td>
                        <td class="text-stone-500">Lv{{ $c->level }}</td>
                        <td class="text-right text-stone-500">{{ rtrim(rtrim(number_format((float) $c->rate, 2, ',', '.'), '0'), ',') }}%</td>
                        <td class="text-right text-stone-500">{{ $rp($c->base_amount) }}</td>
                        <td class="text-right px-4 font-semibold text-stone-800">{{ $rp($c->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @else
        <p class="px-4 py-8 text-center text-xs text-stone-400">Belum ada komisi pada periode ini.</p>
    @endif
</div>
@endsection
```

- [ ] **Step 5: Jalankan tes — hijau**

Run: `C:\php83\php.exe artisan test --filter=CommissionDetailPageTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/ReportController.php routes/web.php resources/views/reports/komisi_detail.blade.php tests/Feature/CommissionDetailPageTest.php
git commit -m "feat(mlm): drill-down rincian komisi per mitra (Fase 3)"
```

---

## Task 4: UI Atur Rate Komisi di Pengaturan

**Files:**
- Modify: `app/Http/Controllers/SettingController.php` (prefill di `index` + method `saveKomisi`)
- Modify: `routes/web.php` (route `settings.komisi.save`)
- Modify: `resources/views/settings/index.blade.php` (kartu "Komisi")
- Test: `tests/Feature/KomisiRateSettingTest.php` (Create)

**Interfaces:**
- Consumes: `AppSetting::put/float`, `CommissionService::RATE_DEFAULTS` (Task 1), `AuditService::log`.
- Produces: route `settings.komisi.save`; rate tersimpan ke 6 key (`komisi_persen_*`) yang dibaca engine.

- [ ] **Step 1: Tes simpan rate (gagal dulu)**

Create `tests/Feature/KomisiRateSettingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KomisiRateSettingTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::create(['name' => 'SA', 'email' => 'sa@t.test', 'password' => bcrypt('x'), 'role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
    }

    public function test_simpan_rate_tulis_semua_key_reseller_sinkron(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'grand' => 7, 'distributor' => 5, 'reseller' => 3, 'join' => 12,
        ])->assertRedirect();

        $this->assertSame('7', AppSetting::get('komisi_persen_grand_distributor'));
        $this->assertSame('5', AppSetting::get('komisi_persen_distributor'));
        $this->assertSame('3', AppSetting::get('komisi_persen_reseller_bronze'));
        $this->assertSame('3', AppSetting::get('komisi_persen_reseller_gold'));
        $this->assertSame('3', AppSetting::get('komisi_persen_reseller'));
        $this->assertSame('12', AppSetting::get('komisi_persen_join'));
    }

    public function test_rate_di_luar_0_100_ditolak(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'grand' => 150, 'distributor' => 5, 'reseller' => 3, 'join' => 12,
        ])->assertSessionHasErrors('grand');

        $this->assertNull(AppSetting::get('komisi_persen_grand_distributor'));
    }
}
```

Run: `C:\php83\php.exe artisan test --filter=KomisiRateSettingTest`
Expected: FAIL (route belum ada).

> Verifikasi role penjaga `permission:system_settings`: default `system_settings` = `[]` (hanya super_admin implisit). Karena itu tes memakai `ROLE_SUPER_ADMIN`. Kalau `PermissionMiddleware` memperlakukan super_admin sebagai bypass (cek `User::canDo`), ini lolos; kalau tidak, sesuaikan (mis. `actingAs` admin + beri izin). Baca `PermissionMiddleware` + `User::canDo` dulu.

- [ ] **Step 2: Method `saveKomisi`**

Di `app/Http/Controllers/SettingController.php`, tambah `use App\Services\CommissionService;` (dan pastikan `use Illuminate\Validation\Rule;`, `AppSetting`, `AuditService` sudah ada). Tambah method:

```php
public function saveKomisi(Request $request): RedirectResponse
{
    $data = $request->validate([
        'grand' => ['required', 'numeric', 'min:0', 'max:100'],
        'distributor' => ['required', 'numeric', 'min:0', 'max:100'],
        'reseller' => ['required', 'numeric', 'min:0', 'max:100'],
        'join' => ['required', 'numeric', 'min:0', 'max:100'],
    ]);

    AppSetting::put('komisi_persen_grand_distributor', (string) $data['grand']);
    AppSetting::put('komisi_persen_distributor', (string) $data['distributor']);
    // satu input "Reseller" → tulis ketiga key reseller supaya bronze/gold/legacy sinkron (tak ada fallback diam-diam)
    AppSetting::put('komisi_persen_reseller_bronze', (string) $data['reseller']);
    AppSetting::put('komisi_persen_reseller_gold', (string) $data['reseller']);
    AppSetting::put('komisi_persen_reseller', (string) $data['reseller']);
    AppSetting::put('komisi_persen_join', (string) $data['join']);

    AuditService::log(action: 'save_komisi_settings', targetType: 'app_setting', after: $data);

    return back()->with('status', 'Rate komisi disimpan.');
}
```

- [ ] **Step 3: Prefill nilai di `index()`**

Di `SettingController::index()`, tambah ke array data view (baca current via AppSetting::float + default dari RATE_DEFAULTS):
```php
'komisiRates' => [
    'grand' => AppSetting::float('komisi_persen_grand_distributor', CommissionService::RATE_DEFAULTS['komisi_persen_grand_distributor']),
    'distributor' => AppSetting::float('komisi_persen_distributor', CommissionService::RATE_DEFAULTS['komisi_persen_distributor']),
    'reseller' => AppSetting::float('komisi_persen_reseller_bronze', CommissionService::RATE_DEFAULTS['komisi_persen_reseller_bronze']),
    'join' => AppSetting::float('komisi_persen_join', CommissionService::RATE_DEFAULTS['komisi_persen_join']),
],
```

- [ ] **Step 4: Route**

Di grup `permission:system_settings` (`routes/web.php`, dekat `settings.ai.save`):
```php
Route::post('/settings/komisi', [SettingController::class, 'saveKomisi'])->name('settings.komisi.save');
```

- [ ] **Step 5: Kartu "Komisi" di view**

Di `resources/views/settings/index.blade.php`, sisipkan kartu (pola sama dgn kartu Asisten AI), mis. setelah kartu AI:

```blade
<div class="bg-white rounded-2xl border border-stone-200 p-6 mt-6">
    <h3 class="text-sm font-bold text-stone-900 mb-1">Rate Komisi</h3>
    <p class="text-xs text-stone-500 mb-4">Persen komisi override per tier (naik-pohon saat repeat order) + bonus join (order pertama). Berlaku untuk komisi ke depan; yang sudah tercatat tak berubah.</p>
    <form method="POST" action="{{ route('settings.komisi.save') }}" class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        @csrf
        <label class="text-[11px] font-semibold text-stone-500">Override Grand (%)
            <input type="number" name="grand" step="0.01" min="0" max="100" value="{{ $komisiRates['grand'] }}"
                class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </label>
        <label class="text-[11px] font-semibold text-stone-500">Override Distributor (%)
            <input type="number" name="distributor" step="0.01" min="0" max="100" value="{{ $komisiRates['distributor'] }}"
                class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </label>
        <label class="text-[11px] font-semibold text-stone-500">Override Reseller (%)
            <input type="number" name="reseller" step="0.01" min="0" max="100" value="{{ $komisiRates['reseller'] }}"
                class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </label>
        <label class="text-[11px] font-semibold text-stone-500">Bonus Join (%)
            <input type="number" name="join" step="0.01" min="0" max="100" value="{{ $komisiRates['join'] }}"
                class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </label>
        <div class="sm:col-span-2 lg:col-span-4">
            <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Rate</button>
        </div>
    </form>
    @error('grand')<p class="text-[11px] text-rose-600 mt-2">{{ $message }}</p>@enderror
    @error('distributor')<p class="text-[11px] text-rose-600 mt-2">{{ $message }}</p>@enderror
    @error('reseller')<p class="text-[11px] text-rose-600 mt-2">{{ $message }}</p>@enderror
    @error('join')<p class="text-[11px] text-rose-600 mt-2">{{ $message }}</p>@enderror
</div>
```

- [ ] **Step 6: Jalankan tes — hijau**

Run: `C:\php83\php.exe artisan test --filter=KomisiRateSettingTest`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/SettingController.php routes/web.php resources/views/settings/index.blade.php tests/Feature/KomisiRateSettingTest.php
git commit -m "feat(mlm): UI atur rate komisi di Pengaturan (reseller sinkron, validasi 0-100) (Fase 3)"
```

---

## Task 5: Verifikasi aturan tingkat (distributor ≠ upline distributor)

**Files:**
- Test: `tests/Feature/PartnerHierarchyServiceTest.php` (Modify — tambah 1 tes)

**Interfaces:**
- Consumes: `PartnerHierarchyService::assignUpline` (sudah menegakkan `allowedParentRoles` di baris ~34-39); helper `mk()` + `$this->svc` yang sudah ada di test file.

**Konteks:** Aturan "Distributor tak boleh punya upline Distributor" SUDAH ditegakkan di `PartnerHierarchyService::assignUpline` (guard `allowedParentRoles`). Tes yang ada hanya menutup skip-level (reseller→grand) dan top-tier; belum ada tes untuk kasus same-level literal. Ini menambah tes itu — TANPA kode produksi.

- [ ] **Step 1: Baca test file & pastikan helper**

Baca `tests/Feature/PartnerHierarchyServiceTest.php`: konfirmasi ada helper `mk(string $name, string $role)` + properti `$this->svc` (dari `test_tolak_level_salah`). Pakai pola yang sama.

- [ ] **Step 2: Tambah tes same-level**

```php
public function test_tolak_distributor_jadi_upline_distributor(): void
{
    $distA = $this->mk('da', 'distributor');
    $distB = $this->mk('db', 'distributor');

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    $this->svc->assignUpline($distB, $distA->id);
}
```

- [ ] **Step 3: Jalankan tes — hijau (menegaskan enforcement sudah ada)**

Run: `C:\php83\php.exe artisan test --filter=PartnerHierarchyServiceTest`
Expected: PASS (guard sudah menolak → exception terlempar). Kalau MERAH (tak ada exception) → enforcement bocor; STOP & lapor (butuh kode produksi, di luar dugaan).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/PartnerHierarchyServiceTest.php
git commit -m "test(mlm): tegaskan distributor tak bisa jadi upline distributor (Fase 3)"
```

---

## Verifikasi akhir (setelah semua task)

- [ ] Full suite hijau: `C:\php83\php.exe artisan test` (target ≥ 778 + tes baru Fase 3, semua PASS).
- [ ] Pint bersih: `C:\php83\php.exe vendor/bin/pint --dirty` (tak ada perubahan tersisa).
- [ ] Manual sanity (opsional, jaringan dorman → banyak kosong): `/reports/komisi` render (empty-state OK), `/settings` kartu Komisi prefilled 6/4/2/10.
- [ ] **Deploy prod: `git pull origin main && /opt/alt/php83/usr/bin/php artisan optimize:clear`** — **TANPA migrate** (Fase 3 nol migrasi).

## Self-Review (diisi saat menulis plan)

**Spec coverage vs `docs/superpowers/specs/2026-08-15-komisi-override-saldo-withdraw-design.md` FASE 3 (baris 50-53):**
- "Laporan Komisi (HQ): komisi per mitra + saldo + antrean withdraw" → Task 2 (ringkasan+per-mitra) + Task 3 (drill-down). Antrean withdraw approve/reject SUDAH ada (Fase 2 `WithdrawalController`), tak diulang. ✅
- "Pengaturan rate (UI edit matriks)" → Task 4. ✅
- "Layar mitra: saldo + riwayat komisi + ajukan withdraw + riwayat withdraw" → SUDAH SELESAI di Fase 2 (`commissions/index.blade.php` punya keempatnya). Tidak ada task — dicatat sebagai selesai. ✅
- "Verifikasi/tegakkan aturan tingkat (distri≠distri)" → Task 5 (sudah ditegakkan; tambah tes). ✅

**Placeholder scan:** tak ada TBD; tiap step punya kode konkret. Angka default 6/4/2/2/2/10 dari RATE_DEFAULTS. Filter `bulan` + markup dari template nyata.

**Type consistency:** `reportPerMitra` mengembalikan array baris dgn key `user/tier/komisi/transaksi/saldo/tertahan/tersedia` — view Task 2 memakai key yang sama. `reportSummary` key `komisi_periode/total_saldo/total_tersedia/total_tertahan/total_cair/jumlah_mitra` — view memakai key sama. `mitraCommissions` → Collection<Commission>, view detail memakai relasi `downline`/kolom `base_amount/amount/rate/level/type/created_at`. Route `reports.komisi-detail` param `{mitra}` konsisten controller `komisiDetail(Request, User $mitra)`.

**Catatan risiko terbawa (bukan blocker Fase 3):** (a) PO backdated makan slot order-pertama (join→override) — hanya relevan saat jaringan hidup; (c) `omzetPerMitra` jual_downline permanen 0 (cleanup Omzet Mitra, di luar scope komisi). Residual (b) overflow rate decimal(5,2) DITUTUP oleh validasi `max:100` Task 4.
