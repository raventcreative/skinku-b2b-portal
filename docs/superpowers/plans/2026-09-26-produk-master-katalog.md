# Produk Master — Redesign Katalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ubah halaman Produk Master jadi katalog bersih ala Desty: master dikonsolidasi **by NAMA produk** (1 produk = 1 baris walau lintas channel), auto dari TikTok+Shopee, dengan foto, tab Satuan/Bundle, gabung/hapus.

**Architecture:** Bangun di atas `MarketplaceMasterService` yang sudah live. Ubah `findOrCreateMaster` jadi dedup by nama (kolom `name_key`), tambah `is_bundle`/`image_url`/foto-upload, tambah aksi `siapkanMaster`/gabung/hapus/toggle-bundle/upload-foto, lalu tulis ulang view index jadi katalog + tab. Data master lama (per-seller_sku, fragmented) di-reset sekali lewat migrasi (data mirror, rebuildable).

**Tech Stack:** Laravel 13, PHP 8.3, Blade+Tailwind (inline, zero-dep), PHPUnit class-style, `ImageService`/`HasFiles` yang sudah ada.

**Spec:** `docs/superpowers/specs/2026-09-26-produk-master-katalog-redesign.md`

## Global Constraints

- **Zero-dependency:** tak menambah package. Reuse `App\Services\ImageService` + trait `App\Models\Concerns\HasFiles`.
- **Test:** PHPUnit **class-style** (no Pest). `Product`/`User` dibuat langsung (`::create`). Http::fake untuk API. Runner `C:\php83\php.exe artisan test` (di shell ini `C:/php83/php.exe artisan test`). Format `C:/php83/php.exe vendor/bin/pint --dirty`.
- **HQ TERPISAH:** jangan baca/tulis `stock_movements`/`InventoryService`/`products.hq_stock`. Aksi katalog ini murni `marketplace_masters`/`marketplace_master_channels`/`marketplace_listings` + `files` (foto).
- **Blade:** tanpa `@json([...])` array literal; form POST + `@csrf`; native `<select>`/input; form upload foto pakai `enctype="multipart/form-data"` + `@method` bila perlu.
- **Izin/rute:** gate `$u->canDo('manage_marketplace_stock')`; rute di grup `permission:manage_marketplace_stock`.
- **Dedup by nama:** kunci = `MarketplaceMaster::normalizeName($name)` = lower + squish (trim + rapatkan spasi). Bundle: `preg_match('/bundl|paket/i', $name)`.
- **Branch:** `feat/produk-master-katalog` (dari main terbaru, sudah berisi Produk Master engine + Content Creator). Migrasi lanjut dari nomor terakhir yang ADA di branch (cek `ls database/migrations | sort | tail -1`; kemungkinan `000144` dari Content Creator → pakai `000145`... **VERIFIKASI nomor terakhir saat Task 1**, pakai nomor bebas berikutnya).

---

## File Structure

**Buat:**
- `database/migrations/<next>_add_catalog_fields_to_marketplace_masters.php` — +name_key/is_bundle/image_url + reset data master lama (T1).
- Tes: `tests/Unit/MarketplaceMaster/CatalogModelTest.php` (T1), `tests/Feature/MarketplaceMaster/DedupByNameTest.php` (T2), `tests/Feature/MarketplaceMaster/SiapkanMasterTest.php` (T3), `tests/Feature/MarketplaceMaster/MasterActionsTest.php` (T4), `tests/Feature/MarketplaceMaster/CatalogPageTest.php` (T5).

**Ubah:**
- `app/Models/MarketplaceMaster.php` — HasFiles + const + fillable/casts + helper (T1).
- `app/Services/MarketplaceMasterService.php` — findOrCreateMaster/upsertListing/resolve (T2) + siapkanMaster/mergeMaster/orphan cleanup (T3) + (toggle/foto helpers if needed) (T4).
- `app/Http/Controllers/MarketplaceStockController.php` — index (tabs) + siapkan/deleteMaster (T3) + toggleBundle/gabung/uploadFoto (T4).
- `routes/web.php` — rute baru di grup (T3, T4).
- `resources/views/marketplace-stock/index.blade.php` — tulis ulang jadi katalog (T5).
- `docs/SISTEM.md` / `docs/PETA-SISTEM.md` (T5).

---

### Task 1: Kolom katalog + model

**Files:**
- Create: `database/migrations/<next>_add_catalog_fields_to_marketplace_masters.php`
- Modify: `app/Models/MarketplaceMaster.php`
- Test: `tests/Unit/MarketplaceMaster/CatalogModelTest.php`

**Interfaces:**
- Produces:
  - Kolom `marketplace_masters`: `name_key` string nullable index, `is_bundle` boolean default false, `image_url` string nullable.
  - `MarketplaceMaster`: `use HasFiles`; const `MASTER_IMAGE = 'master_image'`; fillable += `name_key,is_bundle,image_url`; casts `is_bundle=>boolean`; static `normalizeName(string $name): string`; static `detectBundle(?string $name): bool`; `imageUrl(): ?string` (uploaded firstFileUrl ?? image_url).
- Consumes: existing `MarketplaceMaster`, `MarketplaceListing`, `MarketplaceMasterChannel`.

- [ ] **Step 1: Cek nomor migrasi terakhir**

