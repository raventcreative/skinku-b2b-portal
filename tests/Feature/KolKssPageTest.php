<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolKssPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_form_render_dan_gating(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER, 'r1'))->get(route('kol-skor.kss'))->assertForbidden();
        $this->actingAs($this->user('kol_specialist', 'ks1'))->get(route('kol-skor.kss'))
            ->assertOk()->assertSee('Skor Seleksi KOL');
    }

    public function test_hitung_kss_shortlist(): void
    {
        $spec = $this->user('kol_specialist', 'ks2');
        $res = $this->actingAs($spec)->post(route('kol-skor.kss'), [
            'rate' => 500_000, 'median_views' => 200_000, 'engagement_rate' => 10,
            'niche' => 'beauty_majority', 'history' => 'good', 'readiness' => 'active',
        ])->assertOk();

        // eCPM 2.500 → 100; er 100; niche 100; history 100; readiness 100 = 100 → shortlist
        $result = $res->viewData('result');
        $this->assertSame(100.0, $result['score']);
        $this->assertSame('shortlist', $result['decision']);
    }
}
