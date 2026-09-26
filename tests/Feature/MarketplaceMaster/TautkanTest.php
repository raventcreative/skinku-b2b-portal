<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TautkanTest extends TestCase
{
    use RefreshDatabase;

    public function test_tautkan_ke_master_existing_menggabungkan_listing(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'REI-3', 'name' => 'Reina 3']);
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'REI-30G', 'item_id' => 'IT']);

        app(MarketplaceMasterService::class)->tautkanListing($l, $m->id);

        $this->assertSame($m->id, $l->refresh()->master_id);
    }

    public function test_tautkan_buat_master_baru(): void
    {
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'NEW-1', 'item_id' => 'IT']);

        app(MarketplaceMasterService::class)->tautkanListing($l, null, 'NEW-1', 'Produk Baru');

        $m = MarketplaceMaster::where('master_sku', 'NEW-1')->first();
        $this->assertNotNull($m);
        $this->assertSame($m->id, $l->refresh()->master_id);
    }
}
