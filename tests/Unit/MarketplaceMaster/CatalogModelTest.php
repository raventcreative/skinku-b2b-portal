<?php

namespace Tests\Unit\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_name_lower_dan_rapatkan_spasi(): void
    {
        $this->assertSame('hana glow face mist', MarketplaceMaster::normalizeName('  HANA  Glow   Face Mist '));
    }

    public function test_detect_bundle_dari_nama(): void
    {
        $this->assertTrue(MarketplaceMaster::detectBundle('BUNDLING (3pcs) Body Scrub'));
        $this->assertTrue(MarketplaceMaster::detectBundle('Paket Hemat Sabun'));
        $this->assertFalse(MarketplaceMaster::detectBundle('Day Cream 10gr'));
        $this->assertFalse(MarketplaceMaster::detectBundle(null));
    }

    public function test_is_bundle_cast_boolean(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a', 'is_bundle' => true]);
        $this->assertTrue($m->refresh()->is_bundle);
    }

    public function test_image_url_pakai_image_url_kalau_tak_ada_upload(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a', 'image_url' => 'https://cdn/x.jpg']);
        $this->assertSame('https://cdn/x.jpg', $m->imageUrl());
    }
}
