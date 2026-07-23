<?php

namespace App\Services;

use App\Models\Board;

/**
 * KPI Kanban per anggota (penanggung jawab/assignee) untuk satu papan. Ukur
 * beban & performa: total, selesai, berjalan, telat, tepat waktu, skor %.
 *
 * "Selesai" = kartu punya completed_at (masuk kolom Done). "Telat":
 *  - kartu selesai tapi tanggal selesai > deadline, ATAU
 *  - kartu belum selesai & deadline sudah lewat.
 * Kartu tanpa assignee tak masuk hitungan (dilaporkan terpisah).
 */
class KanbanKpiService
{
    /**
     * @return array{rows: array<int,array<string,mixed>>, unassigned: int, total_cards: int}
     */
    public function forBoard(Board $board): array
    {
        $byUser = [];
        $unassigned = 0;
        $totalCards = 0;

        // "Selesai" ditentukan KOLOM saat ini (Done/Selesai) — akurat untuk kartu
        // lama sekalipun. completed_at (kalau ada) hanya dipakai menilai telat;
        // kartu Done lama tanpa completed_at dianggap tepat waktu (tak bisa dinilai).
        foreach ($board->columns as $column) {
            $columnDone = $column->isDone();
            foreach ($column->cards as $card) {
                $totalCards++;
                if (! $card->assignee_user_id) {
                    $unassigned++;

                    continue;
                }
                $uid = $card->assignee_user_id;
                if (! isset($byUser[$uid])) {
                    $byUser[$uid] = [
                        'nama' => $card->assignee?->fullname ?? ('User #'.$uid),
                        'user_id' => $uid, 'total' => 0, 'selesai' => 0, 'berjalan' => 0, 'telat' => 0, 'tepat' => 0,
                    ];
                }
                $byUser[$uid]['total']++;

                if ($columnDone) {
                    $byUser[$uid]['selesai']++;
                    $late = $card->completed_at && $card->due_date && $card->completed_at->toDateString() > $card->due_date->toDateString();
                    $byUser[$uid][$late ? 'telat' : 'tepat']++;
                } else {
                    $byUser[$uid]['berjalan']++;
                    // Sama seperti badge "lewat!" di kartu (isPast): jatuh tempo
                    // hari ini pun dihitung telat kalau belum selesai.
                    if ($card->due_date && $card->due_date->isPast()) {
                        $byUser[$uid]['telat']++;
                    }
                }
            }
        }

        $rows = array_map(function (array $s): array {
            $s['skor'] = $s['total'] > 0 ? (int) round($s['selesai'] / $s['total'] * 100) : 0;

            return $s;
        }, array_values($byUser));

        usort($rows, fn ($a, $b) => ($b['skor'] <=> $a['skor']) ?: ($b['total'] <=> $a['total']));

        return ['rows' => $rows, 'unassigned' => $unassigned, 'total_cards' => $totalCards];
    }
}
