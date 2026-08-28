<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Models\User;
use App\Services\KolAffiliateService;
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
        // Kartu sudah punya next action → boleh pindah ke tahap aktif (guardrail lolos).
        $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'kandidat',
            'next_action' => 'DM', 'next_action_at' => now()->toDateString()]);

        $this->actingAs($spec)->patch(route('kol-pipeline.stage', $card), ['stage' => 'nego'])->assertRedirect();
        $card->refresh();
        $this->assertSame('nego', $card->stage);
        $this->assertSame('nego', $card->events()->first()->to_stage);

        // Follow-up (endpoint sendiri): count+1 + next action dijadwalkan +2 hari (SLA).
        $this->actingAs($spec)->post(route('kol-pipeline.follow-up', $card))->assertRedirect();
        $card->refresh();
        $this->assertSame(1, $card->followup_count);
        $this->assertSame(now()->addDays(2)->toDateString(), $card->next_action_at->toDateString());
    }

    public function test_papan_affiliate_terpisah_dari_kol(): void
    {
        $spec = $this->user('kol_specialist', 'specaff');
        $kol = $this->kol();

        // Kartu di papan Affiliate (stage khusus affiliate).
        $this->actingAs($spec)->post(route('kol-pipeline.store', ['kind' => 'affiliate']), [
            'kol_id' => $kol->id, 'stage' => 'prospek', 'next_action' => 'ajak gabung', 'next_action_at' => now()->toDateString(),
        ])->assertRedirect();
        $aff = KolPipelineCard::where('kol_id', $kol->id)->where('track', 'affiliate')->first();
        $this->assertSame('prospek', $aff->stage);

        // KOL sama tetap bisa punya kartu di papan KOL (track beda → unique lolos).
        $this->actingAs($spec)->post(route('kol-pipeline.store', ['kind' => 'kol']), [
            'kol_id' => $kol->id, 'stage' => 'kandidat', 'next_action' => 'DM', 'next_action_at' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertSame(2, KolPipelineCard::where('kol_id', $kol->id)->count());

        // Papan affiliate render stage affiliate (Prospek), bukan Kandidat.
        $this->actingAs($spec)->get(route('kol-pipeline.index', ['kind' => 'affiliate']))->assertOk()
            ->assertSee('Prospek')->assertSee('Pembinaan Affiliate');
    }

    public function test_guardrail_pindah_ke_tahap_aktif_wajib_next_action(): void
    {
        $spec = $this->user('kol_specialist', 'specg');
        $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'drop']); // terminal, tanpa next action

        // drop → nego (aktif) tanpa next action → ditolak guardrail (422).
        $this->actingAs($spec)->patchJson(route('kol-pipeline.stage', $card), ['stage' => 'nego'])->assertStatus(422);
        $this->assertSame('drop', $card->refresh()->stage);

        // Dengan next action → boleh.
        $this->actingAs($spec)->patchJson(route('kol-pipeline.stage', $card), [
            'stage' => 'nego', 'next_action' => 'hubungi', 'next_action_at' => now()->toDateString(),
        ])->assertOk();
        $this->assertSame('nego', $card->refresh()->stage);
    }

    public function test_followup_sla_2_hari_dan_auto_park_di_batas(): void
    {
        $spec = $this->user('kol_specialist', 'specfp');
        $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'nego',
            'next_action' => 'x', 'next_action_at' => now()->toDateString(), 'followup_count' => 2]);

        // Follow-up ke-3 = batas → next action jadi keputusan parkir/drop, dijadwalkan +2 hari.
        $this->actingAs($spec)->post(route('kol-pipeline.follow-up', $card))->assertRedirect();
        $card->refresh();
        $this->assertSame(3, $card->followup_count);
        $this->assertStringContainsString('parkir atau drop', $card->next_action);
        $this->assertSame(now()->addDays(2)->toDateString(), $card->next_action_at->toDateString());
    }

    public function test_stage_repeat_terminal_tak_masuk_aktif(): void
    {
        KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'repeat',
            'next_action' => 'lama', 'next_action_at' => now()->subDays(5)->toDateString()]);

        // repeat kini terminal → tak dihitung aktif (tak nagging di reminder).
        $this->assertFalse(KolPipelineCard::first()->isActive());
        $this->assertSame(0, KolPipelineCard::active()->count());
    }

    public function test_halaman_detail_rate_turun_riwayat_dan_update(): void
    {
        $spec = $this->user('kol_specialist', 'specd');
        $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'nego',
            'next_action' => 'x', 'next_action_at' => now()->toDateString(), 'ask_rate' => 1_000_000, 'final_rate' => 800_000]);
        $card->events()->create(['from_stage' => null, 'to_stage' => 'kandidat', 'created_by' => $spec->id]);
        $card->events()->create(['from_stage' => 'kandidat', 'to_stage' => 'nego', 'created_by' => $spec->id]);

        $this->actingAs($spec)->get(route('kol-pipeline.show', $card))->assertOk()
            ->assertSee('Riwayat tahap')->assertSee('turun 20%')->assertSee('Rp 1.000.000');

        // Update rate final + catatan nego dari detail.
        $this->actingAs($spec)->patch(route('kol-pipeline.update', $card), [
            'next_action' => 'kirim MOU', 'next_action_at' => now()->toDateString(),
            'ask_rate' => 1_000_000, 'final_rate' => 500_000, 'negotiation_notes' => 'nego alot',
        ])->assertRedirect();
        $card->refresh();
        $this->assertSame(500_000, $card->final_rate);
        $this->assertSame('nego alot', $card->negotiation_notes);
    }

    public function test_store_ask_rate_dan_catatan_awal_masuk_event(): void
    {
        $spec = $this->user('kol_specialist', 'specca');
        $kol = $this->kol();
        $this->actingAs($spec)->post(route('kol-pipeline.store'), [
            'kol_id' => $kol->id, 'stage' => 'kandidat', 'next_action' => 'DM', 'next_action_at' => now()->toDateString(),
            'ask_rate' => 750_000, 'note' => 'dari FastMoss',
        ])->assertRedirect();

        $card = KolPipelineCard::where('kol_id', $kol->id)->first();
        $this->assertSame(750_000, $card->ask_rate);
        $this->assertSame('dari FastMoss', $card->note);
        $this->assertStringContainsString('dari FastMoss', $card->events()->first()->note);
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

    public function test_reminder_deadline_posting_dan_affiliate_churn(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'root2'); // punya semua izin

        // Deal berjalan, tenggat besok, TANPA konten → deadline posting.
        $kolA = $this->kol();
        KolDeal::create(['kode' => 'RD1', 'kol_id' => $kolA->id, 'jenis' => 'vt', 'status' => 'berjalan', 'periode_selesai' => now()->addDay()->toDateString()]);

        // Affiliate order 5 hari lalu, tak ada konten 14 hari terakhir → churn.
        $kolB = $this->kol();
        app(KolAffiliateService::class)->import([['order_id' => 'RC1', 'username' => $kolB->tiktok_username, 'gmv' => 100_000, 'order_date' => now()->subDays(5)->toDateString()]], 'tiktok', $root->id);

        $res = $this->actingAs($root)->get(route('kol-reminder.index'))->assertOk();
        $this->assertCount(1, $res->viewData('postingDue'));
        $this->assertTrue($res->viewData('churn')->contains('id', $kolB->id));
    }
}
