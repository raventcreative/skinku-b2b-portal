<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ShopeeOrder;
use App\Models\TiktokOrder;
use App\Models\TiktokSkuMap;
use App\Models\User;
use App\Services\ProdukTerlarisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProdukTerlarisTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role), 'username' => $role, 'email' => "{$role}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function produk(string $name, string $sku): Product
    {
        return Product::create([
            'name' => $name, 'sku' => $sku, 'price_distributor' => 1, 'price_reseller' => 1, 'price_retail' => 1,
            'cogs' => 1, 'hq_stock' => 0, 'status' => 'active',
        ]);
    }

    private function po(string $status, Product $p, int $qty, string $at): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'created_by' => 1, 'user_id' => 1,
            'status' => $status, 'total_amount' => 0, 'user_role' => 'reseller',
        ]);
        PurchaseOrder::where('id', $po->id)->update(['created_at' => $at]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $po->id, 'product_id' => $p->id, 'product_name' => $p->name, 'qty' => $qty,
        ]);
    }

    public function test_unit_per_channel_bundle_dipecah_dan_status_batal_diabaikan(): void
    {
        $scrub = $this->produk('Scrub', 'SCRUB-1');
        $mizu = $this->produk('Mizu', 'MZ-1');
        TiktokSkuMap::create(['tiktok_sku' => 'SCRUB-3PCS', 'product_id' => $scrub->id, 'qty' => 3]);

        $this->po('completed', $mizu, 10, '2026-09-05');
        $this->po('cancelled', $mizu, 99, '2026-09-06');          // batal → diabaikan
        $this->po('completed', $mizu, 4, '2026-08-05');           // bulan lalu → pembanding

        TiktokOrder::create(['tiktok_order_id' => 'T1', 'status' => 'COMPLETED', 'total_amount' => 0, 'order_created_at' => '2026-09-10',
            'line_items' => [['sku' => 'SCRUB-3PCS', 'name' => 'Scrub 3 Pcs', 'qty' => 2], ['sku' => 'ASING', 'name' => 'Produk Asing', 'qty' => 1]]]);
        TiktokOrder::create(['tiktok_order_id' => 'T2', 'status' => 'UNPAID', 'total_amount' => 0, 'order_created_at' => '2026-09-11',
            'line_items' => [['sku' => 'MZ-1', 'name' => 'Mizu', 'qty' => 50]]]);
        ShopeeOrder::create(['order_sn' => 'S1', 'status' => 'READY_TO_SHIP', 'total_amount' => 0, 'order_created_at' => '2026-09-12',
            'line_items' => [['sku' => 'MZ-1', 'name' => 'Mizu', 'qty' => 3]]]);

        Carbon::setTestNow('2026-10-15 10:00:00');   // September = bulan penuh (sudah lewat)
        $r = app(ProdukTerlarisService::class)->report(Carbon::parse('2026-09-01'))['channels'];

        $row = fn ($ch, $label) => collect($r[$ch]['rows'])->firstWhere('label', $label);

        $this->assertSame(10, $row('reseller', 'Mizu')['qty']);
        $this->assertSame(4, $row('reseller', 'Mizu')['prev']);
        $this->assertSame(6, $row('tiktok', 'Scrub')['qty']);                 // 2 × bundle isi 3
        $this->assertTrue($row('tiktok', 'Produk Asing')['unmapped']);
        $this->assertSame(3, $row('shopee', 'Mizu')['qty']);                  // auto-cocok SKU, UNPAID TikTok tak dihitung
        $this->assertSame(13, $row('semua', 'Mizu')['qty']);                  // 10 PO + 3 Shopee
        $this->assertSame('Mizu', $r['semua']['rows'][0]['label']);           // urut unit terbanyak
        $this->assertSame(20, $r['semua']['total']);                          // 13 + 6 + 1
        Carbon::setTestNow();
    }

    public function test_bulan_berjalan_dibanding_periode_yang_sama_bulan_lalu(): void
    {
        $mizu = $this->produk('Mizu', 'MZ-1');
        $this->po('completed', $mizu, 5, '2026-10-01');
        $this->po('completed', $mizu, 3, '2026-09-02');           // dalam 1–2 Sep → pembanding
        $this->po('completed', $mizu, 100, '2026-09-20');         // setelah tgl 2 → TIDAK ikut pembanding

        Carbon::setTestNow('2026-10-02 17:00:00');
        $r = app(ProdukTerlarisService::class)->report(Carbon::parse('2026-10-01'));
        Carbon::setTestNow();

        $this->assertSame('1–2 Sep 2026', $r['prev_label']);
        $this->assertSame(3, $r['channels']['reseller']['rows'][0]['prev']);
    }

    public function test_pilihan_bulan_khusus_panel(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/dashboard?pt_bulan=2026-08')
            ->assertOk()->assertSee('Produk Terlaris — Agustus 2026')->assertSee('name="pt_bulan"', false);
    }

    public function test_panel_tampil_untuk_staff_saja(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/dashboard?bulan=2026-09')
            ->assertOk()->assertSee('Produk Terlaris')->assertSee('Shopee');

        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/dashboard')
            ->assertOk()->assertDontSee('Produk Terlaris');
    }
}
