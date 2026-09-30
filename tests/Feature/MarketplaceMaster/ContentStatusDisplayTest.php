<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Status push KONTEN per-listing (last_content_status / last_content_error) tampil di halaman
 * channel sebagai baris ketiga di sel "Kirim" — sejajar dgn status stok & harga, markup sama.
 */
class ContentStatusDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    /** Master + 1 listing di $channel dgn kolom jejak (status stok/harga/konten) sesuai $jejak. */
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

    /** Atribut class dari <div> yang isinya persis $label (mis. "Konten: gagal"); null bila barisnya tak dirender. */
    private function kelasBaris(string $html, string $label): ?string
    {
        return preg_match('/<div class="([^"]*)">\s*'.preg_quote($label, '/').'\s*<\/div>/', $html, $m) === 1 ? $m[1] : null;
    }

    public function test_konten_gagal_tampil_penanda_merah_dan_pesan_error(): void
    {
        $this->masterDenganListing(['last_content_status' => 'failed', 'last_content_error' => 'Deskripsi ditolak']);

        $html = $this->halaman();

        $kelas = $this->kelasBaris($html, 'Konten: gagal');
        $this->assertNotNull($kelas, 'baris "Konten: gagal" harus dirender');
        $this->assertStringContainsString('text-rose-600', $kelas);
        $this->assertStringNotContainsString('Konten: ok', $html);
        $this->assertStringContainsString('>konten: Deskripsi ditolak</div>', $html);
    }

    public function test_konten_ok_tampil_penanda_ok_netral_tanpa_baris_error(): void
    {
        $this->masterDenganListing(['last_content_status' => 'ok', 'last_content_pushed_at' => now(), 'content_hash' => 'h']);

        $html = $this->halaman();

        $kelas = $this->kelasBaris($html, 'Konten: ok');
        $this->assertNotNull($kelas, 'baris "Konten: ok" harus dirender');
        $this->assertStringContainsString('text-stone-500', $kelas);
        $this->assertStringNotContainsString('rose', $kelas);
        $this->assertStringNotContainsString('Konten: gagal', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*konten: /', $html); // tak ada baris pesan error konten
    }

    public function test_listing_yang_belum_pernah_dorong_konten_tak_menampilkan_baris_konten(): void
    {
        $this->masterDenganListing([]);

        $html = $this->halaman();

        $this->assertStringContainsString('Serum X', $html); // barisnya memang dirender (negatif di bawah tak kosong-melompong)
        $this->assertDoesNotMatchRegularExpression('/>\s*Konten: /', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*konten: /', $html);
    }

    public function test_error_konten_panjang_dipotong_80_karakter_dan_penuh_di_title(): void
    {
        $panjang = str_repeat('A', 200);
        $this->masterDenganListing(['last_content_status' => 'failed', 'last_content_error' => $panjang]);

        $html = $this->halaman();

        $this->assertStringContainsString('>konten: '.str_repeat('A', 80).'...</div>', $html); // teks tampil = Str::limit 80, sama spt stok/harga
        $this->assertStringContainsString('title="'.$panjang.'"', $html);                       // pesan penuh ada di tooltip
    }

    public function test_error_konten_di_escape_bukan_html_mentah(): void
    {
        // Pesan error berasal dari respons marketplace (data eksternal) — tak boleh jadi HTML.
        $this->masterDenganListing(['last_content_status' => 'failed', 'last_content_error' => '<script>alert(1)</script>']);

        $html = $this->halaman();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_status_konten_hanya_untuk_listing_di_channel_halaman_itu(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Serum X', 'base_stock' => 20]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'T1', 'item_id' => 'P1', 'master_id' => $m->id, 'last_content_status' => 'ok']);
        MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'S1', 'item_id' => '555', 'master_id' => $m->id, 'last_content_status' => 'failed', 'last_content_error' => 'Berat ditolak Shopee']);

        $tiktok = $this->halaman('tiktok');
        $this->assertNotNull($this->kelasBaris($tiktok, 'Konten: ok'));
        $this->assertNull($this->kelasBaris($tiktok, 'Konten: gagal'));
        $this->assertStringNotContainsString('Berat ditolak Shopee', $tiktok);

        $shopee = $this->halaman('shopee');
        $this->assertNotNull($this->kelasBaris($shopee, 'Konten: gagal'));
        $this->assertNull($this->kelasBaris($shopee, 'Konten: ok'));
        $this->assertStringContainsString('>konten: Berat ditolak Shopee</div>', $shopee);
    }

    public function test_konten_gagal_tak_mewarnai_status_stok_harga(): void
    {
        $this->masterDenganListing(['last_status' => 'ok', 'last_price_status' => 'ok', 'last_content_status' => 'failed', 'last_content_error' => 'Deskripsi ditolak']);

        $html = $this->halaman();

        // Span "stok/harga" tetap netral (hanya kegagalan stok/harga yang merahkannya); konten punya baris sendiri.
        $this->assertMatchesRegularExpression('/<span class="text-stone-500">ok\/ok<\/span>/', $html);
        $this->assertNotNull($this->kelasBaris($html, 'Konten: gagal'));
    }
}
