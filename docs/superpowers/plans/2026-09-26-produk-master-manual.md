# Produk Master E-commerce — Model MANUAL (ala Desty) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ubah halaman Produk Master E-commerce jadi katalog manual ala Desty: admin buat master via "Tambah Produk Baru", tautkan ke listing TikTok/Shopee yang sudah ada untuk sinkron stok; buang semua jalur auto-buat-master.

**Architecture:** Bangun DI ATAS `MarketplaceMasterService` yang sudah live. Mesin sinkron (effectiveStock/Price, setMaster*, push*, applyOrderDelta, tautkanListing, resolve) dipakai ulang tanpa diubah perilakunya kecuali: `upsertListing` berhenti auto-buat master. Tambah CRUD manual (create/store/edit/update/duplicate) + tulis ulang view index jadi kolom Desty. Buang metode/route/tombol auto (siapkan/seed/masterize/gabung/push-per-baris/uploadFoto).

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind (util classes), PHPUnit class-style. Reuse `ImageService`/`HasFiles`.

## Global Constraints

- **Zero-dependency**: tak ada paket composer/npm baru. Reuse `ImageService` + `HasFiles` (koleksi `MarketplaceMaster::MASTER_IMAGE`).
- **HQ TAK disentuh**: tak baca/tulis `products.hq_stock`, `stock_movements`, `InventoryService`. Semua aksi master hanya kena `marketplace_masters`/`marketplace_master_channels`/`marketplace_listings`.
- **Izin**: semua route dalam grup `Route::middleware('permission:manage_marketplace_stock')`. Non-izin → 403.
- **Blade**: JANGAN `@json([...])` literal. Form POST + `@csrf`; hapus via `@method('DELETE')`; update via `@method('PUT')`; picker native `<select>`; form foto `enctype="multipart/form-data"`.
- **Tanpa migrasi baru**: skema `marketplace_masters` sudah lengkap (`master_sku,name,name_key,is_bundle,image_url,product_id,base_stock,base_price,seeded_at`).
- **Runner tes**: `C:/php83/php.exe artisan test`. **Format**: `C:/php83/php.exe vendor/bin/pint --dirty` sebelum commit tiap task.
- **Tes**: PHPUnit class-style (NO Pest, NO ProductFactory). `User`/`Product` via `::create()`. API pakai `Http::fake`. Foto pakai `Storage::fake('public')` + `UploadedFile::fake()->image('a.jpg')`.
- **Commit**: akhiri pesan dengan `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

Helper admin untuk tes (pakai di semua feature test):
```php
private function admin(): \App\Models\User
{
    return \App\Models\User::create([
        'name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(),
        'email' => uniqid().'@t.test', 'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
        'role' => \App\Models\User::ROLE_SUPER_ADMIN, 'status' => \App\Models\User::STATUS_ACTIVE,
    ]);
}
private function reseller(): \App\Models\User
{
    return \App\Models\User::create([
        'name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(),
        'email' => uniqid().'@t.test', 'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
        'role' => \App\Models\User::ROLE_RESELLER, 'status' => \App\Models\User::STATUS_ACTIVE,
    ]);
}
```

---

### Task 1: CRUD manual (backend + form view + duplicateMaster) — hanya MENAMBAH

Tak menghapus apa pun (halaman & route lama tetap jalan). Tambah: `duplicateMaster` di service, method controller create/store/edit/update/duplicate + validateMaster, route baru, view form.

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php` (tambah `duplicateMaster`)
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (tambah create/store/edit/update/duplicate + validateMaster; tambah `use Illuminate\Contracts\View\View;` sudah ada)
- Modify: `routes/web.php` (grup marketplace-stock)
- Create: `resources/views/marketplace-stock/form.blade.php`
- Test: `tests/Feature/MarketplaceMaster/CreateEditMasterTest.php`

**Interfaces:**
- Produces: `MarketplaceMasterService::duplicateMaster(MarketplaceMaster $m): MarketplaceMaster`; routes `marketplace-stock.create|store|edit|update|duplikat`; view `marketplace-stock.form` menerima `$master` (MarketplaceMaster, bisa `new` kosong).
- Consumes: `setMasterStock(MarketplaceMaster,int)`, `setMasterPrice(MarketplaceMaster,float)`, `pushMaster(MarketplaceMaster)`, `ImageService::attach(Model,UploadedFile,string)`, `MarketplaceMaster::normalizeName(string)`, konstanta `MarketplaceMaster::MASTER_IMAGE`.

- [ ] **Step 1: Tulis tes CreateEditMasterTest (gagal dulu)**

