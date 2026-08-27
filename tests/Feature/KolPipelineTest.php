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

    public function test_tanpa_kol_view_pipeline_403(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER, 'res1'))
            ->get(route('kol-pipeline.index'))->assertForbidden();
    }

    public function test_specialist_lihat_kanban_dan_buat_kartu(): void
    {
        $spec = $this->user('kol_specialist', 'spec1');
        $kol = $this->kol();

        $this->actingAs($spec)->get(route('kol-pipeline.index'))->assertOk()->assertSee('Pipeline KOL');

        $this->actingAs($spec)->post(route('kol-pipeline.store'), [
            'kol_id' => $kol->id, 'stage' => 'kandidat',
            'next_action' => 'DM perkenalan', 'next_action_at' => now()->addDay()->toDateString(),
        ])->assertRedirect();

        $card = KolPipelineCard::where('kol_id', $kol->id)->first();
        $this->assertNotNull($card);
        $this->assertSame('kandidat', $card->stage);
        $this->assertSame(1, $card->events()->count()); // event lahir: null → kandidat

        // Kartu kedua utk KOL sama → ditolak validasi (bukan 500).
        $this->actingAs($spec)->post(route('kol-pipeline.store'), ['kol_id' => $kol->id, 'stage' => 'nego'])
            ->assertSessionHasErrors('kol_id');
    }

    public function test_pindah_stage_menulis_event_dan_followup(): void
    {
        $spec = $this->user('kol_specialist', 'spec2');
        $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'kandidat']);

        $this->actingAs($spec)->patch(route('kol-pipeline.stage', $card), ['stage' => 'nego'])->assertRedirect();
        $card->refresh();
        $this->assertSame('nego', $card->stage);
        $this->assertSame('nego', $card->events()->first()->to_stage);

        $this->actingAs($spec)->patch(route('kol-pipeline.next-action', $card), [
            'next_action' => 'Follow-up rate', 'next_action_at' => now()->toDateString(), 'is_followup' => 1,
        ])->assertRedirect();
        $this->assertSame(1, $card->refresh()->followup_count);
    }

    public function test_hapus_kartu_hanya_super_admin(): void
    {
        $spec = $this->user('kol_specialist', 'spec3');
        $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'drop']);

        $this->actingAs($spec)->delete(route('kol-pipeline.destroy', $card))->assertForbidden();
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'root1'))
            ->delete(route('kol-pipeline.destroy', $card))->assertRedirect();
        $this->assertSame(0, KolPipelineCard::count());
    }

    public function test_sidebar_grup_kol_tampil_untuk_specialist(): void
    {
        $this->actingAs($this->user('kol_specialist', 'spec4'))
            ->get(route('dashboard'))->assertSee('Pipeline');
    }

    public function test_reminder_urut_terlambat_dulu(): void
    {
        $spec = $this->user('kol_specialist', 'spec5');
        $late = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'nego',
            'next_action' => 'Telat', 'next_action_at' => now()->subDays(3)->toDateString()]);
        $due = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'deal',
            'next_action' => 'Hari ini', 'next_action_at' => now()->toDateString()]);
        $noAction = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'kandidat']);
        KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'drop',
            'next_action' => 'Diparkir', 'next_action_at' => now()->subDays(9)->toDateString()]);

        $res = $this->actingAs($spec)->get(route('kol-reminder.index'))->assertOk();
        $rows = $res->viewData('rows');
        $this->assertSame([$late->id, $due->id, $noAction->id], $rows->pluck('id')->all()); // drop TIDAK ikut
    }
}
