# Cetak Dokumen PO (Label / Packing / Faktur) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Cetak Dokumen" feature to Purchase Orders — a dialog on the PO detail page that prints Label Pengiriman (A6), Daftar Pengemasan (packing list), and Faktur/Nota (A4) through the browser.

**Architecture:** Standalone Blade print view (its own `<head>` + print CSS, no app layout) rendered from a GET route, opened in a new tab, auto-triggers `window.print()`. Barcode is generated as pure-PHP Code128-B SVG (zero-dependency). Two new nullable PO columns (`kurir`, `no_resi`) are filled manually now via a small form on the detail page; a future courier (J&T) API just populates those same columns without touching the template. HQ sender identity lives in `app_settings` key/value rows, edited on the existing Setelan Sistem page.

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + vanilla JS, Tailwind (via existing app CSS). Test runner: `/c/php83/php.exe artisan test`. Formatter: `/c/php83/php.exe vendor/bin/pint --dirty`.

## Global Constraints

- **Zero-dependency:** never add a composer or npm package. Barcode, printing, everything is hand-written or browser-native.
- **Print = browser print,** not server PDF. Use a standalone Blade view + CSS `@page` + `window.print()`. Users can still change scale/size in the browser print dialog.
- **Runner:** `/c/php83/php.exe artisan test` (append `--filter=X` to scope). Run `/c/php83/php.exe vendor/bin/pint --dirty` before every commit.
- **Migrations** are timestamped `2026_01_01_000NNN_*`. The latest existing is `000124`; the next new migration is `000125`. Only ONE new migration in this plan.
- **Gating:** staff-only actions use `permission:update_po_status` (held by `admin` + `gudang`; `super_admin` always passes). Sender settings live under the existing `permission:system_settings` group.
- **Commit messages** end with:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`
- **UI copy is Indonesian** (match the surrounding code). Rupiah formatting: `number_format($n, 0, ',', '.')` with a `Rp ` prefix.
- **Audit:** side-effectful controller actions call `AuditService::log(action:, targetType:, targetId:, after:)`.

---

## File Structure

- `database/migrations/2026_01_01_000125_add_shipping_fields_to_purchase_orders.php` — **create**. Adds `kurir` + `no_resi`.
- `app/Models/PurchaseOrder.php` — **modify**. Add the two columns to `$fillable`.
- `app/Support/Barcode.php` — **create**. `Barcode::code128()` → SVG string.
- `app/Http/Controllers/SettingController.php` — **modify**. Add `saveSender()`, pass sender values to the view.
- `resources/views/settings/index.blade.php` — **modify**. Add a "Pengirim (HQ)" form.
- `routes/web.php` — **modify**. Add `settings.sender.save`, `purchase-orders.resi`, `purchase-orders.print`.
- `app/Http/Controllers/PurchaseOrderController.php` — **modify**. Add `saveResi()` and `print()`.
- `resources/views/purchase_orders/print.blade.php` — **create**. The standalone print document view.
- `resources/views/purchase_orders/show.blade.php` — **modify**. Add the resi/kurir form and the "Cetak Dokumen" button + dialog.
- `tests/Feature/*` and `tests/Unit/*` — **create** per task.

---

## Task 1: PO shipping columns (`kurir`, `no_resi`)

**Files:**
- Create: `database/migrations/2026_01_01_000125_add_shipping_fields_to_purchase_orders.php`
- Modify: `app/Models/PurchaseOrder.php:106-112` (the `$fillable` array)
- Test: `tests/Feature/PurchaseOrderShippingFieldsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `purchase_orders.kurir` (string, nullable) and `purchase_orders.no_resi` (string, nullable), both mass-assignable on `App\Models\PurchaseOrder`. Later tasks read `$po->kurir` and `$po->no_resi`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderShippingFieldsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderShippingFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_kurir_dan_no_resi_bisa_disimpan_dan_dibaca(): void
    {
        $admin = User::create([
            'name' => 'adm', 'fullname' => 'ADM', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-SHIP-1', 'created_by' => $admin->id, 'user_id' => $admin->id,
            'status' => PurchaseOrder::STATUS_PENDING, 'total_amount' => 100_000, 'user_role' => 'super_admin',
            'kurir' => 'J&T Express', 'no_resi' => 'JT1234567890',
        ]);

        $fresh = $po->fresh();
        $this->assertSame('J&T Express', $fresh->kurir);
        $this->assertSame('JT1234567890', $fresh->no_resi);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderShippingFieldsTest`
Expected: FAIL — column `kurir`/`no_resi` does not exist (or is not fillable so is silently dropped and the assertion fails).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_01_01_000125_add_shipping_fields_to_purchase_orders.php`:

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
            $table->string('kurir', 50)->nullable()->after('shipping_address');
            $table->string('no_resi', 64)->nullable()->after('kurir');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['kurir', 'no_resi']);
        });
    }
};
```

- [ ] **Step 4: Add the columns to `$fillable`**

In `app/Models/PurchaseOrder.php`, the `$fillable` array currently ends:

```php
        'shipping_address', 'notes', 'revision_notes', 'completed_at', 'stock_skipped', 'deleted_by',
    ];
```

Change it to add the two fields:

```php
        'shipping_address', 'kurir', 'no_resi', 'notes', 'revision_notes', 'completed_at', 'stock_skipped', 'deleted_by',
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderShippingFieldsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000125_add_shipping_fields_to_purchase_orders.php app/Models/PurchaseOrder.php tests/Feature/PurchaseOrderShippingFieldsTest.php
git commit -m "$(cat <<'EOF'
feat(po): kolom kurir + no_resi di purchase_orders

Kolom nullable untuk data pengiriman PO (diisi manual sekarang, siap
diisi API kurir/J&T nanti). Dasar fitur Cetak Dokumen.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Code128 barcode helper (SVG, zero-dep)

**Files:**
- Create: `app/Support/Barcode.php`
- Test: `tests/Unit/BarcodeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Support\Barcode::code128(string $value, int $module = 2, int $height = 60): string` — returns a complete `<svg …>…</svg>` element (black bars on transparent). Task 5's label uses it as `Barcode::code128($po->no_resi ?: $po->po_number)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/BarcodeTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Barcode;
use PHPUnit\Framework\TestCase;

class BarcodeTest extends TestCase
{
    public function test_menghasilkan_svg(): void
    {
        $svg = Barcode::code128('PO-123');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
    }

    public function test_deterministik_untuk_input_sama(): void
    {
        $this->assertSame(Barcode::code128('ABC123'), Barcode::code128('ABC123'));
    }

    public function test_input_berbeda_hasil_berbeda(): void
    {
        $this->assertNotSame(Barcode::code128('ABC123'), Barcode::code128('XYZ789'));
    }

    public function test_input_kosong_tidak_error(): void
    {
        $svg = Barcode::code128('');
        $this->assertStringStartsWith('<svg', $svg);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=BarcodeTest`
Expected: FAIL — class `App\Support\Barcode` not found.

- [ ] **Step 3: Write the helper**

Create `app/Support/Barcode.php`. The `PATTERNS` table below is the canonical Code 128 module-width table (symbols 0–106); copy it verbatim — each string is the bar/space width sequence for one symbol, starting with a bar:

```php
<?php

namespace App\Support;

/**
 * Encoder Code 128-B → SVG murni (zero-dependency). Dipakai untuk barcode
 * resi / No PO di label pengiriman — tak butuh paket eksternal.
 */
