<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\OkrCycle;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\AuditService;
use App\Services\OkrAiService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OkrController extends Controller
{
    public function __construct(private OkrAiService $okr) {}

    public function index(): View
    {
        $cycles = OkrCycle::query()
            ->with(['scopeOwner', 'objectives.keyResults.tasks.card:id,completed_at'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return view('okr.index', ['cycles' => $cycles]);
    }

    public function create(): View
    {
        return view('okr.create', [
            'members' => $this->members(),
            'boards' => Board::query()->with('columns')->orderBy('name')->get(),
            'defaultMonth' => now()->format('Y-m'),
            'defaultYear' => now()->year,
            'defaultQuarter' => (int) ceil(now()->month / 3),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period_type' => ['required', 'in:monthly,quarterly'],
            'period_month' => ['nullable', 'date_format:Y-m', 'required_if:period_type,monthly'],
            'period_year' => ['nullable', 'integer', 'between:2020,2100', 'required_if:period_type,quarterly'],
            'period_quarter' => ['nullable', 'integer', 'between:1,4', 'required_if:period_type,quarterly'],
            'scope_type' => ['required', 'in:company,team,individual'],
            'scope_name' => ['nullable', 'string', 'max:150', 'required_if:scope_type,team'],
            'scope_owner_user_id' => ['nullable', 'integer', 'exists:users,id', 'required_if:scope_type,individual'],
            'preferred_board_id' => ['nullable', 'integer', 'exists:boards,id'],
            'direction' => ['required', 'string', 'max:5000'],
        ]);

        $payload = array_merge($data, $this->period($data), [
            'scope_label' => $this->scopeLabel($data),
        ]);

        try {
            $cycle = $this->okr->generate($request->user(), $payload);
        } catch (AiException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('okr.show', $cycle)
            ->with('status', 'Draf OKR dari AI sudah siap. Periksa ringkasannya, lalu setujui untuk membuat kartu Kanban.');
    }

    public function show(OkrCycle $okr): View
    {
        $okr->load([
            'scopeOwner', 'creator', 'approver',
            'objectives.owner',
            'objectives.keyResults.owner',
            'objectives.keyResults.tasks.assignee',
            'objectives.keyResults.tasks.column.board',
            'objectives.keyResults.tasks.card.column.board',
        ]);

        return view('okr.show', [
            'okr' => $okr,
            'members' => $this->members(),
            'columns' => BoardColumn::query()->with('board')->orderBy('board_id')->orderBy('position')->get(),
        ]);
    }

    /** Simpan koreksi manusia pada pratinjau. Struktur tetap buatan AI. */
    public function update(Request $request, OkrCycle $okr): RedirectResponse
    {
        abort_unless($okr->isDraft(), 422, 'OKR aktif tidak bisa mengubah draf.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'string', 'max:5000'],
            'objectives' => ['required', 'array'],
            'objectives.*.title' => ['required', 'string', 'max:255'],
            'objectives.*.specialist' => ['required', 'in:'.implode(',', array_keys(OkrObjective::SPECIALISTS))],
            'objectives.*.description' => ['nullable', 'string', 'max:4000'],
            'objectives.*.owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'key_results' => ['required', 'array'],
            'key_results.*.title' => ['required', 'string', 'max:255'],
            'key_results.*.metric' => ['nullable', 'string', 'max:255'],
            'key_results.*.target' => ['nullable', 'string', 'max:255'],
            'key_results.*.owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'key_results.*.due_date' => ['required', 'date_format:Y-m-d'],
            'tasks' => ['required', 'array'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['required', 'string', 'max:4000'],
            'tasks.*.assignee_user_id' => ['required', 'integer', 'exists:users,id'],
            'tasks.*.board_column_id' => ['required', 'integer', 'exists:board_columns,id'],
            'tasks.*.due_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $okr->load('objectives.keyResults.tasks');
        $this->validatePreviewReferences($okr, $data);
        $taskColumns = $this->matchedTaskColumns($okr, $data);

        DB::transaction(function () use ($okr, $data, $request, $taskColumns) {
            $okr->update(['name' => $data['name'], 'direction' => $data['direction']]);
            foreach ($okr->objectives as $objective) {
                $row = $data['objectives'][$objective->id];
                $objective->update([
                    'specialist' => $row['specialist'],
                    'title' => $row['title'],
                    'description' => $row['description'] ?? null,
                    'owner_user_id' => $row['owner_user_id'] ?? null,
                ]);
                foreach ($objective->keyResults as $kr) {
                    $krRow = $data['key_results'][$kr->id];
                    $kr->update([
                        'title' => $krRow['title'],
                        'metric' => $krRow['metric'] ?? null,
                        'target' => $krRow['target'] ?? null,
                        'owner_user_id' => $krRow['owner_user_id'] ?? null,
                        'due_date' => $krRow['due_date'],
                    ]);
                    foreach ($kr->tasks as $task) {
                        $taskRow = $data['tasks'][$task->id];
                        $task->update([
                            'title' => $taskRow['title'],
                            'description' => $taskRow['description'],
                            'assignee_user_id' => $taskRow['assignee_user_id'],
                            'board_column_id' => $taskColumns[$task->id],
                            'due_date' => $taskRow['due_date'],
                        ]);
                    }
                }
            }

            AuditService::log(
                action: 'update_okr_draft',
                targetType: 'okr_cycle',
                targetId: $okr->id,
                after: ['oleh' => $request->user()->id],
            );
        });

        return redirect()->route('okr.show', $okr)->with('status', 'Perubahan pratinjau OKR disimpan.');
    }

    public function approve(Request $request, OkrCycle $okr): RedirectResponse
    {
        $this->okr->approve($okr, $request->user());

        return redirect()->route('okr.show', $okr)
            ->with('status', 'OKR disetujui. Semua kartu tugas sudah dibuat di Kanban.');
    }

    public function destroy(OkrCycle $okr): RedirectResponse
    {
        abort_unless($okr->isDraft(), 422, 'Hanya draf OKR yang bisa dihapus.');
        $id = $okr->id;
        $okr->delete();
        AuditService::log(action: 'delete_okr_draft', targetType: 'okr_cycle', targetId: $id);

        return redirect()->route('okr.index')->with('status', 'Draf OKR dihapus.');
    }

    /** @return Collection<int,User> */
    private function members()
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('role', [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])
            ->orderBy('fullname')
            ->get();
    }

    /** @param array<string,mixed> $data */
    private function period(array $data): array
    {
        if ($data['period_type'] === OkrCycle::PERIOD_MONTHLY) {
            $start = Carbon::createFromFormat('Y-m', $data['period_month'])->startOfMonth();

            return [
                'period_label' => $start->translatedFormat('F Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
            ];
        }

        $quarter = (int) $data['period_quarter'];
        $year = (int) $data['period_year'];
        $start = Carbon::create($year, (($quarter - 1) * 3) + 1, 1)->startOfDay();

        return [
            'period_label' => "Q{$quarter} {$year}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addMonths(2)->endOfMonth()->toDateString(),
        ];
    }

    /** @param array<string,mixed> $data */
    private function scopeLabel(array $data): string
    {
        if ($data['scope_type'] === OkrCycle::SCOPE_TEAM) {
            return 'Tim '.$data['scope_name'];
        }
        if ($data['scope_type'] === OkrCycle::SCOPE_INDIVIDUAL) {
            $member = $this->members()->firstWhere('id', (int) $data['scope_owner_user_id']);
            if (! $member) {
                throw ValidationException::withMessages(['scope_owner_user_id' => 'Anggota harus aktif dan berasal dari tim internal.']);
            }

            return 'Individu '.$member->displayName();
        }

        return 'Seluruh perusahaan';
    }

    /**
     * Pastikan ID yang dikirim memang milik draf ini dan tugas tidak ditempatkan
     * langsung di Done/Selesai. Ini menutup IDOR sekaligus menjaga progres awal.
     *
     * @param  array<string,mixed>  $data
     */
    private function validatePreviewReferences(OkrCycle $okr, array $data): void
    {
        $memberIds = $this->members()->pluck('id')->map(fn ($id) => (int) $id);
        $errors = [];

        foreach ($okr->objectives as $objective) {
            if (! isset($data['objectives'][$objective->id])) {
                $errors[] = "Objective #{$objective->id} hilang dari form.";
            } elseif (filled($data['objectives'][$objective->id]['owner_user_id'] ?? null)
                && ! $memberIds->contains((int) $data['objectives'][$objective->id]['owner_user_id'])) {
                $errors[] = "Pemilik Objective \"{$objective->title}\" bukan anggota internal aktif.";
            }
            foreach ($objective->keyResults as $kr) {
                if (! isset($data['key_results'][$kr->id])) {
                    $errors[] = "Key Result #{$kr->id} hilang dari form.";
                } elseif (filled($data['key_results'][$kr->id]['owner_user_id'] ?? null)
                    && ! $memberIds->contains((int) $data['key_results'][$kr->id]['owner_user_id'])) {
                    $errors[] = "Pemilik Key Result \"{$kr->title}\" bukan anggota internal aktif.";
                }
                foreach ($kr->tasks as $task) {
                    $row = $data['tasks'][$task->id] ?? null;
                    if (! $row) {
                        $errors[] = "Tugas #{$task->id} hilang dari form.";

                        continue;
                    }
                    if (! $memberIds->contains((int) $row['assignee_user_id'])) {
                        $errors[] = "Penerima tugas \"{$task->title}\" bukan anggota internal aktif.";
                    }
                    $column = BoardColumn::find($row['board_column_id']);
                    if ($column?->isDone()) {
                        $errors[] = "Tugas \"{$task->title}\" tidak boleh dimulai di kolom Done/Selesai.";
                    }
                    if ($row['due_date'] < $okr->start_date->toDateString() || $row['due_date'] > $okr->end_date->toDateString()) {
                        $errors[] = "Tanggal tugas \"{$task->title}\" harus berada dalam periode OKR.";
                    }
                }
                $krRow = $data['key_results'][$kr->id] ?? null;
                if ($krRow && ($krRow['due_date'] < $okr->start_date->toDateString() || $krRow['due_date'] > $okr->end_date->toDateString())) {
                    $errors[] = "Tanggal Key Result \"{$kr->title}\" harus berada dalam periode OKR.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['okr' => implode(' ', array_unique($errors))]);
        }
    }

    /**
     * Bila papan memiliki kolom bernama PIC, kolom tersebut selalu menang.
     * Dengan begitu perubahan PIC Agatha tidak mungkin tetap tersimpan ke To Do Tiar.
     *
     * @param  array<string,mixed>  $data
     * @return array<int,int>
     */
    private function matchedTaskColumns(OkrCycle $okr, array $data): array
    {
        $members = $this->members()->keyBy('id');
        $columns = BoardColumn::query()->with('board')->orderBy('board_id')->orderBy('position')->get()
            ->reject(fn (BoardColumn $column) => $column->isDone());
        $matched = [];

        foreach ($okr->objectives->flatMap->keyResults->flatMap->tasks as $task) {
            $row = $data['tasks'][$task->id];
            $selectedId = (int) $row['board_column_id'];
            $member = $members->get((int) $row['assignee_user_id']);
            if (! $member) {
                $matched[$task->id] = $selectedId;

                continue;
            }

            $memberName = $this->normaliseName($member->displayName());
            $tokens = collect(preg_split('/\s+/u', $memberName) ?: [])
                ->filter(fn (string $token) => mb_strlen($token) >= 3 && ! in_array($token, ['admin', 'super', 'skinku'], true))
                ->values();
            $selectedBoardId = (int) ($columns->firstWhere('id', $selectedId)?->board_id ?? 0);
            $bestId = null;
            $bestScore = 0;

            foreach ($columns as $column) {
                $columnName = $this->normaliseName($column->name);
                $score = str_contains($columnName, $memberName) ? 100 : 0;
                foreach ($tokens as $token) {
                    if (str_contains($columnName, $token)) {
                        $score += 20;
                    }
                }
                if ($score === 0) {
                    continue;
                }
                if ((int) $column->board_id === $selectedBoardId) {
                    $score += 5;
                }
                if (str_contains($columnName, 'to do') || str_contains($columnName, 'todo')) {
                    $score += 5;
                }
                if ($score > $bestScore) {
                    $bestId = $column->id;
                    $bestScore = $score;
                }
            }

            $matched[$task->id] = $bestId ?? $selectedId;
        }

        return $matched;
    }

    private function normaliseName(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^\pL\pN ]+/u', ' ')->squish()->toString();
    }
}
