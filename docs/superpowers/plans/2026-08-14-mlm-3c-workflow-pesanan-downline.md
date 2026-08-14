# Workflow "Pesanan Downline" — Rencana Implementasi

> **Untuk pekerja agentik:** SUB-SKILL WAJIB: `superpowers:subagent-driven-development`. Langkah pakai checkbox `- [ ]`.

**Goal:** Upline (mitra) memproses PO dari downline-nya sendiri (lihat, verifikasi bayar, kirim/selesai, tolak) — ter-scope ke `seller_id = dia`. HQ tak berubah.

**Architecture:** Permission baru + `DownlineOrderController` + grup route + 2 view + nav. Reuse total `PurchaseOrderService` (verifyPayment/updateStatus/cancel). Zero-dependency, tanpa migrasi. Spec: `docs/superpowers/specs/2026-08-14-mlm-3c-workflow-pesanan-downline-design.md`.

**Tech Stack:** Laravel 13, PHP 8.3, Blade+Tailwind, Eloquent. Runner `C:\php83\php.exe artisan test`.

## Global Constraints
- **Otorisasi (kritis):** izin baru `process_downline_po` (default tier partner) untuk gate route. SETIAP aksi ada guard kepemilikan inline allow-list: `abort_unless($po->seller_id === $user->id, 403, '...')`. JANGAN beri mitra `update_po_status`. JANGAN pakai Policy (app ini tak punya Policy — semua inline `if`).
- **Akuntansi:** nol untuk mitra — verifikasi bayar = penanda `payment_status`, bukan jurnal. Jangan sentuh `acc_`.
- Reuse service layer; JANGAN duplikasi logika stok/status. View BARU (jangan ubah view/`PurchaseOrderController` HQ).
- `seller_id` sudah ada (null=HQ). `PurchaseOrder::STATUS_COMPLETED/STATUS_CANCELLED`, `PAYMENT_*`. Pint `--dirty` sebelum commit. Suite existing (750) tetap hijau.
- Pola acuan (baca sebelum nulis): `JaringanSayaController` (controller+route+view mitra berdiri sendiri), `PurchaseOrderController::show()` (guard `user_id!==me`→403, tiru tapi ganti ke `seller_id`), `uploadPayment()` (allow-list style), `Permissions.php` DEFINITIONS+DEFAULTS (key `create_po` sebagai contoh tier partner), `layouts/app.blade.php` nav "Jaringan Saya".

---

## Task 1: Izin `process_downline_po` + daftar pesanan + nav

**Files:**
- Modify: `app/Support/Permissions.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`
- Create: `app/Http/Controllers/DownlineOrderController.php`, `resources/views/pesanan_downline/index.blade.php`
- Test: `tests/Feature/DownlineOrderListTest.php`