class Barcode
{
    /**
     * Pola lebar modul (bar,spasi,bar,…) untuk tiap simbol Code128 0..106.
     * Tabel baku Code 128; simbol 106 (stop) punya 7 elemen, sisanya 6.
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * SVG barcode Code128-B dari $value. $module = lebar 1 modul (px),
     * $height = tinggi bar (px). Mengembalikan elemen <svg> lengkap.
     */
    public static function code128(string $value, int $module = 2, int $height = 60): string
    {
        if ($value === '') {
            $value = '0';
        }

        $codes = [self::START_B];
        $sum = self::START_B;

        foreach (str_split($value) as $i => $ch) {
            $ord = ord($ch);
            // Code128-B mencakup ASCII 32..126; di luar itu → '?' (nilai 31).
            $val = ($ord >= 32 && $ord <= 126) ? $ord - 32 : 31;
            $codes[] = $val;
            $sum += $val * ($i + 1);
        }

        $codes[] = $sum % 103; // checksum
        $codes[] = self::STOP;

        $x = 0;
        $rects = '';
        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $j => $w) {
                $w = (int) $w * $module;
                if ($j % 2 === 0) { // elemen genap = bar hitam
                    $rects .= '<rect x="'.$x.'" y="0" width="'.$w.'" height="'.$height.'"/>';
                }
                $x += $w;
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$x.'" height="'.$height.'" '
            .'viewBox="0 0 '.$x.' '.$height.'" fill="#000">'.$rects.'</svg>';
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=BarcodeTest`
Expected: PASS (all 4 tests).

- [ ] **Step 5: Commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Support/Barcode.php tests/Unit/BarcodeTest.php
git commit -m "$(cat <<'EOF'
feat(support): helper Barcode Code128 → SVG (zero-dep)

Encoder Code128-B murni PHP untuk barcode resi/No PO di label
pengiriman. Tanpa paket eksternal.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: HQ sender settings (Pengirim)

**Files:**
- Modify: `app/Http/Controllers/SettingController.php:40-63` (add `sender` to the `index()` view payload) and add a `saveSender()` method
- Modify: `resources/views/settings/index.blade.php` (add a "Pengirim (HQ)" form)
- Modify: `routes/web.php:672` area (inside the `permission:system_settings` group)
- Test: `tests/Feature/SenderSettingsTest.php`

**Interfaces:**
- Consumes: `App\Models\AppSetting::put()` / `::get()` (already exists).
- Produces: four `app_settings` rows — keys `hq_sender_name`, `hq_sender_address`, `hq_sender_city`, `hq_sender_phone`. Route `settings.sender.save` (POST `/settings/sender`). Task 5 reads these keys.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SenderSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SenderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'Super', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_simpan_setelan_pengirim(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.sender.save'), [
                'hq_sender_name' => 'SKINKU HQ',
                'hq_sender_address' => 'Jl. Mawar 1',
                'hq_sender_city' => 'Surabaya',
                'hq_sender_phone' => '0811222333',
            ])->assertRedirect();

        $this->assertSame('SKINKU HQ', AppSetting::get('hq_sender_name'));
        $this->assertSame('Jl. Mawar 1', AppSetting::get('hq_sender_address'));
        $this->assertSame('Surabaya', AppSetting::get('hq_sender_city'));
        $this->assertSame('0811222333', AppSetting::get('hq_sender_phone'));
    }

    public function test_non_admin_tidak_boleh(): void
    {
        $reseller = User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r', 'email' => 'r@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($reseller)
            ->post(route('settings.sender.save'), ['hq_sender_name' => 'X'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=SenderSettingsTest`
Expected: FAIL — route `settings.sender.save` is not defined.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `Route::middleware('permission:system_settings')->group(function () {` block (near `settings.ai.save`, around line 672), add:

```php
        Route::post('/settings/sender', [SettingController::class, 'saveSender'])->name('settings.sender.save');
```

- [ ] **Step 4: Add `saveSender()` and pass `sender` to the view**

In `app/Http/Controllers/SettingController.php`, add a `sender` block to the array passed to `view('settings.index', [...])` in `index()`. Insert it after the `'ai' => [...]` block:

```php
            'sender' => [
                'name' => AppSetting::get('hq_sender_name', ''),
                'address' => AppSetting::get('hq_sender_address', ''),
                'city' => AppSetting::get('hq_sender_city', ''),
                'phone' => AppSetting::get('hq_sender_phone', ''),
            ],
```

Then add the method (place it right after `saveAi()`):

```php
    /**
     * Simpan identitas Pengirim (HQ) — dipakai di blok pengirim label
     * pengiriman PO. Bukan kredensial, hanya alamat/kontak gudang pusat.
     */
    public function saveSender(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hq_sender_name' => ['nullable', 'string', 'max:100'],
            'hq_sender_address' => ['nullable', 'string', 'max:255'],
            'hq_sender_city' => ['nullable', 'string', 'max:100'],
            'hq_sender_phone' => ['nullable', 'string', 'max:40'],
        ]);

        foreach ($data as $key => $value) {
            AppSetting::put($key, $value !== null ? trim($value) : null);
        }

        AuditService::log(action: 'save_sender_settings', targetType: 'app_setting', after: $data);

        return back()->with('status', 'Setelan Pengirim disimpan.');
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=SenderSettingsTest`
Expected: PASS (both tests).

- [ ] **Step 6: Add the form to the settings page**

In `resources/views/settings/index.blade.php`, add a card mirroring the existing AI/Komisi cards. Place it in the same column/section as the other settings cards:

```blade
    <div class="bg-white rounded-2xl border border-stone-200 p-6">
        <h3 class="text-sm font-bold text-stone-800">Pengirim (HQ)</h3>
        <p class="text-xs text-stone-500 mt-1">Identitas pengirim di label pengiriman PO (gudang pusat).</p>
        <form method="POST" action="{{ route('settings.sender.save') }}" class="mt-4 space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Nama</label>
                <input type="text" name="hq_sender_name" value="{{ $sender['name'] }}" maxlength="100"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" placeholder="SKINKU HQ">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Alamat</label>
                <input type="text" name="hq_sender_address" value="{{ $sender['address'] }}" maxlength="255"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" placeholder="Jl. ...">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-stone-600 mb-1">Kota</label>
                    <input type="text" name="hq_sender_city" value="{{ $sender['city'] }}" maxlength="100"
                           class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-stone-600 mb-1">No. HP</label>
                    <input type="text" name="hq_sender_phone" value="{{ $sender['phone'] }}" maxlength="40"
                           class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
            </div>
            <button type="submit" class="rounded-lg bg-stone-900 text-white text-sm font-semibold px-4 py-2">Simpan Pengirim</button>
        </form>
    </div>
```

- [ ] **Step 7: Verify the settings page renders**

Run: `/c/php83/php.exe artisan test --filter=SenderSettingsTest`
Expected: still PASS. (If the project has an existing settings-page render test, run it too: `/c/php83/php.exe artisan test --filter=Setting`.)

- [ ] **Step 8: Commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/SettingController.php resources/views/settings/index.blade.php routes/web.php tests/Feature/SenderSettingsTest.php
git commit -m "$(cat <<'EOF'
feat(settings): setelan Pengirim (HQ) untuk label pengiriman

Nama/alamat/kota/HP pengirim disimpan di app_settings, diedit di
Setelan Sistem. Dipakai blok pengirim di label cetak PO.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Save resi/kurir on the PO detail page

**Files:**
- Modify: `app/Http/Controllers/PurchaseOrderController.php` (add `saveResi()`)
- Modify: `routes/web.php:149-157` (inside the `permission:update_po_status` group)
- Modify: `resources/views/purchase_orders/show.blade.php` (add a resi/kurir form for staff)
- Test: `tests/Feature/PurchaseOrderResiTest.php`

**Interfaces:**
- Consumes: `purchase_orders.kurir` / `.no_resi` (Task 1).
- Produces: route `purchase-orders.resi` (POST `/purchase-orders/{purchaseOrder}/resi`) → `PurchaseOrderController::saveResi`. Saves `kurir` + `no_resi` on the PO.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderResiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderResiTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role), 'username' => $role,
            'email' => $role.'@skinku.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function po(User $owner): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-RESI-1', 'created_by' => $owner->id, 'user_id' => $owner->id,
            'status' => PurchaseOrder::STATUS_PROCESSING, 'total_amount' => 100_000, 'user_role' => $owner->role,
        ]);
    }

    public function test_staf_simpan_resi(): void
    {
        $admin = $this->make(User::ROLE_ADMIN);
        $po = $this->po($admin);

        $this->actingAs($admin)
            ->post(route('purchase-orders.resi', $po), ['kurir' => 'J&T Express', 'no_resi' => 'JT999'])
            ->assertRedirect();

        $fresh = $po->fresh();
        $this->assertSame('J&T Express', $fresh->kurir);
        $this->assertSame('JT999', $fresh->no_resi);
    }

    public function test_mitra_tidak_boleh_simpan_resi(): void
    {
        $reseller = $this->make(User::ROLE_RESELLER);
        $po = $this->po($reseller);

        $this->actingAs($reseller)
            ->post(route('purchase-orders.resi', $po), ['kurir' => 'X', 'no_resi' => 'Y'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderResiTest`
Expected: FAIL — route `purchase-orders.resi` is not defined.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `Route::middleware('permission:update_po_status')->group(function () {` block (around lines 149–157), add:

```php
        Route::post('/purchase-orders/{purchaseOrder}/resi', [PurchaseOrderController::class, 'saveResi'])->name('purchase-orders.resi');
```

- [ ] **Step 4: Add `saveResi()`**

In `app/Http/Controllers/PurchaseOrderController.php`, add the method after `setShipping()` (around line 417). `AuditService`, `RedirectResponse`, `Request`, `PurchaseOrder` are already imported:

```php
    /**
     * Simpan kurir + no resi PO (input manual staf; nanti bisa diisi API kurir).
     * Gate route = update_po_status (admin/gudang), jadi tak perlu cek ulang.
     */
    public function saveResi(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'kurir' => ['nullable', 'string', 'max:50'],
            'no_resi' => ['nullable', 'string', 'max:64'],
        ]);

        $purchaseOrder->update([
            'kurir' => isset($data['kurir']) ? trim($data['kurir']) : null,
            'no_resi' => isset($data['no_resi']) ? trim($data['no_resi']) : null,
        ]);

        AuditService::log(action: 'update_po_resi', targetType: 'purchase_order', targetId: $purchaseOrder->id, after: $data);

        return back()->with('status', 'Resi & kurir disimpan.');
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderResiTest`
Expected: PASS (both tests).

- [ ] **Step 6: Add the resi form to the detail page**

In `resources/views/purchase_orders/show.blade.php`, add a staff-only card. Put it in the right-hand column (`lg:col-span-1` area) alongside the other action cards — search for where the shipping/status actions are and place it near them. It must be wrapped so only staff see it:

```blade
    @if($u->canDo('update_po_status'))
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h4 class="text-sm font-bold text-stone-800">Kurir & Resi</h4>
        <form method="POST" action="{{ route('purchase-orders.resi', $po) }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Kurir</label>
                <input type="text" name="kurir" value="{{ $po->kurir }}" maxlength="50"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" placeholder="mis. J&T Express">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">No. Resi</label>
                <input type="text" name="no_resi" value="{{ $po->no_resi }}" maxlength="64"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" placeholder="No. resi / AWB">
            </div>
            <button type="submit" class="rounded-lg bg-stone-900 text-white text-sm font-semibold px-4 py-2">Simpan Resi</button>
        </form>
    </div>
    @endif
```

- [ ] **Step 7: Run the full PO test group to confirm nothing broke**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrder`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/PurchaseOrderController.php routes/web.php resources/views/purchase_orders/show.blade.php tests/Feature/PurchaseOrderResiTest.php
git commit -m "$(cat <<'EOF'
feat(po): form simpan kurir + no resi di detail PO (staf)

Staf HQ/gudang isi kurir & no resi manual. Gate update_po_status,
mitra tak bisa. Dipakai label cetak.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Print route + standalone print view

**Files:**
- Modify: `app/Http/Controllers/PurchaseOrderController.php` (add `use App\Models\AppSetting;` and a `print()` method)
- Modify: `routes/web.php:149-157` (inside the `permission:update_po_status` group)
- Create: `resources/views/purchase_orders/print.blade.php`
- Test: `tests/Feature/PurchaseOrderPrintTest.php`

**Interfaces:**
- Consumes: `Barcode::code128()` (Task 2); `purchase_orders.kurir` / `.no_resi` (Task 1); `hq_sender_*` settings (Task 3).
- Produces: route `purchase-orders.print` (GET `/purchase-orders/{purchaseOrder}/cetak?docs=…&size=…`) → `PurchaseOrderController::print`, rendering `purchase_orders.print`. Task 6's dialog links here.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderPrintTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role).' Name', 'username' => $role,
            'email' => $role.'@skinku.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function poWithItem(User $mitra): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-PRINT-1', 'created_by' => $mitra->id, 'user_id' => $mitra->id,
            'status' => PurchaseOrder::STATUS_PROCESSING, 'subtotal' => 100_000, 'discount' => 0,
            'shipping_cost' => 5_000, 'total_amount' => 105_000, 'user_role' => $mitra->role,
            'shipping_address' => 'Jl. Melati 2, Malang',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_name' => 'Serum A', 'sku' => 'SRM-A',
            'qty' => 3, 'unit_price' => 20_000, 'total_price' => 60_000,
        ]);

        return $po;
    }

    public function test_staf_cetak_faktur_dan_label(): void
    {
        AppSetting::put('hq_sender_name', 'SKINKU PUSAT');
        $admin = $this->make(User::ROLE_ADMIN);
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra);

        $this->actingAs($admin)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'label,faktur', 'size' => 'A6']))
            ->assertOk()
            ->assertSee('SKINKU PUSAT')          // pengirim HQ
            ->assertSee('RESELLER Name')          // penerima mitra
            ->assertSee('PO-PRINT-1')             // no PO
            ->assertSee('Serum A')                // item (faktur)
            ->assertSee('105.000');               // total (faktur)
    }

    public function test_docs_ngawur_fallback_label(): void
    {
        $admin = $this->make(User::ROLE_ADMIN);
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra);

        $this->actingAs($admin)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'xxx']))
            ->assertOk()
            ->assertSee('PO-PRINT-1');
    }

    public function test_mitra_tidak_boleh_cetak(): void
    {
        $mitra = $this->make(User::ROLE_RESELLER);
        $po = $this->poWithItem($mitra);

        $this->actingAs($mitra)
            ->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'docs' => 'label']))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderPrintTest`
Expected: FAIL — route `purchase-orders.print` is not defined.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `Route::middleware('permission:update_po_status')->group(function () {` block (same block as Task 4), add:

```php
        Route::get('/purchase-orders/{purchaseOrder}/cetak', [PurchaseOrderController::class, 'print'])->name('purchase-orders.print');
```

- [ ] **Step 4: Add the import and `print()` method**

In `app/Http/Controllers/PurchaseOrderController.php`, add to the imports (after `use App\Models\PoReturnItem;` block, keep alphabetical-ish grouping):

```php
use App\Models\AppSetting;
```

Then add the method after `saveResi()`:

```php
    /**
     * Halaman cetak dokumen PO (standalone, browser-print). ?docs= subset dari
     * label,packing,faktur (default label); ?size= A6|A4 (default A6). Gate route
     * = update_po_status (staf) — mitra tak boleh cetak label HQ.
     */
    public function print(Request $request, PurchaseOrder $purchaseOrder)
    {
        $allowed = ['label', 'packing', 'faktur'];
        $docs = collect(explode(',', (string) $request->query('docs')))
            ->map(fn ($d) => trim($d))
            ->filter(fn ($d) => in_array($d, $allowed, true))
            ->values()->all();
        if ($docs === []) {
            $docs = ['label'];
        }

        $size = strtoupper((string) $request->query('size')) === 'A4' ? 'A4' : 'A6';

        $purchaseOrder->load('items', 'user');

        $sender = [
            'name' => AppSetting::get('hq_sender_name', config('app.name')),
            'address' => AppSetting::get('hq_sender_address', ''),
            'city' => AppSetting::get('hq_sender_city', ''),
            'phone' => AppSetting::get('hq_sender_phone', ''),
        ];

        return view('purchase_orders.print', [
            'po' => $purchaseOrder,
            'docs' => $docs,
            'size' => $size,
            'sender' => $sender,
        ]);
    }
```

- [ ] **Step 5: Create the print view**

Create `resources/views/purchase_orders/print.blade.php`. It is standalone (its own `<html>`/`<head>`, no `@extends`). It prints only the selected docs, sets `@page` size, and auto-opens the print dialog:

```blade
@php
    use App\Support\Barcode;
    $totalQty = $po->items->sum('qty');
    $recipientName = $po->company_name ?: ($po->user->fullname ?? '-');
    $recipientAddr = $po->shipping_address ?: ($po->user->address ?? '-');
    $recipientCity = $po->user->city ?? '';
    $recipientPhone = $po->user->phone ?? '';
    $barcodeValue = $po->no_resi ?: $po->po_number;
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $tanggal = $po->orderDate()->format('d M Y');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak {{ $po->po_number }}</title>
    <style>
        @page { size: {{ $size }}; margin: 4mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; font-size: 11px; }
        .doc-page { page-break-after: always; padding: 2mm; }
        .doc-page:last-child { page-break-after: auto; }
        .bd { border: 1px solid #000; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .muted { color: #333; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        .big { font-size: 15px; font-weight: 700; }
        .sec { padding: 6px; border-bottom: 1px solid #000; }
        .sec:last-child { border-bottom: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 3px 4px; border-bottom: 1px solid #ccc; }
        th { font-size: 9px; text-transform: uppercase; color: #333; }
        td.num, th.num { text-align: right; }
        .barcode svg { width: 100%; height: 46px; }
        .totrow { display: flex; justify-content: space-between; padding: 2px 4px; }
        .totrow.grand { font-weight: 700; font-size: 13px; border-top: 1px solid #000; margin-top: 2px; padding-top: 4px; }
        h2.doc-title { font-size: 13px; margin: 0 0 4px; }
        @media screen { body { background: #eee; } .doc-page { background: #fff; margin: 8px auto; max-width: 480px; box-shadow: 0 1px 4px rgba(0,0,0,.2); } }
    </style>
</head>
<body>

@if(in_array('label', $docs, true))
    <div class="doc-page">
        <div class="bd">
            <div class="sec row">
                <div><span class="muted">Kurir</span><div class="big">{{ $po->kurir ?: '—' }}</div></div>
                <div style="text-align:right"><span class="muted">No. PO</span><div>{{ $po->po_number }}</div><div class="muted">{{ $tanggal }}</div></div>
            </div>
            <div class="sec">
                <span class="muted">Pengirim</span>
                <div><strong>{{ $sender['name'] ?: '—' }}</strong></div>
                <div>{{ $sender['address'] }}{{ $sender['city'] ? ', '.$sender['city'] : '' }}</div>
                <div>{{ $sender['phone'] }}</div>
            </div>
            <div class="sec">
                <span class="muted">Penerima</span>
                <div class="big">{{ $recipientName }}</div>
                <div>{{ $recipientAddr }}{{ $recipientCity ? ', '.$recipientCity : '' }}</div>
                <div>{{ $recipientPhone }}</div>
            </div>
            <div class="sec">
                <div class="barcode">{!! Barcode::code128($barcodeValue) !!}</div>
                <div style="text-align:center; font-family: monospace; letter-spacing: 2px;">{{ $barcodeValue }}</div>
            </div>
            <div class="sec row">
                <div><span class="muted">Jumlah</span> <strong>{{ $totalQty }} pcs</strong></div>
            </div>
        </div>
    </div>
@endif

@if(in_array('packing', $docs, true))
    <div class="doc-page">
        <h2 class="doc-title">Daftar Pengemasan</h2>
        <div class="row" style="margin-bottom:4px">
            <div><span class="muted">No. PO</span> {{ $po->po_number }}</div>
            <div><span class="muted">Tanggal</span> {{ $tanggal }}</div>
        </div>
        <div style="margin-bottom:4px"><span class="muted">Mitra</span> {{ $recipientName }}</div>
        <table>
            <thead><tr><th>Produk</th><th>SKU</th><th class="num">Qty</th></tr></thead>
            <tbody>
                @foreach($po->items as $it)
                    <tr><td>{{ $it->product_name }}</td><td>{{ $it->sku }}</td><td class="num">{{ $it->qty }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="2" class="num"><strong>Total</strong></td><td class="num"><strong>{{ $totalQty }}</strong></td></tr></tfoot>
        </table>
    </div>
@endif

@if(in_array('faktur', $docs, true))
    <div class="doc-page">
        <div class="row" style="margin-bottom:6px">
            <div><strong>{{ $sender['name'] ?: config('app.name') }}</strong><div class="muted">{{ $sender['address'] }}</div></div>
            <div style="text-align:right"><h2 class="doc-title">FAKTUR / NOTA</h2><div>{{ $po->po_number }}</div><div class="muted">{{ $tanggal }}</div></div>
        </div>
        <div style="margin-bottom:6px"><span class="muted">Kepada</span> <strong>{{ $recipientName }}</strong><div>{{ $recipientAddr }}</div></div>
        <table>
            <thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
            <tbody>
                @foreach($po->items as $it)
                    <tr>
                        <td>{{ $it->product_name }}</td>
                        <td class="num">{{ $it->qty }}</td>
                        <td class="num">{{ $rp($it->unit_price) }}</td>
                        <td class="num">{{ $rp($it->total_price) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="margin-top:6px">
            <div class="totrow"><span>Subtotal</span><span>{{ $rp($po->subtotal) }}</span></div>
            <div class="totrow"><span>Diskon</span><span>{{ $rp($po->discount) }}</span></div>
            <div class="totrow"><span>Ongkir</span><span>{{ $rp($po->shipping_cost) }}</span></div>
            <div class="totrow grand"><span>Total</span><span>{{ $rp($po->total_amount) }}</span></div>
            <div class="totrow"><span class="muted">Status Bayar</span><span>{{ $po->isPaid() ? 'LUNAS' : 'BELUM LUNAS' }}</span></div>
        </div>
    </div>
@endif

<script>window.onload = function () { window.print(); };</script>
</body>
</html>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderPrintTest`
Expected: PASS (all 3 tests).

- [ ] **Step 7: Commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/PurchaseOrderController.php routes/web.php resources/views/purchase_orders/print.blade.php tests/Feature/PurchaseOrderPrintTest.php
git commit -m "$(cat <<'EOF'
feat(po): halaman cetak dokumen PO (label/packing/faktur)

View cetak standalone (browser-print, @page A6/A4). Label pakai barcode
Code128 + pengirim HQ + penerima mitra; packing list; faktur A4.
Gate update_po_status. Auto window.print().

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: "Cetak Dokumen" button + dialog on the PO detail page

**Files:**
- Modify: `resources/views/purchase_orders/show.blade.php` (add the button, `<dialog>`, and script)
- Test: `tests/Feature/PurchaseOrderShowCetakButtonTest.php`

**Interfaces:**
- Consumes: route `purchase-orders.print` (Task 5).
- Produces: nothing consumed by later tasks (final UI task).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseOrderShowCetakButtonTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderShowCetakButtonTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role), 'username' => $role,
            'email' => $role.'@skinku.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function po(User $owner): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-BTN-1', 'created_by' => $owner->id, 'user_id' => $owner->id,
            'status' => PurchaseOrder::STATUS_PROCESSING, 'total_amount' => 100_000, 'user_role' => $owner->role,
        ]);
    }

    public function test_staf_lihat_tombol_cetak(): void
    {
        $admin = $this->make(User::ROLE_ADMIN);
        $po = $this->po($admin);

        $this->actingAs($admin)->get(route('purchase-orders.show', $po))
            ->assertOk()->assertSee('Cetak Dokumen');
    }

    public function test_mitra_tidak_lihat_tombol_cetak(): void
    {
        $reseller = $this->make(User::ROLE_RESELLER);
        $po = $this->po($reseller);

        $this->actingAs($reseller)->get(route('purchase-orders.show', $po))
            ->assertOk()->assertDontSee('Cetak Dokumen');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderShowCetakButtonTest`
Expected: FAIL — `test_staf_lihat_tombol_cetak` fails (text "Cetak Dokumen" not present).

- [ ] **Step 3: Add the button + dialog to the detail page**

In `resources/views/purchase_orders/show.blade.php`, add this staff-only block. Place it near the top action area of the page (e.g. right after the header card, inside the main column, or beside the Kurir & Resi card from Task 4). It uses a native `<dialog>` (zero-dep) and opens the print route in a new tab:

```blade
    @if($u->canDo('update_po_status'))
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <button type="button" onclick="document.getElementById('cetakDialog').showModal()"
                class="inline-flex items-center gap-2 rounded-lg bg-stone-900 text-white text-sm font-semibold px-4 py-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Cetak Dokumen
        </button>

        <dialog id="cetakDialog" class="rounded-2xl p-0 backdrop:bg-black/40" style="border:none; max-width:340px;">
            <form method="dialog" class="p-5">
                <h4 class="text-sm font-bold text-stone-800 mb-3">Cetak Dokumen PO</h4>
                <label class="flex items-center gap-2 text-sm mb-2"><input type="checkbox" name="doc" value="label" checked> Label Pengiriman</label>
                <label class="flex items-center gap-2 text-sm mb-2"><input type="checkbox" name="doc" value="packing" checked> Daftar Pengemasan</label>
                <label class="flex items-center gap-2 text-sm mb-3"><input type="checkbox" name="doc" value="faktur"> Faktur / Nota</label>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Ukuran</label>
                <select id="cetakSize" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm mb-4">
                    <option value="A6" selected>A6 (label)</option>
                    <option value="A4">A4</option>
                </select>
                <div class="flex justify-end gap-2">
                    <button value="cancel" class="rounded-lg border border-stone-300 text-sm font-semibold px-4 py-2">Batal</button>
                    <button type="button" onclick="cetakDokumen({{ $po->id }})" class="rounded-lg bg-stone-900 text-white text-sm font-semibold px-4 py-2">Cetak</button>
                </div>
            </form>
        </dialog>
    </div>

    @push('scripts')
    <script>
        function cetakDokumen(poId) {
            var dlg = document.getElementById('cetakDialog');
            var docs = Array.from(dlg.querySelectorAll('input[name="doc"]:checked')).map(function (c) { return c.value; });
            if (docs.length === 0) docs = ['label'];
            var size = document.getElementById('cetakSize').value;
            var url = '{{ url('purchase-orders') }}/' + poId + '/cetak?docs=' + docs.join(',') + '&size=' + size;
            window.open(url, '_blank');
            dlg.close();
        }
    </script>
    @endpush
    @endif
```

> **Note on `@push('scripts')`:** the app layout already renders `@stack('scripts')` (`resources/views/layouts/app.blade.php:600`), so the `@push('scripts') … @endpush` block works as written — no change needed.

- [ ] **Step 4: Run the test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrderShowCetakButtonTest`
Expected: PASS (both tests).

- [ ] **Step 5: Run the full PO group + confirm no regressions**

Run: `/c/php83/php.exe artisan test --filter=PurchaseOrder`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add resources/views/purchase_orders/show.blade.php tests/Feature/PurchaseOrderShowCetakButtonTest.php
git commit -m "$(cat <<'EOF'
feat(po): tombol + dialog Cetak Dokumen di detail PO

Dialog native pilih dokumen (label/packing/faktur) + ukuran (A6/A4),
buka halaman cetak di tab baru. Gate update_po_status.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Final Verification

- [ ] Run the whole suite: `/c/php83/php.exe artisan test`. Expected: all green.
- [ ] Run `/c/php83/php.exe vendor/bin/pint --dirty` once more; commit any formatting.
- [ ] Manual smoke (optional, local): open a PO detail as admin → fill Kurir + No Resi → Simpan → click "Cetak Dokumen" → tick Label + Faktur → Cetak → new tab shows the documents and the browser print dialog opens; the barcode renders; A6 is selected but you can change size/scale in the dialog.

## Deploy Notes (for the user, after merge)

```bash
cd ~/domains/skinku.id/laravel-b2b && git pull && php artisan migrate --force && php artisan optimize:clear
```

Then in **Setelan Sistem → Pengirim (HQ)** fill nama/alamat/kota/HP so the label's sender block is correct.
