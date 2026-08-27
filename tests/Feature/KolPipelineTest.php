<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolPipelineCard;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolPipelineTest extends TestCase
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

        return Kol::create(['tiktok_username' => "pipekol{$n}", 'followers' => 50_000]);
    }

    public function test_model_kartu_dan_event_dasar(): void
    {
        $kol = $this->kol();
        $card = KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'kandidat']);

        $this->assertSame('kol', $card->track);          // default track
        $this->assertTrue($card->isActive());
        $card->events()->create(['from_stage' => null, 'to_stage' => 'kandidat']);
        $this->assertSame(1, $card->events()->count());

        // Unique (kol_id, track): kartu kedua utk KOL sama harus meledak.
        $this->expectException(QueryException::class);
        KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'nego']);
    }
}