**Interfaces:**
- Produces: route `pesanan-downline.index`; `DownlineOrderController::index`; izin `process_downline_po`.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/DownlineOrderListTest.php`

Pakai pola helper dari `tests/Feature/InterPartnerFulfillmentTest.php` (user/product/stock/svc + `createForPartner`). Buat PO inter-partner via service supaya `seller_id` terisi otomatis.

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DownlineOrderListTest extends TestCase
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
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 1000, 'status' => 'active',
        ]);
    }

    private function poFor(User $buyer, Product $p, int $qty = 5)
    {
        return app(PurchaseOrderService::class)->createForPartner($buyer, [['product_id' => $p->id, 'qty' => $qty]], null, null);
    }

    public function test_upline_lihat_pesanan_downlinenya_saja(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);      // downline si grand
        $p = $this->product();
        $poDownline = $this->poFor($dist, $p);                        // seller_id = grand
        $poHq = $this->poFor($grand, $p);                             // grand beli dari HQ → seller_id null

        $resp = $this->actingAs($grand)->get(route('pesanan-downline.index'));
        $resp->assertOk();
        $resp->assertSee($poDownline->po_number);   // pesanan downline muncul
        $resp->assertDontSee($poHq->po_number);      // PO HQ (dia sbagai pembeli) TIDAK muncul di sini
    }

    public function test_upline_lain_tak_lihat_pesanan_bukan_downlinenya(): void
    {
        $grandA = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->user(User::ROLE_DISTRIBUTOR, $grandA->id);
        $grandB = $this->user(User::ROLE_GRAND_DISTRIBUTOR);          // mitra lain, tak berelasi
        $p = $this->product();
        $poA = $this->poFor($distA, $p);                              // seller_id = grandA

        $resp = $this->actingAs($grandB)->get(route('pesanan-downline.index'));
        $resp->assertOk();
        $resp->assertDontSee($poA->po_number);                        // grandB tak lihat pesanan grandA
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`--filter=DownlineOrderListTest`): route belum ada.

- [ ] **Step 3: Tambah izin** — `app/Support/Permissions.php`

Baca `DEFINITIONS` + `DEFAULTS`. Tambah key `process_downline_po`:
- DEFINITIONS: `'process_downline_po' => 'Proses Pesanan Downline',` (samakan format entri lain).
- DEFAULTS: `'process_downline_po' => [User::ROLE_DISTRIBUTOR, User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_RESELLER, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD],` (samakan array `create_po`).

- [ ] **Step 4: Controller** — `app/Http/Controllers/DownlineOrderController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class DownlineOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $orders = PurchaseOrder::query()
            ->where('seller_id', $user->id)                 // KUNCI: hanya pesanan di mana dia penjual
            ->with('user')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('pesanan_downline.index', ['orders' => $orders]);
    }
}
```

- [ ] **Step 5: Route** — `routes/web.php`

Dekat blok PO, dalam grup auth yang sama, tambah grup baru:
```php
Route::middleware('permission:process_downline_po')->group(function () {
    Route::get('/pesanan-downline', [DownlineOrderController::class, 'index'])->name('pesanan-downline.index');
});
```
Import `use App\Http\Controllers\DownlineOrderController;`. (Route show/aksi ditambah di Task 2 & 3 dalam grup yang sama.)

- [ ] **Step 6: View** — `resources/views/pesanan_downline/index.blade.php`

Pola `purchase_orders/index.blade.php` (extends layout, tabel Tailwind, badge status) tapi trim. Kolom: No PO (`$o->po_number`, link ke `route('pesanan-downline.show', $o)` — route show blm ada di Task 1, boleh link dulu, aktif di Task 2), Downline (`$o->user->fullname`), Total (`number_format($o->total_amount,0,',','.')`), Status, Status bayar. Empty state "Belum ada pesanan dari downline." JANGAN `@json([...])` literal.

- [ ] **Step 7: Nav** — `resources/views/layouts/app.blade.php`

Dekat item "Jaringan Saya", pola sama:
```blade
@if($u->isPartner() && $u->downlines()->exists())
    {!! navItem('pesanan-downline.index', 'Pesanan Downline', 'pesanan-downline.index') !!}
@endif
```
(Samakan bentuk `navItem(...)` ke pemakaian existing di file itu.)

- [ ] **Step 8: LULUS + regresi + commit**

`--filter=DownlineOrderListTest` hijau → `C:\php83\php.exe artisan test` hijau → Pint → commit:
```bash
git add app/Support/Permissions.php app/Http/Controllers/DownlineOrderController.php routes/web.php resources/views/pesanan_downline/index.blade.php resources/views/layouts/app.blade.php tests/Feature/DownlineOrderListTest.php
git commit -m "feat(mlm): Pesanan Downline — izin process_downline_po + daftar pesanan (scoped seller) + nav" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Detail pesanan + guard kepemilikan + pre-cek stok

**Files:**
- Modify: `app/Http/Controllers/DownlineOrderController.php`, `routes/web.php`
- Create: `resources/views/pesanan_downline/show.blade.php`
- Test: `tests/Feature/DownlineOrderShowTest.php`

