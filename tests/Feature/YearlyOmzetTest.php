<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class YearlyOmzetTest extends TestCase
{
    use RefreshDatabase;

    public function test_yearly_omzet_mengembalikan_bentuk_yang_benar(): void
    {
        $r = app(ReportService::class)->yearlyOmzet();

        $this->assertArrayHasKey('year', $r);
        $this->assertArrayHasKey('realized', $r);
        $this->assertArrayHasKey('pipeline', $r);
        $this->assertArrayHasKey('total', $r);
        $this->assertEqualsWithDelta($r['realized'] + $r['pipeline'], $r['total'], 0.01);

        // Rincian per channel: 3 channel (Distributor/PO, TikTok, Shopee),
        // tiap channel total = realized + pipeline, dan jumlah semua channel = total.
        $this->assertArrayHasKey('channels', $r);
        $this->assertCount(3, $r['channels']);
        foreach ($r['channels'] as $c) {
            $this->assertArrayHasKey('label', $c);
            $this->assertEqualsWithDelta($c['realized'] + $c['pipeline'], $c['total'], 0.01);
        }
        $this->assertEqualsWithDelta(
            array_sum(array_column($r['channels'], 'total')),
            $r['total'],
            0.01,
        );
    }

    public function test_dashboard_render_dengan_kotak_omzet(): void
    {
        $admin = User::create([
            'name' => 'Admin Omzet', 'fullname' => 'Admin Omzet', 'username' => 'omzetadmin',
            'email' => 'omzetadmin@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Grand Total Omzet')       // kartu di deretan atas (samping Penjualan)
            ->assertSee('setahun')                  // note kartu Grand Total
            ->assertSee('Omzet Distributor / PO');  // box full-width di bawah
    }
}