Buat `tests/Feature/MarketplaceMaster/CreateEditMasterTest.php`:
```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreateEditMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function reseller(): User
    {
        return User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_form_tambah_render(): void
    {
        $this->actingAs($this->admin())->get(route('marketplace-stock.create'))
            ->assertOk()->assertSee('Master SKU')->assertSee('Nama Produk');
    }

    public function test_store_buat_master_manual(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Hana Glow Face Mist 30ml', 'master_sku' => 'FM-1',
            'price' => '35000', 'stock' => '12', 'is_bundle' => '0',
        ])->assertRedirect(route('marketplace-stock.index'))->assertSessionHas('status');

        $m = MarketplaceMaster::where('master_sku', 'FM-1')->first();
        $this->assertNotNull($m);
        $this->assertSame('Hana Glow Face Mist 30ml', $m->name);
        $this->assertSame('hana glow face mist 30ml', $m->name_key);
        $this->assertSame(12, $m->base_stock);
        $this->assertSame('35000.00', (string) $m->base_price);
        $this->assertFalse($m->is_bundle);
        $this->assertNotNull($m->seeded_at); // di-set oleh setMasterStock (guard applyOrderDelta)
    }

    public function test_store_tandai_bundle_dan_stok_harga_boleh_kosong(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Paket Bundling Soap', 'master_sku' => 'SOAP3', 'is_bundle' => '1',
        ])->assertRedirect();
        $m = MarketplaceMaster::where('master_sku', 'SOAP3')->first();
        $this->assertTrue($m->is_bundle);
        $this->assertNull($m->base_stock);
        $this->assertNull($m->base_price);
    }

    public function test_store_dengan_foto(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Body Wash', 'master_sku' => 'BW-1', 'foto' => UploadedFile::fake()->image('bw.jpg'),
        ])->assertRedirect();
        $m = MarketplaceMaster::where('master_sku', 'BW-1')->first();
        $this->assertNotNull($m->imageUrl());
    }

    public function test_store_validasi_wajib(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), ['name' => ''])
            ->assertSessionHasErrors(['name', 'master_sku']);
        $this->assertSame(0, MarketplaceMaster::count());
    }

    public function test_update_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'X Baru', 'master_sku' => 'X-2', 'price' => '5000', 'stock' => '3', 'is_bundle' => '1',
        ])->assertRedirect(route('marketplace-stock.index'));
        $m->refresh();
        $this->assertSame('X Baru', $m->name);
        $this->assertSame('x baru', $m->name_key);
        $this->assertSame('X-2', $m->master_sku);
        $this->assertTrue($m->is_bundle);
        $this->assertSame(3, $m->base_stock);
    }

    public function test_duplicate_master_mulai_bersih(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A-1', 'name' => 'A', 'name_key' => 'a', 'is_bundle' => true, 'base_price' => 9000, 'base_stock' => 50]);
        \App\Models\MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A-1', 'item_id' => 'P1', 'master_id' => $m->id]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.duplikat', $m))->assertRedirect();

        $copy = MarketplaceMaster::where('master_sku', 'A-1-COPY')->first();
        $this->assertNotNull($copy);
        $this->assertSame('A (copy)', $copy->name);
        $this->assertTrue($copy->is_bundle);
        $this->assertSame('9000.00', (string) $copy->base_price);
        $this->assertNull($copy->base_stock);            // stok mulai bersih
        $this->assertSame(0, $copy->listings()->count()); // tanpa listing
        $this->assertSame(1, $m->listings()->count());    // asli tak terganggu
    }

    public function test_hq_tak_tersentuh_saat_buat_master(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('stock_movements')->count();
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), ['name' => 'Z', 'master_sku' => 'Z-1', 'stock' => '10']);
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('stock_movements')->count());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $r = $this->reseller();
        $this->actingAs($r)->get(route('marketplace-stock.create'))->assertForbidden();
        $this->actingAs($r)->post(route('marketplace-stock.store'), ['name' => 'x', 'master_sku' => 'x'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan tes → merah** (`C:/php83/php.exe artisan test --filter=CreateEditMasterTest`). Route belum ada.

- [ ] **Step 3: Tambah `duplicateMaster` di service**

Di `app/Services/MarketplaceMasterService.php`, setelah method `mergeMaster` (sekitar baris 55), tambah:
```php
    /** Gandakan master jadi baris baru (SKU "-COPY", nama "(copy)") tanpa listing/override/foto/stok — mulai bersih. */
    public function duplicateMaster(MarketplaceMaster $m): MarketplaceMaster
    {
        $name = $m->name.' (copy)';

        return MarketplaceMaster::create([
            'master_sku' => $m->master_sku.'-COPY',
            'name' => $name,
            'name_key' => MarketplaceMaster::normalizeName($name),
            'is_bundle' => $m->is_bundle,
            'base_price' => $m->base_price,
        ]);
    }
