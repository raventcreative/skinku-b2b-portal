# Produk Master — Form Lengkap ala Desty Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Perkaya form Produk Master jadi lengkap ala Desty (kategori, deskripsi, foto sampai 9, berat, dimensi, barcode) — data tersimpan di SKINKU. Harga/stok tetap push seperti sekarang; field baru TIDAK di-push ke marketplace.

**Architecture:** Tambah kolom di `marketplace_masters` + reuse trait `HasFiles` (koleksi `master_image`, sudah multi-file) untuk foto banyak. Perkaya controller store/update + form. Tak sentuh mesin push (stok/harga) & HQ.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind, PHPUnit class-style. Reuse `ImageService`/`HasFiles`/`File`.

## Global Constraints

- **Zero-dependency**: tak ada paket baru. Foto pakai `ImageService::attach` + `HasFiles` + model `File` (yang `deleting`-hook-nya sudah hapus file fisik).
- **HQ TAK disentuh**: tak sentuh `products.hq_stock`/`stock_movements`/`InventoryService`.
- **Push marketplace TAK diubah**: field baru (kategori/deskripsi/foto/berat/dimensi/barcode) TIDAK di-push. Cuma stok/harga yang push (mekanisme lama).
- **Izin**: route dalam grup `permission:manage_marketplace_stock`. Non-izin → 403.
- **Blade**: JANGAN `@json([...])` literal; form POST + `@csrf`; DELETE via `@method('DELETE')`; PUT via `@method('PUT')`; foto form `enctype="multipart/form-data"`, input `name="foto[]" multiple`.
- **Migrasi baru = `000150`** (terakhir 000149).
- **Runner**: `C:/php83/php.exe artisan test`. **Format**: `C:/php83/php.exe vendor/bin/pint --dirty`.
- **Tes**: PHPUnit class-style (NO Pest/ProductFactory). `User`/`Product` via `::create()`. Foto: `Storage::fake('public')` + `UploadedFile::fake()->image()`.
- **Commit**: akhiri `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

Helper admin/reseller (pakai di feature test):
```php
private function admin(): \App\Models\User {
    return \App\Models\User::create(['name'=>'a','fullname'=>'A','username'=>'a'.uniqid(),'email'=>uniqid().'@t.test','password'=>\Illuminate\Support\Facades\Hash::make('secret123'),'role'=>\App\Models\User::ROLE_SUPER_ADMIN,'status'=>\App\Models\User::STATUS_ACTIVE]);
}
private function reseller(): \App\Models\User {
    return \App\Models\User::create(['name'=>'r','fullname'=>'R','username'=>'r'.uniqid(),'email'=>uniqid().'@t.test','password'=>\Illuminate\Support\Facades\Hash::make('secret123'),'role'=>\App\Models\User::ROLE_RESELLER,'status'=>\App\Models\User::STATUS_ACTIVE]);
}
```

---

### Task 1: Migrasi + model + backend store/update (field baru + foto banyak)

**Files:**
- Create: `database/migrations/2026_01_01_000150_add_detail_fields_to_marketplace_masters.php`
- Modify: `app/Models/MarketplaceMaster.php` (fillable)
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (validateMaster, masterAttributes, store, update, applyMasterInputs)
- Modify: `tests/Feature/MarketplaceMaster/CreateEditMasterTest.php`

**Interfaces:**
- Produces: kolom baru di `marketplace_masters`; store/update simpan field baru + terima `foto[]` banyak (maks 9 total).

- [ ] **Step 1: Tulis migrasi** `database/migrations/2026_01_01_000150_add_detail_fields_to_marketplace_masters.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->string('category')->nullable()->after('name');
            $t->text('description')->nullable()->after('category');
            $t->unsignedInteger('weight_g')->nullable()->after('description');
            $t->unsignedInteger('length_cm')->nullable()->after('weight_g');
            $t->unsignedInteger('width_cm')->nullable()->after('length_cm');
            $t->unsignedInteger('height_cm')->nullable()->after('width_cm');
            $t->string('barcode')->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->dropColumn(['category', 'description', 'weight_g', 'length_cm', 'width_cm', 'height_cm', 'barcode']);
        });
    }
};
```

- [ ] **Step 2: Model fillable** — di `app/Models/MarketplaceMaster.php`, tambah kolom baru ke `$fillable` (setelah kolom yang ada):
```php
    protected $fillable = ['master_sku', 'name', 'name_key', 'is_bundle', 'image_url', 'product_id', 'base_stock', 'base_price', 'seeded_at', 'category', 'description', 'weight_g', 'length_cm', 'width_cm', 'height_cm', 'barcode'];