Run: `ls database/migrations | sort | tail -1`
Pakai nomor urut berikutnya (mis. jika terakhir `2026_01_01_000144_*`, file baru `2026_01_01_000145_add_catalog_fields_to_marketplace_masters.php`). Ganti `<next>` di semua langkah dengan nomor itu.

- [ ] **Step 2: Tulis migrasi**

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
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->string('name_key')->nullable()->after('name')->index();
            $t->boolean('is_bundle')->default(false)->after('name_key');
            $t->string('image_url')->nullable()->after('is_bundle');
        });

        // Reset master lama (dibentuk per-seller_sku, fragmented) → dibangun ulang
        // by NAMA lewat tombol "Siapkan Master". Ini data mirror marketplace yang
        // rebuildable; pada fresh DB (test) tabel kosong jadi no-op.
        if (Schema::hasTable('marketplace_listings')) {
            DB::table('marketplace_listings')->update(['master_id' => null]);
        }
        if (Schema::hasTable('marketplace_master_channels')) {
            DB::table('marketplace_master_channels')->delete();
        }
        DB::table('marketplace_masters')->delete();
    }

    public function down(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->dropColumn(['name_key', 'is_bundle', 'image_url']);
        });
    }
};
```

- [ ] **Step 3: Tulis tes model (gagal dulu)**

`tests/Unit/MarketplaceMaster/CatalogModelTest.php`:

```php
<?php

