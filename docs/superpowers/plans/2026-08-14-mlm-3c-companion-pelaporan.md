# Companion Pelaporan Model X — Rencana Implementasi

> **Untuk pekerja agentik:** SUB-SKILL WAJIB: `superpowers:subagent-driven-development`. Langkah pakai checkbox `- [ ]`.

**Goal:** Laporan HQ mengecualikan PO antar-mitra (`seller_id != null`), plus halaman baru "Omzet Mitra" buat HQ pantau total jualan tiap mitra.

**Architecture:** Tambah filter query di titik pelaporan HQ + satu method agregasi baru + satu halaman (pola `reports.index`). Zero-dependency, tanpa migrasi. Spec: `docs/superpowers/specs/2026-08-14-mlm-3c-companion-pelaporan-design.md`.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Eloquent. Runner `C:\php83\php.exe artisan test`.

## Global Constraints
- Zero-dependency; tanpa migrasi baru.
- HQ context (viewer null/staff, atau query company-wide) → `seller_id IS NULL`. Partner context (mitra lihat data sendiri) → JANGAN diubah. Operasional daftar/eksport PO → JANGAN diubah.
- Funnel engagement (pernah-PO, aktif-30-hari) TETAP inklusif (keputusan spec A4).
- Pint `--dirty` sebelum tiap commit. Suite existing (740) tetap hijau.
- `seller_id` & `PartnerSale` sudah ada; `PurchaseOrder::STATUS_COMPLETED` = status selesai; kolom nilai `total_amount`; `PartnerSale` kolom: `user_id`, `total_amount`, `sold_at`.

---

## Task 1: Pengecualian HQ di `ReportService`

**Files:**
- Modify: `app/Services/ReportService.php`
- Test: `tests/Feature/HqReportSellerExclusionTest.php` (baru)

