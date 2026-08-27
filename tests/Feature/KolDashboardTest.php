<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolPipelineCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_gating_dan_render_ringkasan(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER, 'r1'))->get(route('kol-dashboard.index'))->assertForbidden();

        $spec = $this->user('kol_specialist', 'ks1');
        $kol = Kol::create(['tiktok_username' => 'dashkol', 'followers' => 40_000]);
        KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'nego', 'next_action' => 'x', 'next_action_at' => now()->subDay()->toDateString()]);
        $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/v/1', 'label' => 'earned', 'posted_at' => now()->toDateString()]);
        $c->snapshots()->create(['views' => 50_000, 'captured_on' => now()->startOfDay(), 'source' => 'manual']);

        $res = $this->actingAs($spec)->get(route('kol-dashboard.index'))->assertOk()->assertSee('Dashboard KOL');
        $this->assertSame(1, $res->viewData('pipeline')['active']);
        $this->assertSame(1, $res->viewData('pipeline')['terlambat']);
        $this->assertSame(50_000, $res->viewData('totalViews'));
    }
}
