<?php

namespace App\Services\Ai\Tools;

use App\Models\LearningModule;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Alat BACA: SKINKU Academy (menu Academy), izin view_learning sama dgn halamannya. Modul & materi yang terlihat
 * user ini dari LearningModule/Lesson::terlihatUntuk (= halaman: pengelola semua, lainnya terbit & sesuai audiens).
 */
class AcademyTool extends BaseTool
{
    public function name(): string
    {
        return 'academy';
    }

    public function permission(): ?string
    {
        return 'view_learning';
    }

    public function description(): string
    {
        return 'SKINKU Academy (menu Academy): modul & materi belajar (video / dokumen) yang tersedia untuk user ini — '
            .'judul, kategori, deskripsi singkat, link ke halaman materi. Tanpa cari → daftar modul & judul materi; isi cari '
            .'(topik/judul/kategori) untuk materi yang cocok beserta deskripsinya. Isi video/dokumen tidak dibaca — arahkan '
            .'user membuka link materi.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cari' => ['type' => 'string', 'description' => 'Topik/judul/kategori materi (opsional).'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $modules = LearningModule::terlihatUntuk($user);
        $lessons = Lesson::terlihatUntuk($user);
        $namaModul = $modules->pluck('title', 'id');
        $tipe = fn (Lesson $l) => $l->type === Lesson::TYPE_VIDEO ? 'Video' : 'Dokumen';
        $cari = mb_strtolower(trim((string) ($args['cari'] ?? '')));

        if ($cari !== '') {
            $cocok = $lessons->filter(fn (Lesson $l) => str_contains(mb_strtolower($l->title.' '.$l->category.' '.$l->description), $cari))->values();

            return array_filter([
                'cari' => $args['cari'],
                'jumlah_materi' => $cocok->count(),
                'catatan' => $cocok->isEmpty() ? 'Tidak ada materi yang cocok — ulangi tanpa cari untuk melihat semua materi.' : null,
                'materi' => $cocok->take(10)->map(fn (Lesson $l) => array_filter([
                    'judul' => $l->title,
                    'modul' => $namaModul[$l->module_id] ?? null,
                    'tipe' => $tipe($l),
                    'kategori' => $l->category,
                    'deskripsi' => $l->description ? Str::limit($l->description, 300) : null,
                    'link' => route('learning.show', $l),
                ], fn ($v) => $v !== null))->all(),
            ], fn ($v) => $v !== null);
        }

        // Sama dgn halaman: materi per modul yang terlihat; tanpa modul = module_id kosong.
        $perModul = $lessons->groupBy(fn (Lesson $l) => $l->module_id ?: 0);
        $judul = fn (Lesson $l) => $l->title.' ('.$tipe($l).($l->category ? ', '.$l->category : '').')';

        return array_filter([
            'jumlah_modul' => $modules->count(),
            'jumlah_materi' => $lessons->count(),
            'modul' => $modules->map(fn (LearningModule $m) => array_filter([
                'judul' => $m->title,
                'deskripsi' => $m->description ? Str::limit($m->description, 150) : null,
                'materi' => ($perModul[$m->id] ?? collect())->map($judul)->values()->all(),
            ], fn ($v) => $v !== null))->all(),
            'materi_tanpa_modul' => ($perModul[0] ?? collect())->map($judul)->values()->all() ?: null,
        ], fn ($v) => $v !== null);
    }
}
