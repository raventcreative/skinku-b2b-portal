<?php

namespace App\Services\Ai\Tools;

use App\Models\Mindmap;
use App\Models\MindmapNode;
use App\Models\User;

/**
 * Helper bersama untuk alat AI yang membuat node Mindmaps (BuatMindmap &
 * TambahMindmap): validasi daftar node dari AI + buat node/garis dengan tata
 * letak sederhana (kedalaman -> ke kanan, urutan -> ke bawah).
 */
class MindmapNodeBuilder
{
    private const MAX_NODES = 60;

    private const STEP_X = 260.0;

    private const STEP_Y = 144.0;

    /** Validasi daftar node dari AI. Balikin pesan minta-perjelas atau null. */
    public static function validateList($nodes): ?string
    {
        if (! is_array($nodes) || count($nodes) === 0) {
            return 'Butuh minimal 1 sticky. Tiap sticky: {teks, dan induk opsional untuk cabang}.';
        }
        if (count($nodes) > self::MAX_NODES) {
            return 'Maksimal '.self::MAX_NODES.' sticky sekali jalan. Pecah jadi beberapa bagian.';
        }
        $n = count($nodes);
        foreach (array_values($nodes) as $i => $node) {
            if (! is_array($node) || blank($node['teks'] ?? null)) {
                return 'Sticky ke-'.($i + 1)." butuh 'teks'.";
            }
            $ind = $node['induk'] ?? null;
            if ($ind !== null && (! is_int($ind) || $ind < 0 || $ind >= $n || $ind === $i)) {
                return 'Sticky ke-'.($i + 1)." punya 'induk' tak valid (harus indeks 0..".($n - 1).', bukan dirinya).';
            }
        }

        return null;
    }

    /**
     * Buat node + garis induk->anak di papan. Balikin daftar node yang dibuat
     * (terindeks sesuai urutan input).
     *
     * @return array<int,MindmapNode>
     */
    public static function build(Mindmap $map, array $nodes, User $user, float $baseX, float $baseY): array
    {
        $nodes = array_values($nodes);
        $depths = self::depths($nodes);

        $created = [];
        foreach ($nodes as $i => $node) {
            $created[$i] = $map->nodes()->create([
                'type' => 'sticky',
                'x' => $baseX + $depths[$i] * self::STEP_X,
                'y' => $baseY + $i * self::STEP_Y,
                'text' => (string) $node['teks'],
                'color' => 'kuning',
                'created_by' => $user->id,
            ]);
        }
        foreach ($nodes as $i => $node) {
            $ind = $node['induk'] ?? null;
            if (is_int($ind) && isset($created[$ind])) {
                $map->edges()->create([
                    'from_node_id' => $created[$ind]->id,
                    'to_node_id' => $created[$i]->id,
                ]);
            }
        }

        return $created;
    }

    /** Kedalaman tiap node (telusuri rantai induk; guard siklus/indeks liar). */
    private static function depths(array $nodes): array
    {
        $n = count($nodes);
        $depths = [];
        foreach (array_keys($nodes) as $i) {
            $d = 0;
            $cur = $i;
            $seen = [$i => true];
            while (true) {
                $ind = $nodes[$cur]['induk'] ?? null;
                if (! is_int($ind) || $ind < 0 || $ind >= $n || isset($seen[$ind]) || $d >= $n) {
                    break;
                }
                $seen[$ind] = true;
                $cur = $ind;
                $d++;
            }
            $depths[$i] = $d;
        }

        return $depths;
    }
}