```
(Ambil daftar fillable AKTUAL dari file, tambahkan 7 kolom baru di ujung — jangan hapus yang ada.)

- [ ] **Step 3: Tulis/perbarui tes** di `tests/Feature/MarketplaceMaster/CreateEditMasterTest.php`:
  - Perbarui `test_store_dengan_foto` (yang lama kirim `'foto' => UploadedFile::fake()->image(...)`) → kirim ARRAY `'foto' => [UploadedFile::fake()->image('a.jpg')]`.
  - Tambah test baru:
```php
    public function test_store_simpan_field_lengkap(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Day Cream 10gr', 'master_sku' => 'DC-1',
            'category' => 'Perawatan Wajah / BB Cream',
            'description' => 'Day cream BB SPF 30 ...',
            'weight_g' => 10, 'length_cm' => 5, 'width_cm' => 5, 'height_cm' => 5,
            'barcode' => '8991234567890',
        ])->assertRedirect(route('marketplace-stock.index'));

        $m = \App\Models\MarketplaceMaster::where('master_sku', 'DC-1')->firstOrFail();
        $this->assertSame('Perawatan Wajah / BB Cream', $m->category);
        $this->assertSame('Day cream BB SPF 30 ...', $m->description);
        $this->assertSame(10, $m->weight_g);
        $this->assertSame(5, $m->length_cm);
        $this->assertSame('8991234567890', $m->barcode);
    }

    public function test_update_simpan_field_lengkap(): void
    {
        $m = \App\Models\MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'X', 'master_sku' => 'X-1', 'category' => 'Body Care', 'weight_g' => 100,
        ])->assertRedirect();
        $m->refresh();
        $this->assertSame('Body Care', $m->category);
        $this->assertSame(100, $m->weight_g);
    }

    public function test_store_foto_banyak(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Multi Foto', 'master_sku' => 'MF-1',
            'foto' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg'), \Illuminate\Http\UploadedFile::fake()->image('b.jpg'), \Illuminate\Http\UploadedFile::fake()->image('c.jpg')],
        ])->assertRedirect();
        $m = \App\Models\MarketplaceMaster::where('master_sku', 'MF-1')->firstOrFail();
        $this->assertSame(3, $m->filesIn(\App\Models\MarketplaceMaster::MASTER_IMAGE)->count());
    }

    public function test_foto_dibatasi_maks_9(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $m = \App\Models\MarketplaceMaster::create(['master_sku' => 'C-1', 'name' => 'C', 'name_key' => 'c']);
        // sudah ada 8
        for ($i = 0; $i < 8; $i++) {
            app(\App\Services\ImageService::class)->attach($m, \Illuminate\Http\UploadedFile::fake()->image("x{$i}.jpg"), \App\Models\MarketplaceMaster::MASTER_IMAGE);
        }
        // kirim 3 lagi → cuma 1 yang masuk (total mentok 9)
        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'C', 'master_sku' => 'C-1',
            'foto' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg'), \Illuminate\Http\UploadedFile::fake()->image('b.jpg'), \Illuminate\Http\UploadedFile::fake()->image('c.jpg')],
        ])->assertRedirect();
        $this->assertSame(9, $m->filesIn(\App\Models\MarketplaceMaster::MASTER_IMAGE)->count());
    }
