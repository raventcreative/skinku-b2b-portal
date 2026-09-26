<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupByNameTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    public function test_nama_sama_satu_master(): void
    {
        $a = $this->svc()->findOrCreateMaster('FM-1', 'Hana Glow Face Mist');
        $b = $this->svc()->findOrCreateMaster('FM-30G', 'HANA  Glow  Face Mist'); // nama sama (beda spasi/case), SKU beda
        $this->assertSame($a->id, $b->id);                // 1 master
        $this->assertSame('FM-1', $a->master_sku);        // SKU perwakilan = yg pertama
        $this->assertSame(1, MarketplaceMaster::count());
    }

    public function test_nama_beda_dua_master(): void
    {
        $this->svc()->findOrCreateMaster('FM-1', 'Face Mist');
        $this->svc()->findOrCreateMaster('DC-1', 'Day Cream');
        $this->assertSame(2, MarketplaceMaster::count());
    }

    public function test_bundle_auto_dari_nama(): void
    {
        $m = $this->svc()->findOrCreateMaster('JPX-3', 'BUNDLING (3pcs) Scrub');
        $this->assertTrue($m->is_bundle);
    }

    public function test_image_url_diisi_saat_buat_dan_backfill_kalau_kosong(): void
    {
        $m = $this->svc()->findOrCreateMaster('FM-1', 'Face Mist', 'https://cdn/a.jpg');
        $this->assertSame('https://cdn/a.jpg', $m->image_url);
        // temukan lagi dgn image beda: tak ditimpa (sudah ada)
        $again = $this->svc()->findOrCreateMaster('FM-9', 'Face Mist', 'https://cdn/b.jpg');
        $this->assertSame($m->id, $again->id);
        $this->assertSame('https://cdn/a.jpg', $again->refresh()->image_url);
        // master tanpa image, lalu di-backfill
        $n = $this->svc()->findOrCreateMaster('DC-1', 'Day Cream');
        $this->svc()->findOrCreateMaster('DC-9', 'Day Cream', 'https://cdn/dc.jpg');
        $this->assertSame('https://cdn/dc.jpg', $n->refresh()->image_url);
    }

    public function test_fallback_nama_kosong_pakai_sku(): void
    {
        $m = $this->svc()->findOrCreateMaster('SKU-X', null);
        $this->assertSame('SKU-X', $m->name);
        $this->assertSame(MarketplaceMaster::normalizeName('SKU-X'), $m->name_key);
    }

    public function test_seller_sku_sama_nama_beda_jadi_dua_master_tanpa_crash(): void
    {
        // SKU sama antar channel tapi judul beda → 2 master by nama, TANPA unique violation.
        $a = $this->svc()->findOrCreateMaster('ABC', 'Nama Versi TikTok');
        $b = $this->svc()->findOrCreateMaster('ABC', 'Nama Versi Shopee');
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, MarketplaceMaster::count());
        $this->assertSame('ABC', $a->master_sku);
        $this->assertSame('ABC', $b->master_sku);
    }
}
