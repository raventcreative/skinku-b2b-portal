<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\File;
use App\Models\MarketplaceMaster;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Upload foto master anti-timeout: foto dikecilkan di BROWSER (canvas, maks 1600px, q0.85)
 * sebelum form dikirim, dan server menyimpan foto master lebih tajam (1600px, q85) ketimbang
 * default ImageService (1280/q80). JS tak bisa dijalankan PHPUnit — yang diuji di sini:
 * parameter server, default lama tetap utk pemanggil lain, dan skrip kompres termuat di halaman.
 */
class FotoKompresTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function master(): MarketplaceMaster
    {
        return MarketplaceMaster::create(['master_sku' => 'K-1', 'name' => 'K', 'name_key' => 'k']);
    }

    /** @return array{0:int,1:int} */
    private function dimensi(File $f): array
    {
        [$w, $h] = getimagesize(Storage::disk('public')->path($f->path));

        return [$w, $h];
    }

    public function test_attach_default_tetap_1280_untuk_pemanggil_lain(): void
    {
        Storage::fake('public');
        $f = app(ImageService::class)->attach($this->master(), UploadedFile::fake()->image('a.jpg', 2400, 1800), 'lain');

        $this->assertSame([1280, 960], $this->dimensi($f));
    }

    public function test_attach_menghormati_maxdim_dan_quality_kustom(): void
    {
        Storage::fake('public');
        $f = app(ImageService::class)->attach($this->master(), UploadedFile::fake()->image('a.jpg', 2400, 1800), MarketplaceMaster::MASTER_IMAGE, 1600, 85);

        $this->assertSame([1600, 1200], $this->dimensi($f));
        $this->assertSame('image/jpeg', $f->mime_type);
    }

    public function test_foto_master_lewat_form_disimpan_1600px(): void
    {
        Storage::fake('public');
        $m = $this->master();

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'K', 'master_sku' => 'K-1',
            'foto' => [UploadedFile::fake()->image('besar.jpg', 3000, 2000)],
        ])->assertRedirect();

        $f = $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->first();
        $this->assertNotNull($f);
        $this->assertSame([1600, 1067], $this->dimensi($f));
    }

    public function test_foto_kecil_tidak_diperbesar(): void
    {
        Storage::fake('public');
        $m = $this->master();

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'K', 'master_sku' => 'K-1',
            'foto' => [UploadedFile::fake()->image('kecil.jpg', 800, 600)],
        ])->assertRedirect();

        $this->assertSame([800, 600], $this->dimensi($m->filesIn(MarketplaceMaster::MASTER_IMAGE)->first()));
    }

    public function test_halaman_tambah_dan_ubah_memuat_skrip_kompres_browser(): void
    {
        $admin = $this->admin();
        foreach ([route('marketplace-stock.create'), route('marketplace-stock.edit', $this->master())] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('MAX_DIM = 1600', $html);
            $this->assertStringContainsString('canvas.toBlob', $html);
            $this->assertStringContainsString('new DataTransfer()', $html);
            $this->assertStringContainsString('pendingSubmit', $html); // submit ditahan sampai kompres selesai
        }
    }
}
