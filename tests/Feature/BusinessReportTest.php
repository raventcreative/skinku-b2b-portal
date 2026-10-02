<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\TiktokOrder;
use App\Models\User;
use App\Services\BusinessReportService;
use App\Services\ReportBot\ReportAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, string $u = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function po(int $userId, string $status, float $amount, string $at, ?Product $p = null, int $qty = 0): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'created_by' => $userId, 'user_id' => $userId, 'company_name' => 'Toko '.$userId,
            'status' => $status, 'total_amount' => $amount, 'user_role' => 'reseller',
        ]);
        PurchaseOrder::where('id', $po->id)->update(['created_at' => $at]);
        if ($p) {
            DB::table('purchase_order_items')->insert(['purchase_order_id' => $po->id, 'product_id' => $p->id, 'product_name' => $p->name, 'qty' => $qty]);
        }
    }

    public function test_periode_per_jenis_dan_periode_berjalan_dipotong_adil(): void
    {
        Carbon::setTestNow('2026-10-02 10:00:00');
        $svc = app(BusinessReportService::class);

        $p = $svc->period('bulanan', '2026-09');
        $this->assertSame(['2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31'],
            [$p['from']->toDateString(), $p['to']->toDateString(), $p['prevFrom']->toDateString(), $p['prevTo']->toDateString()]);

        $p = $svc->period('bulanan', '2026-10');                 // berjalan → 1–2 Okt vs 1–2 Sep
        $this->assertSame(['2026-10-02', '2026-09-01', '2026-09-02'], [$p['to']->toDateString(), $p['prevFrom']->toDateString(), $p['prevTo']->toDateString()]);

        $p = $svc->period('mingguan', '2026-09-23');             // Rabu → Sen 21 – Min 27
        $this->assertSame(['2026-09-21', '2026-09-27', '2026-09-14'], [$p['from']->toDateString(), $p['to']->toDateString(), $p['prevFrom']->toDateString()]);

        $p = $svc->period('kuartal', '2026-Q2');
        $this->assertSame(['2026-04-01', '2026-06-30', '2026-01-01', '2026-03-31'],
            [$p['from']->toDateString(), $p['to']->toDateString(), $p['prevFrom']->toDateString(), $p['prevTo']->toDateString()]);

        $this->po(1, 'completed', 100, '2026-03-15');
        $p = $svc->period('semua', null);                          // sejak transaksi pertama s/d hari ini, tanpa pembanding
        $this->assertSame(['2026-03-15', '2026-10-02'], [$p['from']->toDateString(), $p['to']->toDateString()]);
        $this->assertFalse($p['compare']);
        $this->assertTrue($svc->period('bulanan', '2026-09')['compare']);

        $p = $svc->period('custom', null, '2026-09-11', '2026-09-20');   // 10 hari → 10 hari sebelumnya
        $this->assertSame(['2026-09-01', '2026-09-10'], [$p['prevFrom']->toDateString(), $p['prevTo']->toDateString()]);
    }

    public function test_halaman_laporan_excel_dan_ai(): void
    {
        Carbon::setTestNow('2026-10-02 10:00:00');
        $admin = $this->user(User::ROLE_ADMIN);
        $mitra = $this->user(User::ROLE_RESELLER, 'tokoa');
        $diam = $this->user(User::ROLE_RESELLER, 'tokob');
        $mizu = Product::create(['name' => 'Mizu', 'sku' => 'MZ', 'price_distributor' => 1, 'price_reseller' => 1, 'price_retail' => 1, 'cogs' => 1, 'hq_stock' => 30, 'status' => 'active']);

        $this->po($mitra->id, 'completed', 3_000_000, '2026-09-10', $mizu, 30);   // 1 unit/hari → stok 30 cukup 30 hari
        $this->po($mitra->id, 'completed', 1_000_000, '2026-08-10');
        $this->po($diam->id, 'completed', 500_000, '2026-07-01');                  // tak order Sep → "tidak order"
        TiktokOrder::create(['tiktok_order_id' => 'T1', 'status' => 'COMPLETED', 'total_amount' => 2_000_000, 'order_created_at' => '2026-09-12', 'line_items' => []]);

        $q = ['jenis' => 'bulanan', 'acuan' => '2026-09'];
        $this->actingAs($admin)->get(route('reports.business', $q))->assertOk()
            ->assertSee('Laporan Bisnis Bulanan — September 2026')
            ->assertSee('Rp 5.000.000')               // omzet PO 3jt + TikTok 2jt
            ->assertSee('Toko '.$mitra->id)
            ->assertSee('tokob')                      // mitra tidak order
            ->assertSee('Analisis &amp; Rekomendasi AI', false);

        $this->actingAs($admin)->get(route('reports.business', ['jenis' => 'semua']))->assertOk()
            ->assertSee('Laporan Bisnis Semua Periode')->assertSee('seluruh data sejak transaksi pertama')->assertDontSee('dibanding');
        $this->actingAs($admin)->get(route('reports.business.excel', ['jenis' => 'semua']))->assertOk();

        $this->actingAs($admin)->get(route('reports.business.excel', $q))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=laporan-bisnis-bulanan-20260901-20260930.xlsx');

        $this->mock(ReportAi::class)->shouldReceive('analyze')->once()->andReturn(
            "```json\n{\"ringkasan\":\"Omzet naik.\",\"sorotan\":[\"TikTok kuat\"],\"perhatian\":[],\"aksi\":[{\"judul\":\"Follow-up tokob\",\"detail\":\"Hubungi\",\"dampak\":\"tinggi\"}]}\n```");
        $this->actingAs($admin)->postJson(route('reports.business.ai'), $q)
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('data.aksi.0.judul', 'Follow-up tokob');
        // Panggilan kedua dengan data sama → dari cache (mock hanya boleh dipanggil sekali).
        $this->actingAs($admin)->postJson(route('reports.business.ai'), $q)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'generate_business_report_ai']);
    }

    public function test_hanya_staff_dengan_izin_laporan(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get(route('reports.business'))->assertForbidden();
    }
}