**Interfaces:**
- Consumes: Task 1 controller/route.
- Produces: route `pesanan-downline.show`; guard kepemilikan `seller_id===me`; data pre-cek stok ke view.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/DownlineOrderShowTest.php`

Salin helper Task 1. Tes (INTI keamanan):
```php
    public function test_upline_lihat_detail_pesanan_downlinenya(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->poFor($dist, $this->product());
        $this->actingAs($grand)->get(route('pesanan-downline.show', $po))->assertOk();
    }

    public function test_upline_ditolak_akses_PO_HQ(): void
    {
        // PO HQ = seller_id null. Guard seller_id===me otomatis gagal.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $poHq = $this->poFor($grand, $this->product());       // grand beli dari HQ → seller null
        $this->actingAs($grand)->get(route('pesanan-downline.show', $poHq))->assertForbidden();
    }

    public function test_upline_ditolak_akses_pesanan_mitra_lain(): void
    {
        $grandA = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->user(User::ROLE_DISTRIBUTOR, $grandA->id);
        $poA = $this->poFor($distA, $this->product());         // seller_id = grandA
        $grandB = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->actingAs($grandB)->get(route('pesanan-downline.show', $poA))->assertForbidden();
    }
```

- [ ] **Step 2: Jalankan — GAGAL** (route show belum ada).

- [ ] **Step 3: Method `show` + guard + pre-cek stok** — tambah ke `DownlineOrderController`

```php
use App\Models\Inventory;

public function show(Request $request, PurchaseOrder $purchaseOrder)
{
    $user = $request->user();
    abort_unless($purchaseOrder->seller_id === $user->id, 403, 'Ini bukan pesanan downline Anda.');

    $purchaseOrder->load(['items', 'user']);

    // Pre-cek stok upline per item (biar pesan rapi, bukan exception generik).
    $stok = Inventory::where('user_id', $user->id)->pluck('quantity', 'product_id');
    $kurang = [];
    foreach ($purchaseOrder->items as $item) {
        $tersedia = (int) ($stok[$item->product_id] ?? 0);
        if ($tersedia < (int) $item->qty) {
            $kurang[] = ['nama' => $item->product_name, 'tersedia' => $tersedia, 'butuh' => (int) $item->qty];
        }
    }

    return view('pesanan_downline.show', [
        'po' => $purchaseOrder,
        'stokKurang' => $kurang,      // [] = cukup
    ]);
}
```
(Verifikasi nama kolom item: `PurchaseOrderItem` punya `product_id`, `qty`, `product_name` — cek model bila ragu.)

- [ ] **Step 4: Route** — tambah di grup `permission:process_downline_po` (Task 1):
```php
Route::get('/pesanan-downline/{purchaseOrder}', [DownlineOrderController::class, 'show'])->name('pesanan-downline.show');
```

- [ ] **Step 5: View** — `resources/views/pesanan_downline/show.blade.php`

Fork trim `purchase_orders/show.blade.php`: header PO, tabel item, total, bukti transfer (`@if($po->paymentProofUrl()) <img src="{{ $po->paymentProofUrl() }}">`), status bayar. **Placeholder tombol aksi** (form aktif diisi Task 3) — buat sekarang: kalau `count($stokKurang)` > 0, tampilkan peringatan merah daftar produk kurang + tombol "Kirim/Selesai" disabled. JANGAN `@json([...])` literal. Tanpa JS framework.

- [ ] **Step 6: LULUS + regresi + commit**

`--filter=DownlineOrderShowTest` hijau (3 tes; 2 di antaranya = 403 keamanan) → suite hijau → Pint → commit:
```bash
git add app/Http/Controllers/DownlineOrderController.php routes/web.php resources/views/pesanan_downline/show.blade.php tests/Feature/DownlineOrderShowTest.php
git commit -m "feat(mlm): Pesanan Downline — detail + guard kepemilikan (403 utk PO HQ/mitra lain) + pre-cek stok" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Aksi — verifikasi bayar · kirim/selesai · tolak

**Files:**
- Modify: `app/Http/Controllers/DownlineOrderController.php`, `routes/web.php`, `resources/views/pesanan_downline/show.blade.php`
- Test: `tests/Feature/DownlineOrderActionTest.php`

**Interfaces:**
- Consumes: Task 2 (show + guard). Reuse `PurchaseOrderService::verifyPayment/updateStatus/cancel`.
- Produces: routes `pesanan-downline.{verify-payment,fulfill,reject}` + method masing-masing (semua guard kepemilikan).

