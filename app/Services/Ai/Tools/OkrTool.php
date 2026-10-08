<?php

namespace App\Services\Ai\Tools;

use App\Models\OkrCycle;
use App\Models\OkrKeyResult;
use App\Models\OkrObjective;
use App\Models\OkrTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Alat BACA: OKR (menu OKR), izin okr.view + khusus tim internal (middleware `internal`: mitra diblok keras) — sama
 * dgn halamannya. Progres = tugas Kanban turunan yang selesai ÷ total (OkrTask::isCompleted, rumus halaman).
 */
class OkrTool extends BaseTool
{
    public function name(): string
    {
        return 'okr';
    }

    public function permission(): ?string
    {
        return 'okr.view';
    }

    public function availableFor(User $user): bool
    {
        return ! $user->isPartner();
    }

    public function description(): string
    {
        return 'OKR tim SKINKU (menu OKR): siklus OKR bulanan/kuartalan (perusahaan/tim/individu), status draft/aktif, '
            .'progres = tugas Kanban turunan yang selesai ÷ total, objective per divisi (CMO/CFO/COO) dan key result (metrik, '
            .'baseline, target, tenggat, PIC). Tanpa nama → ringkasan siklus terbaru; isi nama (atau periode, mis. '
            .'"Oktober 2026") untuk rincian objective, key result & tugas (selesai/belum).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nama' => ['type' => 'string', 'description' => 'Nama atau label periode siklus OKR (opsional) → rincian.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $cycles = OkrCycle::query()
            ->with(['scopeOwner', 'objectives.owner', 'objectives.keyResults.owner', 'objectives.keyResults.tasks.card:id,completed_at',
                'objectives.keyResults.tasks.assignee'])
            ->orderByDesc('start_date')->orderByDesc('id')->get();
        $nama = mb_strtolower(trim((string) ($args['nama'] ?? '')));

        if ($nama === '') {
            return [
                'jumlah_siklus' => $cycles->count(),
                'catatan' => $cycles->isEmpty() ? 'Belum ada OKR — susun lewat menu OKR → Susun OKR dengan AI.' : 'Isi nama siklus untuk rincian objective & key result.',
                'siklus' => $cycles->take(10)->map(fn (OkrCycle $c) => $this->ringkas($c) + [
                    'objective' => $c->objectives->map(fn (OkrObjective $o) => $o->specialistLabel().': '.$o->title)->all(),
                ])->values()->all(),
            ];
        }

        $cocok = $cycles->filter(fn (OkrCycle $c) => str_contains(mb_strtolower($c->name.' '.$c->period_label), $nama))->values();
        if ($cocok->count() !== 1) {
            return ['error' => $cocok->isEmpty() ? "Tidak ada siklus OKR yang cocok dengan \"{$args['nama']}\"."
                : 'Ada beberapa siklus OKR yang cocok — tanyakan ke user yang mana.',
                'siklus_tersedia' => ($cocok->isEmpty() ? $cycles : $cocok)->take(10)->map(fn (OkrCycle $c) => $c->name.' ('.$c->period_label.')')->all()];
        }
        $c = $cocok->first();

        return $this->ringkas($c) + array_filter([
            'arah' => $c->direction ? Str::limit($c->direction, 300) : null,
            'ringkasan_analisis' => $c->analysis_summary ? Str::limit($c->analysis_summary, 600) : null,
            'objective' => $c->objectives->map(fn (OkrObjective $o) => array_filter([
                'judul' => $o->title,
                'divisi' => $o->specialistLabel(),
                'pemilik' => $o->ownerLabel(),
                'progres' => $this->progres($o->keyResults->flatMap->tasks),
                'key_result' => $o->keyResults->map(fn (OkrKeyResult $kr) => array_filter([
                    'judul' => $kr->title,
                    'metrik' => $kr->metric,
                    'baseline' => $kr->baseline,
                    'target' => $kr->target,
                    'tenggat' => $kr->due_date?->toDateString(),
                    'pic' => $kr->ownerLabel(),
                    'progres' => $this->progres($kr->tasks),
                    'tugas' => $kr->tasks->map(fn (OkrTask $t) => array_filter([
                        'judul' => $t->title,
                        'pic' => $t->assigneeLabel(),
                        'tenggat' => $t->due_date?->toDateString(),
                        'selesai' => $t->isCompleted(),
                    ], fn ($v) => $v !== null))->values()->all(),
                ], fn ($v) => $v !== null && $v !== ''))->values()->all(),
            ], fn ($v) => $v !== null))->values()->all(),
        ], fn ($v) => $v !== null);
    }

    private function ringkas(OkrCycle $c): array
    {
        return array_filter([
            'nama' => $c->name,
            'periode' => $c->period_label,
            'tanggal' => $c->start_date?->toDateString().' s/d '.$c->end_date?->toDateString(),
            'cakupan' => $c->scopeLabel(),
            'status' => $c->isDraft() ? 'draft (belum disetujui)' : 'aktif',
            'penyusunan_ai' => $c->isGenerating() ? 'sedang disusun AI' : ($c->generationFailed() ? 'gagal disusun AI' : null),
            'progres' => $this->progres($c->objectives->flatMap->keyResults->flatMap->tasks),
        ], fn ($v) => $v !== null);
    }

    /** Rumus halaman OKR: tugas selesai ÷ total, dibulatkan. */
    private function progres(Collection $tasks): array
    {
        $done = $tasks->filter(fn (OkrTask $t) => $t->isCompleted())->count();
        $total = $tasks->count();

        return ['tugas_selesai' => $done, 'total_tugas' => $total, 'persen' => $total ? (int) round($done / $total * 100) : 0];
    }
}
