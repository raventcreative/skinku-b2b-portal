<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\User;
use App\Services\KanbanKpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KPI Kanban: pencatatan waktu selesai (completed_at) + statistik per anggota.
 */
class KanbanKpiTest extends TestCase
{
    use RefreshDatabase;

    private function super(string $u = 'sa'): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** @return array{0:User,1:Board,2:BoardColumn,3:BoardColumn} */
    private function board(): array
    {
        $sa = $this->super();
        $board = Board::create(['name' => 'Papan', 'created_by' => $sa->id]);
        $todo = BoardColumn::create(['board_id' => $board->id, 'name' => 'To Do List Agatha', 'position' => 0]);
        $done = BoardColumn::create(['board_id' => $board->id, 'name' => 'Done Agatha', 'position' => 1]);

        return [$sa, $board, $todo, $done];
    }

    public function test_pindah_ke_done_stempel_completed_at(): void
    {
        [$sa, , $todo, $done] = $this->board();
        $card = $todo->cards()->create(['title' => 'X', 'position' => 0, 'created_by' => $sa->id]);
        $this->assertNull($card->completed_at);

        $card->update(['column_id' => $done->id]);
        $this->assertNotNull($card->fresh()->completed_at);
    }

    public function test_dibuat_langsung_di_done_ikut_terstempel(): void
    {
        [$sa, , , $done] = $this->board();
        $card = $done->cards()->create(['title' => 'X', 'position' => 0, 'created_by' => $sa->id]);
        $this->assertNotNull($card->completed_at);
    }

    public function test_keluar_dari_done_dibersihkan(): void
    {
        [$sa, , $todo, $done] = $this->board();
        $card = $done->cards()->create(['title' => 'X', 'position' => 0, 'created_by' => $sa->id]);
        $card->update(['column_id' => $todo->id]);
        $this->assertNull($card->fresh()->completed_at);
    }

    public function test_edit_biasa_tak_ubah_completed_at(): void
    {
        [$sa, , , $done] = $this->board();
        $card = $done->cards()->create(['title' => 'X', 'position' => 0, 'created_by' => $sa->id]);
        $ts = $card->completed_at?->timestamp;

        $card->update(['title' => 'Judul baru']);   // bukan pindah kolom
        $this->assertSame($ts, $card->fresh()->completed_at?->timestamp);
    }

    private function agatha(): User
    {
        return User::create([
            'name' => 'agatha', 'fullname' => 'Agatha', 'username' => 'agatha', 'email' => 'agatha@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_kpi_hitung_per_assignee(): void
    {
        [$sa, $board, $todo, $done] = $this->board();
        $agatha = $this->agatha();

        // Selesai tepat waktu (due besok, selesai hari ini).
        $c1 = $todo->cards()->create(['title' => 'a', 'position' => 0, 'created_by' => $sa->id, 'assignee_user_id' => $agatha->id, 'due_date' => now()->addDay()->toDateString()]);
        $c1->update(['column_id' => $done->id]);
        // Selesai TELAT (due 2 hari lalu, selesai hari ini).
        $c2 = $todo->cards()->create(['title' => 'b', 'position' => 1, 'created_by' => $sa->id, 'assignee_user_id' => $agatha->id, 'due_date' => now()->subDays(2)->toDateString()]);
        $c2->update(['column_id' => $done->id]);
        // Berjalan & overdue (due kemarin, belum selesai).
        $todo->cards()->create(['title' => 'c', 'position' => 2, 'created_by' => $sa->id, 'assignee_user_id' => $agatha->id, 'due_date' => now()->subDay()->toDateString()]);
        // Tanpa assignee → tak dihitung.
        $todo->cards()->create(['title' => 'd', 'position' => 3, 'created_by' => $sa->id]);

        $board->load(['columns.cards.assignee']);
        $kpi = (new KanbanKpiService)->forBoard($board);

        $this->assertCount(1, $kpi['rows']);
        $row = $kpi['rows'][0];
        $this->assertSame('Agatha', $row['nama']);
        $this->assertSame(3, $row['total']);
        $this->assertSame(2, $row['selesai']);
        $this->assertSame(1, $row['berjalan']);
        $this->assertSame(2, $row['telat']);   // 1 selesai-telat + 1 overdue-belum
        $this->assertSame(1, $row['tepat']);
        $this->assertSame(67, $row['skor']);   // 2/3
        $this->assertSame(1, $kpi['unassigned']);
    }

    public function test_kartu_lama_di_done_tanpa_completed_at_tetap_selesai(): void
    {
        [$sa, $board, , $done] = $this->board();
        $agatha = $this->agatha();
        $card = $done->cards()->create(['title' => 'lama', 'position' => 0, 'created_by' => $sa->id, 'assignee_user_id' => $agatha->id]);
        $card->completed_at = null;
        $card->saveQuietly();   // simulasikan kartu lama: di Done tapi belum tercatat waktunya

        $board->load(['columns.cards.assignee']);
        $kpi = (new KanbanKpiService)->forBoard($board);

        $this->assertSame(1, $kpi['rows'][0]['selesai']);   // tetap dihitung selesai (via kolom Done)
        $this->assertSame(0, $kpi['rows'][0]['berjalan']);
    }

    public function test_papan_render_kpi(): void
    {
        [$sa, $board, $todo, $done] = $this->board();
        $agatha = $this->agatha();
        $c = $todo->cards()->create(['title' => 'a', 'position' => 0, 'created_by' => $sa->id, 'assignee_user_id' => $agatha->id]);
        $c->update(['column_id' => $done->id]);

        $this->actingAs($sa)->get(route('kanban.show', $board))->assertOk()
            ->assertSee('Statistik / KPI Anggota')->assertSee('Agatha')->assertSee('kpiChart', false);
    }
}
