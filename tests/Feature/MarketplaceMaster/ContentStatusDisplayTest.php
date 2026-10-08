<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Status push per-listing tampil di halaman channel sebagai badge di kolom "Status kirim": Stok & Harga selalu ada,
 * KONTEN (last_content_status / last_content_error) hanya bila pernah didorong — markup sama, pesan error di bawahnya.
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

    /** Badge status kirim jenis $kunci (stok/harga/konten/foto) → [kelas, teks]; null bila badgenya tak dirender. */
    private function pil(string $html, string $kunci): ?array
    {
        return preg_match('/<span data-kirim="'.$kunci.'" class="([^"]*)" title="[^"]*">([^<]*)<\/span>/', $html, $m) === 1 ? [$m[1], $m[2]] : null;
    }

    public function test_status_stok_harga_berupa_badge_berlabel_bukan_ok_garis_miring_ok(): void
    {
        $this->masterDenganListing(['last_status' => 'failed', 'last_error' => 'Stok ditolak', 'last_price_status' => null]);

        $html = $this->halaman();

        [$kelas, $teks] = $this->pil($html, 'stok');
        $this->assertSame('Stok gagal', $teks);
        $this->assertStringContainsString('text-rose-700', $kelas);
        $this->assertStringContainsString('>stok: Stok ditolak</div>', $html);
        $this->assertSame('Harga —', $this->pil($html, 'harga')[1]); // belum pernah dikirim
        $this->assertStringNotContainsString('ok/ok', $html);
        $this->assertStringNotContainsString('—/—', $html);
    }

    public function test_konten_gagal_tampil_penanda_merah_dan_pesan_error(): void
    {
        $this->masterDenganListing(['last_content_status' => 'failed', 'last_content_error' => 'Deskripsi ditolak']);

        $html = $this->halaman();

        $pil = $this->pil($html, 'konten');
        $this->assertNotNull($pil, 'badge "Konten" harus dirender');
        $this->assertSame('Konten gagal', $pil[1]);
        $this->assertStringContainsString('text-rose-700', $pil[0]);
        $this->assertStringContainsString('>konten: Deskripsi ditolak</div>', $html);
    }

    public function test_konten_ok_tampil_penanda_hijau_tanpa_baris_error(): void
    {
        $this->masterDenganListing(['last_content_status' => 'ok', 'last_content_pushed_at' => now(), 'content_hash' => 'h']);

        $html = $this->halaman();

        $pil = $this->pil($html, 'konten');
        $this->assertNotNull($pil, 'badge "Konten" harus dirender');
        $this->assertSame('Konten ✓', $pil[1]);
        $this->assertStringContainsString('text-emerald-700', $pil[0]);
        $this->assertStringNotContainsString('rose', $pil[0]);
        $this->assertDoesNotMatchRegularExpression('/>\s*konten: /', $html); // tak ada baris pesan error konten
    }

    public function test_listing_yang_belum_pernah_dorong_konten_tak_menampilkan_badge_konten(): void
    {
        $this->masterDenganListing([]);

        $html = $this->halaman();

        $this->assertStringContainsString('Serum X', $html); // barisnya memang dirender (negatif di bawah tak kosong-melompong)
        $this->assertNotNull($this->pil($html, 'stok'));
        $this->assertNull($this->pil($html, 'konten'));
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
        $this->assertSame('Konten ✓', $this->pil($tiktok, 'konten')[1] ?? null);
        $this->assertStringNotContainsString('Berat ditolak Shopee', $tiktok);

        $shopee = $this->halaman('shopee');
        $this->assertSame('Konten gagal', $this->pil($shopee, 'konten')[1] ?? null);
        $this->assertStringContainsString('>konten: Berat ditolak Shopee</div>', $shopee);
    }

    public function test_konten_gagal_tak_mewarnai_status_stok_harga(): void
    {
        $this->masterDenganListing(['last_status' => 'ok', 'last_price_status' => 'ok', 'last_content_status' => 'failed', 'last_content_error' => 'Deskripsi ditolak']);

        $html = $this->halaman();

        // Badge stok & harga tetap hijau (hanya kegagalan stok/harga yang memerahkannya); konten punya badge sendiri.
        foreach (['stok' => 'Stok ✓', 'harga' => 'Harga ✓'] as $kunci => $teks) {
            [$kelas, $isi] = $this->pil($html, $kunci);
            $this->assertSame($teks, $isi);
            $this->assertStringContainsString('text-emerald-700', $kelas);
        }
        $this->assertSame('Konten gagal', $this->pil($html, 'konten')[1]);
    }
}
