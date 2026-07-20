<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardCard;
use App\Models\BoardColumn;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kanban ala Trello. Seluruh route di balik permission kanban.view; siapa pun
 * pemegangnya boleh mengelola papan/kolom/kartu — ini alat kerja tim, bukan
 * data keuangan. Pengecualian: HAPUS papan hanya pembuatnya atau super admin,
 * karena satu papan bisa berisi pekerjaan banyak orang.
 */
class KanbanController extends Controller
{
    public function index()
    {
        $boards = Board::query()
            ->with('creator')
            ->withCount(['columns'])
            ->orderByDesc('id')
            ->get();

        // Jumlah kartu per papan (lewat kolom, sekali query).
        $cardCounts = BoardCard::query()
            ->join('board_columns', 'board_columns.id', '=', 'board_cards.column_id')
            ->whereNull('board_cards.deleted_at')
            ->selectRaw('board_columns.board_id, COUNT(*) as n')
            ->groupBy('board_columns.board_id')
            ->pluck('n', 'board_id');

        return view('kanban.index', ['boards' => $boards, 'cardCounts' => $cardCounts]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);

        $board = DB::transaction(function () use ($data, $request) {
            $board = Board::create(['name' => $data['name'], 'created_by' => $request->user()->id]);
            foreach (Board::DEFAULT_COLUMNS as $i => $name) {
                $board->columns()->create(['name' => $name, 'position' => $i]);
            }

            return $board;
        });

        AuditService::log(action: 'create_board', targetType: 'board', targetId: $board->id, after: ['name' => $board->name]);

        return redirect()->route('kanban.show', $board)->with('status', "Papan \"{$board->name}\" dibuat.");
    }

    public function show(Board $board)
    {
        $board->load(['columns.cards.assignee']);

        // Kandidat penanggung jawab: pengguna internal aktif — mitra tak ikut
        // (mereka tak punya akses kanban sama sekali).
        $assignees = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('role', [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])
            ->orderBy('fullname')
            ->get(['id', 'fullname']);

        return view('kanban.show', ['board' => $board, 'assignees' => $assignees]);
    }

    public function update(Request $request, Board $board): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $board->update($data);

        return back()->with('status', 'Nama papan diperbarui.');
    }

    public function destroy(Request $request, Board $board): RedirectResponse
    {
        // Papan berisi pekerjaan banyak orang — hanya pembuat / super admin.
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $board->created_by === $user->id, 403,
            'Hanya pembuat papan atau Super Admin yang boleh menghapus papan.');

        $board->delete();

        AuditService::log(action: 'delete_board', targetType: 'board', targetId: $board->id, before: ['name' => $board->name]);

        return redirect()->route('kanban.index')->with('status', "Papan \"{$board->name}\" dihapus.");
    }

    /* ---------------- Kolom ---------------- */

    public function storeColumn(Request $request, Board $board): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $board->columns()->create([
            'name' => $data['name'],
            'position' => ((int) $board->columns()->max('position')) + 1,
        ]);

        return back()->with('status', 'Kolom ditambahkan.');
    }

    public function updateColumn(Request $request, BoardColumn $column): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $column->update($data);

        return back()->with('status', 'Nama kolom diperbarui.');
    }

    public function destroyColumn(BoardColumn $column): RedirectResponse
    {
        // Hanya kolom KOSONG yang bisa dihapus — menghapus kolom berisi kartu
        // diam-diam melenyapkan pekerjaan orang. Pindahkan kartunya dulu.
        if ($column->cards()->count() > 0) {
            return back()->withErrors(['column' => "Kolom \"{$column->name}\" masih berisi kartu — pindahkan dulu sebelum menghapus."]);
        }

        $column->delete();

        return back()->with('status', 'Kolom dihapus.');
    }

    /** Urutan kolom via drag (AJAX). */
    public function reorderColumns(Request $request, Board $board): JsonResponse
    {
        $data = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data, $board) {
            foreach ($data['ordered_ids'] as $pos => $id) {
                // where board_id: id kolom papan lain diabaikan diam-diam,
                // tak bisa dipakai mengacak papan orang.
                BoardColumn::where('board_id', $board->id)->where('id', $id)->update(['position' => $pos]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /* ---------------- Kartu ---------------- */

    public function storeCard(Request $request, BoardColumn $column): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $column->cards()->create([
            'title' => $data['title'],
            'position' => ((int) $column->cards()->max('position')) + 1,
            'created_by' => $request->user()->id,
        ]);

        AuditService::log(action: 'create_board_card', targetType: 'board_card',
            after: ['judul' => $data['title'], 'papan' => $column->board->name, 'kolom' => $column->name]);

        return back()->with('status', 'Kartu ditambahkan.');
    }

    public function updateCard(Request $request, BoardCard $card): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ]);

        $card->update($data);

        return back()->with('status', 'Kartu diperbarui.');
    }

    public function destroyCard(BoardCard $card): RedirectResponse
    {
        $card->delete();

        AuditService::log(action: 'delete_board_card', targetType: 'board_card', targetId: $card->id,
            before: ['judul' => $card->title, 'kolom' => $card->column->name]);

        return back()->with('status', 'Kartu dihapus.');
    }

    /**
     * Pindah kartu via drag (AJAX): kolom tujuan + urutan id kartu di kolom itu.
     * Kolom tujuan WAJIB satu papan dengan kolom asal — drag tak boleh jadi
     * pintu memindahkan kartu ke papan lain tanpa sadar.
     */
    public function moveCard(Request $request, BoardCard $card): JsonResponse
    {
        $data = $request->validate([
            'column_id' => ['required', 'integer', 'exists:board_columns,id'],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ]);

        $target = BoardColumn::findOrFail($data['column_id']);

        if ($target->board_id !== $card->column->board_id) {
            return response()->json(['ok' => false, 'error' => 'Kolom tujuan bukan bagian papan ini.'], 422);
        }

        $from = $card->column->name;

        DB::transaction(function () use ($card, $target, $data) {
            $card->update(['column_id' => $target->id]);
            foreach ($data['ordered_ids'] as $pos => $id) {
                // Hanya kartu yang benar-benar ada di kolom tujuan yang diposisikan
                // ulang — id asing diabaikan.
                BoardCard::where('column_id', $target->id)->where('id', $id)->update(['position' => $pos]);
            }
        });

        // Pindah kolom = perubahan status pekerjaan — layak tercatat. Geser urutan
        // DI DALAM kolom yang sama tidak dicatat, biar log tak banjir.
        if ($from !== $target->name) {
            AuditService::log(action: 'move_board_card', targetType: 'board_card', targetId: $card->id,
                after: ['judul' => $card->title, 'dari' => $from, 'ke' => $target->name]);
        }

        return response()->json(['ok' => true]);
    }
}
