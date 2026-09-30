<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Status push FOTO per-listing (last_photo_status / last_photo_error) tampil di halaman channel,
 * sejajar dgn baris status Konten — markup & kelas sama (hanya kelas yang sudah ter-compile).
 */
class PhotoStatusDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function masterDenganListing(array $jejak, string $channel = 'tiktok'): MarketplaceMaster
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Serum X', 'base_stock' => 20]);
        MarketplaceListing::create(array_merge(['channel' => $channel, 'seller_sku' => 'SKU-1', 'item_id' => 'P1', 'master_id' => $m->id], $jejak));

        return $m;
    }

    private function halaman(string $channel = 'tiktok'): string
    {
        return $this->actingAs($this->admin())->get(route('marketplace-stock.channel', $channel))->assertOk()->getContent();
    }

    /** Atribut class dari <div> yang isinya persis $label; null bila barisnya tak dirender. */
    private function kelasBaris(string $html, string $label): ?string
    {
        return preg_match('/<div class="([^"]*)">\s*'.preg_quote($label, '/').'\s*<\/div>/', $html, $m) === 1 ? $m[1] : null;
    }

    public function test_foto_gagal_tampil_penanda_merah_dan_pesan_error(): void
    {
        $this->masterDenganListing(['last_photo_status' => 'failed', 'last_photo_error' => 'Foto ditolak']);

        $html = $this->halaman();

        $kelas = $this->kelasBaris($html, 'Foto: gagal');
        $this->assertNotNull($kelas, 'baris "Foto: gagal" harus dirender');
        $this->assertStringContainsString('text-rose-600', $kelas);
        $this->assertStringNotContainsString('Foto: ok', $html);
        $this->assertStringContainsString('>foto: Foto ditolak</div>', $html);
    }

    public function test_foto_ok_tampil_penanda_netral_tanpa_baris_error(): void
    {
        $this->masterDenganListing(['last_photo_status' => 'ok', 'last_photo_pushed_at' => now(), 'photo_hash' => 'h']);

        $html = $this->halaman();

        $kelas = $this->kelasBaris($html, 'Foto: ok');
        $this->assertNotNull($kelas, 'baris "Foto: ok" harus dirender');
        $this->assertStringContainsString('text-stone-500', $kelas);
        $this->assertStringNotContainsString('rose', $kelas);
        $this->assertStringNotContainsString('>foto: ', $html);
    }

    public function test_belum_pernah_dorong_foto_tak_ada_baris_foto(): void
    {
        $this->masterDenganListing([]);

        $html = $this->halaman();

        $this->assertStringContainsString('Serum X', $html); // baris listing benar dirender (cek absen tak vakum)
        $this->assertNull($this->kelasBaris($html, 'Foto: ok'));
        $this->assertNull($this->kelasBaris($html, 'Foto: gagal'));
        $this->assertStringNotContainsString('>foto: ', $html);
    }

    public function test_error_foto_di_escape_bukan_html_mentah(): void
    {
        $this->masterDenganListing(['last_photo_status' => 'failed', 'last_photo_error' => '<script>alert(1)</script>']);

        $html = $this->halaman();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_error_foto_panjang_dipotong_80_dan_teks_penuh_di_title(): void
    {
        $panjang = 'Gambar ditolak: '.str_repeat('x', 120);
        $this->masterDenganListing(['last_photo_status' => 'failed', 'last_photo_error' => $panjang]);

        $html = $this->halaman();

        $this->assertStringContainsString('title="'.$panjang.'"', $html);
        $this->assertStringContainsString('>foto: '.Str::limit($panjang, 80).'</div>', $html);
    }

    public function test_status_foto_dan_konten_tampil_terpisah_tak_saling_mewarnai(): void
    {
        $this->masterDenganListing([
            'last_content_status' => 'ok', 'last_content_pushed_at' => now(), 'content_hash' => 'c',
            'last_photo_status' => 'failed', 'last_photo_error' => 'Foto ditolak',
        ]);

        $html = $this->halaman();

        $this->assertStringContainsString('text-stone-500', (string) $this->kelasBaris($html, 'Konten: ok'));
        $this->assertStringContainsString('text-rose-600', (string) $this->kelasBaris($html, 'Foto: gagal'));
        $this->assertStringNotContainsString('>konten: ', $html);
    }
}