**WAJIB dibaca implementer dulu:** `PurchaseOrderController::verifyPayment()`, `updateStatus()`, `cancel()` + method service yang dipanggilnya (`PurchaseOrderService::verifyPayment/updateStatus/cancel`) — supaya SIGNATURE & argumen dipanggil PERSIS sama, cuma dari controller baru + guard kepemilikan di depan. Cek juga `PurchaseOrder::TRANSITIONS`: apakah PO `pending` bisa langsung ke `completed` via `updateStatus`; kalau perlu status antara, ikuti mekanisme yang dipakai HQ (`updateStatus`/`advanceStatus`) — JANGAN bikin jalur baru.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/DownlineOrderActionTest.php`

Salin helper Task 1 + tambah `stock($u,$p,$qty)` (`Inventory::create`) + `qty($u,$p)` (baca quantity) seperti `InterPartnerFulfillmentTest`. Tes:
```php
    public function test_upline_verifikasi_bayar_downline(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->poFor($dist, $this->product());
        $this->actingAs($grand)->post(route('pesanan-downline.verify-payment', $po), ['approve' => '1'])->assertRedirect();
        $po->refresh();
        $this->assertSame(PurchaseOrder::PAYMENT_PAID, $po->payment_status);
        $this->assertSame($grand->id, (int) $po->payment_verified_by);
    }

    public function test_upline_kirim_transfer_stok_upline_ke_downline(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->stock($grand, $p, 50);                         // upline punya stok
        $po = $this->poFor($dist, $p, 10);
        $this->actingAs($grand)->post(route('pesanan-downline.verify-payment', $po), ['approve' => '1']);
        $this->actingAs($grand)->post(route('pesanan-downline.fulfill', $po))->assertRedirect();
        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $po->status);
        $this->assertSame(40, $this->qty($grand, $p));        // upline -10
        $this->assertSame(10, $this->qty($dist, $p));         // downline +10
    }

    public function test_kirim_sebelum_lunas_ditolak_stok_tak_berubah(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->stock($grand, $p, 50);
        $po = $this->poFor($dist, $p, 10);                    // belum lunas, bukan tempo
        $resp = $this->actingAs($grand)->post(route('pesanan-downline.fulfill', $po));
        $po->refresh();
        $this->assertNotSame(PurchaseOrder::STATUS_COMPLETED, $po->status); // gate bayar menahan
        $this->assertSame(50, $this->qty($grand, $p));        // stok tak berubah
    }

    public function test_upline_tolak_pesanan_dengan_alasan(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->poFor($dist, $this->product());
        $this->actingAs($grand)->post(route('pesanan-downline.reject', $po), ['reason' => 'Stok habis'])->assertRedirect();
        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $po->status);
    }

    public function test_aksi_di_pesanan_mitra_lain_ditolak_403(): void
    {
        $grandA = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->user(User::ROLE_DISTRIBUTOR, $grandA->id);
        $po = $this->poFor($distA, $this->product());         // seller_id = grandA
        $grandB = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->actingAs($grandB)->post(route('pesanan-downline.fulfill', $po))->assertForbidden();
        $this->actingAs($grandB)->post(route('pesanan-downline.verify-payment', $po), ['approve' => '1'])->assertForbidden();
        $this->actingAs($grandB)->post(route('pesanan-downline.reject', $po), ['reason' => 'x'])->assertForbidden();
    }
```
(Sesuaikan payload `approve`/`reason` + status bayar terminal ke SIGNATURE service yang kamu baca. Kalau `verifyPayment` service butuh arg beda, sesuaikan controller + tes.)

- [ ] **Step 2: Jalankan — GAGAL** (route aksi belum ada).

- [ ] **Step 3: Methods** — tambah ke `DownlineOrderController` (tiap method: guard kepemilikan dulu, lalu panggil service)

```php
use App\Services\PurchaseOrderService;

private function guardOwner(PurchaseOrder $po, $user): void
{
    abort_unless($po->seller_id === $user->id, 403, 'Ini bukan pesanan downline Anda.');
}

public function verifyPayment(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service)
{
    $this->guardOwner($purchaseOrder, $request->user());
    $approve = $request->boolean('approve');
    // Panggil PERSIS seperti PurchaseOrderController::verifyPayment (verifierId = user id).
    $service->verifyPayment($purchaseOrder, $approve, $request->input('note'), $request->user()->id);
    return back()->with('status', $approve ? 'Pembayaran diverifikasi.' : 'Pembayaran ditolak.');
}

