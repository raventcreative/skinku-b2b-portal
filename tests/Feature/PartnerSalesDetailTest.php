<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PartnerSalesDetailTest extends TestCase
{
    use RefreshDatabase;

    private function po(string $name, string $role, float $amount, string $at, string $status = 'completed'): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'created_by' => 1, 'user_id' => 1,
            'company_name' => $name, 'user_role' => $role,
            'status' => $status, 'total_amount' => $amount,
        ]);
        PurchaseOrder::where('id', $po->id)->update(['order_date' => $at]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'pdadm', 'email' => 'pd@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_includes_resellers_and_one_time_buyers_not_just_distributors(): void
    {
        $this->po('Kamay', 'distributor', 2_250_000, '2026-06-10');
        $this->po('Kamay', 'distributor', 480_000, '2026-06-12');   // 2 order → digabung
        $this->po('Tyara', 'reseller', 1_450_000, '2026-06-02');    // reseller: dulu tak pernah tampil
        $this->po('Vani', 'reseller', 264_000, '2026-06-18');       // pembeli lepas
        $this->po('Batal', 'reseller', 9_000_000, '2026-06-20', 'cancelled');   // tak dihitung

        $rows = collect(app(ReportService::class)->partnerSalesDetail())->keyBy('label');

        $this->assertSame(2, $rows['Kamay']['orders']);
        $this->assertEqualsWithDelta(2_730_000, $rows['Kamay']['revenue'], 0.01);
        $this->assertEqualsWithDelta(1_365_000, $rows['Kamay']['avg'], 0.01);
        $this->assertEqualsWithDelta(1_450_000, $rows['Tyara']['revenue'], 0.01);
        $this->assertEqualsWithDelta(264_000, $rows['Vani']['revenue'], 0.01);
        $this->assertArrayNotHasKey('Batal', $rows->all());   // PO batal bukan penjualan

        // urut dari terbesar
        $first = app(ReportService::class)->partnerSalesDetail()[0];
        $this->assertSame('Kamay', $first['label']);
    }

    public function test_scoped_by_month(): void
    {
        $this->po('Kamay', 'distributor', 1_000_000, '2026-06-10');
        $this->po('Kamay', 'distributor', 5_000_000, '2026-07-10');

        $svc = app(ReportService::class);
        $jun = collect($svc->partnerSalesDetail(Carbon::parse('2026-06-15')))->keyBy('label');
        $jul = collect($svc->partnerSalesDetail(Carbon::parse('2026-07-15')))->keyBy('label');

        $this->assertEqualsWithDelta(1_000_000, $jun['Kamay']['revenue'], 0.01);
        $this->assertEqualsWithDelta(5_000_000, $jul['Kamay']['revenue'], 0.01);
    }

    public function test_reports_page_shows_the_table(): void
    {
        $this->po('Kamay', 'distributor', 2_250_000, '2026-06-10');

        $this->actingAs($this->admin())->get(route('reports.index'))->assertOk()
            ->assertSee('Penjualan per Mitra')
            ->assertSee('Kamay')
            ->assertSee('2.250.000');
    }
}