**Interfaces:**
- Consumes: `PurchaseOrder.seller_id`, `PurchaseOrderService` (untuk bikin PO di tes).
- Produces: laporan HQ hanya menghitung `seller_id IS NULL`; view mitra tak berubah.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/HqReportSellerExclusionTest.php`

Pakai service layer (pola sama seperti `tests/Feature/InterPartnerFulfillmentTest.php`) supaya PO valid + ada item + revenue.

```php
<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HqReportSellerExclusionTest extends TestCase
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

    private function product(int $hqStock = 1000): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 39000,
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

    private function report(): ReportService
    {
        return app(ReportService::class);
    }

    /** Bikin PO selesai. seller null (HQ) kalau buyer tanpa upline; inter-partner kalau buyer punya upline (stok upline disiapkan). */
    private function completedPo(User $buyer, Product $p, int $qty): void
    {
        if ($buyer->upline_id) {
            $seller = User::find($buyer->upline_id);
            $this->stock($seller, $p, $qty);
        }
        $po = $this->svc()->createForPartner($buyer, [['product_id' => $p->id, 'qty' => $qty]], null, null);
        $this->svc()->complete($po);
    }

    public function test_omzet_hq_kecualikan_po_antar_mitra(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);              // beli dari HQ (seller null)
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);         // beli dari grand (inter-partner)
        $p = $this->product();

        $this->completedPo($grand, $p, 3);  // HQ→grand: omzet HQ
        $this->completedPo($dist, $p, 5);   // grand→dist: BUKAN omzet HQ

        $summary = $this->report()->summary(null); // viewer null = HQ
        // total_sales HQ = hanya PO seller-null (3 unit @ harga grand), bukan yang 5.
        $this->assertGreaterThan(0, $summary['total_sales']);
        // Bukti kunci: nilai = PO HQ saja. Hitung ekspektasi dari PO seller-null di DB.
        $expectedHq = \App\Models\PurchaseOrder::whereNull('seller_id')
            ->where('status', \App\Models\PurchaseOrder::STATUS_COMPLETED)->sum('total_amount');
        $this->assertEqualsWithDelta((float) $expectedHq, (float) $summary['total_sales'], 0.01);
        // dan lebih kecil dari total semua PO (ada inter-partner yang dibuang).
        $allPo = \App\Models\PurchaseOrder::where('status', \App\Models\PurchaseOrder::STATUS_COMPLETED)->sum('total_amount');
        $this->assertLessThan((float) $allPo, (float) $summary['total_sales']);
    }

    public function test_view_mitra_sendiri_tetap_hitung_pembelian_dari_upline(): void
    {
        // Regresi: distributor yang beli dari upline harus TETAP lihat pembeliannya di ringkasannya sendiri.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->completedPo($dist, $p, 5); // inter-partner: dist sebagai pembeli

        $summary = $this->report()->summary($dist); // viewer = mitra pembeli
        $this->assertGreaterThan(0, $summary['total_sales']); // pembeliannya TAK boleh hilang
    }
}
```

- [ ] **Step 2: Jalankan — pastikan GAGAL**

Run: `C:\php83\php.exe artisan test --filter=HqReportSellerExclusionTest`
Expected: `test_omzet_hq_kecualikan_po_antar_mitra` GAGAL (total_sales HQ masih memasukkan inter-partner). Test regresi mitra harus sudah LULUS (jaring pengaman).

- [ ] **Step 3: Ubah `scopePo()` jadi kondisional**

`app/Services/ReportService.php`, method `scopePo` (sekitar :45-52). Bentuk akhir:

```php
private function scopePo($query, ?User $viewer)
{
    if ($viewer && $viewer->isPartner()) {
        $query->where('user_id', $viewer->id);   // view mitra sendiri — TAK diubah
    } else {
        $query->whereNull('seller_id');           // view HQ — hanya penjualan HQ
    }

    return $query;
}
```

- [ ] **Step 4: Tambah filter di 6 method non-scopePo**

Di `app/Services/ReportService.php`, tambah pengecualian `seller_id IS NULL` pada query PO completed di tiap method berikut (baca method, temukan query PO-nya, tambah filter):
- `channelSales()` (~:117-131): pada builder `$po` (Eloquent) → `->whereNull('seller_id')`.
- `grossProfit()` (~:187-194): raw `DB::table(... as po)` → `->whereNull('po.seller_id')`.
- `salesByProduct()` (~:279-288): raw join; sudah ada logika viewer inline (~:283-285). Tambah `->whereNull('po.seller_id')` HANYA untuk cabang non-mitra (kalau `$viewer` mitra, jangan tambah — mirror `scopePo`).
- `partnerSalesDetail()` (~:312-315): Eloquent → `->whereNull('seller_id')`.
- `salesByPartner()` (~:331-334): Eloquent → `->whereNull('seller_id')`.
- `salesByRegion()` (~:349-352): Eloquent join `users` → `->whereNull('purchase_orders.seller_id')` (qualify kolom).

JANGAN sentuh: cabang mitra `scopePo`; `salesByProduct` guard `deleted_at` (celah lama, di luar cakupan).

- [ ] **Step 5: Jalankan — pastikan LULUS**

Run: `C:\php83\php.exe artisan test --filter=HqReportSellerExclusionTest` → semua hijau.

- [ ] **Step 6: Regresi + commit**

Run: `C:\php83\php.exe artisan test` (seluruh suite hijau).
```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/ReportService.php tests/Feature/HqReportSellerExclusionTest.php
git commit -m "feat(mlm): laporan HQ ReportService kecualikan PO antar-mitra (seller_id)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Pengecualian HQ di `OkrBusinessSnapshotService` (+ carve-out funnel)

**Files:**
- Modify: `app/Services/OkrBusinessSnapshotService.php`
- Test: `tests/Feature/OkrSellerExclusionTest.php` (baru)

**Interfaces:**
- Consumes: `PurchaseOrder.seller_id`.
- Produces: omzet distributor + status-PO + piutang tempo = HQ-only; funnel pernah-PO/aktif-30-hari TETAP inklusif.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/OkrSellerExclusionTest.php`

Buat helper user/product/stock/completedPo yang sama seperti Task 1 (salin). Lalu:

```php
    public function test_okr_omzet_distributor_kecualikan_inter_partner_tapi_funnel_tetap_inklusif(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();

        $this->completedPo($grand, $p, 3);  // HQ→grand
        $this->completedPo($dist, $p, 5);   // grand→dist (inter-partner) — dist AKTIF beli

        $snap = app(\App\Services\OkrBusinessSnapshotService::class)->/* method snapshot yang memuat distributorSnapshot + funnel */ ...;

        // Omzet distributor (uang) HANYA dari PO seller-null.
        // Funnel: dist yang cuma beli dari upline TETAP terhitung "pernah PO"/"aktif".
        // (Assert sesuai bentuk return snapshot — lihat Step 2/3.)
    }