```

- [ ] **Step 4: Tambah method controller**

Di `app/Http/Controllers/MarketplaceStockController.php`, tambah method-method ini (mis. setelah `index`/`channel`). Pastikan `use App\Services\ImageService;` sudah ada (SUDAH, baris 7) & `use Illuminate\View\View;` (SUDAH):
```php
    public function create(): View
    {
        return view('marketplace-stock.form', ['master' => new MarketplaceMaster]);
    }

    public function store(Request $r, ImageService $img, MarketplaceMasterService $svc): RedirectResponse
    {
        $this->validateMaster($r);
        $master = MarketplaceMaster::create([
            'master_sku' => $r->master_sku,
            'name' => $r->name,
            'name_key' => MarketplaceMaster::normalizeName($r->name),
            'is_bundle' => $r->boolean('is_bundle'),
        ]);
        $this->applyMasterInputs($r, $master, $img, $svc);

        return redirect()->route('marketplace-stock.index')->with('status', "Produk master \"{$master->name}\" dibuat.");
    }

    public function edit(MarketplaceMaster $master): View
    {
        return view('marketplace-stock.form', ['master' => $master]);
    }

    public function update(Request $r, MarketplaceMaster $master, ImageService $img, MarketplaceMasterService $svc): RedirectResponse
    {
        $this->validateMaster($r);
        $master->update([
            'master_sku' => $r->master_sku,
            'name' => $r->name,
            'name_key' => MarketplaceMaster::normalizeName($r->name),
            'is_bundle' => $r->boolean('is_bundle'),
        ]);
        $this->applyMasterInputs($r, $master, $img, $svc);
        $svc->pushMaster($master);

        return redirect()->route('marketplace-stock.index')->with('status', "Produk master \"{$master->name}\" diperbarui.");
    }

    public function duplicate(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $copy = $svc->duplicateMaster($master);

        return redirect()->route('marketplace-stock.edit', $copy)->with('status', "Digandakan dari \"{$master->name}\". Sesuaikan lalu simpan.");
    }

    private function validateMaster(Request $r): void
    {
        $r->validate([
            'name' => ['required', 'string', 'max:255'],
            'master_sku' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'is_bundle' => ['nullable', 'boolean'],
            'foto' => ['nullable', 'image', 'max:5120'],
        ]);
    }

    /** Set harga/stok (via setter supaya seeded_at ke-set) + attach foto bila di-upload. */
    private function applyMasterInputs(Request $r, MarketplaceMaster $master, ImageService $img, MarketplaceMasterService $svc): void
    {
        if ($r->filled('price')) {
            $svc->setMasterPrice($master, (float) $r->price);
        }
        if ($r->filled('stock')) {
            $svc->setMasterStock($master, (int) $r->stock);
        }
        if ($r->hasFile('foto')) {
            $img->attach($master, $r->file('foto'), MarketplaceMaster::MASTER_IMAGE);
        }
    }
```

- [ ] **Step 5: Tambah route** (di `routes/web.php`, dalam grup `permission:manage_marketplace_stock`, LETAKKAN `create`/`store` sebelum route `{channel}` bukan keharusan karena `{channel}` di-`whereIn('tiktok','shopee')`, tapi taruh setelah `index` biar rapi):
```php
        Route::get('/marketplace-stock/create', [MarketplaceStockController::class, 'create'])->name('marketplace-stock.create');
        Route::post('/marketplace-stock', [MarketplaceStockController::class, 'store'])->name('marketplace-stock.store');
        Route::get('/marketplace-stock/master/{master}/edit', [MarketplaceStockController::class, 'edit'])->name('marketplace-stock.edit');
        Route::put('/marketplace-stock/master/{master}', [MarketplaceStockController::class, 'update'])->name('marketplace-stock.update');
        Route::post('/marketplace-stock/master/{master}/duplikat', [MarketplaceStockController::class, 'duplicate'])->name('marketplace-stock.duplikat');
