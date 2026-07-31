<?php

namespace App\Services\Ai\Tools;

use App\Models\Board;
use App\Models\User;
use App\Services\KanbanKpiService;
use Illuminate\Support\Collection;

/**
 * Alat BACA: KPI kinerja tim dari papan Kanban (per orang: total, selesai,
 * berjalan, TELAT, tepat waktu, skor%) lewat KanbanKpiService — supaya AI bisa
 * menjawab "siapa paling sering telat / paling produktif" dari data nyata,
 * bukan minta screenshot.
 */
class RingkasKpiKanbanTool extends BaseTool
{
    public function __construct(private KanbanKpiService $kpi) {}

    /** KPI tim internal — mitra (distributor/reseller) tak boleh melihatnya. */
    public function permission(): ?string
    {
        return 'kanban.view';
    }

    public function name(): string
    {
        return 'ringkas_kpi_kanban';
    }

    public function description(): string
    {
        return 'Baca KPI kinerja tim dari papan Kanban: per orang — jumlah kartu, selesai, '
            .'sedang berjalan, TELAT (lewat tenggat), tepat waktu, dan skor persen. '
            .'Panggil ini untuk menjawab siapa paling sering telat, siapa paling produktif, '
            .'atau ringkasan performa Kanban tim.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'papan' => [
                    'type' => 'string',
                    'description' => 'Nama papan Kanban (opsional, pencocokan sebagian). Kosongkan untuk semua papan.',
                ],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $filter = trim((string) ($args['papan'] ?? ''));
        $boards = Board::query()
            ->with('columns.cards')
            ->when($filter !== '', fn ($q) => $q->where('name', 'like', '%'.$filter.'%'))
            ->orderBy('name')
            ->limit(10)
            ->get();

        if ($boards->isEmpty()) {
            return ['catatan' => 'Tidak ada papan Kanban yang cocok.'];
        }

        $papan = $boards->map(function (Board $board) {
            $kpi = $this->kpi->forBoard($board);

            return [
                'papan' => $board->name,
                'total_kartu' => $kpi['total_cards'] ?? 0,
                'per_orang' => (new Collection($kpi['rows'] ?? []))->map(fn (array $r) => [
                    'nama' => $r['nama'] ?? '—',
                    'total' => $r['total'] ?? 0,
                    'selesai' => $r['selesai'] ?? 0,
                    'berjalan' => $r['berjalan'] ?? 0,
                    'telat' => $r['telat'] ?? 0,
                    'tepat_waktu' => $r['tepat'] ?? 0,
                    'skor_persen' => $r['skor'] ?? 0,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'papan' => $papan,
            'catatan' => 'Angka dari kartu Kanban aktual. "telat" = kartu selesai lewat tenggat, '
                .'atau masih terbuka padahal tenggat sudah lewat. Skor = selesai/total.',
        ];
    }
}
