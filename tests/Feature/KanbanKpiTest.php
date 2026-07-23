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

    public function test_kpi_dikelompokkan_per_nama_kolom(): void
    {
        [$sa, $board, $todo, $done] = $this->board();   // "To Do List Agatha" + "Done Agatha"

        // Agatha (murni dari NAMA KOLOM, tanpa assignee): 3 kartu.
        $c1 = $todo->cards()->create(['title' => 'a', 'position' => 0, 'created_by' => $sa->id, 'due_date' => now()->addDay()->toDateString()]);
        $c1->update(['column_id' => $done->id]);   // selesai tepat (due besok)
        $done->cards()->create(['title' => 'b', 'position' => 1, 'created_by' => $sa->id, 'due_date' => now()->subDays(2)->toDateString()]);   // selesai telat
        $todo->cards()->create(['title' => 'c', 'position' => 1, 'created_by' => $sa->id, 'due_date' => now()->subDay()->toDateString()]);       // berjalan overdue

        // Orang kedua: Billy (kolom sendiri) — 1 kartu selesai.
        $billyDone = BoardColumn::create(['board_id' => $board->id, 'name' => 'Done Billy', 'position' => 2]);
        $billyDone->cards()->create(['title' => 'x', 'position' => 0, 'created_by' => $sa->id]);

        $board->load('columns.cards');
        $kpi = (new KanbanKpiService)->forBoard($board);

        $names = array_column($kpi['rows'], 'nama');
        $this->assertContains('Agatha', $names);   // gabungan "To Do List Agatha" + "Done Agatha"
        $this->assertContains('Billy', $names);
        $this->assertSame(0, $kpi['unassigned']);

        $agatha = collect($kpi['rows'])->firstWhere('nama', 'Agatha');
        $this->assertSame(3, $agatha['total']);
        $this->assertSame(2, $agatha['selesai']);
        $this->assertSame(1, $agatha['berjalan']);
        $this->assertSame(2, $agatha['telat']);   // b selesai-telat + c overdue
        $this->assertSame(1, $agatha['tepat']);   // a
        $this->assertSame(67, $agatha['skor']);   // 2/3
    }

    public function test_kartu_lama_di_done_tanpa_completed_at_tetap_selesai(): void
    {
        [$sa, $board, , $done] = $this->board();
        $card = $done->cards()->create(['title' => 'lama', 'position' => 0, 'created_by' => $sa->id]);
        $card->completed_at = null;
        $card->saveQuietly();   // kartu lama: di Done tapi waktu selesai belum tercatat

        $board->load('columns.cards');
        $row = (new KanbanKpiService)->forBoard($board)['rows'][0];
        $this->assertSame(1, $row['selesai']);   // tetap selesai (via kolom Done)
        $this->assertSame(0, $row['berjalan']);
    }

    public function test_jatuh_tempo_hari_ini_dihitung_telat(): void
    {
        [$sa, $board, $todo] = $this->board();
        $todo->cards()->create(['title' => 'due hari ini', 'position' => 0, 'created_by' => $sa->id, 'due_date' => now()->toDateString()]);

        $board->load('columns.cards');
        $this->assertSame(1, (new KanbanKpiService)->forBoard($board)['rows'][0]['telat']);   // selaras badge "lewat!"
    }

    public function test_papan_render_kpi(): void
    {
        [$sa, $board, , $done] = $this->board();
        $done->cards()->create(['title' => 'a', 'position' => 0, 'created_by' => $sa->id]);

        $this->actingAs($sa)->get(route('kanban.show', $board))->assertOk()
            ->assertSee('Statistik / KPI Anggota')->assertSee('Agatha')->assertSee('kpiChart', false);
    }
}
