<?php

namespace App\Services\Ai\Tools;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Carbon;

/**
 * Alat TULIS: buat kartu Kanban buat delegasi tugas. TAK PERNAH jalan tanpa
 * konfirmasi user (isWrite). Kalau papan/kolom/penerima ambigu, validate()
 * balikin pesan biar AI tanya balik dulu (lihat AI_ASSISTANT_SPEC §4).
 */
class BuatKartuKanbanTool extends BaseTool
{
    public function name(): string
    {
        return 'buat_kartu_kanban';
    }

    public function description(): string
    {
        return 'Buat kartu tugas baru di papan Kanban untuk mendelegasikan pekerjaan ke tim. '
            .'Wajib: papan, kolom, judul. Opsional: deskripsi, penerima (nama anggota), tenggat (YYYY-MM-DD). '
            .'Kalau papan/kolom belum jelas, tanya user dulu. Penerima OPSIONAL: kalau namanya tak cocok '
            .'user portal (mis. cuma nama kolom), kartu tetap dibuat di kolomnya tanpa penanggung jawab.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'papan' => ['type' => 'string', 'description' => 'Nama papan Kanban'],
                'kolom' => ['type' => 'string', 'description' => 'Nama kolom di papan itu'],
                'judul' => ['type' => 'string', 'description' => 'Judul kartu/tugas'],
                'deskripsi' => ['type' => 'string', 'description' => 'Rincian tugas (opsional)'],
                'penerima' => ['type' => 'string', 'description' => 'Nama anggota penanggung jawab (opsional)'],
                'tenggat' => ['type' => 'string', 'description' => 'Tenggat, format YYYY-MM-DD (opsional)'],
            ],
            'required' => ['papan', 'kolom', 'judul'],
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'kanban.view';   // butuh akses Kanban buat bikin kartu
    }

    public function validate(array $args, User $user): ?string
    {
        return $this->resolve($args)['error'];
    }

    public function previewText(array $args, User $user): string
    {
        $r = $this->resolve($args);
        if ($r['error']) {
            return $r['error'];
        }

        $line = "Buat kartu \"{$args['judul']}\" di papan **{$r['board']->name} › {$r['column']->name}**";
        if ($r['assignee']) {
            $line .= " untuk **{$r['assignee']->fullname}**";
        } elseif ($r['assignee_note']) {
            $line .= ' (tanpa penanggung jawab)';
        }
        if (! empty($args['tenggat'])) {
            $line .= ", tenggat **{$args['tenggat']}**";
        }

        return $line.'.';
    }

    public function run(array $args, User $user): array
    {
        $r = $this->resolve($args);
        if ($r['error']) {
            throw new \RuntimeException($r['error']);
        }

        /** @var BoardColumn $column */
        $column = $r['column'];
        $card = $column->cards()->create([
            'title' => $args['judul'],
            'description' => $args['deskripsi'] ?? null,
            'assignee_user_id' => $r['assignee']?->id,
            'due_date' => ! empty($args['tenggat']) ? $args['tenggat'] : null,
            'position' => ((int) $column->cards()->max('position')) + 1,
            'created_by' => $user->id,
        ]);

        AuditService::log(
            action: 'create_board_card',
            targetType: 'board_card',
            targetId: $card->id,
            after: ['judul' => $card->title, 'papan' => $r['board']->name, 'kolom' => $column->name, 'via' => 'asisten_ai'],
        );

        $pesan = "Kartu \"{$card->title}\" dibuat di {$r['board']->name} › {$column->name}";
        if ($r['assignee']) {
            $pesan .= " untuk {$r['assignee']->fullname}";
        }
        $pesan .= '.';
        if ($r['assignee_note']) {
            $pesan .= ' Catatan: '.$r['assignee_note'].'.';
        }

        return ['ok' => true, 'pesan' => $pesan];
    }

    /**
     * Cari papan/kolom/penerima dari nama (case-insensitive, cocok persis).
     * Balikin pesan 'error' yang ramah + menyebut pilihan bila tak ketemu/ambigu.
     *
     * @return array{board:?Board, column:?BoardColumn, assignee:?User, error:?string}
     */
    private function resolve(array $args): array
    {
        $out = ['board' => null, 'column' => null, 'assignee' => null, 'assignee_note' => null, 'error' => null];

        foreach (['papan', 'kolom', 'judul'] as $req) {
            if (blank($args[$req] ?? null)) {
                $out['error'] = "Butuh {$req} dulu. Kartu Kanban perlu papan, kolom, dan judul.";

                return $out;
            }
        }

        $boards = Board::whereRaw('LOWER(name) = ?', [$this->norm($args['papan'])])->get();
        if ($boards->count() !== 1) {
            $all = Board::orderBy('name')->pluck('name')->implode(', ');
            $out['error'] = $boards->isEmpty()
                ? "Papan \"{$args['papan']}\" tidak ketemu. Papan yang ada: {$all}. Maksudnya yang mana?"
                : "Ada beberapa papan bernama mirip \"{$args['papan']}\". Sebutkan lebih spesifik.";

            return $out;
        }
        $out['board'] = $boards->first();

        $columns = BoardColumn::where('board_id', $out['board']->id)
            ->whereRaw('LOWER(name) = ?', [$this->norm($args['kolom'])])->get();
        if ($columns->count() !== 1) {
            $all = BoardColumn::where('board_id', $out['board']->id)->orderBy('position')->pluck('name')->implode(', ');
            $out['error'] = "Kolom \"{$args['kolom']}\" tidak ada di papan {$out['board']->name}. Kolom tersedia: {$all}. Yang mana?";

            return $out;
        }
        $out['column'] = $columns->first();

        // Penerima OPSIONAL & best-effort: nama kolom Kanban sering = nama orang
        // yang belum jadi user portal. Jangan blokir kartu — kalau tak cocok,
        // buat tanpa penanggung jawab + catatan.
        if (filled($args['penerima'] ?? null)) {
            $match = $this->findUser($args['penerima']);
            if ($match) {
                $out['assignee'] = $match;
            } else {
                $out['assignee_note'] = "penanggung jawab \"{$args['penerima']}\" belum cocok dengan user portal, jadi kartu dibuat tanpa penanggung jawab";
            }
        }

        if (filled($args['tenggat'] ?? null) && ! $this->validDate($args['tenggat'])) {
            $out['error'] = "Format tenggat harus YYYY-MM-DD (mis. 2026-08-01). Kamu tulis \"{$args['tenggat']}\".";

            return $out;
        }

        return $out;
    }

    /** Cari user dari nama: cocok PERSIS dulu, lalu cocok SEBAGIAN. Null kalau tak jelas/ambigu. */
    private function findUser(string $name): ?User
    {
        $n = $this->norm($name);

        $exact = User::whereRaw('LOWER(fullname) = ?', [$n])
            ->orWhereRaw('LOWER(name) = ?', [$n])
            ->orWhereRaw('LOWER(username) = ?', [$n])->get();
        if ($exact->count() === 1) {
            return $exact->first();
        }
        if ($exact->count() > 1) {
            return null;   // beberapa user sama persis → jangan nebak
        }

        $like = '%'.$n.'%';
        $partial = User::whereRaw('LOWER(fullname) LIKE ?', [$like])
            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(username) LIKE ?', [$like])->get();

        return $partial->count() === 1 ? $partial->first() : null;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    private function validDate(string $v): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return false;
        }

        try {
            Carbon::createFromFormat('Y-m-d', $v);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
