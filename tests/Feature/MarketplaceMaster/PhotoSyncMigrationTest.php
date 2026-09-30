<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi 000153 — jejak push FOTO produk per listing marketplace, paralel dgn jejak push
 * stok (last_status/…), harga (last_price_*) dan konten (last_content_*): status ok|failed,
 * error asli (dipotong 500), waktu push terakhir, dan hash daftar foto terkirim (diff-guard).
 */
class PhotoSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = ['last_photo_status', 'last_photo_error', 'last_photo_pushed_at', 'photo_hash'];

    public function test_kolom_photo_sync_ada_di_marketplace_listings(): void
    {
        $this->assertTrue(Schema::hasColumns('marketplace_listings', self::COLUMNS));
    }

    public function test_kolom_photo_sync_nullable_dan_kosong_secara_default(): void
    {
        // Listing lama/baru yang belum pernah di-push foto tetap valid (semua kolom nullable).
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'PS-0']);

        $row = DB::table('marketplace_listings')->where('id', $l->id)->first();
        foreach (self::COLUMNS as $kolom) {
            $this->assertNull($row->{$kolom}, "Kolom {$kolom} harus null secara default.");
        }
    }

    public function test_keempat_kolom_photo_sync_bisa_diupdate_dan_tersimpan(): void
    {
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'PS-1']);
        // Pesan error asli marketplace dipotong 500 karakter (spec) → kolom harus muat teks sepanjang itu.
        $error = mb_substr(str_repeat('Foto ditolak marketplace. ', 30), 0, 500);
        // Hash diff-guard = md5 daftar [id, sort_order] foto master terurut (spec §6).
        $hash = md5(json_encode([[11, 0], [12, 1]]));

        $l->update([
            'last_photo_status' => 'failed',
            'last_photo_error' => $error,
            'last_photo_pushed_at' => '2026-09-30 10:15:00',
            'photo_hash' => $hash,
        ]);

        // Baca baris mentah dari DB (bukan atribut model): membuktikan nilai benar-benar tersimpan
        // sekaligus menjaga $fillable — mass-assignment membuang kunci non-fillable secara diam-diam,
        // sehingga kolom yang lupa didaftarkan akan tetap null di sini.
        $row = DB::table('marketplace_listings')->where('id', $l->id)->first();
        $this->assertSame('failed', $row->last_photo_status);
        $this->assertSame($error, $row->last_photo_error);
        $this->assertSame(500, mb_strlen($row->last_photo_error));
        $this->assertSame('2026-09-30 10:15:00', $row->last_photo_pushed_at);
        $this->assertSame($hash, $row->photo_hash);
    }
}