namespace Tests\Unit\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_name_lower_dan_rapatkan_spasi(): void
    {
        $this->assertSame('hana glow face mist', MarketplaceMaster::normalizeName('  HANA  Glow   Face Mist '));
    }

    public function test_detect_bundle_dari_nama(): void
    {
        $this->assertTrue(MarketplaceMaster::detectBundle('BUNDLING (3pcs) Body Scrub'));
        $this->assertTrue(MarketplaceMaster::detectBundle('Paket Hemat Sabun'));
        $this->assertFalse(MarketplaceMaster::detectBundle('Day Cream 10gr'));
        $this->assertFalse(MarketplaceMaster::detectBundle(null));
    }

    public function test_is_bundle_cast_boolean(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a', 'is_bundle' => true]);
        $this->assertTrue($m->refresh()->is_bundle);
    }

    public function test_image_url_pakai_image_url_kalau_tak_ada_upload(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a', 'image_url' => 'https://cdn/x.jpg']);
        $this->assertSame('https://cdn/x.jpg', $m->imageUrl());
    }
}
```

- [ ] **Step 4: Jalankan — gagal.** `C:/php83/php.exe artisan test --filter=CatalogModelTest`

- [ ] **Step 5: Update model `MarketplaceMaster`**

Tambah `use App\Models\Concerns\HasFiles;` dan `use Illuminate\Support\Str;`. Ke class: `use HasFiles;` (di baris `class ... extends Model {` tambahkan trait). Tambah:

```php
    public const MASTER_IMAGE = 'master_image';
```
Fillable += `'name_key', 'is_bundle', 'image_url'`. Di `casts()` tambah `'is_bundle' => 'boolean'`. Tambah method:

```php
    public static function normalizeName(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    public static function detectBundle(?string $name): bool
    {
        return $name !== null && preg_match('/bundl|paket/i', $name) === 1;
    }

    /** URL foto: upload manual (koleksi master_image) menang, else image_url dari marketplace. */
    public function imageUrl(): ?string
    {
        return $this->firstFileUrl(self::MASTER_IMAGE) ?? $this->image_url;
    }
```

(Pastikan trait `HasFiles` dipakai — lihat `app/Models/Product.php` sbg pola: `use HasFactory, HasFiles, SoftDeletes;`. `MarketplaceMaster` tak pakai SoftDeletes/HasFactory; cukup `use HasFiles;`.)

- [ ] **Step 6: Jalankan — hijau.** `C:/php83/php.exe artisan test --filter=CatalogModelTest` (4 passed). Lalu `--filter=MarketplaceMaster` — **CATATAN:** tes lama mungkin masih hijau di task ini (migrasi reset hanya no-op di fresh test DB; kolom baru nullable/default). Bila ada tes lama yang gagal karena butuh `name_key`, itu ditangani Task 2. Bila semua hijau, lanjut.

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Models/MarketplaceMaster.php database/migrations tests/Unit/MarketplaceMaster/CatalogModelTest.php
git commit -m "feat(mp-katalog): kolom name_key/is_bundle/image_url + reset master lama + helper model"
```

---

### Task 2: Dedup master by NAMA + tarik foto saat resolve

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php`
- Test: `tests/Feature/MarketplaceMaster/DedupByNameTest.php` (+ update tes lama yang terpengaruh)

**Interfaces:**
- Consumes: `MarketplaceMaster::normalizeName/detectBundle` (T1), models.
- Produces:
  - `findOrCreateMaster(string $sellerSku, ?string $name, ?string $imageUrl = null): MarketplaceMaster` — dedup by `name_key` (dari `$name`, fallback `$sellerSku`). Master baru: `name` (= $name ?: $sellerSku), `name_key`, `master_sku=$sellerSku`, `is_bundle`=detect, `image_url`=$imageUrl. Master lama ditemukan: JANGAN timpa name/master_sku/is_bundle; isi `image_url` hanya bila masih null & $imageUrl ada.
  - `upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title, ?string $imageUrl = null): MarketplaceListing` — dedup master via nama (findOrCreateMaster($sellerSku, $title, $imageUrl)).
  - `resolveTiktok`/`resolveShopee` — ekstrak URL foto listing, teruskan ke upsertListing.

- [ ] **Step 1: Tulis tes dedup+foto (gagal dulu)**

`tests/Feature/MarketplaceMaster/DedupByNameTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupByNameTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    public function test_nama_sama_satu_master(): void
    {
        $a = $this->svc()->findOrCreateMaster('FM-1', 'Hana Glow Face Mist');
        $b = $this->svc()->findOrCreateMaster('FM-30G', 'HANA  Glow  Face Mist'); // nama sama (beda spasi/case), SKU beda
        $this->assertSame($a->id, $b->id);                // 1 master
        $this->assertSame('FM-1', $a->master_sku);        // SKU perwakilan = yg pertama
        $this->assertSame(1, MarketplaceMaster::count());
    }

    public function test_nama_beda_dua_master(): void
    {
        $this->svc()->findOrCreateMaster('FM-1', 'Face Mist');
        $this->svc()->findOrCreateMaster('DC-1', 'Day Cream');
        $this->assertSame(2, MarketplaceMaster::count());
    }

    public function test_bundle_auto_dari_nama(): void
    {
        $m = $this->svc()->findOrCreateMaster('JPX-3', 'BUNDLING (3pcs) Scrub');
        $this->assertTrue($m->is_bundle);
    }

    public function test_image_url_diisi_saat_buat_dan_backfill_kalau_kosong(): void
    {
        $m = $this->svc()->findOrCreateMaster('FM-1', 'Face Mist', 'https://cdn/a.jpg');
        $this->assertSame('https://cdn/a.jpg', $m->image_url);
        // temukan lagi dgn image beda: tak ditimpa (sudah ada)
        $again = $this->svc()->findOrCreateMaster('FM-9', 'Face Mist', 'https://cdn/b.jpg');
        $this->assertSame($m->id, $again->id);
        $this->assertSame('https://cdn/a.jpg', $again->refresh()->image_url);
        // master tanpa image, lalu di-backfill
        $n = $this->svc()->findOrCreateMaster('DC-1', 'Day Cream');
        $this->svc()->findOrCreateMaster('DC-9', 'Day Cream', 'https://cdn/dc.jpg');
        $this->assertSame('https://cdn/dc.jpg', $n->refresh()->image_url);
    }

    public function test_fallback_nama_kosong_pakai_sku(): void
    {
        $m = $this->svc()->findOrCreateMaster('SKU-X', null);
        $this->assertSame('SKU-X', $m->name);
        $this->assertSame(MarketplaceMaster::normalizeName('SKU-X'), $m->name_key);
    }
}
```

- [ ] **Step 2: Jalankan — gagal.** `C:/php83/php.exe artisan test --filter=DedupByNameTest`

- [ ] **Step 3: Ganti `findOrCreateMaster` + `upsertListing`**

Ganti `findOrCreateMaster` jadi (dedup by name_key):

```php
    public function findOrCreateMaster(string $sellerSku, ?string $name, ?string $imageUrl = null): MarketplaceMaster
    {
        $displayName = $name !== null && $name !== '' ? $name : $sellerSku;
        $key = MarketplaceMaster::normalizeName($displayName);

        $m = MarketplaceMaster::firstOrNew(['name_key' => $key]);
        if (! $m->exists) {
            $m->master_sku = $sellerSku;
            $m->name = $displayName;
            $m->is_bundle = MarketplaceMaster::detectBundle($displayName);
            $m->image_url = $imageUrl;
            $m->product_id = Product::where('sku', $sellerSku)->value('id'); // opsional
            $m->save();
        } elseif ($imageUrl !== null && $imageUrl !== '' && ($m->image_url === null || $m->image_url === '')) {
            $m->update(['image_url' => $imageUrl]); // backfill foto, jangan timpa yg sudah ada / upload manual
        }

        return $m;
    }
```

Ganti `upsertListing` untuk terima+teruskan imageUrl:

```php
    private function upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title, ?string $imageUrl = null): MarketplaceListing
    {
        $l = MarketplaceListing::updateOrCreate(
            ['channel' => $channel, 'seller_sku' => $sellerSku],
            ['item_id' => $itemId, 'variation_id' => $variationId, 'warehouse_id' => $warehouseId, 'title' => $title, 'resolved_at' => now()],
        );
        if ($l->master_id === null) {
            $master = $this->findOrCreateMaster($sellerSku, $title, $imageUrl);
            $l->update(['master_id' => $master->id]);
        }

        return $l;
    }
```

- [ ] **Step 4: Ekstrak foto di resolve**

Di `resolveTiktok`, di loop `foreach ($prod['skus'] ...)`, hitung image sekali per produk sebelum loop sku:

```php
                $img = (string) (data_get($prod, 'main_images.0.url_list.0') ?? data_get($prod, 'main_images.0.thumb_url_list.0') ?? '');
