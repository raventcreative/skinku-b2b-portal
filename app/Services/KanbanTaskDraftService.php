<?php

namespace App\Services;

use App\Models\BoardColumn;
use App\Models\BoardCard;
use App\Models\User;
use App\Services\Ai\AiProvider;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class KanbanTaskDraftService
{
    public function __construct(private AiProvider $ai) {}

    /** @return array{title:string,description:string,assignee_user_id:?int,due_date:?string,priority:string} */
    public function draft(BoardColumn $column, string $prompt, Collection $assignees): array
    {
        $people = $assignees->map(fn (User $user) => ['id' => $user->id, 'name' => $user->fullname])->values()->all();
        $choices = json_encode($people, JSON_UNESCAPED_UNICODE);
        $priorities = implode(', ', array_keys(BoardCard::PRIORITIES));
        $turn = $this->ai->chat([
            ['role' => 'system', 'content' => "Susun draft kartu tugas Kanban dari permintaan pengguna. Konteks tetap: papan '{$column->board->name}', kolom '{$column->name}'. Jangan membuat tugas; hanya usulkan draft. Gunakan penanggung jawab hanya dari daftar ID yang diberikan. Balas satu objek JSON saja dengan kunci title, description, assignee_user_id (ID atau null), due_date (YYYY-MM-DD atau null), priority (salah satu: {$priorities}). Jangan menebak nama, tanggal, atau ID yang tidak disebutkan; gunakan null untuk yang tidak jelas. Judul maksimal 255 karakter dan deskripsi maksimal 5000 karakter."],
            ['role' => 'user', 'content' => "Daftar penanggung jawab yang boleh dipilih: {$choices}\n\nPermintaan tugas: {$prompt}"],
        ], []);

        $text = trim((string) $turn->text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $draft = json_decode($text, true);
        if (! is_array($draft) || ! is_string($draft['title'] ?? null) || trim($draft['title']) === '') {
            throw ValidationException::withMessages(['prompt' => 'Agent tidak menghasilkan draft yang bisa dipakai. Coba jelaskan tugasnya dengan lebih spesifik.']);
        }

        $assigneeId = $draft['assignee_user_id'] ?? null;
        if ($assigneeId !== null) {
            $assigneeId = filter_var($assigneeId, FILTER_VALIDATE_INT);
            if ($assigneeId === false || ! $assignees->contains(fn (User $user) => $user->id === $assigneeId)) {
                throw ValidationException::withMessages(['prompt' => 'Agent memilih penanggung jawab yang tidak tersedia. Buat draft lagi atau pilih manual.']);
            }
        }

        $date = $draft['due_date'] ?? null;
        if ($date !== null) {
            $parsedDate = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $date)
                : false;
            if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                throw ValidationException::withMessages(['prompt' => 'Agent menghasilkan format tenggat yang tidak valid. Coba buat draft lagi.']);
            }
        }
        $priority = $draft['priority'] ?? 'normal';
        if (! is_string($priority) || ! array_key_exists($priority, BoardCard::PRIORITIES)) {
            $priority = 'normal';
        }

        return [
            'title' => mb_substr(trim($draft['title']), 0, 255),
            'description' => is_string($draft['description'] ?? null) ? mb_substr(trim($draft['description']), 0, 5000) : '',
            'assignee_user_id' => $assigneeId === null ? null : (int) $assigneeId,
            'due_date' => $date,
            'priority' => $priority,
        ];
    }
}
