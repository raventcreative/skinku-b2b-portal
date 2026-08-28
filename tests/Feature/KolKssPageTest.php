<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolScore;
use App\Models\User;
use App\Services\KolAffiliateService;
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
            ->assertOk()->assertSee('Kalkulator KSS')->assertSee('Ranking APS');
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

    public function test_snapshot_aps_ranking_kolom_dan_riwayat_kss(): void
    {
        $spec = $this->user('kol_specialist', 'sd1');
        $kol = Kol::create(['tiktok_username' => 'scoredkol', 'followers' => 50_000]);

        // GMV bulan ini → masuk ranking.
        app(KolAffiliateService::class)->import([
            ['order_id' => 'A1', 'username' => 'scoredkol', 'gmv' => 3_000_000, 'order_date' => now()->toDateString()],
        ], 'tiktok', $spec->id);
        // Konten di 4 minggu berbeda → weeksOfData = 4 (scored, bukan "new").
        for ($i = 0; $i < 4; $i++) {
            KolContent::create(['kol_id' => $kol->id, 'url' => "https://www.tiktok.com/@x/v/{$i}",
                'label' => 'earned', 'posted_at' => now()->subWeeks($i)->toDateString()]);
        }

        // Halaman APS: kolom komponen + rubrik tampil.
        $this->actingAs($spec)->get(route('kol-skor.kss'))->assertOk()
            ->assertSee('Growth')->assertSee('Konsistensi')->assertSee('Cara APS dihitung');

        // Tombol Snapshot APS → rekam ke kol_scores.
        $this->actingAs($spec)->post(route('kol-skor.aps-snapshot'))->assertRedirect();
        $this->assertGreaterThanOrEqual(1, KolScore::where('type', 'aps')->count());

        // KSS untuk KOL → tersimpan + muncul di Riwayat KSS.
        $this->actingAs($spec)->post(route('kol-skor.kss'), [
            'kol_id' => $kol->id, 'rate' => 500_000, 'median_views' => 200_000, 'engagement_rate' => 8,
            'niche' => 'beauty_majority', 'history' => 'good', 'readiness' => 'active',
        ])->assertOk();
        $this->assertSame(1, KolScore::where('type', 'kss')->count());
        $this->actingAs($spec)->get(route('kol-skor.kss'))->assertOk()->assertSee('Riwayat KSS');
    }
}
