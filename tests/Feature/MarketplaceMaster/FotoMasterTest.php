<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\File;
use App\Models\MarketplaceMaster;
use App\Models\Product;
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

    public function test_jadikan_utama_ubah_image_url(): void
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

    /**
     * Tabel files dipakai bersama (galeri produk, bukti bayar PO, dst). Guard harus 404
     * bukan cuma utk master lain, tapi juga file di koleksi lain & file milik MODEL lain
     * yg fileable_id-nya kebetulan sama dgn id master — dan tak boleh ada efek samping.
     */
    public function test_foto_koleksi_lain_atau_model_lain_ditolak_tanpa_efek_samping(): void
    {
        $m = $this->masterWith2Foto();
        $admin = $this->admin();

        $lainKoleksi = app(ImageService::class)->attach($m, UploadedFile::fake()->image('x.jpg'), 'koleksi_lain');
        $lainKoleksi->update(['sort_order' => 7]);
        $milikProduk = File::create([
            'fileable_type' => Product::class, 'fileable_id' => $m->id, 'collection' => MarketplaceMaster::MASTER_IMAGE,
            'disk' => 'public', 'path' => 'x/produk.jpg', 'sort_order' => 7,
        ]);

        foreach ([$lainKoleksi, $milikProduk] as $asing) {
            $this->actingAs($admin)->delete(route('marketplace-stock.master.foto.hapus', [$m, $asing]))->assertNotFound();
            $this->actingAs($admin)->post(route('marketplace-stock.master.foto.utama', [$m, $asing]))->assertNotFound();

            $this->assertSame(7, File::findOrFail($asing->id)->sort_order); // tak terhapus & tak diutak-atik
        }
        // foto asli master tak terganggu (urutan tetap 0,1 — tak digeser oleh setFotoUtama yg ditolak)
        $this->assertSame([0, 1], $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->pluck('sort_order')->all());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $m = $this->masterWith2Foto();
        $file = $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->first();
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->delete(route('marketplace-stock.master.foto.hapus', [$m, $file]))->assertForbidden();
    }
}
