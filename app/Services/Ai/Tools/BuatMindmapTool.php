<?php

namespace App\Services\Ai\Tools;

use App\Models\Mindmap;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Collection;

/**
 * Alat TULIS: buat papan Mindmaps BARU dari deskripsi. TAK PERNAH jalan tanpa
 * konfirmasi (isWrite). Papan jadi milik user. Node bisa bercabang lewat "induk"
 * (indeks node induk di daftar yang sama).
 */
class BuatMindmapTool extends BaseTool
{
    public function name(): string
    {
        return 'buat_mindmap';
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
        return 'Buat papan Mindmaps BARU. Wajib: judul + daftar sticky (tiap sticky: teks, dan induk '
            .'opsional = indeks sticky induk untuk cabang). Contoh: judul "Struktur Channel", '
            .'node [{teks:"Channel"},{teks:"TikTok",induk:0},{teks:"Shopee",induk:0}]. Papan jadi milikmu.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'judul' => ['type' => 'string', 'description' => 'Judul papan mindmap'],
                'node' => [
                    'type' => 'array',
                    'description' => 'Daftar sticky. Tiap item {teks, induk?}. "induk" = indeks (0-based) sticky induk di daftar ini untuk membuat cabang.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'teks' => ['type' => 'string'],
                            'induk' => ['type' => 'integer', 'description' => 'Indeks sticky induk (opsional)'],
                        ],
                        'required' => ['teks'],
                    ],
                ],
            ],
            'required' => ['judul', 'node'],
        ];
    }

    public function validate(array $args, User $user): ?string
    {
        if (blank($args['judul'] ?? null)) {
            return 'Butuh judul papan.';
        }

        return MindmapNodeBuilder::validateList($args['node'] ?? null);
    }

    public function previewText(array $args, User $user): string
    {
        $nodes = is_array($args['node'] ?? null) ? $args['node'] : [];
        $contoh = (new Collection($nodes))->take(3)
            ->map(fn ($n) => is_array($n) ? ($n['teks'] ?? '') : '')->filter()->implode(', ');

        return "Buat papan **\"{$args['judul']}\"** berisi ".count($nodes).' sticky'
            .($contoh !== '' ? " (mis. {$contoh})" : '').'.';
    }

    public function run(array $args, User $user): array
    {
        if ($err = $this->validate($args, $user)) {
            throw new \RuntimeException($err);
        }

        $map = Mindmap::create(['title' => $args['judul'], 'created_by' => $user->id]);
        $created = MindmapNodeBuilder::build($map, $args['node'], $user, 100.0, 100.0);

        AuditService::log(action: 'create_mindmap_ai', targetType: 'mindmap', targetId: $map->id,
            after: ['judul' => $map->title, 'jumlah_sticky' => count($created), 'via' => 'asisten_ai']);

        return ['ok' => true, 'pesan' => "Papan \"{$map->title}\" dibuat dengan ".count($created).' sticky.', 'papan_id' => $map->id];
    }
}