```

Catatan implementer: baca dulu `OkrBusinessSnapshotService` untuk method publik yang mengembalikan `distributorSnapshot`, status-counts, funnel, dan piutang; sesuaikan assert ke bentuk arraynya. Assert minimal:
- Omzet distributor `$dist` = 0 (dia tak beli dari HQ; belanjanya inter-partner) — buktikan uang inter-partner dibuang.
- Funnel "pernah PO"/"aktif 30 hari" MENGHITUNG `$dist` (>= termasuk dia) — buktikan carve-out.

- [ ] **Step 2: Jalankan — pastikan GAGAL** (`--filter=OkrSellerExclusionTest`): omzet distributor masih memasukkan inter-partner.

- [ ] **Step 3: Tambah filter (finansial/operasional) + biarkan funnel**

Di `app/Services/OkrBusinessSnapshotService.php`:
- **Omzet distributor** (~:446-452, SUM group by buyer) → `->whereNull('seller_id')`.
- **Status counts PO (coo)** (~:293-297) → `->whereNull('seller_id')`.
- **Piutang tempo** (~:201-213) → `->whereNull('seller_id')`.
- **Funnel pernah-PO** (~:461-464) & **aktif-30-hari** (~:468-473) → **BIARKAN** (jangan tambah filter). Tambahkan komentar singkat `// engagement: mitra aktif beli dari upline tetap dihitung (spec A4)`.

- [ ] **Step 4: Jalankan — pastikan LULUS** (`--filter=OkrSellerExclusionTest`).

- [ ] **Step 5: Regresi + commit**

```bash
C:\php83\php.exe artisan test
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/OkrBusinessSnapshotService.php tests/Feature/OkrSellerExclusionTest.php
git commit -m "feat(mlm): OKR kecualikan inter-partner (omzet/status/piutang), funnel tetap inklusif" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Method agregasi `omzetPerMitra()`

**Files:**
- Modify: `app/Services/ReportService.php`
- Test: `tests/Feature/OmzetMitraServiceTest.php` (baru)

**Interfaces:**
- Consumes: `PurchaseOrder` (seller_id, total_amount, status), `PartnerSale` (user_id, total_amount, sold_at), `User`.
- Produces: `omzetPerMitra(?Carbon $month=null): array` — baris `['user_id','nama','tier','jual_downline','jual_customer','total']`, urut total desc, hanya total>0.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/OmzetMitraServiceTest.php`

Helper user/product/stock/completedPo seperti Task 1. Tambah helper PartnerSale:
```php
    private function partnerSale(User $seller, float $amount, string $soldAt = '2026-08-10'): void
    {
        \App\Models\PartnerSale::create([
            'sale_number' => 'PS-'.(++$this->seq), 'user_id' => $seller->id,
            'customer_name' => 'Cust '.$this->seq, 'total_amount' => $amount,
            'sold_at' => $soldAt, 'created_by' => $seller->id,
        ]);
    }
```
Tes:
```php
    public function test_omzet_per_mitra_gabung_jual_downline_dan_customer(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);   // beli dari grand → grand jadi seller
        $p = $this->product();
        $this->completedPo($dist, $p, 5);        // grand jual ke downline (dist)
        $downlineRp = (float) \App\Models\PurchaseOrder::where('seller_id', $grand->id)
            ->where('status', \App\Models\PurchaseOrder::STATUS_COMPLETED)->sum('total_amount');
        $this->partnerSale($grand, 40000);       // grand jual ke customer akhir

        $rows = app(\App\Services\ReportService::class)->omzetPerMitra(null);
        $grandRow = collect($rows)->firstWhere('user_id', $grand->id);

        $this->assertNotNull($grandRow);
        $this->assertEqualsWithDelta($downlineRp, $grandRow['jual_downline'], 0.01);
        $this->assertEqualsWithDelta(40000, $grandRow['jual_customer'], 0.01);
        $this->assertEqualsWithDelta($downlineRp + 40000, $grandRow['total'], 0.01);
    }

    public function test_omzet_per_mitra_abaikan_po_hq_dan_mitra_tanpa_jualan(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $this->completedPo($grand, $p, 3);       // HQ→grand: grand sbg PEMBELI, bukan seller → bukan jualan grand
        $rows = app(\App\Services\ReportService::class)->omzetPerMitra(null);
        $this->assertNull(collect($rows)->firstWhere('user_id', $grand->id)); // grand tak punya jualan → tak muncul
    }
```

- [ ] **Step 2: Jalankan — pastikan GAGAL** (`--filter=OmzetMitraServiceTest`): method belum ada.

- [ ] **Step 3: Implementasi `omzetPerMitra()`**

Di `app/Services/ReportService.php`, tambah method (pakai `inMonth()`/pola `?Carbon $month` yang ada di file ini). Referensi konstanta status: `PurchaseOrder::STATUS_COMPLETED` (atau `self::REVENUE_STATUS`).

