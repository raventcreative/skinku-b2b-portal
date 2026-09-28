<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardCard;
use App\Models\BoardCardComment;
use App\Models\OkrCycle;
use App\Models\OkrTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * OKR sebelumnya cuma bisa dihapus saat berstatus draft. Sekarang OKR AKTIF
 * (sudah disetujui) juga boleh dihapus, dan kartu Kanban yang dibuat dari
 * tugas-tugasnya harus ikut terhapus (bukan cuma soft-delete, karena
 * board_cards pakai SoftDeletes dan FK cascade ke board_card_comments cuma
 * jalan lewat DELETE fisik).
 */
class OkrDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $name): User
    {
        return User::create([
            'name' => $name,
            'fullname' => strtoupper($name),
            'username' => $name,
            'email' => "{$name}@skinku.test",
            'password' => Hash::make('secret123'),
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /**
     * Bangun OKR AKTIF lengkap: objective -> key result -> task, dengan task
     * bertaut ke BoardCard sungguhan (seperti hasil OkrAiService::approve()).
     *
     * @return array{0:OkrCycle,1:BoardCard,2:OkrTask}
     */
    private function activeOkrWithCard(User $creator, User $assignee): array
    {
        $board = Board::create(['name' => 'Papan Delete Test', 'created_by' => $creator->id]);
        $column = $board->columns()->create(['name' => 'To Do', 'position' => 0]);

        $cycle = OkrCycle::create([
            'name' => 'OKR Aktif Untuk Dihapus',
            'period_type' => OkrCycle::PERIOD_QUARTERLY,
            'period_label' => 'Q3 2026',
            'start_date' => '2026-07-01',
            'end_date' => '2026-09-30',
            'scope_type' => OkrCycle::SCOPE_COMPANY,
            'direction' => 'Arahan uji hapus OKR aktif.',
            'status' => OkrCycle::STATUS_ACTIVE,
            'created_by' => $creator->id,
            'approved_by' => $creator->id,
            'approved_at' => now(),
        ]);

        $objective = $cycle->objectives()->create([
            'specialist' => 'cmo',
            'title' => 'Objective uji hapus',
            'rationale' => 'Rasional pengujian penghapusan OKR aktif beserta kartunya.',
            'owner_user_id' => $assignee->id,
            'owner_name' => $assignee->fullname,
            'position' => 0,
        ]);

        $kr = $objective->keyResults()->create([
            'title' => 'KR uji hapus',
            'metric' => 'Persentase',
            'target' => '100%',
            'baseline_status' => 'actual',
            'baseline' => '0%',
            'target_gap' => 'Gap uji penghapusan.',
            'owner_user_id' => $assignee->id,
            'owner_name' => $assignee->fullname,
            'due_date' => '2026-09-30',
            'position' => 0,
        ]);

        $card = $column->cards()->create([
            'title' => 'Kartu uji hapus',
            'description' => 'Kartu dari tugas OKR aktif.',
            'assignee_user_id' => $assignee->id,
            'due_date' => '2026-08-01',
            'position' => 0,
            'created_by' => $creator->id,
            'created_via' => 'ai',
        ]);

        $task = $kr->tasks()->create([
            'title' => 'Tugas uji hapus',
            'description' => 'Deskripsi tugas uji hapus.',
            'assignee_user_id' => $assignee->id,
            'board_column_id' => $column->id,
            'due_date' => '2026-08-01',
            'position' => 0,
            'board_card_id' => $card->id,
        ]);

        return [$cycle, $card, $task];
    }

    public function test_hapus_okr_aktif_menghapus_kartu_kanban_dan_komentarnya(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrdelsuper');
        $member = $this->user(User::ROLE_ADMIN, 'okrdelmember');
        [$cycle, $card, $task] = $this->activeOkrWithCard($super, $member);
        $objective = $cycle->objectives()->firstOrFail();
        $kr = $objective->keyResults()->firstOrFail();
        $comment = BoardCardComment::create([
            'card_id' => $card->id,
            'user_id' => $member->id,
            'body' => 'Komentar yang harus ikut lenyap.',
        ]);

        $response = $this->actingAs($super)->delete(route('okr.destroy', $cycle));

        $response->assertRedirect(route('okr.index'));
        $response->assertSessionHas('status', 'OKR dihapus (termasuk kartu Kanban terkait).');

        $this->assertDatabaseMissing('okr_cycles', ['id' => $cycle->id]);
        $this->assertDatabaseMissing('okr_objectives', ['id' => $objective->id]);
        $this->assertDatabaseMissing('okr_key_results', ['id' => $kr->id]);
        $this->assertDatabaseMissing('okr_tasks', ['id' => $task->id]);

        // Kartu benar-benar lenyap (bukan cuma soft-deleted) supaya komentarnya
        // ikut ter-cascade lewat FK — assertDatabaseMissing membaca tabel apa
        // adanya, tanpa scope Eloquent, jadi ini menolak baris yang cuma
        // deleted_at-nya keisi.
        $this->assertDatabaseMissing('board_cards', ['id' => $card->id]);
        $this->assertDatabaseMissing('board_card_comments', ['id' => $comment->id]);
    }

    public function test_hapus_draf_okr_tetap_berfungsi(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrdeldraft');
        $member = $this->user(User::ROLE_ADMIN, 'okrdeldraftpic');
        $board = Board::create(['name' => 'Papan Draf Delete', 'created_by' => $super->id]);
        $column = $board->columns()->create(['name' => 'To Do', 'position' => 0]);

        $cycle = OkrCycle::create([
            'name' => 'Draf OKR Untuk Dihapus',
            'period_type' => OkrCycle::PERIOD_QUARTERLY,
            'period_label' => 'Q3 2026',
            'start_date' => '2026-07-01',
            'end_date' => '2026-09-30',
            'scope_type' => OkrCycle::SCOPE_COMPANY,
            'direction' => 'Arahan draf.',
            'status' => OkrCycle::STATUS_DRAFT,
            'created_by' => $super->id,
        ]);
        $objective = $cycle->objectives()->create([
            'specialist' => 'cmo',
            'title' => 'Objective draf',
            'rationale' => 'Rasional draf uji penghapusan draf OKR.',
            'owner_user_id' => $member->id,
            'owner_name' => $member->fullname,
            'position' => 0,
        ]);
        $kr = $objective->keyResults()->create([
            'title' => 'KR draf',
            'baseline_status' => 'actual',
            'baseline' => '0%',
            'target_gap' => 'Gap draf.',
            'owner_user_id' => $member->id,
            'owner_name' => $member->fullname,
            'due_date' => '2026-09-30',
            'position' => 0,
        ]);
        $task = $kr->tasks()->create([
            'title' => 'Tugas draf',
            'description' => 'Deskripsi tugas draf.',
            'assignee_user_id' => $member->id,
            'board_column_id' => $column->id,
            'due_date' => '2026-08-01',
            'position' => 0,
            // board_card_id sengaja null: draf belum disetujui, belum ada kartu.
        ]);

        $response = $this->actingAs($super)->delete(route('okr.destroy', $cycle));

        $response->assertRedirect(route('okr.index'));
        $response->assertSessionHas('status', 'Draf OKR dihapus.');
        $this->assertDatabaseMissing('okr_cycles', ['id' => $cycle->id]);
        $this->assertDatabaseMissing('okr_objectives', ['id' => $objective->id]);
        $this->assertDatabaseMissing('okr_key_results', ['id' => $kr->id]);
        $this->assertDatabaseMissing('okr_tasks', ['id' => $task->id]);
    }

    public function test_hapus_okr_ditolak_tanpa_izin_okr_manage(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'okrdelnoperm');
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrdelnopermsuper');
        $member = $this->user(User::ROLE_ADMIN, 'okrdelnopermpic');
        [$cycle] = $this->activeOkrWithCard($super, $member);

        // ROLE_ADMIN tidak diberi okr.manage secara default (lihat OkrTest::
        // test_hak_akses_dan_halaman_baru_render_ok) — route okr.destroy dijaga
        // middleware permission:okr.manage yang sama dengan generate/update/approve.
        $response = $this->actingAs($admin)->delete(route('okr.destroy', $cycle));

        $response->assertForbidden();
        $this->assertDatabaseHas('okr_cycles', ['id' => $cycle->id]);
    }
}
