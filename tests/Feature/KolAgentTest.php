<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_salah_atau_kosong_401(): void
    {
        config(['services.kol_agent.token' => 'rahasia123']);

        // Tanpa header token → 401.
        $this->postJson(route('kol-agent.affiliate'), ['platform' => 'tiktok', 'transactions' => [['order_id' => 'Z1']]])
            ->assertStatus(401);
        // Token salah → 401.
        $this->withHeader('X-Agent-Token', 'salah')
            ->postJson(route('kol-agent.affiliate'), ['platform' => 'tiktok', 'transactions' => [['order_id' => 'Z1']]])
            ->assertStatus(401);
    }

    public function test_token_kosong_di_server_menolak(): void
    {
        config(['services.kol_agent.token' => '']); // belum diset → endpoint mati
        $this->withHeader('X-Agent-Token', '')
            ->postJson(route('kol-agent.affiliate'), ['platform' => 'tiktok', 'transactions' => [['order_id' => 'Z1']]])
            ->assertStatus(401);
    }

    public function test_token_benar_simpan_source_agent(): void
    {
        config(['services.kol_agent.token' => 'rahasia123']);
        Kol::create(['tiktok_username' => 'agentkol', 'followers' => 40_000]);

        $this->withHeader('X-Agent-Token', 'rahasia123')
            ->postJson(route('kol-agent.affiliate'), [
                'platform' => 'tiktok',
                'transactions' => [
                    ['order_id' => 'AG1', 'username' => 'agentkol', 'gmv' => 120_000, 'order_date' => now()->toDateString()],
                    ['order_id' => 'AG2', 'username' => 'ga_kenal', 'gmv' => 30_000, 'order_date' => now()->toDateString()],
                ],
            ])
            ->assertOk()
            ->assertJson(['imported' => 2, 'matched' => 1, 'unmatched' => 1]);

        $t = KolAffiliateTransaction::where('order_id', 'AG1')->first();
        $this->assertSame('agent', $t->source);
        $this->assertNull($t->created_by); // agen tak punya user
    }
}