```php
public function omzetPerMitra(?Carbon $month = null): array
{
    // Jual ke downline: PO completed di mana mitra jadi seller.
    $poQuery = PurchaseOrder::query()
        ->where('status', self::REVENUE_STATUS)
        ->whereNotNull('seller_id');
    if ($month) {
        $this->inMonth($poQuery, $month); // sesuaikan nama argumen kolom bila inMonth butuh
    }
    $downline = $poQuery->groupBy('seller_id')
        ->selectRaw('seller_id as uid, SUM(total_amount) as total')
        ->pluck('total', 'uid');

    // Jual ke customer akhir: PartnerSale by user.
    $psQuery = PartnerSale::query();
    if ($month) {
        $psQuery->whereBetween('sold_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
    }
    $customer = $psQuery->groupBy('user_id')
        ->selectRaw('user_id as uid, SUM(total_amount) as total')
        ->pluck('total', 'uid');

    $ids = $downline->keys()->merge($customer->keys())->unique()->values();
    if ($ids->isEmpty()) {
        return [];
    }
    $users = User::whereIn('id', $ids)->get(['id', 'fullname', 'name', 'role'])->keyBy('id');

    $rows = [];
    foreach ($ids as $id) {
        $jd = (float) ($downline[$id] ?? 0);
        $jc = (float) ($customer[$id] ?? 0);
        $total = $jd + $jc;
        if ($total <= 0) {
            continue;
        }
        $u = $users[$id] ?? null;
        $rows[] = [
            'user_id' => (int) $id,
            'nama' => $u?->fullname ?: ($u?->name ?? '—'),
            'tier' => $u ? $this->roleLabel($u->role) : '—', // pakai helper label role yang ada; kalau tak ada, kirim raw role
            'jual_downline' => $jd,
            'jual_customer' => $jc,
            'total' => $total,
        ];
    }
    usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

    return $rows;
}
```
Implementer: pastikan `use App\Models\PartnerSale;` ada. Untuk `tier`/label role, pakai helper yang sudah dipakai laporan lain (mis. label dari registry role); kalau tak ada helper ringkas, kirim `$u->role` mentah — JANGAN nambah dependency.

- [ ] **Step 4: Jalankan — pastikan LULUS** (`--filter=OmzetMitraServiceTest`).

- [ ] **Step 5: Regresi + commit**

```bash
C:\php83\php.exe artisan test
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/ReportService.php tests/Feature/OmzetMitraServiceTest.php
git commit -m "feat(mlm): ReportService::omzetPerMitra (jual downline + customer per mitra)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Halaman "Omzet Mitra" (HQ)

**Files:**
- Modify: `routes/web.php`, `app/Http/Controllers/ReportController.php`, `resources/views/layouts/app.blade.php`
- Create: `resources/views/reports/omzet_mitra.blade.php`
- Test: `tests/Feature/OmzetMitraPageTest.php` (baru)

**Interfaces:**
- Consumes: `ReportService::omzetPerMitra()` (Task 3), pola `ReportController::parseMonth()`.
- Produces: route `reports.omzet-mitra` (staff-only), halaman tabel + filter bulan, nav item.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/OmzetMitraPageTest.php`

```php
    public function test_staff_lihat_halaman_omzet_mitra(): void
    {
        // siapkan 1 mitra dgn jualan (pola helper Task 3) ...
        $staff = /* user staff/super_admin */;
        $resp = $this->actingAs($staff)->get(route('reports.omzet-mitra'));
        $resp->assertOk();
        $resp->assertSee('Omzet Mitra');
        $resp->assertSee($mitra->fullname);
    }

    public function test_mitra_tak_boleh_akses_omzet_mitra(): void
    {
        $mitra = /* user role reseller/distributor */;
        $this->actingAs($mitra)->get(route('reports.omzet-mitra'))->assertForbidden();
    }
```
Implementer: bikin user staff & mitra sesuai pola tes lain (lihat `BackdatedSaleTest::admin()` untuk super_admin). Isi data jualan pakai helper Task 3.

- [ ] **Step 2: Jalankan — pastikan GAGAL** (route belum ada).

- [ ] **Step 3: Route**

`routes/web.php`, di grup `permission:view_reports` (dekat `reports.index` ~:188-192):
```php
Route::get('/reports/omzet-mitra', [ReportController::class, 'omzetMitra'])->name('reports.omzet-mitra');
```

- [ ] **Step 4: Controller**

`app/Http/Controllers/ReportController.php`, tambah method (ikut pola `index()` + `parseMonth()`):
```php
public function omzetMitra(Request $request)
{
    $user = $request->user();
    abort_unless($user->isStaff(), 403);

    $month = $this->parseMonth($request); // null bila 'all'
    $rows = app(ReportService::class)->omzetPerMitra($month);
    $grandTotal = array_sum(array_column($rows, 'total'));

    return view('reports.omzet_mitra', [
        'rows' => $rows,
        'grandTotal' => $grandTotal,
        'bulan' => $request->query('bulan'),
    ]);
}
```
Pastikan `ReportService` & `Request` ter-import; `parseMonth()` sudah private di controller (pakai apa adanya).