```
> Run merah dulu sebelum implementasi backend.

- [ ] **Step 4: Perbarui controller** `app/Http/Controllers/MarketplaceStockController.php`:
  - Ganti `validateMaster` jadi:
```php
    private function validateMaster(Request $r): void
    {
        $r->validate([
            'name' => ['required', 'string', 'max:255'],
            'master_sku' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'is_bundle' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'weight_g' => ['nullable', 'integer', 'min:0'],
            'length_cm' => ['nullable', 'integer', 'min:0'],
            'width_cm' => ['nullable', 'integer', 'min:0'],
            'height_cm' => ['nullable', 'integer', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'foto' => ['nullable', 'array', 'max:9'],
            'foto.*' => ['image', 'max:5120'],
        ]);
    }

    /** Atribut master dari request (dipakai store & update). */
    private function masterAttributes(Request $r): array
    {
        return [
            'master_sku' => $r->master_sku,
            'name' => $r->name,
            'name_key' => MarketplaceMaster::normalizeName($r->name),
            'is_bundle' => $r->boolean('is_bundle'),
            'category' => $r->input('category'),
            'description' => $r->input('description'),
            'weight_g' => $r->filled('weight_g') ? (int) $r->weight_g : null,
            'length_cm' => $r->filled('length_cm') ? (int) $r->length_cm : null,
            'width_cm' => $r->filled('width_cm') ? (int) $r->width_cm : null,
            'height_cm' => $r->filled('height_cm') ? (int) $r->height_cm : null,
            'barcode' => $r->input('barcode'),
        ];
    }
```
  - `store()`: ganti `MarketplaceMaster::create([...4 keys...])` jadi `$master = MarketplaceMaster::create($this->masterAttributes($r));` (biarkan validasi + applyMasterInputs + redirect apa adanya).
  - `update()`: ganti `$master->update([...4 keys...])` jadi `$master->update($this->masterAttributes($r));` (sisanya tetap).
  - Ganti `applyMasterInputs` (bagian foto) jadi multi + cap 9:
```php
    private function applyMasterInputs(Request $r, MarketplaceMaster $master, ImageService $img, MarketplaceMasterService $svc): void
    {
        if ($r->filled('price')) {
            $svc->setMasterPrice($master, (float) $r->price);
        }
        if ($r->filled('stock')) {
            $svc->setMasterStock($master, (int) $r->stock);
        }
        $existing = $master->filesIn(MarketplaceMaster::MASTER_IMAGE)->count();
        foreach ((array) $r->file('foto', []) as $file) {
            if (! $file || $existing >= 9) {
                continue;
            }
            $img->attach($master, $file, MarketplaceMaster::MASTER_IMAGE);
            $existing++;
        }
    }
```

- [ ] **Step 5:** `--filter=CreateEditMasterTest` → hijau. Pint `--dirty`. Suite marketplace `--filter="MarketplaceMaster|MarketplaceStock"` hijau. Commit:
```
feat(marketplace-master): field lengkap (kategori/deskripsi/berat/dimensi/barcode) + foto banyak di store/update
```

---

### Task 2: Foto — hapus per-foto + jadikan utama

**Files:**
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (import `App\Models\File`; method `deleteFoto`, `setFotoUtama`)
- Modify: `routes/web.php`
- Test: `tests/Feature/MarketplaceMaster/FotoMasterTest.php`

**Interfaces:**
- Produces: route `marketplace-stock.master.foto.hapus` (DELETE {master}/foto/{file}), `marketplace-stock.master.foto.utama` (POST {master}/foto/{file}/utama).

- [ ] **Step 1: Tulis `tests/Feature/MarketplaceMaster/FotoMasterTest.php`:**
```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function masterWith2Foto(): MarketplaceMaster
    {
        Storage::fake('public');
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Foto', 'name_key' => 'foto']);
        app(ImageService::class)->attach($m, UploadedFile::fake()->image('a.jpg'), MarketplaceMaster::MASTER_IMAGE);
        app(ImageService::class)->attach($m, UploadedFile::fake()->image('b.jpg'), MarketplaceMaster::MASTER_IMAGE);

        return $m;
    }

    public function test_hapus_foto(): void
    {
        $m = $this->masterWith2Foto();
        $file = $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->first();
        $this->actingAs($this->admin())
            ->delete(route('marketplace-stock.master.foto.hapus', [$m, $file]))
            ->assertRedirect();
        $this->assertSame(1, $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->count());
    }

    public function test_jadikan_utama_ubah_imageUrl(): void
    {
        $m = $this->masterWith2Foto();
        $kedua = $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->get()->last();
        $this->assertNotSame($kedua->url(), $m->imageUrl()); // awalnya foto pertama yg utama

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.master.foto.utama', [$m, $kedua]))
            ->assertRedirect();

        $this->assertSame($kedua->url(), $m->fresh()->imageUrl()); // sekarang foto kedua jadi utama
    }

    public function test_hapus_foto_master_lain_ditolak(): void
    {
        $m1 = $this->masterWith2Foto();
        $m2 = MarketplaceMaster::create(['master_sku' => 'M2', 'name' => 'M2', 'name_key' => 'm2']);
        $fileM1 = $m1->filesIn(MarketplaceMaster::MASTER_IMAGE)->first();
        $this->actingAs($this->admin())
            ->delete(route('marketplace-stock.master.foto.hapus', [$m2, $fileM1]))
            ->assertNotFound();
        $this->assertSame(2, $m1->filesIn(MarketplaceMaster::MASTER_IMAGE)->count());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $m = $this->masterWith2Foto();
        $file = $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->first();
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->delete(route('marketplace-stock.master.foto.hapus', [$m, $file]))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan → merah.**

- [ ] **Step 3: Controller** — tambah `use App\Models\File;` (cek belum ada) + 2 method:
```php
    public function deleteFoto(MarketplaceMaster $master, File $file): RedirectResponse
    {
        $this->assertFotoMilikMaster($master, $file);
        $file->delete(); // model File hapus file fisik via deleting-hook

        return back()->with('status', 'Foto dihapus.');
    }

    public function setFotoUtama(MarketplaceMaster $master, File $file): RedirectResponse
    {
        $this->assertFotoMilikMaster($master, $file);
        // Foto utama = sort_order paling kecil. Set file ini 0, sisanya digeser >=1.
        $file->update(['sort_order' => 0]);
        $others = $master->filesIn(MarketplaceMaster::MASTER_IMAGE)->where('id', '!=', $file->id)->get();
        $i = 1;
        foreach ($others as $o) {
            $o->update(['sort_order' => $i++]);
        }

        return back()->with('status', 'Foto utama diperbarui.');
    }

    private function assertFotoMilikMaster(MarketplaceMaster $master, File $file): void
    {
        abort_unless(
            $file->fileable_type === MarketplaceMaster::class
                && (int) $file->fileable_id === (int) $master->id
                && $file->collection === MarketplaceMaster::MASTER_IMAGE,
            404,
        );
    }
```
> Catatan: `filesIn()` sudah `orderBy('sort_order')->orderBy('id')`, jadi sort_order 0 = pertama = `firstFileUrl` = `imageUrl()`.

- [ ] **Step 4: Rute** (grup `permission:manage_marketplace_stock`), tambah:
```php
        Route::delete('/marketplace-stock/master/{master}/foto/{file}', [MarketplaceStockController::class, 'deleteFoto'])->name('marketplace-stock.master.foto.hapus');
        Route::post('/marketplace-stock/master/{master}/foto/{file}/utama', [MarketplaceStockController::class, 'setFotoUtama'])->name('marketplace-stock.master.foto.utama');
```

- [ ] **Step 5:** `--filter=FotoMasterTest` → hijau. Pint. Suite marketplace hijau. Commit:
```
feat(marketplace-master): kelola foto master — hapus per-foto + jadikan foto utama
```

---

### Task 3: Form UI lengkap (ala Desty) + galeri + docs

**Files:**
- Modify: `resources/views/marketplace-stock/form.blade.php` (tulis ulang jadi lengkap)
- Test: `tests/Feature/MarketplaceMaster/CreateEditMasterTest.php` (tambah cek render field baru) atau `CatalogPageTest`
- Modify: `docs/SISTEM.md` (§9d), `docs/PETA-SISTEM.md`

- [ ] **Step 1: Tambah tes render** (di CreateEditMasterTest):
```php
    public function test_form_render_field_lengkap(): void
    {
        $this->actingAs($this->admin())->get(route('marketplace-stock.create'))
            ->assertOk()
            ->assertSee('Kategori')->assertSee('Deskripsi')->assertSee('Berat')
            ->assertSee('Barcode')->assertSee('foto[]', false);
    }

    public function test_form_edit_tampilkan_galeri_dan_field_terisi(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $m = \App\Models\MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x', 'category' => 'Body Care', 'weight_g' => 50]);
        app(\App\Services\ImageService::class)->attach($m, \Illuminate\Http\UploadedFile::fake()->image('a.jpg'), \App\Models\MarketplaceMaster::MASTER_IMAGE);

        $this->actingAs($this->admin())->get(route('marketplace-stock.edit', $m))
            ->assertOk()
            ->assertSee('Body Care')          // kategori terisi
            ->assertSee('value="50"', false)  // berat terisi
            ->assertSee('Jadikan Utama');     // aksi galeri foto (ada >=1 foto, tp foto pertama = utama → cek tombol Hapus juga)
    }
```
> Jika foto tunggal (foto pertama = utama) bikin "Jadikan Utama" tak muncul (karena di-skip utk index 0), ganti assert ke `assertSee('Hapus')` atau tambah foto kedua di fixture. Sesuaikan biar assertion valid.

- [ ] **Step 2: Tulis ulang** `resources/views/marketplace-stock/form.blade.php`:
```blade
@extends('layouts.app')
@section('title', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Master')
@section('heading', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Baru')
@section('content')
@php $gallery = $master->exists ? $master->fileGallery('master_image') : []; @endphp
<div class="max-w-3xl">
    @if($errors->any())<div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif
    @if(session('status'))<div class="mb-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif

    <form method="POST" action="{{ $master->exists ? route('marketplace-stock.update', $master) : route('marketplace-stock.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @if($master->exists)@method('PUT')@endif

        {{-- Informasi Produk --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Informasi Produk</h3>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Nama Produk</label>
                <input type="text" name="name" value="{{ old('name', $master->name) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Kategori</label>
                <input type="text" name="category" value="{{ old('category', $master->category) }}" placeholder="mis. Perawatan Wajah / BB Cream" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Deskripsi</label>
                <textarea name="description" rows="4" class="w-full px-3 py-2 border border-stone-200 rounded-lg">{{ old('description', $master->description) }}</textarea>
            </div>
        </div>

        {{-- Informasi Penjualan --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Informasi Penjualan</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Harga</label>
                    <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $master->base_price) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Stok</label>
                    <input type="number" min="0" name="stock" value="{{ old('stock', $master->base_stock) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Master SKU</label>
                    <input type="text" name="master_sku" value="{{ old('master_sku', $master->master_sku) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Barcode</label>
                    <input type="text" name="barcode" value="{{ old('barcode', $master->barcode) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tipe</label>
                    <select name="is_bundle" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                        <option value="0" @selected(! old('is_bundle', $master->is_bundle))>Satuan</option>
                        <option value="1" @selected(old('is_bundle', $master->is_bundle))>Bundle</option>
                    </select>
                </div>
            </div>
            <p class="text-[11px] text-stone-400">Harga & stok akan disinkron ke TikTok/Shopee. Field lain disimpan di SKINKU.</p>
        </div>

        {{-- Foto Produk --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-3">
            <h3 class="font-semibold text-stone-800">Foto Produk <span class="text-xs font-normal text-stone-400">(maks 9)</span></h3>
            @if($gallery)
                <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                    @foreach($gallery as $i => $g)
                        <div class="relative border border-stone-200 rounded-lg p-1">
                            <img src="{{ $g['url'] }}" alt="" class="w-full h-20 object-cover rounded">
                            @if($i === 0)<span class="absolute top-1 left-1 text-[9px] bg-indigo-600 text-white px-1 rounded">Utama</span>@endif
                            <div class="flex gap-2 mt-1 justify-center">
                                @if($i !== 0)
                                    <form method="POST" action="{{ route('marketplace-stock.master.foto.utama', [$master, $g['id']]) }}">@csrf<button class="text-[10px] text-indigo-600 hover:underline">Jadikan Utama</button></form>
                                @endif
                                <form method="POST" action="{{ route('marketplace-stock.master.foto.hapus', [$master, $g['id']]) }}" onsubmit="return confirm('Hapus foto ini?')">@csrf @method('DELETE')<button class="text-[10px] text-rose-600 hover:underline">Hapus</button></form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            <div>
                <input type="file" name="foto[]" accept="image/*" multiple class="block text-sm">
                <p class="text-xs text-stone-400 mt-1">JPG/PNG, tiap file maks 5MB. Bisa pilih beberapa sekaligus. Total maks 9.</p>
            </div>
        </div>

        {{-- Pengiriman --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Pengiriman</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Berat (g)</label>
                    <input type="number" min="0" name="weight_g" value="{{ old('weight_g', $master->weight_g) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Panjang (cm)</label>
                    <input type="number" min="0" name="length_cm" value="{{ old('length_cm', $master->length_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Lebar (cm)</label>
                    <input type="number" min="0" name="width_cm" value="{{ old('width_cm', $master->width_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tinggi (cm)</label>
                    <input type="number" min="0" name="height_cm" value="{{ old('height_cm', $master->height_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
            </div>
        </div>

        <div class="flex gap-2">
            <button class="px-5 py-2 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">Simpan</button>
            <a href="{{ route('marketplace-stock.index') }}" class="px-5 py-2 bg-stone-100 text-stone-600 rounded-lg hover:bg-stone-200">Batal</a>
        </div>
    </form>
</div>
@endsection
```

- [ ] **Step 3:** `--filter=CreateEditMasterTest` → hijau. `view:clear` lalu cek render create + edit tak 500. Pint.

- [ ] **Step 4: Docs** — `docs/SISTEM.md` §9d + `docs/PETA-SISTEM.md`: form Produk Master kini lengkap (kategori/deskripsi/foto s.d. 9 + hapus/utama/berat/dimensi/barcode) disimpan di SKINKU; **hanya stok & harga yang push ke marketplace** (field lain internal, push konten = fase lanjut). Migrasi 000150.

- [ ] **Step 5: SUITE PENUH** `C:/php83/php.exe artisan test` hijau. Pint. Commit:
```
feat(marketplace-master): form Produk Master lengkap ala Desty (kategori/deskripsi/foto galeri/berat/dimensi/barcode)
```

---

## Self-Review (penulis rencana)
- **Cakupan spec**: kolom baru ✅; foto banyak (upload/hapus/utama) ✅; form 4 seksi ala Desty ✅; harga/stok push tak diubah ✅; field baru TAK push (internal) ✅; HQ tak disentuh ✅; migrasi 000150 ✅; tanpa dependency baru ✅; tanpa menu baru (perkaya form existing) ✅.
- **Placeholder**: tak ada.
- **Konsistensi**: kolom fillable ↔ masterAttributes ↔ migrasi ↔ form field names cocok; foto pakai koleksi `master_image` konsisten (imageUrl firstFileUrl); route foto.hapus/utama ↔ form ↔ test.
- **Urutan aman-hijau**: T1 backend (test foto lama diubah ke array) → T2 foto endpoints → T3 form pakai semuanya. Tiap task suite hijau.
