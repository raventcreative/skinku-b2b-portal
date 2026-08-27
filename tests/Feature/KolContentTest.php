<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolContentTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function kol(): Kol
    {
        static $n = 0;
        $n++;

        return Kol::create(['tiktok_username' => "kontenkol{$n}", 'followers' => 50_000]);
    }

    public function test_model_konten_dan_snapshot_replace_per_hari(): void
    {
        $kol = $this->kol();
        $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/1',
            'label' => 'earned', 'posted_at' => now()->toDateString()]);

        $c->snapshots()->updateOrCreate(['captured_on' => now()->startOfDay()], ['views' => 100, 'source' => 'manual']);
        $c->snapshots()->updateOrCreate(['captured_on' => now()->startOfDay()], ['views' => 250, 'source' => 'manual']);

        $this->assertSame(1, $c->snapshots()->count());          // hari sama = replace
        $this->assertSame(250, (int) $c->latestSnapshot->views);
        $this->assertSame('tiktok', $c->platform);               // default
    }
}
