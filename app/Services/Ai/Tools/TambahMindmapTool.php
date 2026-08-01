<?php

namespace App\Services\Ai\Tools;

use App\Models\Mindmap;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Alat TULIS: tambah sticky/cabang ke papan Mindmaps yang SUDAH ada. Wajib user
 * boleh EDIT papan itu (owner/anggota can_edit), kalau tidak ditolak lewat
 * validate(). Opsional "sambung_dari" = teks sticky lama untuk jadi pangkal
 * cabang baru.
 */
class TambahMindmapTool extends BaseTool
{
    public function name(): string
    {
        return 'tambah_mindmap';
    }

    public function permission(): ?string
    {
        return 'mindmap.view';
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Tambah sticky/cabang ke papan Mindmaps yang SUDAH ada (harus boleh kamu edit). '
            .'Wajib: papan (nama) + node (daftar {teks, induk?}). Opsional: sambung_dari '
            .'(teks sticky lama untuk jadi pangkal cabang baru).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'papan' => ['type' => 'string', 'description' => 'Nama papan tujuan (harus boleh kamu edit)'],
                'node' => [
                    'type' => 'array',
                    'description' => 'Daftar sticky baru. Tiap item {teks, induk?}. "induk" = indeks (0-based) di daftar ini.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'teks' => ['type' => 'string'],
                            'induk' => ['type' => 'integer', 'description' => 'Indeks sticky induk (opsional)'],
                        ],
                        'required' => ['teks'],
                    ],
                ],
                'sambung_dari' => ['type' => 'string', 'description' => 'Teks sticky lama untuk jadi pangkal cabang baru (opsional)'],
            ],
            'required' => ['papan', 'node'],
        ];
    }

    public function validate(array $args, User $user): ?string
    {
        $map = $this->findEditable((string) ($args['papan'] ?? ''), $user);
        if (is_string($map)) {
            return $map;
        }

        return MindmapNodeBuilder::validateList($args['node'] ?? null);
    }

    public function previewText(array $args, User $user): string
    {
        $n = is_array($args['node'] ?? null) ? count($args['node']) : 0;
        $line = "Tambah {$n} sticky ke papan **\"{$args['papan']}\"**";
        if (filled($args['sambung_dari'] ?? null)) {
            $line .= " menyambung dari \"{$args['sambung_dari']}\"";
        }

        return $line.'.';
    }

    public function run(array $args, User $user): array
    {
        $map = $this->findEditable((string) ($args['papan'] ?? ''), $user);
        if (is_string($map)) {
            throw new \RuntimeException($map);
        }
        if ($err = MindmapNodeBuilder::validateList($args['node'] ?? null)) {
            throw new \RuntimeException($err);
        }

        $nodes = array_values($args['node']);
        $baseX = $map->nodes()->count() > 0 ? ((float) $map->nodes()->max('x')) + 260.0 : 100.0;
        $created = MindmapNodeBuilder::build($map, $nodes, $user, $baseX, 100.0);

        $catatan = $this->connectAnchor($map, $args['sambung_dari'] ?? null, $nodes, $created);
        $map->touch();

        AuditService::log(action: 'append_mindmap_ai', targetType: 'mindmap', targetId: $map->id,
            after: ['judul' => $map->title, 'jumlah_sticky' => count($created), 'via' => 'asisten_ai']);

        $pesan = count($created)." sticky ditambahkan ke \"{$map->title}\".";
        if ($catatan) {
            $pesan .= ' Catatan: '.$catatan.'.';
        }

        return ['ok' => true, 'pesan' => $pesan];
    }

    /** Sambungkan sticky lama (sambung_dari) ke akar sub-pohon baru. Balikin catatan bila gagal cocok. */
    private function connectAnchor(Mindmap $map, ?string $sambungDari, array $nodes, array $created): ?string
    {
        if (blank($sambungDari)) {
            return null;
        }
        $baruIds = (new Collection($created))->map->id->all();
        $anchor = $map->nodes()
            ->whereRaw('LOWER(text) = ?', [mb_strtolower(trim($sambungDari))])
            ->whereNotIn('id', $baruIds)
            ->get();
        if ($anchor->count() !== 1) {
            return "sticky \"{$sambungDari}\" tak ketemu/ambigu, jadi cabang baru tak tersambung ke sticky lama";
        }
        $anchorId = $anchor->first()->id;
        foreach ($nodes as $i => $node) {
            if (($node['induk'] ?? null) === null && isset($created[$i])) {   // akar sub-pohon baru
                $map->edges()->create(['from_node_id' => $anchorId, 'to_node_id' => $created[$i]->id]);
            }
        }

        return null;
    }

    /** Cari papan yang user boleh EDIT (nama persis). Balikin Mindmap atau string error ramah. */
    private function findEditable(string $name, User $user): Mindmap|string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Butuh nama papan tujuan.';
        }
        $matches = $this->editableQuery($user)->whereRaw('LOWER(title) = ?', [mb_strtolower($name)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            return "Ada beberapa papan bernama \"{$name}\". Sebutkan lebih spesifik.";
        }
        $editable = $this->editableQuery($user)->orderByDesc('updated_at')->pluck('title')->implode(', ');

        return "Papan \"{$name}\" tak ketemu atau kamu tak punya izin edit. Papan yang bisa kamu edit: ".($editable !== '' ? $editable : '(belum ada)').'.';
    }

    /** Query papan yang user boleh edit: owner/super_admin, atau anggota can_edit. */
    private function editableQuery(User $user): Builder
    {
        $q = Mindmap::query();
        if (! $user->isSuperAdmin()) {
            $q->where(fn ($w) => $w->where('created_by', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('can_edit', true)));
        }

        return $q;
    }
}
