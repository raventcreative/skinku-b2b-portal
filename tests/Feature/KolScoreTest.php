<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolScore;
use App\Models\User;
use App\Services\KolScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Persist skor KOL (kol_scores): jejak historis APS/KSS. */
class KolScoreTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_service_record_idempoten_harian_dan_snapshot_aps(): void
    {
        $a = Kol::create(['tiktok_username' => 'skora', 'followers' => 10_000]);
        $b = Kol::create(['tiktok_username' => 'skorb', 'followers' => 10_000]);
        $svc = app(KolScoreService::class);

        $svc->record($a->id, KolScore::TYPE_KSS, 72.0, 'shortlist', ['rate' => 1000]);
        $svc->record($a->id, KolScore::TYPE_KSS, 80.0, 'shortlist'); // hari sama → replace, bukan dobel
        $this->assertSame(1, KolScore::where('kol_id', $a->id)->where('type', 'kss')->count());
        $this->assertSame(80.0, (float) KolScore::where('kol_id', $a->id)->where('type', 'kss')->value('score'));

        // snapshotAps: hanya yang 'scored' direkam, 'new' dilewati.
        $n = $svc->snapshotAps([
            ['kol' => $a, 'aps' => ['status' => 'scored', 'score' => 65.0, 'label' => 'pantau', 'capped' => false]],
            ['kol' => $b, 'aps' => ['status' => 'new', 'score' => null, 'label' => 'new', 'capped' => false]],
        ]);
        $this->assertSame(1, $n);
        $this->assertSame(1, KolScore::where('type', 'aps')->count());
        $this->assertSame($a->id, KolScore::where('type', 'aps')->value('kol_id'));
    }

    public function test_kss_http_merekam_jejak_untuk_kol(): void
    {
        $spec = $this->user('kol_specialist', 'sc1');
        $kol = Kol::create(['tiktok_username' => 'scorekol', 'followers' => 20_000]);

        $this->actingAs($spec)->post(route('kol-skor.kss'), [
            'kol_id' => $kol->id, 'rate' => 1_000_000, 'median_views' => 400_000,
            'engagement_rate' => 6, 'niche' => 'beauty_majority', 'history' => 'none', 'readiness' => 'active',
        ])->assertOk()->assertSee('tersimpan ke jejak historis');

        $row = KolScore::where('kol_id', $kol->id)->where('type', 'kss')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->score);
        $this->assertContains($row->label, ['shortlist', 'nego', 'tolak']);

        // Tanpa kol_id → tak direkam (kalkulator lepas).
        $this->actingAs($spec)->post(route('kol-skor.kss'), [
            'rate' => 500_000, 'median_views' => 100_000, 'engagement_rate' => 3,
            'niche' => 'general', 'history' => 'none', 'readiness' => 'none',
        ])->assertOk();
        $this->assertSame(1, KolScore::where('type', 'kss')->count()); // tetap 1
    }
}