```

- [ ] **Step 6: Buat view form** `resources/views/marketplace-stock/form.blade.php`:
```blade
@extends('layouts.app')
@section('title', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Master')
@section('heading', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Baru')
@section('content')
<div class="max-w-2xl">
    @if($errors->any())<div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif
    <form method="POST" action="{{ $master->exists ? route('marketplace-stock.update', $master) : route('marketplace-stock.store') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
        @csrf
        @if($master->exists)@method('PUT')@endif
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Nama Produk</label>
            <input type="text" name="name" value="{{ old('name', $master->name) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
        </div>
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Master SKU</label>
            <input type="text" name="master_sku" value="{{ old('master_sku', $master->master_sku) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Harga</label>
                <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $master->base_price) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Stok</label>
                <input type="number" min="0" name="stock" value="{{ old('stock', $master->base_stock) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Tipe</label>
            <select name="is_bundle" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                <option value="0" @selected(! old('is_bundle', $master->is_bundle))>Satuan</option>
                <option value="1" @selected(old('is_bundle', $master->is_bundle))>Bundle</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Foto</label>
            @if($master->imageUrl())<img src="{{ $master->imageUrl() }}" alt="" class="w-16 h-16 rounded-lg object-cover border border-stone-200 mb-2">@endif
            <input type="file" name="foto" accept="image/*" class="block text-sm">
            <p class="text-xs text-stone-400 mt-1">JPG/PNG, maks 5MB. Kosongkan bila tak ganti.</p>
        </div>
        <div class="flex gap-2 pt-2">
            <button class="px-5 py-2 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">Simpan</button>
            <a href="{{ route('marketplace-stock.index') }}" class="px-5 py-2 bg-stone-100 text-stone-600 rounded-lg hover:bg-stone-200">Batal</a>
        </div>
    </form>
</div>
@endsection
```

- [ ] **Step 7: `C:/php83/php.exe artisan test --filter=CreateEditMasterTest` → hijau.** Lalu `C:/php83/php.exe vendor/bin/pint --dirty`.

- [ ] **Step 8: Jalankan suite marketplace penuh** (`--filter="MarketplaceMaster|MarketplaceStock"`) pastikan tak ada regresi. Commit:
```
feat(marketplace-master): CRUD manual produk master (tambah/ubah/duplikat) + form
```

---

### Task 2: Tulis ulang halaman index jadi katalog Desty + tautkan picker

Ganti view index + `index()` controller ke kolom Desty (Foto/Nama/Master SKU/Harga/Stok/Produk Terkait/Toko Terkait/Atur), tab, empty-state, menu Atur (Ubah/Duplikat/Tambah ke Marketplace/Bundle/Hapus). Buang helper `channelSummary`/`mastersForTab` (tak dipakai lagi). Route lama (siapkan/seed/foto/gabung/push) MASIH ADA (dihapus di Task 3) tapi view baru TAK memakainya.

**Files:**
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (`index()`, hapus `channelSummary`, `mastersForTab`)
- Modify: `resources/views/marketplace-stock/index.blade.php` (tulis ulang)
- Test: `tests/Feature/MarketplaceMaster/CatalogPageTest.php` (tulis ulang isinya)

**Interfaces:**
- Consumes: route `marketplace-stock.create|edit|duplikat|tautkan|push-all|resolve|master.bundle|master.hapus|master.stok|master.harga`; relasi `MarketplaceMaster::listings` (channel), `MarketplaceMaster::imageUrl()`.
- Produces: view `index` menerima `$tab, $masters (Collection<MarketplaceMaster> with listings), $counts (array semua/satuan/bundle), $unlinkedListings (Collection id/channel/seller_sku/title), $unlinkedCount (int)`.

- [ ] **Step 1: Tulis ulang `tests/Feature/MarketplaceMaster/CatalogPageTest.php`** (ganti seluruh isi):
```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_empty_state_saat_belum_ada_master(): void
    {
        $this->actingAs($this->admin())->get(route('marketplace-stock.index'))
            ->assertOk()
            ->assertSee('Belum ada produk master')
            ->assertSee('Tambah Produk Baru');
    }

    public function test_kolom_desty_dan_baris_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Hana Glow', 'name_key' => 'hana glow', 'base_price' => 35000, 'base_stock' => 9]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'master_id' => $m->id]);
        MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'FM-1S', 'item_id' => 'P2', 'master_id' => $m->id]);

        $res = $this->actingAs($this->admin())->get(route('marketplace-stock.index'))->assertOk();
        foreach (['Informasi Produk', 'Master SKU', 'Harga', 'Stok', 'Produk Terkait', 'Toko Terkait', 'Atur'] as $col) {
            $res->assertSee($col);
        }
        $res->assertSee('Hana Glow')->assertSee('FM-1');
        $res->assertSee('2 Produk'); // 2 listing tertaut
        $res->assertSee('2 Toko');   // 2 channel unik
    }

    public function test_menu_atur_berisi_aksi_desty(): void
    {
        MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        $res = $this->actingAs($this->admin())->get(route('marketplace-stock.index'))->assertOk();
        foreach (['Ubah', 'Duplikat Produk', 'Tambah ke Marketplace', 'Jadikan Bundle', 'Hapus'] as $act) {
            $res->assertSee($act);
        }
    }

    public function test_tab_bundle_hanya_bundle(): void
    {
        MarketplaceMaster::create(['master_sku' => 'S-1', 'name' => 'Satuan', 'name_key' => 'satuan', 'is_bundle' => false]);
        MarketplaceMaster::create(['master_sku' => 'B-1', 'name' => 'Paket', 'name_key' => 'paket', 'is_bundle' => true]);

        $this->actingAs($this->admin())->get(route('marketplace-stock.index', ['tab' => 'bundle']))
            ->assertOk()->assertSee('Paket')->assertDontSee('>Satuan<', false);
    }

    public function test_picker_tambah_ke_marketplace_berisi_listing_belum_tertaut(): void
    {
        MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'NEW-SKU', 'item_id' => 'P9', 'master_id' => null, 'title' => 'Produk Baru']);

        $this->actingAs($this->admin())->get(route('marketplace-stock.index'))
            ->assertOk()->assertSee('NEW-SKU');
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->get(route('marketplace-stock.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan → merah** (`--filter=CatalogPageTest`).

- [ ] **Step 3: Ganti `index()` di controller** — ganti seluruh method `index()` (baris ~20-54) DAN hapus `mastersForTab()` (baris ~56-67) & `channelSummary()` (baris ~261-274) dengan `index()` baru berikut (channelSummary/mastersForTab dibuang):
```php
    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['satuan', 'bundle'], true) ? $request->query('tab') : 'semua';

        $q = MarketplaceMaster::with('listings:id,master_id,channel');
        if ($tab === 'satuan') {
            $q->where('is_bundle', false);
        } elseif ($tab === 'bundle') {
            $q->where('is_bundle', true);
        }

        return view('marketplace-stock.index', [
            'tab' => $tab,
            'masters' => $q->orderBy('name')->get(),
            'counts' => [
                'semua' => MarketplaceMaster::count(),
                'satuan' => MarketplaceMaster::where('is_bundle', false)->count(),
                'bundle' => MarketplaceMaster::where('is_bundle', true)->count(),
            ],
            'unlinkedListings' => MarketplaceListing::whereNull('master_id')->orderBy('channel')->orderBy('seller_sku')->get(['id', 'channel', 'seller_sku', 'title']),
            'unlinkedCount' => MarketplaceListing::whereNull('master_id')->count(),
        ]);
    }
```
> Catatan: `use Illuminate\Database\Eloquent\Builder;` jadi tak terpakai setelah `mastersForTab` dihapus — hapus baris import itu bila jadi unused (pint/analisa). Jangan hapus import lain.

- [ ] **Step 4: Tulis ulang `resources/views/marketplace-stock/index.blade.php`** (ganti seluruh isi):
```blade
@extends('layouts.app')
@section('title','Produk Master')
@section('heading','Produk Master E-commerce')
@section('content')
@php
    $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.');
    $tabUrl = fn ($t) => route('marketplace-stock.index', array_filter(['tab' => $t === 'semua' ? null : $t]));
@endphp
<div class="space-y-4">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('marketplace-stock.create') }}" class="px-4 py-2 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">+ Tambah Produk Baru</a>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">↻ Refresh Listing</button></form>
            <a href="{{ route('marketplace-stock.channel', 'tiktok') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga TikTok →</a>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga Shopee →</a>
        </div>
        @if($unlinkedCount > 0)
            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{{ $unlinkedCount }} listing belum ditautkan ke master — pakai "Tambah ke Marketplace" pada produk untuk menautkan.</p>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 text-sm">
        @foreach(['semua' => 'Semua', 'satuan' => 'Satuan', 'bundle' => 'Bundle'] as $key => $label)
            <a href="{{ $tabUrl($key) }}" class="px-4 py-2 rounded-lg {{ $tab === $key ? 'bg-stone-800 text-white' : 'bg-white border border-stone-200 text-stone-600 hover:bg-stone-50' }}">{{ $label }} <span class="opacity-70">({{ $counts[$key] }})</span></a>
        @endforeach
    </div>

    @if($masters->isEmpty())
        <div class="bg-white rounded-2xl border border-stone-200">
            <p class="px-5 py-12 text-center text-stone-400 text-sm">Belum ada produk master. Klik <span class="font-medium text-stone-600">+ Tambah Produk Baru</span> untuk mulai.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-stone-200 overflow-visible">
            <table class="w-full text-sm">
                <thead class="text-left text-stone-500 border-b border-stone-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">Informasi Produk</th>
                        <th class="px-4 py-3 font-medium">Master SKU</th>
                        <th class="px-4 py-3 font-medium">Harga</th>
                        <th class="px-4 py-3 font-medium">Stok</th>
                        <th class="px-4 py-3 font-medium">Produk Terkait</th>
                        <th class="px-4 py-3 font-medium">Toko Terkait</th>
                        <th class="px-4 py-3 font-medium text-right">Atur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($masters as $m)
                        @php
                            $produkTerkait = $m->listings->count();
                            $tokoTerkait = $m->listings->pluck('channel')->unique()->count();
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @if($m->imageUrl())
                                        <img src="{{ $m->imageUrl() }}" alt="" class="w-10 h-10 rounded-lg object-cover border border-stone-200">
                                    @else
                                        <div class="w-10 h-10 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-300 text-[10px]">no img</div>
                                    @endif
                                    <div>
                                        <div class="font-medium text-stone-800">{{ $m->name }}</div>
                                        @if($m->is_bundle)<span class="text-[10px] uppercase tracking-wide text-amber-700 bg-amber-100 rounded px-1.5 py-0.5">Bundle</span>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $m->master_sku }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1">@csrf
                                    <input type="number" step="0.01" min="0" name="price" value="{{ $m->base_price }}" placeholder="—" class="w-24 px-2 py-1 border border-stone-200 rounded">
                                    <button class="text-xs text-indigo-600 hover:underline">set</button>
                                </form>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1">@csrf
                                    <input type="number" min="0" name="quantity" value="{{ $m->base_stock }}" placeholder="—" class="w-20 px-2 py-1 border border-stone-200 rounded">
                                    <button class="text-xs text-indigo-600 hover:underline">set</button>
                                </form>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $produkTerkait > 0 ? $produkTerkait.' Produk' : '—' }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ $tokoTerkait > 0 ? $tokoTerkait.' Toko' : '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <details class="relative inline-block text-left">
                                    <summary class="cursor-pointer list-none px-3 py-1.5 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-700">Atur ▾</summary>
                                    <div class="absolute right-0 mt-1 w-56 bg-white border border-stone-200 rounded-lg shadow-lg z-20 py-1 text-left">
                                        <a href="{{ route('marketplace-stock.edit', $m) }}" class="block px-4 py-2 hover:bg-stone-50">Ubah</a>
                                        <form method="POST" action="{{ route('marketplace-stock.duplikat', $m) }}">@csrf<button class="w-full text-left px-4 py-2 hover:bg-stone-50">Duplikat Produk</button></form>
                                        <details class="group">
                                            <summary class="cursor-pointer list-none px-4 py-2 hover:bg-stone-50">Tambah ke Marketplace</summary>
                                            <form method="POST" action="{{ route('marketplace-stock.tautkan') }}" class="px-4 py-2 space-y-1 bg-stone-50">@csrf
                                                <input type="hidden" name="master_id" value="{{ $m->id }}">
                                                <select name="listing_id" required class="w-full px-2 py-1 border border-stone-200 rounded text-xs">
                                                    <option value="">Pilih listing…</option>
                                                    @foreach($unlinkedListings as $l)
                                                        <option value="{{ $l->id }}">{{ strtoupper($l->channel) }} · {{ $l->seller_sku }}{{ $l->title ? ' — '.\Illuminate\Support\Str::limit($l->title, 30) : '' }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="w-full px-2 py-1 bg-indigo-600 text-white rounded text-xs">Tautkan</button>
                                            </form>
                                        </details>
                                        <form method="POST" action="{{ route('marketplace-stock.master.bundle', $m) }}">@csrf<button class="w-full text-left px-4 py-2 hover:bg-stone-50">{{ $m->is_bundle ? 'Jadikan Satuan' : 'Jadikan Bundle' }}</button></form>
                                        <form method="POST" action="{{ route('marketplace-stock.master.hapus', $m) }}" onsubmit="return confirm('Hapus produk master ini?')">@csrf @method('DELETE')<button class="w-full text-left px-4 py-2 text-rose-600 hover:bg-rose-50">Hapus</button></form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
```
> Catatan test `test_menu_atur_berisi_aksi_desty` mencari string "Jadikan Bundle" — tombol menampilkan "Jadikan Bundle" saat master satuan (default `is_bundle=false`), jadi cocok.

- [ ] **Step 5: `--filter=CatalogPageTest` → hijau.** Pint `--dirty`.

- [ ] **Step 6: Suite marketplace penuh** (`--filter="MarketplaceMaster|MarketplaceStock"`). Route lama masih ada (belum dihapus) — pastikan hijau. Commit:
```
feat(marketplace-master): halaman katalog ala Desty (kolom + tab + menu Atur + tautkan picker)
```

---

### Task 3: Buang jalur auto (service + controller + routes + tes usang)

Sekarang view baru TAK memakai route auto lama, aman dihapus. Buang: `siapkanMaster`, `deleteOrphanMasters`, `seedFromTiktok`, `masterizeUnmastered`, `mergeMaster` (service); `siapkan`, `seed`, `masterizeAll`, `gabung`, `push`, `uploadFoto` (controller); route terkait; hentikan auto-buat master di `upsertListing`; `resolveTiktok/resolveShopee` tak lagi hitung `mastered`.

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php`
- Modify: `app/Http/Controllers/MarketplaceStockController.php`
- Modify: `routes/web.php`
- Delete: `tests/Feature/MarketplaceMaster/SiapkanMasterTest.php`, `tests/Feature/MarketplaceMaster/SeedMasterTest.php`, `tests/Feature/MarketplaceMaster/MasterizeTest.php`
- Modify: `tests/Feature/MarketplaceMaster/ResolveMasterTest.php`, `tests/Feature/MarketplaceMaster/MasterActionsTest.php`
- Test: `tests/Feature/MarketplaceMaster/RefreshNoAutoMasterTest.php` (baru)

**Interfaces:**
- Changes: `resolveListings(string): array{found:int}` (tanpa `mastered`). `upsertListing` tak lagi buat master.

- [ ] **Step 1: Tulis `RefreshNoAutoMasterTest` (gagal dulu)** `tests/Feature/MarketplaceMaster/RefreshNoAutoMasterTest.php`:
```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefreshNoAutoMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_tiktok_isi_listing_tanpa_auto_buat_master(): void
    {
        TiktokConnection::create([
            'shop_id' => 'S1', 'shop_cipher' => 'CIPHER', 'shop_name' => 'Toko',
            'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(30),
        ]);
        Http::fake([
            '*/product/*/warehouses*' => Http::response(['data' => ['warehouses' => [['id' => 'W1', 'type' => 'SALES_WAREHOUSE']]]]),
            '*/products/search*' => Http::response(['data' => ['products' => [[
                'id' => 'P1', 'title' => 'Hana Glow', 'skus' => [['id' => 'SK1', 'seller_sku' => 'FM-1']],
            ]], 'next_page_token' => '']]),
            '*' => Http::response(['data' => []]),
        ]);

        $out = app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $this->assertSame(1, $out['found']);
        $this->assertArrayNotHasKey('mastered', $out);
        $this->assertSame(1, MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->count());
        $this->assertNull(MarketplaceListing::where('seller_sku', 'FM-1')->value('master_id')); // TIDAK auto-termaster
        $this->assertSame(0, MarketplaceMaster::count()); // TIDAK ada master dibuat
    }
}
```
> Jika endpoint fake di atas tak persis cocok dg `TikTokClient` (mis. path warehouses/searchProducts), sesuaikan pola URL dengan yang dipakai `ResolveMasterTest` yang ada (baca file itu untuk pola `Http::fake` yang sudah terbukti) — inti assertion tetap: found=1, master_id null, MarketplaceMaster::count()=0.

- [ ] **Step 2: Jalankan → merah.**

- [ ] **Step 3: Ubah `upsertListing`** di service (baris ~479-491) jadi TAK buat master + buang param `$imageUrl`:
```php
    private function upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title): MarketplaceListing
    {
        return MarketplaceListing::updateOrCreate(
            ['channel' => $channel, 'seller_sku' => $sellerSku],
            ['item_id' => $itemId, 'variation_id' => $variationId, 'warehouse_id' => $warehouseId, 'title' => $title, 'resolved_at' => now()],
        );
    }
