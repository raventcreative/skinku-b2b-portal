<?php

namespace Tests\Unit\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettersTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    public function test_set_master_stock_menyetel_dan_stempel_seeded(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setMasterStock($m, 25);
        $m->refresh();
        $this->assertSame(25, $m->base_stock);
        $this->assertNotNull($m->seeded_at);
    }

    public function test_set_master_stock_clamp_negatif_ke_nol(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setMasterStock($m, -5);
        $this->assertSame(0, $m->refresh()->base_stock);
    }

    public function test_set_master_price(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setMasterPrice($m, 39000);
        $this->assertSame('39000.00', (string) $m->refresh()->base_price);
    }

    public function test_set_channel_stock_update_or_create_dan_stempel(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $row = $this->svc()->setChannelStock($m, 'tiktok', 7);
        $this->assertSame(7, $row->stock);
        $this->assertNotNull($row->seeded_at);
        $this->svc()->setChannelStock($m, 'tiktok', 9); // update, bukan baris baru
        $this->assertSame(1, MarketplaceMasterChannel::where('master_id', $m->id)->count());
        $this->assertSame(9, MarketplaceMasterChannel::where('master_id', $m->id)->first()->stock);
    }

    public function test_ikut_master_nullkan_field_dan_hapus_baris_bila_kosong(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->svc()->setChannelStock($m, 'tiktok', 7);
        $this->svc()->setChannelPrice($m, 'tiktok', 42000);

        $this->svc()->ikutMaster($m, 'tiktok', 'stock'); // harga masih override → baris tetap
        $row = MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->stock);
        $this->assertSame('42000.00', (string) $row->price);

        $this->svc()->ikutMaster($m, 'tiktok', 'price'); // dua-duanya null → hapus baris
        $this->assertSame(0, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->count());
    }
}
