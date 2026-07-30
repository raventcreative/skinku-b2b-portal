<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolAffiliateMetric;
use App\Models\ProductDevelopment;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\OkrBusinessSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OkrBusinessDataSourceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $username): User
    {
        return User::create([
            'name' => $username,
            'fullname' => ucfirst($username),
            'username' => $username,
            'email' => "{$username}@test.local",
            'password' => Hash::make('secret123'),
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_metrik_affiliate_bulanan_bisa_diinput_dan_diperbarui(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN, 'affiliateadmin');
        $kol = Kol::create([
            'tiktok_username' => 'affiliate_a',
            'platform' => 'tiktok',
            'followers' => 1000,
            'status' => Kol::STATUS_AKTIF,
        ]);

        $payload = [
            'period_month' => '2026-07',
            'stage' => KolAffiliateMetric::STAGE_ORDER_ACTIVE,
            'content_count' => 8,
            'live_count' => 2,
            'order_count' => 17,
            'gmv' => 2500000,
            'conversion_rate' => 1.25,
            'retention_rate' => 30,
        ];
        $this->actingAs($admin)->post(route('kols.affiliate-metrics.store', $kol), $payload)->assertRedirect();
        $payload['order_count'] = 20;
        $this->actingAs($admin)->post(route('kols.affiliate-metrics.store', $kol), $payload)->assertRedirect();

        $this->assertSame(1, KolAffiliateMetric::count());
        $this->assertSame(20, KolAffiliateMetric::firstOrFail()->order_count);
    }

    public function test_tahap_distributor_dan_pipeline_produk_bisa_dikelola(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN, 'pipelineadmin');

        $this->actingAs($admin)->post(route('users.store'), [
            'fullname' => 'Distributor Baru',
            'email' => 'distributor.baru@test.local',
            'username' => 'distributor_baru',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => User::ROLE_DISTRIBUTOR,
            'company_name' => 'PT Baru',
            'distributor_stage' => User::DISTRIBUTOR_STAGE_ONBOARDING,
            'status' => User::STATUS_ACTIVE,
        ])->assertRedirect();
        $this->assertDatabaseHas('users', [
            'username' => 'distributor_baru',
            'distributor_stage' => User::DISTRIBUTOR_STAGE_ONBOARDING,
        ]);

        $this->actingAs($admin)->post(route('product-developments.store'), [
            'name' => 'Serum Barrier Baru',
            'category' => 'Serum',
            'stage' => 'sampling',
            'owner_user_id' => $admin->id,
            'target_launch_date' => '2026-09-30',
            'notes' => 'Gate formula dan stabilitas wajib lulus.',
        ])->assertRedirect();
        $this->assertDatabaseHas('product_developments', [
            'name' => 'Serum Barrier Baru',
            'stage' => 'sampling',
        ]);
    }

    public function test_snapshot_memisahkan_omzet_distributor_dan_membaca_sumber_data_baru(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN, 'snapshotadmin');
        $distributor = $this->user(User::ROLE_DISTRIBUTOR, 'snapshotdist');
        $reseller = $this->user(User::ROLE_RESELLER, 'snapshotreseller');
        foreach ([[$distributor, 300000], [$reseller, 700000]] as [$partner, $amount]) {
            PurchaseOrder::create([
                'po_number' => 'PO-'.$partner->id,
                'user_id' => $partner->id,
                'company_name' => $partner->displayName(),
                'user_role' => $partner->role,
                'status' => PurchaseOrder::STATUS_COMPLETED,
                'total_amount' => $amount,
                'order_date' => now()->startOfMonth()->toDateString(),
                'completed_at' => now(),
                'created_by' => $admin->id,
            ]);
        }
        $kol = Kol::create([
            'tiktok_username' => 'snapshotaffiliate',
            'platform' => 'tiktok',
            'followers' => 2000,
            'status' => Kol::STATUS_AKTIF,
        ]);
        KolAffiliateMetric::create([
            'kol_id' => $kol->id,
            'period_month' => now()->startOfMonth(),
            'stage' => KolAffiliateMetric::STAGE_ORDER_ACTIVE,
            'content_count' => 5,
            'live_count' => 1,
            'order_count' => 10,
            'gmv' => 1250000,
        ]);
        ProductDevelopment::create([
            'name' => 'Brightening Body Serum',
            'category' => 'Body Care',
            'stage' => 'market_test',
        ]);

        $snapshot = app(OkrBusinessSnapshotService::class)->for('cmo', $admin, [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);
        $currentTrend = collect($snapshot['tren_penjualan_3_bulan'])->last();

        $this->assertSame(300000.0, $currentTrend['distributor_po_confirmed']);
        $this->assertSame(1250000.0, $snapshot['kol']['affiliate']['gmv']);
        $this->assertSame(1, $snapshot['pipeline_produk_baru']['item_di_luar_perfume_acne']);
    }
}
