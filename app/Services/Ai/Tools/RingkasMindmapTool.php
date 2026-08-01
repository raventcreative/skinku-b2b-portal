<?php

namespace App\Services\Ai\Tools;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Alat BACA: papan Mindmaps yang boleh diakses user. Tanpa argumen -> daftar
 * papan; dengan nama -> isi & struktur satu papan (sticky + garis). HANYA papan
 * yang user itu owner/anggota (super_admin = semua) — akses per-papan dihormati.
 */
class RingkasMindmapTool extends BaseTool
{
    public function permission(): ?string
    {
        return 'mindmap.view';
    }

    public function name(): string
    {
        return 'ringkas_mindmap';
    }

    public function description(): string
    {
        return 'Baca papan Mindmaps yang boleh kamu akses: tanpa argumen = daftar papan; '
            .'isi "papan" = isi & struktur satu papan (sticky + hubungan garis). '
            .'Hanya papan yang kamu owner/anggota. Panggil untuk "apa isi papan X", "ringkas mindmap", melihat cabang.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'papan' => [
                    'type' => 'string',
                    'description' => 'Nama papan (opsional, cocok sebagian). Kosongkan untuk daftar semua papan yang bisa diakses.',
                ],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $filter = trim((string) ($args['papan'] ?? ''));
        $boards = $this->accessible($user, $filter);

        if ($boards->isEmpty()) {
            return ['catatan' => $filter !== ''
                ? "Tidak ada papan Mindmaps bernama mirip \"{$filter}\" yang bisa kamu akses."
                : 'Belum ada papan Mindmaps yang bisa kamu akses.'];
        }

        if ($filter !== '') {
            /** @var Mindmap $map */
            $map = $boards->first();
            $map->load([
                'nodes:id,mindmap_id,text,color',
                'edges:id,mindmap_id,from_node_id,to_node_id,label',
                'creator:id,fullname,name',
            ]);
            $teks = $map->nodes->pluck('text', 'id');

            return [
                'papan' => $map->title,
                'pemilik' => $map->creator?->fullname ?? $map->creator?->name ?? '—',
                'sticky' => $map->nodes->map(fn ($n) => ['teks' => $n->text ?? '', 'warna' => $n->color])->values()->all(),
                'garis' => $map->edges->map(fn ($e) => [
                    'dari' => $teks[$e->from_node_id] ?? ('#'.$e->from_node_id),
                    'ke' => $teks[$e->to_node_id] ?? ('#'.$e->to_node_id),
                    'label' => $e->label,
                ])->values()->all(),
                'catatan' => 'Baca "garis" sebagai induk -> anak untuk memahami cabang.',
            ];
        }

        return [
            'papan' => $boards->map(fn (Mindmap $m) => [
                'judul' => $m->title,
                'pemilik' => $m->creator?->fullname ?? $m->creator?->name ?? '—',
                'jumlah_sticky' => $m->nodes_count,
                'jumlah_garis' => $m->edges_count,
            ])->values()->all(),
            'catatan' => 'Sebutkan nama papan untuk melihat isinya.',
        ];
    }

    /**
     * Papan yang user boleh lihat (super_admin = semua).
     *
     * @return Collection<int,Mindmap>
     */
    private function accessible(User $user, string $filter): Collection
    {
        $q = Mindmap::query()->with('creator:id,fullname,name')->withCount(['nodes', 'edges']);

        if (! $user->isSuperAdmin()) {
            $q->where(fn ($w) => $w->where('created_by', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)));
        }
        if ($filter !== '') {
            $q->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($filter).'%']);
        }

        return $q->orderByDesc('updated_at')->limit(20)->get();
    }
}
