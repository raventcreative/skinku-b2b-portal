<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 7 — migrasi 000143 sudah men-drop marketplace_stocks/marketplace_channel_overrides
 * SEBELUM test ini jalan (RefreshDatabase menjalankan seluruh riwayat migrasi termasuk
 * 000143 saat setup, di tabel yang saat itu kosong). Untuk menguji jalur migrasi-data
 * ("jangan hilangkan data lama"), tabel lama direkreasi manual + diisi, baru up() migrasi
 * dipanggil LANGSUNG (require file migrasi mengembalikan instance kelas anonim baru —
 * pola yang sama dipakai Laravel Migrator sendiri).
 */
class LegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrasi_data_lama_stok_dan_override_ke_master(): void
    {
        // Rekreasi tabel lama yang sudah di-drop 000143 saat setup RefreshDatabase.
        Schema::create('marketplace_stocks', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(0);
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('marketplace_channel_overrides', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('channel', 16);
            $t->integer('quantity')->default(0);
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
        });

        $p = Product::create(['name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        DB::table('marketplace_stocks')->insert(['product_id' => $p->id, 'quantity' => 50, 'seeded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketplace_channel_overrides')->insert(['product_id' => $p->id, 'channel' => 'tiktok', 'quantity' => 12, 'seeded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // Jalankan up() migrasi langsung (file migrasi return instance kelas anonim).
        (require database_path('migrations/2026_01_01_000143_drop_legacy_marketplace_stock_tables.php'))->up();

        $m = MarketplaceMaster::where('master_sku', 'FM-1')->first();
        $this->assertNotNull($m);
        $this->assertSame(50, $m->base_stock);
        $this->assertEquals($p->id, $m->product_id);
        $this->assertSame(12, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->value('stock'));

        // Tabel lama ter-drop lagi setelah migrasi.
        $this->assertFalse(Schema::hasTable('marketplace_stocks'));
        $this->assertFalse(Schema::hasTable('marketplace_channel_overrides'));
    }

    /**
     * Produk yang HANYA punya override channel (tak pernah ada baris di
     * marketplace_stocks/base pool) dulu ke-skip diam-diam di loop kedua
     * (where()->first() → null → continue). Sekarang loop kedua ikut pakai
     * firstOrCreate() spt loop pertama, jadi master tetap dibuat (base_stock
     * null krn tak pernah di-set loop pertama) & override-nya tak hilang.
     */
    public function test_migrasi_override_tanpa_base_pool_tetap_buat_master(): void
    {
        // Rekreasi tabel lama yang sudah di-drop 000143 saat setup RefreshDatabase.
        Schema::create('marketplace_stocks', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(0);
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('marketplace_channel_overrides', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('channel', 16);
            $t->integer('quantity')->default(0);
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
        });

        $p = Product::create(['name' => 'Toner', 'sku' => 'TN-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        // TAK ADA baris marketplace_stocks utk produk ini — hanya override channel.
        DB::table('marketplace_channel_overrides')->insert(['product_id' => $p->id, 'channel' => 'tiktok', 'quantity' => 8, 'seeded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_01_01_000143_drop_legacy_marketplace_stock_tables.php'))->up();

        $m = MarketplaceMaster::where('master_sku', 'TN-1')->first();
        $this->assertNotNull($m);
        $this->assertNull($m->base_stock);
        $this->assertEquals($p->id, $m->product_id);
        $this->assertSame(8, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->value('stock'));
    }
}