```
lalu pada pemanggilan upsertListing tambahkan argumen `$img !== '' ? $img : null` di posisi terakhir.

Di `resolveShopee`: `getItemBaseInfo` mengembalikan `response.item_list`. Ambil map item_id→image sekali:
```php
                $img = (string) (data_get($info, 'image.image_url_list.0') ?? '');
```
(`$info` = data item base info per item yang sudah diambil di loop), teruskan ke upsertListing arg terakhir.

> **Implementer:** VERIFIKASI field foto ke respons nyata / docs. TikTok product search 202309 mungkin tak selalu memuat `main_images` — kalau tak ada, biarkan `null` (foto fallback ke placeholder / upload manual). Shopee `get_item_base_info` memuat `image.image_url_list` (andal). Baca `resolveTiktok/resolveShopee` yang ada + `tests/Feature/MarketplaceMaster/ResolveMasterTest.php` untuk bentuk data.

- [ ] **Step 5: Perbaiki pemanggil lain + tes lama yang terpengaruh**

Cari pemanggil `findOrCreateMaster` (grep) — `seedFromTiktok`, `masterizeUnmastered`, `tautkanListing`. Signature-nya kompatibel (arg ke-2 = nama; imageUrl opsional). Tapi karena dedup pindah ke NAMA, cek & update tes yang mengasumsikan dedup-by-SKU:
- Jalankan `C:/php83/php.exe artisan test --filter=MarketplaceMaster` — perbaiki tes yang gagal karena perubahan dedup (mis. `ResolveMasterTest`/`MasterizeTest`/`SeedMasterTest`/`TautkanTest`). Umumnya tes pakai nama berbeda per SKU jadi tetap 1 master masing-masing; sesuaikan assertion bila ada yang menuntut master ber-`master_sku` tanpa `name_key`. Pastikan `MarketplaceMaster::create([...])` di tes lama menyertakan `name_key` bila dibutuhkan constraint (name_key nullable jadi tak wajib, tapi dedup butuh — set eksplisit di tes yang relevan).

- [ ] **Step 6: Jalankan — hijau.** `C:/php83/php.exe artisan test --filter=MarketplaceMaster` (DedupByName + tes lama hijau). Lalu full `--filter=Marketplace` (HQ/backdate tetap hijau).

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MarketplaceMasterService.php tests/
git commit -m "feat(mp-katalog): master dedup by NAMA + tarik foto listing saat resolve"
```

---

