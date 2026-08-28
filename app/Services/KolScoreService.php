<?php

namespace App\Services;

use App\Models\KolScore;

/**
 * Merekam jejak skor KOL (APS/KSS). Idempoten harian: satu baris per KOL per
 * type per hari (updateOrCreate captured_on = hari ini). APS direkam otomatis
 * saat ranking dilihat; KSS saat kalkulator dipakai untuk KOL tertentu.
 */
class KolScoreService
{
    /** Rekam satu skor (upsert harian). */
    public function record(int $kolId, string $type, ?float $score, ?string $label, array $meta = [], ?int $actorId = null): void
    {
        KolScore::updateOrCreate(
            ['kol_id' => $kolId, 'type' => $type, 'captured_on' => now()->startOfDay()],
            ['score' => $score, 'label' => $label, 'meta' => $meta ?: null, 'created_by' => $actorId],
        );
    }

    /**
     * Snapshot APS untuk ranking yang sudah dihitung (pakai ulang hasil aps —
     * tak query skor lagi). Hanya yang berstatus 'scored'. Return jumlah direkam.
     *
     * @param  iterable<array{kol:mixed,aps:array}>  $ranking
     */
    public function snapshotAps(iterable $ranking, ?int $actorId = null): int
    {
        $n = 0;
        foreach ($ranking as $row) {
            $aps = $row['aps'] ?? null;
            if (! $aps || ($aps['status'] ?? null) !== 'scored') {
                continue;
            }
            $this->record((int) $row['kol']->id, KolScore::TYPE_APS, $aps['score'], $aps['label'],
                ['capped' => (bool) ($aps['capped'] ?? false)], $actorId);
            $n++;
        }

        return $n;
    }
}
