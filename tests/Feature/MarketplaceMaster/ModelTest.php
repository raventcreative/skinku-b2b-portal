<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_menyimpan_dan_relasi(): void
    {
        $p = Product::create(['name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist', 'product_id' => $p->id, 'base_stock' => 100, 'base_price' => 39000]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 50]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id]);

        $this->assertSame(100, $m->base_stock);
        $this->assertSame('39000.00', (string) $m->base_price);
        $p->refresh();
        $this->assertSame('Face Mist', $m->product->name);
        $this->assertSame(1, $m->channels()->count());
        $this->assertSame($m->id, $l->master->id);
    }

    /**
     * Task 2 (dedup by NAMA) fix-round: constraint unique master_sku di-drop
     * (migrasi 000148) krn identitas master sekarang murni via name_key
     * (app-level, di MarketplaceMasterService::findOrCreateMaster) — master_sku
     * cuma SKU representatif & SAH duplikat antar master (kasus nyata: SKU
     * sama dipakai ulang di channel berbeda dgn judul produk berbeda).
     * Dulu tes ini menuntut QueryException; sekarang justru menuntut SEBALIKNYA.
     */
    public function test_master_sku_boleh_duplikat_krn_dedup_sekarang_by_name_key(): void
    {
        $a = MarketplaceMaster::create(['master_sku' => 'DUP', 'name' => 'A']);
        $b = MarketplaceMaster::create(['master_sku' => 'DUP', 'name' => 'B']);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, MarketplaceMaster::where('master_sku', 'DUP')->count());
    }

    public function test_channel_unik_per_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X']);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 1]);
        $this->expectException(QueryException::class);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 2]);
    }

    public function test_bundle_master_punya_stok_sendiri_tanpa_produk(): void
    {
        // Bundle = master SKU baru, product_id null, stok/harga sendiri.
        $m = MarketplaceMaster::create(['master_sku' => 'BUNDLE-3', 'name' => 'Paket 3pcs', 'product_id' => null, 'base_stock' => 7, 'base_price' => 99000]);
        $this->assertNull($m->product_id);
        $this->assertSame(7, $m->base_stock);
    }
}