```
Perbarui docblock method (buang narasi auto-buat master; ganti jadi: "Upsert baris listing by (channel, seller_sku); master_id dibiarkan apa adanya — penautan ke master dilakukan manual lewat tautkanListing.").

- [ ] **Step 4: Ubah `resolveTiktok`** (baris ~498-555):
  - `return ['found' => 0, 'mastered' => 0];` → `return ['found' => 0];`
  - Hapus baris ekstraksi `$img = (string) (data_get($prod, 'main_images.0.urls.0') ...);`
  - `$found = $mastered = 0;` → `$found = 0;`
  - Panggilan upsert: `$this->upsertListing('tiktok', $sellerSku, $pid, (string) data_get($sku, 'id', ''), $warehouseId, $title !== null ? (string) $title : null);` (drop arg `$img...`)
  - Hapus `$mastered++;` (sisakan `$found++;`)
  - `return compact('found', 'mastered');` → `return ['found' => $found];`

- [ ] **Step 5: Ubah `resolveShopee`** (baris ~563-625) dg pola sama:
  - `return ['found' => 0, 'mastered' => 0];` → `return ['found' => 0];`
  - Hapus `$img = (string) (data_get($info, 'image.image_url_list.0') ?? '');`
  - `$found = $mastered = 0;` → `$found = 0;`
  - Dua panggilan upsert: drop arg `$img...` terakhir → `..., $title);`
  - Hapus dua `$mastered++;`
  - `return compact('found', 'mastered');` → `return ['found' => $found];`

- [ ] **Step 6: Hapus method service** `siapkanMaster` (~182-197), `deleteOrphanMasters` (~200-213), `seedFromTiktok` (~396-437), `masterizeUnmastered` (~270-280), `mergeMaster` (~48-55). Pastikan tak ada pemanggil tersisa (grep `mergeMaster|siapkanMaster|deleteOrphanMasters|seedFromTiktok|masterizeUnmastered` → hanya definisi yang dihapus).

- [ ] **Step 7: Hapus method controller** `siapkan` (~230-240), `seed` (~250-259), `masterizeAll` (~185-190), `gabung` (~167-173), `push` (~192-197), `uploadFoto` (~176-182). Perbarui `resolve()` (~206-227) — buang bagian `mastered` dari pesan:
```php
    public function resolve(MarketplaceMasterService $svc): RedirectResponse
    {
        $notes = [];
        $errors = [];
        foreach (['tiktok', 'shopee'] as $channel) {
            try {
                $r = $svc->resolveListings($channel);
                $notes[] = ucfirst($channel).": {$r['found']} listing";
            } catch (\Throwable $e) {
                $errors[] = ucfirst($channel).' gagal: '.$e->getMessage();
            }
        }
        $redirect = back();
        if ($notes) {
            $redirect->with('status', 'Refresh listing — '.implode(' · ', $notes));
        }
        if ($errors) {
            $redirect->with('error', implode(' · ', $errors).' — cek izin/scope Product di app channel.');
        }

        return $redirect;
    }
