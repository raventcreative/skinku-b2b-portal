<?php

namespace App\Services;

use App\Models\Board;

/**
 * KPI Kanban per ORANG berdasarkan NAMA KOLOM untuk satu papan. Papan disusun
 * kolom-per-orang ("To Do List X" / "Done X"), jadi orang diambil dari nama
 * kolom (buang kata status di depan) — bukan assignee. Semua orang muncul tanpa
 * perlu akun user / assign kartu.
 *
 * Metrik: total, selesai (kartu di kolom Done/Selesai orang itu), berjalan,
 * telat, tepat waktu, skor % (selesai/total). "Telat":
 *  - berjalan & deadline sudah lewat (isPast, sama seperti badge "lewat!"), ATAU
 *  - selesai tapi tanggal selesai > deadline.
 */
class KanbanKpiService
{
    /**
     * @return array{rows: array<int,array<string,mixed>>, unassigned: int, total_cards: int}
     */
    public function forBoard(Board $board): array
    {
        $groups = [];
        $totalCards = 0;

        foreach ($board->columns as $column) {
            $person = $this->personOf($column->name);
            $key = mb_strtolower($person);
            $columnDone = $column->isDone();

            foreach ($column->cards as $card) {
                $totalCards++;
                if (! isset($groups[$key])) {
                    $groups[$key] = ['nama' => $person, 'total' => 0, 'selesai' => 0, 'berjalan' => 0, 'telat' => 0, 'tepat' => 0];
                }
                $groups[$key]['total']++;

                if ($columnDone) {
                    $groups[$key]['selesai']++;
                    $late = $card->completed_at && $card->due_date && $card->completed_at->toDateString() > $card->due_date->toDateString();
                    $groups[$key][$late ? 'telat' : 'tepat']++;
                } else {
                    $groups[$key]['berjalan']++;
                    if ($card->due_date && $card->due_date->isPast()) {
                        $groups[$key]['telat']++;
                    }
                }
            }
        }

        $rows = array_map(function (array $s): array {
            $s['skor'] = $s['total'] > 0 ? (int) round($s['selesai'] / $s['total'] * 100) : 0;

            return $s;
        }, array_values($groups));

        usort($rows, fn ($a, $b) => ($b['skor'] <=> $a['skor']) ?: ($b['total'] <=> $a['total']));

        return ['rows' => $rows, 'unassigned' => 0, 'total_cards' => $totalCards];
    }

    /** Nama orang dari nama kolom: buang kata status di depan ("To Do List Tiar" → "Tiar"). */
    private function personOf(string $columnName): string
    {
        $stripped = preg_replace(
            '/^\s*(to\s*do\s*list|to\s*do|todo|done|selesai|proses|in\s*progress|backlog)\s+/i',
            '',
            trim($columnName),
        );
        $stripped = trim((string) $stripped);

        return $stripped !== '' ? $stripped : trim($columnName);   // fallback: nama kolom apa adanya
    }
}