### Task 3: Aksi "Siapkan Master" + Hapus master

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php` (siapkanMaster + deleteOrphanMasters)
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (siapkan, deleteMaster)
- Modify: `routes/web.php`
- Test: `tests/Feature/MarketplaceMaster/SiapkanMasterTest.php`

**Interfaces:**
- Produces:
  - `siapkanMaster(): array` → `['found'=>int,'errors'=>string[],'orphan_deleted'=>int]` — resolve tiktok+shopee (try/catch per channel; kumpulkan error), lalu `deleteOrphanMasters()`.
  - `deleteOrphanMasters(): int` — hapus master tanpa listing (`MarketplaceMaster::doesntHave('listings')->get()` lalu delete masing-masing; master_channels ikut cascade). Return jumlah.
  - Rute `roi`… bukan — rute: `marketplace-stock.siapkan` (POST `/marketplace-stock/siapkan`), `marketplace-stock.master.hapus` (DELETE `/marketplace-stock/master/{master}`).
  - Controller `siapkan(MarketplaceMasterService): RedirectResponse`, `deleteMaster(MarketplaceMaster, MarketplaceMasterService): RedirectResponse`.

- [ ] **Step 1: Tulis tes (gagal dulu)**

`tests/Feature/MarketplaceMaster/SiapkanMasterTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiapkanMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_siapkan_hapus_master_orphan(): void
    {
        // Master tanpa listing (orphan) harus terhapus; master dgn listing tetap.
        $orphan = MarketplaceMaster::create(['master_sku' => 'OLD', 'name' => 'Old', 'name_key' => 'old']);
        $keep = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist', 'name_key' => 'face mist']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'master_id' => $keep->id]);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []], 200)]); // resolve: tak ada koneksi → 0, aman

        $this->actingAs($this->admin())->post(route('marketplace-stock.siapkan'))->assertRedirect()->assertSessionHas('status');

        $this->assertNull(MarketplaceMaster::find($orphan->id));
        $this->assertNotNull(MarketplaceMaster::find($keep->id));
    }

    public function test_hapus_master_listing_jadi_unmastered(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist', 'name_key' => 'face mist']);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'master_id' => $m->id]);

        $this->actingAs($this->admin())->delete(route('marketplace-stock.master.hapus', $m))->assertRedirect()->assertSessionHas('status');

        $this->assertNull(MarketplaceMaster::find($m->id));
        $this->assertNull($l->refresh()->master_id); // FK nullOnDelete
    }

    public function test_mitra_ditolak(): void
    {
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->post(route('marketplace-stock.siapkan'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — gagal.** `C:/php83/php.exe artisan test --filter=SiapkanMasterTest`

- [ ] **Step 3: Service `siapkanMaster` + `deleteOrphanMasters`**

Tambah ke `MarketplaceMasterService`:

```php
    public function siapkanMaster(): array
    {
        $found = 0;
        $errors = [];
        foreach (['tiktok', 'shopee'] as $channel) {
            try {
                $r = $this->resolveListings($channel);
                $found += (int) ($r['found'] ?? 0);
            } catch (\Throwable $e) {
                $errors[] = ucfirst($channel).': '.$e->getMessage();
            }
        }
        $orphanDeleted = $this->deleteOrphanMasters();

        return ['found' => $found, 'errors' => $errors, 'orphan_deleted' => $orphanDeleted];
    }

    /** Hapus master yang tak punya listing sama sekali. */
    public function deleteOrphanMasters(): int
    {
        $n = 0;
        foreach (MarketplaceMaster::doesntHave('listings')->get() as $m) {
            $m->delete(); // master_channels ikut cascade
            $n++;
        }

        return $n;
    }
```

- [ ] **Step 4: Controller `siapkan` + `deleteMaster`**

Tambah ke `MarketplaceStockController` (import `MarketplaceMaster` sudah ada):

```php
    public function siapkan(MarketplaceMasterService $svc): RedirectResponse
    {
        $r = $svc->siapkanMaster();
        $msg = "Siapkan master: {$r['found']} listing diproses, {$r['orphan_deleted']} master kosong dibersihkan.";
        $redirect = back()->with('status', $msg);
        if ($r['errors']) {
            $redirect->with('error', implode(' · ', $r['errors']).' — cek izin/scope Product di channel.');
        }

        return $redirect;
    }

    public function deleteMaster(MarketplaceMaster $master): RedirectResponse
    {
        $name = $master->name;
        $master->delete();

        return back()->with('status', "Master \"{$name}\" dihapus.");
    }
```

- [ ] **Step 5: Rute** — di grup `permission:manage_marketplace_stock`, tambah:

```php
        Route::post('/marketplace-stock/siapkan', [MarketplaceStockController::class, 'siapkan'])->name('marketplace-stock.siapkan');
        Route::delete('/marketplace-stock/master/{master}', [MarketplaceStockController::class, 'deleteMaster'])->name('marketplace-stock.master.hapus');
```

- [ ] **Step 6: Jalankan — hijau.** `C:/php83/php.exe artisan test --filter=SiapkanMasterTest` (3 passed) + `--filter=MarketplaceMaster`.

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MarketplaceMasterService.php app/Http/Controllers/MarketplaceStockController.php routes/web.php tests/Feature/MarketplaceMaster/SiapkanMasterTest.php
git commit -m "feat(mp-katalog): aksi Siapkan Master (resolve+bersih orphan) + Hapus master"
```

---

### Task 4: Toggle Bundle + Gabung master + Upload Foto

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php` (mergeMaster)
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (toggleBundle, gabung, uploadFoto)
- Modify: `routes/web.php`
- Test: `tests/Feature/MarketplaceMaster/MasterActionsTest.php`

**Interfaces:**
- Consumes: `ImageService::attach(Model, UploadedFile, string $collection, int $maxDim)`, `MarketplaceMaster::MASTER_IMAGE`.
- Produces:
  - `mergeMaster(MarketplaceMaster $source, MarketplaceMaster $target): void` — pindahkan semua listing source→target (`$source->listings()->update(['master_id'=>$target->id])`), lalu `$source->delete()`.
  - Rute: `marketplace-stock.master.bundle` (POST `/marketplace-stock/master/{master}/bundle`), `marketplace-stock.master.gabung` (POST `/marketplace-stock/master/{master}/gabung`, body `target_master_id`), `marketplace-stock.master.foto` (POST `/marketplace-stock/master/{master}/foto`, file `foto`).
  - Controller `toggleBundle`, `gabung`, `uploadFoto`.

- [ ] **Step 1: Tulis tes (gagal dulu)**

`tests/Feature/MarketplaceMaster/MasterActionsTest.php`:

```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MasterActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_toggle_bundle(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a', 'is_bundle' => false]);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.bundle', $m))->assertRedirect();
        $this->assertTrue($m->refresh()->is_bundle);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.bundle', $m))->assertRedirect();
        $this->assertFalse($m->refresh()->is_bundle);
    }

    public function test_gabung_master_pindah_listing_dan_hapus_sumber(): void
    {
        $src = MarketplaceMaster::create(['master_sku' => 'SRC', 'name' => 'Src', 'name_key' => 'src']);
        $tgt = MarketplaceMaster::create(['master_sku' => 'TGT', 'name' => 'Tgt', 'name_key' => 'tgt']);
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'SRC', 'item_id' => 'P1', 'master_id' => $src->id]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.gabung', $src), ['target_master_id' => $tgt->id])->assertRedirect()->assertSessionHas('status');

        $this->assertSame($tgt->id, $l->refresh()->master_id);
        $this->assertNull(MarketplaceMaster::find($src->id)); // sumber terhapus
    }

    public function test_upload_foto(): void
    {
        Storage::fake('public');
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a']);

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.master.foto', $m), ['foto' => UploadedFile::fake()->image('x.jpg', 600, 600)])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertNotNull($m->refresh()->imageUrl()); // foto upload terpasang → imageUrl() ada
    }

    public function test_upload_foto_tolak_bukan_gambar(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a']);
        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.master.foto', $m), ['foto' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('foto');
    }
}
```

- [ ] **Step 2: Jalankan — gagal.** `C:/php83/php.exe artisan test --filter=MasterActionsTest`

- [ ] **Step 3: Service `mergeMaster`**

```php
    public function mergeMaster(MarketplaceMaster $source, MarketplaceMaster $target): void
    {
        if ($source->id === $target->id) {
            return;
        }
        $source->listings()->update(['master_id' => $target->id]);
        $source->delete();
    }
```

- [ ] **Step 4: Controller actions**

Tambah `use App\Services\ImageService;` (import). Tambah:

```php
    public function toggleBundle(MarketplaceMaster $master): RedirectResponse
    {
        $master->update(['is_bundle' => ! $master->is_bundle]);

        return back()->with('status', $master->is_bundle ? "\"{$master->name}\" ditandai Bundle." : "\"{$master->name}\" jadi Satuan.");
    }

    public function gabung(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate(['target_master_id' => ['required', 'integer', 'exists:marketplace_masters,id', 'different:'.$master->id]]);
        $svc->mergeMaster($master, MarketplaceMaster::findOrFail($data['target_master_id']));

        return back()->with('status', 'Master digabung.');
    }

    public function uploadFoto(Request $r, MarketplaceMaster $master, ImageService $img): RedirectResponse
    {
        $r->validate(['foto' => ['required', 'image', 'max:5120']]);
        $img->attach($master, $r->file('foto'), MarketplaceMaster::MASTER_IMAGE);

        return back()->with('status', "Foto \"{$master->name}\" diperbarui.");
    }
```

- [ ] **Step 5: Rute** — di grup, tambah:

```php
        Route::post('/marketplace-stock/master/{master}/bundle', [MarketplaceStockController::class, 'toggleBundle'])->name('marketplace-stock.master.bundle');
        Route::post('/marketplace-stock/master/{master}/gabung', [MarketplaceStockController::class, 'gabung'])->name('marketplace-stock.master.gabung');
        Route::post('/marketplace-stock/master/{master}/foto', [MarketplaceStockController::class, 'uploadFoto'])->name('marketplace-stock.master.foto');
```

- [ ] **Step 6: Jalankan — hijau.** `C:/php83/php.exe artisan test --filter=MasterActionsTest` (4 passed) + `--filter=MarketplaceMaster`.

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Services/MarketplaceMasterService.php app/Http/Controllers/MarketplaceStockController.php routes/web.php tests/Feature/MarketplaceMaster/MasterActionsTest.php
git commit -m "feat(mp-katalog): toggle bundle + gabung master + upload foto"
```

---

### Task 5: UI katalog (index) + tab + controller + docs

**Files:**
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (index → tabs + counts + rows)
- Modify: `resources/views/marketplace-stock/index.blade.php` (tulis ulang jadi katalog)
- Modify: `docs/SISTEM.md`, `docs/PETA-SISTEM.md`
- Test: `tests/Feature/MarketplaceMaster/CatalogPageTest.php`

**Interfaces:**
- Consumes: semua aksi Task 3-4; `MarketplaceMaster::imageUrl()`, `is_bundle`; `MarketplaceMasterService::effectiveStock/effectivePrice`.
- Produces: `index()` yang mem-filter master by tab (`?tab=semua|satuan|bundle`), kirim `rows`, `counts` (semua/satuan/bundle), `unmasteredCount`, `masters` (utk dropdown gabung).

- [ ] **Step 1: Tulis tes render (gagal dulu)**

`tests/Feature/MarketplaceMaster/CatalogPageTest.php`:

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

    private function master(string $sku, string $name, bool $bundle = false): MarketplaceMaster
    {
        $m = MarketplaceMaster::create(['master_sku' => $sku, 'name' => $name, 'name_key' => MarketplaceMaster::normalizeName($name), 'is_bundle' => $bundle, 'base_stock' => 10, 'base_price' => 39000]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => $sku, 'item_id' => 'P'.$sku, 'master_id' => $m->id]);

        return $m;
    }

    public function test_katalog_render_dan_tab_default_semua(): void
    {
        $this->master('FM-1', 'Face Mist');
        $this->master('JPX-3', 'Bundling 3pcs', true);

        $this->actingAs($this->admin())->get('/marketplace-stock')->assertOk()
            ->assertSee('Face Mist')->assertSee('Bundling 3pcs')->assertSee('Produk Master');
    }

    public function test_tab_bundle_hanya_bundle(): void
    {
        $this->master('FM-1', 'Face Mist');
        $this->master('JPX-3', 'Bundling 3pcs', true);

        $this->actingAs($this->admin())->get('/marketplace-stock?tab=bundle')->assertOk()
            ->assertSee('Bundling 3pcs')->assertDontSee('Face Mist');
    }

    public function test_tab_satuan_hanya_satuan(): void
    {
        $this->master('FM-1', 'Face Mist');
        $this->master('JPX-3', 'Bundling 3pcs', true);

        $this->actingAs($this->admin())->get('/marketplace-stock?tab=satuan')->assertOk()
            ->assertSee('Face Mist')->assertDontSee('Bundling 3pcs');
    }

    public function test_reseller_ditolak(): void
    {
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->get('/marketplace-stock')->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — gagal.** `C:/php83/php.exe artisan test --filter=CatalogPageTest`

- [ ] **Step 3: Rewrite `index()`**

```php
    public function index(Request $request, MarketplaceMasterService $svc): View
    {
        $tab = in_array($request->query('tab'), ['satuan', 'bundle'], true) ? $request->query('tab') : 'semua';

        $base = MarketplaceMaster::with(['channels', 'listings']);
        $q = (clone $base);
        if ($tab === 'satuan') {
            $q->where('is_bundle', false);
        } elseif ($tab === 'bundle') {
            $q->where('is_bundle', true);
        }
        $masters = $q->orderBy('name')->get();

        $rows = $masters->map(fn (MarketplaceMaster $m) => [
            'master' => $m,
            'tiktok' => $this->channelSummary($svc, $m, 'tiktok'),
            'shopee' => $this->channelSummary($svc, $m, 'shopee'),
        ]);

        return view('marketplace-stock.index', [
            'tab' => $tab,
            'rows' => $rows,
            'counts' => [
                'semua' => (clone $base)->count(),
                'satuan' => (clone $base)->where('is_bundle', false)->count(),
                'bundle' => (clone $base)->where('is_bundle', true)->count(),
            ],
            'unmasteredCount' => MarketplaceListing::whereNull('master_id')->count(),
            'allMasters' => MarketplaceMaster::orderBy('name')->get(['id', 'master_sku', 'name']),
        ]);
    }
```

(Buang `unmastered`/`masters` lama dari index bila tak dipakai lagi; `channelSummary` private sudah ada.)

- [ ] **Step 4: Tulis ulang `resources/views/marketplace-stock/index.blade.php`**

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
            <form method="POST" action="{{ route('marketplace-stock.siapkan') }}">@csrf<button class="px-4 py-2 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">⚡ Siapkan Master (TikTok+Shopee)</button></form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <a href="{{ route('marketplace-stock.channel', 'tiktok') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga TikTok →</a>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga Shopee →</a>
        </div>
        @if($unmasteredCount > 0)
            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{{ $unmasteredCount }} listing belum termaster — klik "Siapkan Master" untuk merapikan.</p>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 text-sm">
        @foreach(['semua' => 'Semua', 'satuan' => 'Satuan', 'bundle' => 'Bundle'] as $key => $label)
            <a href="{{ $tabUrl($key) }}" class="px-4 py-2 rounded-lg {{ $tab === $key ? 'bg-stone-800 text-white' : 'bg-white border border-stone-200 text-stone-600 hover:bg-stone-50' }}">{{ $label }} <span class="opacity-70">({{ $counts[$key] }})</span></a>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        @if(count($rows))
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Produk</th>
                    <th class="text-left">Master SKU</th>
                    <th class="text-left">Harga</th>
                    <th class="text-left">Stok</th>
                    <th class="text-left">Channel Terkait</th>
                    <th class="text-left pr-4">Atur</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php $m = $row['master']; @endphp
                    <tr class="border-t border-stone-100 align-top">
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                @if($m->imageUrl())
                                    <img src="{{ $m->imageUrl() }}" alt="" class="w-9 h-9 rounded-lg object-cover border border-stone-200" loading="lazy">
                                @else
                                    <div class="w-9 h-9 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-300 text-[9px]">no img</div>
                                @endif
                                <div>
                                    <div class="font-semibold text-stone-800 whitespace-normal max-w-[260px]">{{ $m->name }}</div>
                                    @if($m->is_bundle)<span class="inline-block mt-0.5 px-1.5 py-0.5 rounded-sm bg-purple-50 text-purple-700 text-[9px] font-semibold">BUNDLE</span>@endif
                                </div>
                            </div>
                        </td>
                        <td class="py-2.5 font-mono text-stone-500">{{ $m->master_sku }}</td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1">@csrf
                                <input type="number" name="price" min="0" step="any" value="{{ $m->base_price !== null ? (int) $m->base_price : '' }}" placeholder="—" class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">✓</button>
                            </form>
                        </td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1">@csrf
                                <input type="number" name="quantity" min="0" step="1" value="{{ $m->base_stock }}" placeholder="—" class="w-20 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">✓</button>
                            </form>
                        </td>
                        <td class="py-2.5">
                            @foreach(['tiktok' => 'TikTok', 'shopee' => 'Shopee'] as $ch => $lbl)
                                @if($row[$ch]['listing'])
                                    <div class="text-[11px] text-stone-600">{{ $lbl }}: stok {{ $row[$ch]['listing']->last_status ?? '—' }} / harga {{ $row[$ch]['listing']->last_price_status ?? '—' }}</div>
                                @endif
                            @endforeach
                            @if(! $row['tiktok']['listing'] && ! $row['shopee']['listing'])<span class="text-stone-400 text-[11px]">belum ada listing</span>@endif
                        </td>
                        <td class="py-2.5 pr-4">
                            <details class="inline-block">
                                <summary class="cursor-pointer text-indigo-700 text-[11px]">Atur ▾</summary>
                                <div class="mt-1 space-y-1 bg-stone-50 border border-stone-200 rounded-lg p-2 min-w-[220px]">
                                    <form method="POST" action="{{ route('marketplace-stock.push', $m) }}">@csrf<button class="w-full text-left px-2 py-1 text-[11px] hover:bg-stone-100 rounded">⇪ Sinkron</button></form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.bundle', $m) }}">@csrf<button class="w-full text-left px-2 py-1 text-[11px] hover:bg-stone-100 rounded">{{ $m->is_bundle ? 'Jadikan Satuan' : 'Jadikan Bundle' }}</button></form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.foto', $m) }}" enctype="multipart/form-data" class="flex items-center gap-1 px-2 py-1">@csrf
                                        <input type="file" name="foto" accept="image/*" class="text-[10px] w-32">
                                        <button class="px-2 py-0.5 bg-stone-700 text-white rounded text-[10px]">Foto</button>
                                    </form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.gabung', $m) }}" class="flex items-center gap-1 px-2 py-1">@csrf
                                        <select name="target_master_id" required class="text-[10px] px-1 py-0.5 border border-stone-300 rounded max-w-[130px]">
                                            <option value="">Gabung ke…</option>
                                            @foreach($allMasters as $mm)@if($mm->id !== $m->id)<option value="{{ $mm->id }}">{{ $mm->name }}</option>@endif @endforeach
                                        </select>
                                        <button class="px-2 py-0.5 bg-amber-600 text-white rounded text-[10px]">Gabung</button>
                                    </form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.hapus', $m) }}" onsubmit="return confirm('Hapus master ini?')">@csrf @method('DELETE')<button class="w-full text-left px-2 py-1 text-[11px] text-rose-600 hover:bg-rose-50 rounded">Hapus</button></form>
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <p class="px-5 py-8 text-center text-stone-400 text-sm">Belum ada master. Klik "Siapkan Master (TikTok+Shopee)" untuk menariknya otomatis.</p>
        @endif
    </div>
</div>
@endsection
```

- [ ] **Step 5: Jalankan — hijau.** `C:/php83/php.exe artisan test --filter=CatalogPageTest` (4 passed). Lalu SELURUH suite `C:/php83/php.exe artisan test` — semua hijau (view lama tak dipakai lagi; pastikan tak ada tes yang menuntut elemen lama seperti "belum termaster" table / tombol "Buat master otomatis"/"Refresh listing" yang mungkin dihapus — sesuaikan/junk-kan bila ada).

- [ ] **Step 6: Docs** — `docs/SISTEM.md` §9d (Produk Master): perbarui — katalog by nama, tab Satuan/Bundle, foto, gabung, hapus, aksi "Siapkan Master". `docs/PETA-SISTEM.md`: catat redesign katalog.

- [ ] **Step 7: Pint + commit**

```bash
C:/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/MarketplaceStockController.php resources/views/marketplace-stock/index.blade.php docs/ tests/Feature/MarketplaceMaster/CatalogPageTest.php
git commit -m "feat(mp-katalog): halaman katalog Produk Master (tab Satuan/Bundle, foto, atur: sinkron/bundle/gabung/hapus)"
```

---

## Self-Review

**1. Spec coverage:**
- Master dedup by nama → T2 (findOrCreateMaster by name_key). ✓
- Auto dari TikTok+Shopee sekali klik ("Siapkan Master") → T3 (siapkanMaster). ✓
- Gabung SKU beda-nama ke master lain → T4 (gabung/mergeMaster). ✓
- Katalog ala Desty (Foto/Produk/Master SKU/Harga/Stok/Channel/Atur) + tab Satuan/Bundle → T5. ✓
- Foto auto marketplace + upload manual → T2 (image_url dari resolve) + T4 (uploadFoto) + T1 (imageUrl precedence). ✓
- Bundle auto dari nama + toggle → T1 (detectBundle) + T2 (set saat buat) + T4 (toggle). ✓
- Tombol Hapus → T3 (deleteMaster). ✓
- Buang daftar "belum termaster" besar → banner kecil (T5). ✓
- Terpisah HQ → Global Constraint; tak ada aksi menyentuh HQ. ✓
- Reset master lama (rebuild by nama) → T1 (migrasi reset) + T3 (siapkan). ✓

**2. Placeholder scan:** Tak ada TBD/TODO. `<next>` migration number = instruksi eksplisit (Step 1 Task 1 menghitungnya). Catatan "verifikasi field foto TikTok" = arahan konkret dgn fallback null, bukan placeholder.

**3. Type consistency:**
- `findOrCreateMaster(string, ?string, ?string)`, `upsertListing(..., ?string $imageUrl=null)`, `siapkanMaster(): array{found,errors,orphan_deleted}`, `deleteOrphanMasters(): int`, `mergeMaster(MarketplaceMaster,MarketplaceMaster): void` — konsisten antar task. ✓
- `MarketplaceMaster::normalizeName/detectBundle/imageUrl/MASTER_IMAGE/is_bundle/name_key/image_url` — didefinisikan T1, dipakai T2/T3/T4/T5. ✓
- Nama rute `marketplace-stock.siapkan/master.hapus/master.bundle/master.gabung/master.foto` — konsisten controller↔routes↔view. ✓
- `channelSummary` (existing private) dipakai index T5 — sudah ada. ✓

---

## Catatan
- **Reset data master**: migrasi Task 1 mengosongkan master lama (fragmented) + null `listing.master_id`; user klik "Siapkan Master" utk membangun ulang by nama. Data mirror, rebuildable — aman.
- **Foto TikTok**: best-effort (field `main_images` mungkin absen di product search; fallback null/placeholder/upload). Shopee andal.