public function fulfill(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service)
{
    $this->guardOwner($purchaseOrder, $request->user());
    try {
        $service->updateStatus($purchaseOrder, PurchaseOrder::STATUS_COMPLETED, $request->input('notes'));
    } catch (\RuntimeException $e) {
        return back()->with('error', $e->getMessage());   // gate bayar / stok kurang → pesan rapi
    }
    return back()->with('status', 'Pesanan dikirim & diselesaikan.');
}

public function reject(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service)
{
    $this->guardOwner($purchaseOrder, $request->user());
    $request->validate(['reason' => 'required|string|max:500']);
    $service->cancel($purchaseOrder, $request->input('reason'));
    return back()->with('status', 'Pesanan ditolak.');
}
```
(SIGNATURE `verifyPayment/updateStatus/cancel` — sesuaikan ke yang kamu baca. `updateStatus` mungkin butuh urutan arg beda; samakan ke `PurchaseOrderController`.)

- [ ] **Step 4: Route** — tambah 3 POST di grup `permission:process_downline_po`:
```php
Route::post('/pesanan-downline/{purchaseOrder}/verify-payment', [DownlineOrderController::class, 'verifyPayment'])->name('pesanan-downline.verify-payment');
Route::post('/pesanan-downline/{purchaseOrder}/fulfill', [DownlineOrderController::class, 'fulfill'])->name('pesanan-downline.fulfill');
Route::post('/pesanan-downline/{purchaseOrder}/reject', [DownlineOrderController::class, 'reject'])->name('pesanan-downline.reject');
```

- [ ] **Step 5: View aksi** — lengkapi `pesanan_downline/show.blade.php`

Form `@csrf` POST ke tiap route: tombol **Verifikasi bayar** (approve=1) / **Tolak bayar** (approve=0) muncul saat `payment_status = awaiting_verification`; **Kirim/Selesai** (disabled kalau belum `paid`/tempo ATAU `count($stokKurang)>0`); **Tolak pesanan** (buka input alasan → POST reject). Tampilkan `session('status')`/`session('error')`. Sembunyikan semua aksi kalau status sudah `completed`/`cancelled`.

- [ ] **Step 6: LULUS + regresi + commit**

`--filter=DownlineOrderActionTest` hijau (termasuk transfer stok + gate bayar + 403 lintas-mitra) → `C:\php83\php.exe artisan test` hijau → Pint → commit:
```bash
git add app/Http/Controllers/DownlineOrderController.php routes/web.php resources/views/pesanan_downline/show.blade.php tests/Feature/DownlineOrderActionTest.php
git commit -m "feat(mlm): Pesanan Downline — aksi verifikasi/kirim/tolak (reuse service, guard kepemilikan)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian
Setelah 3 task + suite hijau: review whole-branch (opus) → `superpowers:finishing-a-development-branch`. Deploy prod = `git pull origin main && optimize:clear` (TANPA migrasi; izin baru muncul di matriks Hak Akses otomatis). Dormant-safe: menu hanya untuk mitra yang punya downline.

## Self-Review
- **Cakupan spec:** izin+list+nav→Task1 · detail+guard+precek→Task2 · aksi(verify/fulfill/reject)+guard→Task3 · tes otorisasi (403 PO HQ / mitra lain) tersebar Task2+3. ✅
- **Placeholder:** kode konkret; SIGNATURE service ditandai "samakan ke PurchaseOrderController" (implementer baca — bukan tebak).
- **Konsistensi:** guard `seller_id===me` di SETIAP aksi; izin `process_downline_po` (bukan `update_po_status`); reuse service; view baru. `PurchaseOrder::STATUS_*/PAYMENT_*` konsisten.
- **Risiko diketahui:** (1) transisi `pending→completed` — implementer Task 3 verifikasi TRANSITIONS + reuse mekanisme HQ. (2) SIGNATURE verifyPayment/updateStatus/cancel — implementer baca controller HQ. (3) nama kolom item (`product_name`/`qty`) — cek model.
