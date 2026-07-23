<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardCard;
use App\Models\BoardCardComment;
use App\Models\BoardColumn;
use App\Models\File;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ImageService;
use App\Services\KanbanKpiService;
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
    /** Batas lampiran per kartu — cukup untuk mockup/referensi, bukan galeri. */
    private const MAX_ATTACHMENTS = 8;

    public function __construct(private ImageService $images) {}

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
        $board->load(['columns.cards.assignee', 'columns.cards.comments.author', 'columns.cards.files']);

        // Kandidat penanggung jawab: pengguna internal aktif — mitra tak ikut
        // (mereka tak punya akses kanban sama sekali).
        $assignees = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('role', [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])
            ->orderBy('fullname')
            ->get(['id', 'fullname']);

        // KPI per anggota (pakai data papan yang sudah di-load).
        $kpi = (new KanbanKpiService)->forBoard($board);
        $kpiChart = [
            'labels' => array_column($kpi['rows'], 'nama'),
            'selesai' => array_column($kpi['rows'], 'selesai'),
            'berjalan' => array_column($kpi['rows'], 'berjalan'),
            'telat' => array_column($kpi['rows'], 'telat'),
        ];

        return view('kanban.show', compact('board', 'assignees', 'kpi', 'kpiChart'));
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

    /* ---------------- Komentar kartu ---------------- */

    public function storeComment(Request $request, BoardCard $card): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);

        $card->comments()->create(['body' => $data['body'], 'user_id' => $request->user()->id]);

        // Tanpa Audit Log: komentar sudah membawa penulis + waktu sendiri, dan
        // mencatat tiap komentar cuma membanjiri log.
        return back()->with('status', 'Komentar ditambahkan.');
    }

    public function destroyComment(Request $request, BoardCardComment $comment): RedirectResponse
    {
        // Hanya penulisnya sendiri atau super admin — menghapus omongan orang
        // lain bukan hak siapa pun.
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $comment->user_id === $user->id, 403,
            'Hanya penulis komentar atau Super Admin yang boleh menghapusnya.');

        $comment->delete();

        return back()->with('status', 'Komentar dihapus.');
    }

    /* ---------------- Lampiran gambar kartu ---------------- */

    /**
     * Unggah lampiran gambar ke kartu. ImageService memperkecil (maks 1280px,
     * JPEG q80) sebelum menyimpan — foto HP 4MB jadi ratusan KB, hemat storage
     * server. Disimpan di disk publik + baris File (bukan blob DB), sama seperti
     * foto produk & bukti bayar.
     */
    public function storeAttachment(Request $request, BoardCard $card): RedirectResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:12288'],
        ]);

        $sisa = self::MAX_ATTACHMENTS - $card->files()->where('collection', BoardCard::ATTACHMENT)->count();
        if ($sisa <= 0) {
            return back()->withErrors(['images' => 'Lampiran kartu sudah penuh (maks '.self::MAX_ATTACHMENTS.' gambar) — hapus salah satu dulu.']);
        }

        // Bisa unggah beberapa sekaligus. Hanya sebanyak slot tersisa yang
        // diambil; selebihnya DILEWATI (bukan tolak semua) supaya tak perlu
        // pilih ulang. Tiap gambar diperkecil ImageService sebelum disimpan.
        $files = $request->file('images');
        $ditambah = 0;
        foreach ($files as $img) {
            if ($ditambah >= $sisa) {
                break;
            }
            $this->images->attach($card, $img, BoardCard::ATTACHMENT);
            $ditambah++;
        }

        $dilewati = count($files) - $ditambah;
        $msg = $ditambah.' lampiran ditambahkan.';
        if ($dilewati > 0) {
            $msg .= " {$dilewati} dilewati — batas ".self::MAX_ATTACHMENTS.' gambar/kartu.';
        }

        return back()->with('status', $msg);
    }

    /**
     * Hapus satu lampiran. Route ini HANYA untuk lampiran kartu — dijaga ketat
     * supaya tak bisa dipakai menghapus bukti bayar / foto produk (tabel File
     * dipakai banyak modul) hanya dengan menebak id.
     */
    public function destroyAttachment(File $file): RedirectResponse
    {
        abort_unless(
            $file->fileable_type === BoardCard::class && $file->collection === BoardCard::ATTACHMENT,
            404,
        );

        $file->delete(); // hook model File menghapus berkas fisiknya juga

        return back()->with('status', 'Lampiran dihapus.');
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
