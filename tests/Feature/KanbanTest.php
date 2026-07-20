<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KanbanTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function board(User $creator): Board
    {
        $board = Board::create(['name' => 'Papan Uji', 'created_by' => $creator->id]);
        foreach (Board::DEFAULT_COLUMNS as $i => $name) {
            $board->columns()->create(['name' => $name, 'position' => $i]);
        }

        return $board;
    }

    /** Mitra & role tanpa kanban.view tak melihat apa pun. */
    public function test_tanpa_kanban_view_semua_route_403(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm0');
        $board = $this->board($admin);

        foreach ([
            $this->user(User::ROLE_DISTRIBUTOR, 'kbmitra'),
            $this->user(User::ROLE_RESELLER, 'kbresel'),
        ] as $user) {
            $this->actingAs($user)->get(route('kanban.index'))->assertForbidden();
            $this->actingAs($user)->get(route('kanban.show', $board))->assertForbidden();
            $this->actingAs($user)->post(route('kanban.store'), ['name' => 'x'])->assertForbidden();
        }

        // Semua tim internal dapat default — gudang & kol_specialist termasuk.
        $this->actingAs($this->user(User::ROLE_GUDANG, 'kbgud'))->get(route('kanban.index'))->assertOk();
        $this->actingAs($this->user('kol_specialist', 'kbspec'))->get(route('kanban.index'))->assertOk();

        // Sidebar mitra tak memuat menu Kanban.
        $this->actingAs(User::where('username', 'kbmitra')->first())
            ->get('/dashboard')->assertOk()->assertDontSee('>Kanban<', false);
    }

    public function test_buat_papan_dapat_tiga_kolom_bawaan(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm1');

        $this->actingAs($admin)->post(route('kanban.store'), ['name' => 'Marketing Juli'])->assertRedirect();

        $board = Board::where('name', 'Marketing Juli')->first();
        $this->assertNotNull($board);
        $this->assertSame(['To Do', 'Proses', 'Selesai'], $board->columns->pluck('name')->all());
        $this->assertNotNull(AuditLog::where('action', 'create_board')->first());
    }

    public function test_tambah_kolom_dan_kartu(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm2');
        $board = $this->board($admin);

        $this->actingAs($admin)->post(route('kanban.columns.store', $board), ['name' => 'Review'])->assertRedirect();
        $this->assertSame(4, $board->columns()->count());

        $col = $board->columns()->first();
        $this->actingAs($admin)->post(route('kanban.cards.store', $col), ['title' => 'Brief KOL Agustus'])->assertRedirect();

        $this->assertSame(1, $col->cards()->count());
        $this->assertNotNull(AuditLog::where('action', 'create_board_card')->first());
    }

    public function test_pindah_kartu_antar_kolom_tersimpan_dan_tercatat(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm3');
        $board = $this->board($admin);
        [$todo, $proses] = [$board->columns[0], $board->columns[1]];
        $a = $todo->cards()->create(['title' => 'Kartu A', 'position' => 0]);
        $b = $proses->cards()->create(['title' => 'Kartu B', 'position' => 0]);

        // A pindah ke kolom Proses, ditaruh DI ATAS B.
        $this->actingAs($admin)->postJson(route('kanban.cards.move', $a), [
            'column_id' => $proses->id,
            'ordered_ids' => [$a->id, $b->id],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($proses->id, $a->fresh()->column_id);
        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);

        $log = AuditLog::where('action', 'move_board_card')->first();
        $this->assertNotNull($log);
        $this->assertSame('To Do', $log->after_data['dari']);
        $this->assertSame('Proses', $log->after_data['ke']);
    }

    /** Drag tak boleh jadi pintu memindahkan kartu ke papan LAIN. */
    public function test_pindah_kartu_ke_papan_lain_ditolak(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm4');
        $board = $this->board($admin);
        $lain = Board::create(['name' => 'Papan Lain', 'created_by' => $admin->id]);
        $kolomLain = $lain->columns()->create(['name' => 'X', 'position' => 0]);
        $card = $board->columns[0]->cards()->create(['title' => 'Kartu', 'position' => 0]);

        $this->actingAs($admin)->postJson(route('kanban.cards.move', $card), [
            'column_id' => $kolomLain->id,
            'ordered_ids' => [$card->id],
        ])->assertStatus(422);

        $this->assertSame($board->columns[0]->id, $card->fresh()->column_id);
    }

    public function test_kolom_berisi_kartu_tak_bisa_dihapus_kosong_bisa(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm5');
        $board = $this->board($admin);
        $col = $board->columns[0];
        $col->cards()->create(['title' => 'Isi', 'position' => 0]);

        $this->actingAs($admin)->from(route('kanban.show', $board))
            ->delete(route('kanban.columns.destroy', $col))->assertSessionHasErrors('column');
        $this->assertNotNull(BoardColumn::find($col->id));

        $kosong = $board->columns[1];
        $this->actingAs($admin)->delete(route('kanban.columns.destroy', $kosong))->assertRedirect();
        $this->assertNull(BoardColumn::find($kosong->id));
    }

    /** Hapus papan: hanya pembuat atau super admin. */
    public function test_hapus_papan_hanya_pembuat_atau_super_admin(): void
    {
        $pembuat = $this->user(User::ROLE_ADMIN, 'kbadm6');
        $adminLain = $this->user(User::ROLE_ADMIN, 'kbadm7');
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'kbsuper');
        $board = $this->board($pembuat);

        $this->actingAs($adminLain)->delete(route('kanban.destroy', $board))->assertForbidden();
        $this->assertNotNull(Board::find($board->id));

        $this->actingAs($pembuat)->delete(route('kanban.destroy', $board))->assertRedirect();
        $this->assertNull(Board::find($board->id));
        $this->assertNotNull(Board::withTrashed()->find($board->id));   // soft delete

        $board2 = $this->board($pembuat);
        $this->actingAs($super)->delete(route('kanban.destroy', $board2))->assertRedirect();
        $this->assertNull(Board::find($board2->id));
    }

    public function test_update_kartu_assignee_dan_due_date(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm8');
        $pj = $this->user(User::ROLE_ADMIN, 'kbadm9');
        $board = $this->board($admin);
        $card = $board->columns[0]->cards()->create(['title' => 'Kartu', 'position' => 0]);

        $this->actingAs($admin)->put(route('kanban.cards.update', $card), [
            'title' => 'Kartu Revisi', 'description' => 'Detail tugas',
            'assignee_user_id' => $pj->id, 'due_date' => '2026-07-25',
        ])->assertRedirect();

        $card->refresh();
        $this->assertSame('Kartu Revisi', $card->title);
        $this->assertSame($pj->id, $card->assignee_user_id);
        $this->assertSame('2026-07-25', $card->due_date->format('Y-m-d'));
    }

    public function test_papan_tampil_dengan_kolom_dan_kartunya(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm10');
        $board = $this->board($admin);
        $board->columns[0]->cards()->create(['title' => 'Brief KOL Agustus', 'position' => 0]);

        $this->actingAs($admin)->get(route('kanban.show', $board))->assertOk()
            ->assertSee('To Do')->assertSee('Proses')->assertSee('Selesai')
            ->assertSee('Brief KOL Agustus')
            ->assertSee('sortablejs');   // library drag ikut termuat

        $this->actingAs($admin)->get(route('kanban.index'))->assertOk()->assertSee('Papan Uji');
    }

    /**
     * Blokir KERAS mitra: kanban.view yang keliru tercentang untuk role mitra
     * di matriks TIDAK membuka apa pun — middleware 'internal' berdiri di atas
     * permission. Papan tugas internal bisa memuat strategi & harga deal.
     */
    public function test_mitra_tetap_403_walau_permission_tercentang_di_matriks(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm11');
        $board = $this->board($admin);
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'kbmitra2');

        // Simulasikan salah centang di Manajemen Hak Akses.
        RolePermission::create([
            'role' => User::ROLE_DISTRIBUTOR, 'permission_key' => 'kanban.view', 'allowed' => true,
        ]);
        Permissions::flushCache();
        $this->assertTrue($mitra->canDo('kanban.view'));   // permission-nya memang lolos...

        // ...tapi pintunya tetap terkunci.
        $this->actingAs($mitra)->get(route('kanban.index'))->assertForbidden();
        $this->actingAs($mitra)->get(route('kanban.show', $board))->assertForbidden();
    }

    public function test_komentar_bisa_ditambah_dan_tampil_di_modal_kartu(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm12');
        $board = $this->board($admin);
        $card = $board->columns[0]->cards()->create(['title' => 'Kartu Komentar', 'position' => 0]);

        $this->actingAs($admin)->post(route('kanban.comments.store', $card), [
            'body' => 'Tolong follow up KOL ini besok',
        ])->assertRedirect();

        $this->assertSame(1, $card->comments()->count());

        $html = $this->actingAs($admin)->get(route('kanban.show', $board))->assertOk()->getContent();
        $this->assertStringContainsString('Tolong follow up KOL ini besok', $html);
        $this->assertStringContainsString('💬 1', $html);                 // hitungan di muka kartu
        $this->assertStringContainsString('Deskripsi', $html);            // modal memuat field lengkap
        $this->assertStringContainsString('Deadline', $html);
        $this->assertStringContainsString('Penanggung jawab', $html);
    }

    /** Hapus komentar: hanya penulisnya atau super admin. */
    public function test_hapus_komentar_hanya_penulis_atau_super_admin(): void
    {
        $penulis = $this->user(User::ROLE_ADMIN, 'kbadm13');
        $lain = $this->user(User::ROLE_ADMIN, 'kbadm14');
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'kbsuper2');
        $board = $this->board($penulis);
        $card = $board->columns[0]->cards()->create(['title' => 'K', 'position' => 0]);
        $c1 = $card->comments()->create(['body' => 'komentar satu', 'user_id' => $penulis->id]);
        $c2 = $card->comments()->create(['body' => 'komentar dua', 'user_id' => $penulis->id]);

        // Orang lain (bukan penulis, bukan super) -> 403, komentar utuh.
        $this->actingAs($lain)->delete(route('kanban.comments.destroy', $c1))->assertForbidden();
        $this->assertSame(2, $card->comments()->count());

        // Penulis boleh hapus miliknya; super admin boleh hapus siapa pun.
        $this->actingAs($penulis)->delete(route('kanban.comments.destroy', $c1))->assertRedirect();
        $this->actingAs($super)->delete(route('kanban.comments.destroy', $c2))->assertRedirect();
        $this->assertSame(0, $card->comments()->count());
    }

    /** Komentar penulis yang akunnya dihapus tetap terbaca. */
    public function test_komentar_tetap_terbaca_walau_penulisnya_dihapus(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'kbadm15');
        $mantan = $this->user(User::ROLE_ADMIN, 'kbadm16');
        $board = $this->board($admin);
        $card = $board->columns[0]->cards()->create(['title' => 'K', 'position' => 0]);
        $card->comments()->create(['body' => 'catatan penting dari mantan staf', 'user_id' => $mantan->id]);

        $mantan->forceDelete();   // user hilang total -> user_id jadi null (nullOnDelete)

        $html = $this->actingAs($admin)->get(route('kanban.show', $board))->assertOk()->getContent();
        $this->assertStringContainsString('catatan penting dari mantan staf', $html);
        $this->assertStringContainsString('(akun terhapus)', $html);
    }
}