```
> `ImageService` mungkin jadi import tak terpakai di controller setelah `uploadFoto` & `store/update` — CEK: `store`/`update` (Task 1) MASIH pakai `ImageService` (foto), jadi import tetap perlu. Jangan hapus.

- [ ] **Step 8: Hapus route** di `routes/web.php` (grup marketplace-stock): baris `siapkan`, `seed-tiktok`, `masterize-all`, `master.gabung` (gabung), `push/{master}` (push), `master.foto` (uploadFoto). SISakan `push-all`, `resolve`, `tautkan`, `master.stok/harga`, `channel.*`, `ikut-master`, `master.hapus`, `master.bundle`, dan route Task 1.

- [ ] **Step 9: Hapus tes usang**: `SiapkanMasterTest.php`, `SeedMasterTest.php`, `MasterizeTest.php`.

- [ ] **Step 10: Perbarui `ResolveMasterTest.php`** — buang assertion yang mengharap listing auto-termaster / `mastered` count; ganti jadi assert listing ter-upsert + `master_id` null + `MarketplaceMaster::count()===0` (baca file dulu; sesuaikan tiap test case yang menyentuh mastering). Jika seluruh tujuan file itu adalah "auto-master", kurangi jadi test "found + listing upserted (no master)" (overlap dg RefreshNoAutoMasterTest boleh — hapus test yang jadi redundan/menyesatkan).

- [ ] **Step 11: Perbarui `MasterActionsTest.php`** — hapus test yang memanggil route `gabung` / `mergeMaster`. Pertahankan test `toggleBundle` & `deleteMaster`. (Duplikat sudah diuji di CreateEditMasterTest.)

- [ ] **Step 12: `--filter="MarketplaceMaster|MarketplaceStock"` → hijau.** Pint `--dirty`. Commit:
```
refactor(marketplace-master): buang jalur auto-buat master (siapkan/seed/masterize/gabung), refresh listing tak auto-master
```

---

### Task 4: Dokumentasi + suite penuh + sisa

**Files:**
- Modify: `docs/SISTEM.md` (§9d Produk Master E-commerce)
- Modify: `docs/PETA-SISTEM.md` (status modul)
- Modify (bila perlu): `tests/Feature/MarketplaceMaster/DedupByNameTest.php`, `tests/Unit/MarketplaceMaster/CatalogModelTest.php`

- [ ] **Step 1: Verifikasi `DedupByNameTest` & `CatalogModelTest`** masih relevan. `findOrCreateMaster` MASIH ADA (dipakai `tautkanListing` jalur buat-baru), jadi DedupByNameTest untuk `findOrCreateMaster` boleh tetap. `CatalogModelTest` (normalizeName/detectBundle/imageUrl/cast is_bundle) tetap valid. Jalankan keduanya; perbaiki hanya bila menyentuh method yang dihapus.

- [ ] **Step 2: Update `docs/SISTEM.md` §9d** — deskripsikan model manual: master dibuat manual (Tambah Produk Baru), ditautkan ke listing (Tambah ke Marketplace) untuk sinkron; buang penyebutan Siapkan Master/Seed/auto-dedup; menu Atur (Ubah/Duplikat/Tambah ke Marketplace/Bundle/Hapus). Baca seksi itu dulu, sesuaikan seperlunya.

- [ ] **Step 3: Update `docs/PETA-SISTEM.md`** — baris status Stok Marketplace/Produk Master: model manual ala Desty (CRUD manual + tautkan), route baru (create/store/edit/update/duplikat), route dihapus (siapkan/seed/masterize/gabung/push/foto).

- [ ] **Step 4: SUITE PENUH** `C:/php83/php.exe artisan test` → semua hijau. Pint `--dirty`. Commit:
```
docs(marketplace-master): perbarui SISTEM.md/PETA-SISTEM.md ke model manual
```

---

## Self-Review (penulis rencana)

- **Cakupan spec**: kosongkan (000147 sudah) ✅; manual create/edit/duplicate ✅; kolom Desty + tab ✅; menu Atur 5 aksi ✅; Tambah ke Marketplace = tautkan existing ✅; buang auto (siapkan/seed/masterize/gabung) ✅; sinkron engine dipakai ulang ✅; HQ tak disentuh (tes) ✅; foto manual ✅; tanpa migrasi ✅.
- **Placeholder**: tak ada TODO/TBD; semua langkah bawa kode nyata.
- **Konsistensi tipe**: `duplicateMaster(MarketplaceMaster): MarketplaceMaster`, `resolveListings(): array{found}`, view index vars (`masters/counts/unlinkedListings/unlinkedCount`) konsisten controller↔view↔test.
- **Urutan aman-hijau**: T1 nambah (lama utuh) → T2 view pakai route baru (lama tak direferensi) → T3 baru hapus route/metode lama → T4 docs. Tiap task suite hijau.
