<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_form_render_field_lengkap(): void
    {
        $this->actingAs($this->admin())->get(route('marketplace-stock.create'))
            ->assertOk()
            ->assertSee('Kategori')->assertSee('Deskripsi')->assertSee('Berat')
            ->assertSee('Barcode')->assertSee('foto[]', false);
    }

    public function test_form_tambah_tanpa_galeri_tapi_upload_multi(): void
    {
        $html = $this->actingAs($this->admin())->get(route('marketplace-stock.create'))
            ->assertOk()
            ->assertSee('Foto Produk')          // kartu foto + tombol "+" kini tampil juga di Tambah (bisa upload saat buat)
            ->assertDontSee('Jadikan Utama')    // tapi tanpa thumbnail existing (master belum ada)
            ->assertSee('enctype="multipart/form-data"', false)
            ->getContent();

        // input upload foto: name="foto[]" + multiple (urutan atribut bebas)
        $this->assertMatchesRegularExpression('/<input[^>]*name="foto\[\]"[^>]*\bmultiple\b[^>]*>/', $html);
    }

    public function test_form_edit_tampilkan_galeri_dan_field_terisi(): void
    {
        Storage::fake('public');
        $m = MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x', 'category' => 'Body Care', 'weight_g' => 50]);
        // 2 foto: yang ke-2 (non-utama) memunculkan tombol "Jadikan Utama"; foto pertama = utama (tanpa tombol itu).
        $utama = app(ImageService::class)->attach($m, UploadedFile::fake()->image('a.jpg'), MarketplaceMaster::MASTER_IMAGE);
        $kedua = app(ImageService::class)->attach($m, UploadedFile::fake()->image('b.jpg'), MarketplaceMaster::MASTER_IMAGE);

        $this->actingAs($this->admin())->get(route('marketplace-stock.edit', $m))
            ->assertOk()
            ->assertSee('Body Care')          // kategori terisi
            ->assertSee('value="50"', false)  // berat terisi
            ->assertSee('Foto Produk')
            ->assertSee('Jadikan Utama')      // aksi galeri (foto ke-2)
            // form per-foto menunjuk ke route Task 2 yang benar
            ->assertSee(route('marketplace-stock.master.foto.hapus', [$m, $utama->id]), false)
            ->assertSee(route('marketplace-stock.master.foto.hapus', [$m, $kedua->id]), false)
            ->assertSee(route('marketplace-stock.master.foto.utama', [$m, $kedua->id]), false)
            // foto utama tak punya tombol "Jadikan Utama"
            ->assertDontSee(route('marketplace-stock.master.foto.utama', [$m, $utama->id]), false);
    }

    public function test_form_edit_tanpa_foto_tampilkan_kotak_tambah(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'E-1', 'name' => 'E', 'name_key' => 'e']);

        // Tanpa foto: kotak "+ Tambah Foto" jadi empty-state (bukan lagi teks "Belum ada foto").
        $this->actingAs($this->admin())->get(route('marketplace-stock.edit', $m))
            ->assertOk()
            ->assertSee('Foto Produk')
            ->assertSee('Tambah Foto')
            ->assertDontSee('Jadikan Utama');
    }

    public function test_tombol_tambah_foto_terhubung_ke_input_file(): void
    {
        // Orang awam cukup klik kotak "+ Tambah Foto" (label) yang memicu input file tersembunyi —
        // bukan input "Choose File" mentah. Buktikan label for= dan input id= nyambung ke foto[].
        $html = $this->actingAs($this->admin())->get(route('marketplace-stock.create'))
            ->assertOk()
            ->assertSee('Tambah Foto')
            ->getContent();

        $this->assertMatchesRegularExpression('/<label[^>]*\bfor="fotoUpload"[^>]*>/', $html, 'Tombol "+" (label for="fotoUpload") tak ada.');
        preg_match('/<input\b[^>]*\bid="fotoUpload"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, 'Input file id="fotoUpload" tak ada.');
        $this->assertStringContainsString('type="file"', $m[0]);
        $this->assertStringContainsString('name="foto[]"', $m[0]);
    }

    public function test_form_edit_tak_ada_form_bersarang(): void
    {
        Storage::fake('public');
        $m = MarketplaceMaster::create(['master_sku' => 'N-1', 'name' => 'N', 'name_key' => 'n']);
        app(ImageService::class)->attach($m, UploadedFile::fake()->image('a.jpg'), MarketplaceMaster::MASTER_IMAGE);
        app(ImageService::class)->attach($m, UploadedFile::fake()->image('b.jpg'), MarketplaceMaster::MASTER_IMAGE);

        $html = $this->actingAs($this->admin())->get(route('marketplace-stock.edit', $m))->assertOk()->getContent();

        // Tombol Hapus/Jadikan Utama tiap foto = <form> sendiri. HTML melarang <form> bersarang, jadi
        // semuanya WAJIB di luar <form> update utama: kedalaman <form> di area konten maks 1.
        preg_match_all('#<(/?)form\b#i', Str::between($html, '<main', '</main>'), $tags);
        $depth = 0;
        $maxDepth = 0;
        foreach ($tags[1] as $closing) {
            $depth += $closing === '/' ? -1 : 1;
            $maxDepth = max($maxDepth, $depth);
        }

        $this->assertSame(0, $depth, '<form> tak seimbang (ada yang tak tertutup).');
        $this->assertSame(1, $maxDepth, 'Ada <form> bersarang di halaman Ubah Produk Master.');
    }

    public function test_form_edit_flash_status_tampil_sekali(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'S-1', 'name' => 'S', 'name_key' => 's']);

        // Layout sudah menampilkan session('status'); view form tak boleh mengulanginya (banner dobel
        // tiap habis "Hapus foto"/"Jadikan Utama" yang redirect back() dgn flash status).
        $html = $this->actingAs($this->admin())->withSession(['status' => 'Foto dihapus.'])
            ->get(route('marketplace-stock.edit', $m))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Foto dihapus.'));
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
            'name' => 'Body Wash', 'master_sku' => 'BW-1', 'foto' => [UploadedFile::fake()->image('bw.jpg')],
        ])->assertRedirect();
        $m = MarketplaceMaster::where('master_sku', 'BW-1')->first();
        $this->assertNotNull($m->imageUrl());
    }

    public function test_store_simpan_field_lengkap(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Day Cream 10gr', 'master_sku' => 'DC-1',
            'category' => 'Perawatan Wajah / BB Cream',
            'description' => 'Day cream BB SPF 30 ...',
            'weight_g' => 10, 'length_cm' => 5, 'width_cm' => 5, 'height_cm' => 5,
            'barcode' => '8991234567890',
        ])->assertRedirect(route('marketplace-stock.index'));

        $m = MarketplaceMaster::where('master_sku', 'DC-1')->firstOrFail();
        $this->assertSame('Perawatan Wajah / BB Cream', $m->category);
        $this->assertSame('Day cream BB SPF 30 ...', $m->description);
        $this->assertSame(10, $m->weight_g);
        $this->assertSame(5, $m->length_cm);
        $this->assertSame('8991234567890', $m->barcode);
    }

    public function test_update_simpan_field_lengkap(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'X', 'master_sku' => 'X-1', 'category' => 'Body Care', 'weight_g' => 100,
        ])->assertRedirect();
        $m->refresh();
        $this->assertSame('Body Care', $m->category);
        $this->assertSame(100, $m->weight_g);
    }

    public function test_store_foto_banyak(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Multi Foto', 'master_sku' => 'MF-1',
            'foto' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
        ])->assertRedirect();
        $m = MarketplaceMaster::where('master_sku', 'MF-1')->firstOrFail();
        $this->assertSame(3, $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->count());
    }

    public function test_foto_dibatasi_maks_9(): void
    {
        Storage::fake('public');
        $m = MarketplaceMaster::create(['master_sku' => 'C-1', 'name' => 'C', 'name_key' => 'c']);
        // sudah ada 8
        for ($i = 0; $i < 8; $i++) {
            app(ImageService::class)->attach($m, UploadedFile::fake()->image("x{$i}.jpg"), MarketplaceMaster::MASTER_IMAGE);
        }
        // kirim 3 lagi → cuma 1 yang masuk (total mentok 9)
        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'C', 'master_sku' => 'C-1',
            'foto' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
        ])->assertRedirect();
        $this->assertSame(9, $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->count());
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
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A-1', 'item_id' => 'P1', 'master_id' => $m->id]);

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
        $before = DB::table('stock_movements')->count();
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), ['name' => 'Z', 'master_sku' => 'Z-1', 'stock' => '10']);
        $this->assertSame($before, DB::table('stock_movements')->count());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $r = $this->reseller();
        $this->actingAs($r)->get(route('marketplace-stock.create'))->assertForbidden();
        $this->actingAs($r)->post(route('marketplace-stock.store'), ['name' => 'x', 'master_sku' => 'x'])->assertForbidden();
    }

    /** Angka raksasa (> batas kolom) ditolak 422, bukan 500 di MySQL strict. */
    public function test_field_angka_raksasa_ditolak(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Big', 'master_sku' => 'BIG-1', 'weight_g' => '99999999999', // > unsignedInt 4294967295
        ])->assertSessionHasErrors('weight_g');

        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Big2', 'master_sku' => 'BIG-2', 'stock' => '9999999999', // > signed int 2147483647
        ])->assertSessionHasErrors('stock');

        $this->assertSame(0, MarketplaceMaster::whereIn('master_sku', ['BIG-1', 'BIG-2'])->count());
    }
}
