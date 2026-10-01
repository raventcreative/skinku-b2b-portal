<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\File;
use App\Models\MarketplaceMaster;
use App\Models\User;
use App\Services\ImageService;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Atur urutan foto master dengan geser (drag & drop): endpoint AJAX `marketplace-stock.master.foto.urutan`
 * menyimpan sort_order sesuai urutan kiriman (pertama = Utama). Kiriman wajib PERSIS himpunan foto master itu
 * (guard IDOR + cegah urutan setengah). Skrip geser (JS) diverifikasi di browser; di sini: server + atribut halaman.
 */
class FotoUrutanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function reseller(): User
    {
        return User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
    }

    /** @return array{0: MarketplaceMaster, 1: list<File>} master + fotonya urut (pertama = Utama) */
    private function masterBerfoto(int $jumlah = 3, string $sku = 'U-1'): array
    {
        $m = MarketplaceMaster::create(['master_sku' => $sku, 'name' => 'Produk '.$sku]);
        for ($i = 1; $i <= $jumlah; $i++) {
            app(ImageService::class)->attach($m, UploadedFile::fake()->image("{$sku}-{$i}.jpg", 20 + $i, 20 + $i), MarketplaceMaster::MASTER_IMAGE);
        }

        return [$m, $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->get()->all()];
    }

    /** @return list<int> id foto master sesuai urutan tersimpan */
    private function urutan(MarketplaceMaster $m): array
    {
        return $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_urutan_tersimpan_dan_foto_pertama_jadi_utama(): void
    {
        [$m, [$a, $b, $c]] = $this->masterBerfoto();

        $this->actingAs($this->admin())
            ->postJson(route('marketplace-stock.master.foto.urutan', $m), ['urutan' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'utama' => $c->id]);

        $this->assertSame([$c->id, $a->id, $b->id], $this->urutan($m));
        $this->assertSame($c->url(), $m->fresh()->imageUrl());
    }

    public function test_urutan_baru_mengubah_photo_hash_supaya_dorong_berikutnya_kirim_ulang(): void
    {
        [$m, [$a, $b, $c]] = $this->masterBerfoto();
        $svc = app(MarketplaceMasterService::class);
        $sebelum = $svc->photoHash($m);

        $this->actingAs($this->admin())
            ->postJson(route('marketplace-stock.master.foto.urutan', $m), ['urutan' => [$b->id, $c->id, $a->id]])
            ->assertOk();

        $this->assertNotSame($sebelum, $svc->photoHash($m->fresh()));
    }

    public function test_ditolak_bila_tak_persis_himpunan_foto_master_tanpa_mengubah_apa_pun(): void
    {
        [$m, [$a, $b, $c]] = $this->masterBerfoto();
        [, [$asing]] = $this->masterBerfoto(1, 'LAIN-1');
        $admin = $this->admin();
        $asli = $this->urutan($m);

        foreach ([
            'kurang satu' => [$b->id, $a->id],
            'id foto master lain (IDOR)' => [$c->id, $b->id, $asing->id],
            'kelebihan id asing' => [$c->id, $b->id, $a->id, $asing->id],
            'duplikat' => [$a->id, $a->id, $b->id],
            'kosong' => [],
            'bukan array' => 'x',
        ] as $kasus => $kiriman) {
            // Selalu 422 (BUKAN redirect 302 yang diikuti fetch jadi 200 → kegagalan tampak sukses).
            $this->actingAs($admin)
                ->postJson(route('marketplace-stock.master.foto.urutan', $m), ['urutan' => $kiriman])
                ->assertStatus(422);
            $this->assertSame($asli, $this->urutan($m), "urutan berubah pada kasus: {$kasus}");
        }
        $this->assertSame([$asing->id], $this->urutan(MarketplaceMaster::where('master_sku', 'LAIN-1')->first()));
    }

    public function test_tanpa_json_kembali_ke_halaman_dengan_status(): void
    {
        [$m, [$a, $b]] = $this->masterBerfoto(2);

        $this->actingAs($this->admin())
            ->from(route('marketplace-stock.edit', $m))
            ->post(route('marketplace-stock.master.foto.urutan', $m), ['urutan' => [$b->id, $a->id]])
            ->assertRedirect(route('marketplace-stock.edit', $m))
            ->assertSessionHas('status', 'Urutan foto disimpan.');

        $this->assertSame([$b->id, $a->id], $this->urutan($m));
    }

    public function test_non_izin_ditolak(): void
    {
        [$m, [$a, $b]] = $this->masterBerfoto(2);

        $this->actingAs($this->reseller())
            ->postJson(route('marketplace-stock.master.foto.urutan', $m), ['urutan' => [$b->id, $a->id]])
            ->assertForbidden();

        $this->assertSame([$a->id, $b->id], $this->urutan($m));
    }

    public function test_halaman_ubah_siap_digeser_halaman_tambah_tidak(): void
    {
        [$m, $foto] = $this->masterBerfoto(2);
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('marketplace-stock.edit', $m))->assertOk()->getContent();
        $this->assertStringContainsString('data-urutan-url="'.route('marketplace-stock.master.foto.urutan', $m).'"', $html);
        foreach ($foto as $f) {
            $this->assertStringContainsString('data-file-id="'.$f->id.'"', $html);
        }
        $this->assertStringContainsString('class="mps-grip"', $html);
        $this->assertStringContainsString('Atur urutan:', $html);
        $this->assertStringContainsString("grid.getAttribute('data-urutan-url')", $html);

        // Master baru belum punya foto tersimpan → grid tak diberi atribut url urutan (foto Baru diurutkan lewat urutan_foto saat Simpan).
        $this->assertStringNotContainsString('data-urutan-url="', $this->actingAs($admin)->get(route('marketplace-stock.create'))->assertOk()->getContent());
    }

    /** @return list<string> original_name foto master sesuai urutan tersimpan */
    private function namaUrut(MarketplaceMaster $m): array
    {
        return $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->pluck('original_name')->all();
    }

    public function test_foto_baru_bisa_diurutkan_bersama_foto_tersimpan_saat_simpan(): void
    {
        [$m, [$a, $b]] = $this->masterBerfoto(2);

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => $m->name, 'master_sku' => $m->master_sku,
            'foto' => [UploadedFile::fake()->image('baru0.jpg'), UploadedFile::fake()->image('baru1.jpg')],
            'urutan_foto' => "n1,f{$b->id},n0,f{$a->id}",
        ])->assertRedirect();

        $this->assertSame(['baru1.jpg', 'U-1-2.jpg', 'baru0.jpg', 'U-1-1.jpg'], $this->namaUrut($m));
    }

    public function test_tambah_produk_foto_baru_ikut_urutan_geser(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Produk Baru', 'master_sku' => 'NB-1',
            'foto' => [UploadedFile::fake()->image('x0.jpg'), UploadedFile::fake()->image('x1.jpg'), UploadedFile::fake()->image('x2.jpg')],
            'urutan_foto' => 'n2,n0,n1',
        ])->assertRedirect();

        $this->assertSame(['x2.jpg', 'x0.jpg', 'x1.jpg'], $this->namaUrut(MarketplaceMaster::where('master_sku', 'NB-1')->firstOrFail()));
    }

    public function test_urutan_foto_abaikan_token_asing_dan_foto_master_lain(): void
    {
        [$m, [$a, $b, $c]] = $this->masterBerfoto();
        [, [$asing]] = $this->masterBerfoto(1, 'LAIN');

        // Token foto master lain, token rusak, duplikat diabaikan; foto yang tak disebut ditaruh di belakang (urutan lama).
        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => $m->name, 'master_sku' => $m->master_sku,
            'urutan_foto' => "f{$asing->id},x9,f{$c->id},f{$c->id},n5",
        ])->assertRedirect();

        $this->assertSame([$c->id, $a->id, $b->id], $this->urutan($m));
        $this->assertSame(0, (int) $asing->fresh()->sort_order);
    }

    public function test_tanpa_urutan_foto_foto_baru_tetap_di_belakang(): void
    {
        [$m, [$a]] = $this->masterBerfoto(1);

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => $m->name, 'master_sku' => $m->master_sku,
            'foto' => [UploadedFile::fake()->image('baru.jpg')],
        ])->assertRedirect();

        $this->assertSame(['U-1-1.jpg', 'baru.jpg'], $this->namaUrut($m));
    }
}
