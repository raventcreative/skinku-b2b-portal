<?php

namespace Tests\Feature\ReportBot;

use App\Models\ReportSkuMap;
use App\Models\User;
use App\Services\ReportBot\TikTokIncomeN8nService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportSkuMapTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Migrasi men-seed peta dari konstanta → jumlah SKU ID sama. */
    public function test_seed_dari_konstanta(): void
    {
        $this->assertSame(count(TikTokIncomeN8nService::SKU_MAP), ReportSkuMap::distinct('sku_id')->count('sku_id'));
        // JPX-3 (item 1) ikut ter-seed.
        $this->assertDatabaseHas('report_sku_maps', ['sku_id' => '1736331520467240874', 'category' => 'Scrub', 'qty' => 3]);
    }

    /** Parser mengenali SKU dari DB (bukan cuma konstanta) — tambah di DB → langsung dikenal. */
    public function test_parser_kenali_sku_dari_db(): void
    {
        $csv = "Order ID,c1,c2,c3,c4,SKU ID,c6,c7,c8,Quantity\n"
            ."ORD-DB1,x,x,x,x,9999000011112222,x,x,x,3\n";

        // Belum ada di DB (maupun konstanta) → belum dikenal. (PHP meng-int-kan
        // key string numerik ≤ PHP_INT_MAX, jadi bandingkan sebagai string.)
        $s1 = TikTokIncomeN8nService::orderCsvSummary($csv);
        $this->assertSame(['9999000011112222'], array_map('strval', $s1['unmapped']));

        // Tambah ke DB → import berikutnya langsung kenal, TANPA deploy.
        ReportSkuMap::create(['sku_id' => '9999000011112222', 'category' => 'Scrub', 'qty' => 2]);
        $s2 = TikTokIncomeN8nService::orderCsvSummary($csv);
        $this->assertSame([], $s2['unmapped']);
    }

    /** activeMap: DB kosong → fallback konstanta. */
    public function test_active_map_fallback_konstanta_saat_db_kosong(): void
    {
        ReportSkuMap::query()->delete();
        $this->assertSame(TikTokIncomeN8nService::SKU_MAP, TikTokIncomeN8nService::activeMap());
    }

    public function test_crud_dan_gating(): void
    {
        // Non-settings → dilarang.
        $this->actingAs($this->user('kol_specialist', 'nosettings'))->get(route('report-bot.sku-map'))->assertForbidden();

        $admin = $this->user(User::ROLE_SUPER_ADMIN, 'adm');
        $this->actingAs($admin)->get(route('report-bot.sku-map'))->assertOk()->assertSee('Peta SKU');

        // Tambah.
        $this->actingAs($admin)->post(route('report-bot.sku-map.store'), [
            'sku_id' => '1234567890123456789', 'category' => 'Scrub', 'qty' => 3, 'note' => 'Produk X',
        ])->assertRedirect();
        $row = ReportSkuMap::where('sku_id', '1234567890123456789')->where('category', 'Scrub')->first();
        $this->assertNotNull($row);
        $this->assertSame(3, $row->qty);

        // Isi ulang SKU+kategori sama → perbarui qty, tak duplikat.
        $this->actingAs($admin)->post(route('report-bot.sku-map.store'), [
            'sku_id' => '1234567890123456789', 'category' => 'Scrub', 'qty' => 5,
        ])->assertRedirect();
        $this->assertSame(5, $row->fresh()->qty);
        $this->assertSame(1, ReportSkuMap::where('sku_id', '1234567890123456789')->count());

        // Kategori tak valid → ditolak.
        $this->actingAs($admin)->post(route('report-bot.sku-map.store'), [
            'sku_id' => '111', 'category' => 'KategoriNgawur', 'qty' => 1,
        ])->assertSessionHasErrors('category');

        // Hapus.
        $this->actingAs($admin)->delete(route('report-bot.sku-map.destroy', $row))->assertRedirect();
        $this->assertNull(ReportSkuMap::find($row->id));
    }
}
