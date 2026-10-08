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
 * Status push FOTO per-listing (last_photo_status / last_photo_error) tampil di halaman channel sebagai badge
 * "Foto" di kolom Status kirim, sejajar dgn badge Konten — markup & kelas sama (hanya kelas yang sudah ter-compile).
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

    /** Badge status kirim jenis $kunci (stok/harga/konten/foto) → [kelas, teks]; null bila badgenya tak dirender. */
    private function pil(string $html, string $kunci): ?array
    {
        return preg_match('/<span data-kirim="'.$kunci.'" class="([^"]*)" title="[^"]*">([^<]*)<\/span>/', $html, $m) === 1 ? [$m[1], $m[2]] : null;
    }

    public function test_foto_gagal_tampil_penanda_merah_dan_pesan_error(): void
    {
        $this->masterDenganListing(['last_photo_status' => 'failed', 'last_photo_error' => 'Foto ditolak']);

        $html = $this->halaman();

        $pil = $this->pil($html, 'foto');
        $this->assertNotNull($pil, 'badge "Foto" harus dirender');
        $this->assertSame('Foto gagal', $pil[1]);
        $this->assertStringContainsString('text-rose-700', $pil[0]);
        $this->assertStringContainsString('>foto: Foto ditolak</div>', $html);
    }

    public function test_foto_ok_tampil_penanda_hijau_tanpa_baris_error(): void
    {
        $this->masterDenganListing(['last_photo_status' => 'ok', 'last_photo_pushed_at' => now(), 'photo_hash' => 'h']);

        $html = $this->halaman();

        $pil = $this->pil($html, 'foto');
        $this->assertNotNull($pil, 'badge "Foto" harus dirender');
        $this->assertSame('Foto ✓', $pil[1]);
        $this->assertStringContainsString('text-emerald-700', $pil[0]);
        $this->assertStringNotContainsString('rose', $pil[0]);
        $this->assertStringNotContainsString('>foto: ', $html);
    }

    public function test_belum_pernah_dorong_foto_tak_ada_badge_foto(): void
    {
        $this->masterDenganListing([]);

        $html = $this->halaman();

        $this->assertStringContainsString('Serum X', $html); // baris listing benar dirender (cek absen tak vakum)
        $this->assertNotNull($this->pil($html, 'stok'));
        $this->assertNull($this->pil($html, 'foto'));
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

        $this->assertSame(['Konten ✓', 'Foto gagal'], [$this->pil($html, 'konten')[1] ?? null, $this->pil($html, 'foto')[1] ?? null]);
        $this->assertStringContainsString('text-emerald-700', (string) ($this->pil($html, 'konten')[0] ?? ''));
        $this->assertStringContainsString('text-rose-700', (string) ($this->pil($html, 'foto')[0] ?? ''));
        $this->assertStringNotContainsString('>konten: ', $html);
    }
}
