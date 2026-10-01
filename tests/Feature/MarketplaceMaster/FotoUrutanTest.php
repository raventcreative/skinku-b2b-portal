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

        // Master baru belum punya foto tersimpan → grid tak diberi atribut url urutan (skrip geser tak aktif).
        $this->assertStringNotContainsString('data-urutan-url="', $this->actingAs($admin)->get(route('marketplace-stock.create'))->assertOk()->getContent());
    }
}