- [ ] **Step 5: View** — `resources/views/reports/omzet_mitra.blade.php`

Ikut pola `resources/views/reports/index.blade.php` (extends layout, filter bulan GET, format rupiah pakai helper existing mis. `number_format`). Struktur:
```blade
@extends('layouts.app')
@section('title', 'Omzet Mitra')
@section('heading', 'Omzet Mitra')
@section('content')
<form method="GET" class="mb-4">
    <input type="month" name="bulan" value="{{ $bulan !== 'all' ? $bulan : '' }}" onchange="this.form.submit()">
</form>
<div class="overflow-x-auto">
<table class="min-w-full text-sm">
    <thead><tr>
        <th class="text-left">Mitra</th><th class="text-left">Tier</th>
        <th class="text-right">Jual ke Downline</th><th class="text-right">Jual ke Customer</th>
        <th class="text-right">Total Omzet</th>
    </tr></thead>
    <tbody>
    @forelse($rows as $r)
        <tr>
            <td>{{ $r['nama'] }}</td>
            <td>{{ $r['tier'] }}</td>
            <td class="text-right">Rp {{ number_format($r['jual_downline'], 0, ',', '.') }}</td>
            <td class="text-right">Rp {{ number_format($r['jual_customer'], 0, ',', '.') }}</td>
            <td class="text-right font-semibold">Rp {{ number_format($r['total'], 0, ',', '.') }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="text-center py-4 text-gray-500">Belum ada jualan mitra pada periode ini.</td></tr>
    @endforelse
    </tbody>
    <tfoot><tr>
        <td colspan="4" class="text-right font-semibold">Total</td>
        <td class="text-right font-semibold">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
    </tr></tfoot>
</table>
</div>
@endsection
```
Sesuaikan kelas CSS/format ke gaya view laporan yang ada bila beda. JANGAN `@json([...])` dengan array literal (bug lama — kalau perlu, `json_encode`). Halaman ini tak butuh JS.

- [ ] **Step 6: Nav**

`resources/views/layouts/app.blade.php`, dekat item `navItem('reports.index', ...)` (~:141-142), tambah (staff-only):
```blade
@if($u->isStaff())
    {!! navItem('reports.omzet-mitra', 'Omzet Mitra', 'reports.omzet-mitra') !!}
@endif
```
Sesuaikan pemanggilan `navItem` ke bentuk yang persis dipakai file itu (echo vs `{!! !!}`).

- [ ] **Step 7: Jalankan — pastikan LULUS** (`--filter=OmzetMitraPageTest`).

- [ ] **Step 8: Regresi + commit**

```bash
C:\php83\php.exe artisan test
C:\php83\php.exe vendor/bin/pint --dirty
git add routes/web.php app/Http/Controllers/ReportController.php resources/views/reports/omzet_mitra.blade.php resources/views/layouts/app.blade.php tests/Feature/OmzetMitraPageTest.php
git commit -m "feat(mlm): halaman Omzet Mitra (HQ) — tabel jualan per mitra + filter bulan" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian
Setelah 4 task + suite hijau: review whole-branch (opus) → `superpowers:finishing-a-development-branch`. Deploy prod = `git pull origin main && optimize:clear` (TANPA migrasi). Dorman-aman: jaringan kosong → semua PO seller-null → laporan HQ = seperti sekarang; halaman Omzet Mitra tampil kosong sampai ada jualan mitra.

## Self-Review
- **Cakupan spec:** A1 scopePo→Task1 · A2 6-site→Task1 · A3 OKR→Task2 · A4 carve-out→Task2 · B1/B2 service→Task3 · B3 UI→Task4 · tes A6/B4 tersebar di tiap task. ✅
- **Placeholder:** kode produksi & tes konkret; instruksi per-site menyebut method+filter tepat (bukan "handle it").
- **Konsistensi tipe:** `omzetPerMitra(?Carbon):array` dipakai konsisten Task3→Task4; kolom `seller_id`/`total_amount`/`user_id`/`sold_at` sesuai model. `isStaff()`/`isPartner()` sesuai User existing.
- **Risiko:** `inMonth()` signature & `roleLabel` helper mungkin beda nama — implementer Task3/4 baca file dulu; kalau helper label tak ada, fallback role mentah (tanpa dependency).
