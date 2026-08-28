<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Models\KolSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolReminderTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_bucket_besok_h1(): void
    {
        $kol = Kol::create(['tiktok_username' => 'remkol', 'followers' => 10_000]);
        KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'nego', 'next_action' => 'siapkan',
            'next_action_at' => now()->addDay()->toDateString()]);

        $res = $this->actingAs($this->user('kol_specialist', 'rem1'))->get(route('kol-reminder.index'))->assertOk();
        $this->assertSame(1, $res->viewData('besokCount'));
        $res->assertSee('besok (H-1)')->assertSee('Besok');
    }

    public function test_reminder_sampel_tertahan(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'remroot');
        $kol = Kol::create(['tiktok_username' => 'smkol', 'followers' => 5000]);
        $deal = KolDeal::create(['kode' => 'RS1', 'kol_id' => $kol->id, 'jenis' => 'vt', 'status' => 'berjalan', 'periode_mulai' => now()->toDateString()]);

        // Pending 4 hari lalu → tertahan.
        $stuck = KolSample::create(['kol_deal_id' => $deal->id, 'kol_id' => $kol->id, 'product' => 'SerumTertahan', 'units' => 1, 'status' => 'pending']);
        KolSample::where('id', $stuck->id)->update(['created_at' => now()->subDays(4)]);
        // Pending baru → tak tertahan.
        KolSample::create(['kol_deal_id' => $deal->id, 'kol_id' => $kol->id, 'product' => 'SampelBaruX', 'units' => 1, 'status' => 'pending']);

        $res = $this->actingAs($root)->get(route('kol-reminder.index'))->assertOk()
            ->assertSee('Sampel tertahan')->assertSee('SerumTertahan')->assertDontSee('SampelBaruX');
        $this->assertCount(1, $res->viewData('stuckSamples'));
    }

    public function test_sampel_tertahan_dikirim_lama(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'remroot2');
        $kol = Kol::create(['tiktok_username' => 'smkol2', 'followers' => 5000]);
        $deal = KolDeal::create(['kode' => 'RS2', 'kol_id' => $kol->id, 'jenis' => 'vt', 'status' => 'berjalan', 'periode_mulai' => now()->toDateString()]);

        // Dikirim 8 hari lalu, belum diterima → tertahan.
        KolSample::create(['kol_deal_id' => $deal->id, 'kol_id' => $kol->id, 'product' => 'KirimLama', 'units' => 1,
            'status' => 'shipped', 'shipped_at' => now()->subDays(8)->toDateString()]);
        // Dikirim kemarin → belum tertahan.
        KolSample::create(['kol_deal_id' => $deal->id, 'kol_id' => $kol->id, 'product' => 'KirimBaruY', 'units' => 1,
            'status' => 'shipped', 'shipped_at' => now()->subDay()->toDateString()]);

        $res = $this->actingAs($root)->get(route('kol-reminder.index'))->assertOk()
            ->assertSee('KirimLama')->assertDontSee('KirimBaruY');
        $this->assertCount(1, $res->viewData('stuckSamples'));
    }
}
