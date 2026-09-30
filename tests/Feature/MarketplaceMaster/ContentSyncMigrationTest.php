<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi 000151 — jejak push KONTEN (deskripsi/berat/dimensi) per listing marketplace,
 * paralel dgn jejak push stok (last_status/…) dan harga (last_price_*):
 * status ok|failed, error asli (dipotong 500), waktu push terakhir, dan hash payload
 * terkirim (diff-guard).
 */
class ContentSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = ['last_content_status', 'last_content_error', 'last_content_pushed_at', 'content_hash'];

    public function test_kolom_content_sync_ada_di_marketplace_listings(): void
    {
        $this->assertTrue(Schema::hasColumns('marketplace_listings', self::COLUMNS));
    }

    public function test_kolom_content_sync_nullable_dan_kosong_secara_default(): void
    {
        // Listing lama/baru yang belum pernah di-push konten tetap valid (semua kolom nullable).
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'CS-0']);

        $row = DB::table('marketplace_listings')->where('id', $l->id)->first();
        foreach (self::COLUMNS as $kolom) {
            $this->assertNull($row->{$kolom}, "Kolom {$kolom} harus null secara default.");
        }
    }

    public function test_keempat_kolom_content_sync_bisa_diupdate_dan_tersimpan(): void
    {
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'CS-1']);
        // Pesan error asli marketplace dipotong 500 karakter (spec) → kolom harus muat teks sepanjang itu.
        $error = mb_substr(str_repeat('Deskripsi ditolak marketplace. ', 30), 0, 500);

        $l->update([
            'last_content_status' => 'failed',
            'last_content_error' => $error,
            'last_content_pushed_at' => '2026-09-30 10:15:00',
            'content_hash' => hash('sha256', 'payload-konten'),
        ]);

        // Baca baris mentah dari DB (bukan atribut model): membuktikan nilai benar-benar tersimpan
        // sekaligus menjaga $fillable — mass-assignment membuang kunci non-fillable secara diam-diam,
        // sehingga kolom yang lupa didaftarkan akan tetap null di sini.
        $row = DB::table('marketplace_listings')->where('id', $l->id)->first();
        $this->assertSame('failed', $row->last_content_status);
        $this->assertSame($error, $row->last_content_error);
        $this->assertSame(500, mb_strlen($row->last_content_error));
        $this->assertSame('2026-09-30 10:15:00', $row->last_content_pushed_at);
        $this->assertSame(hash('sha256', 'payload-konten'), $row->content_hash);
    }
}
