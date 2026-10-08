<?php

namespace App\Services\Ai\Tools;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Alat BACA: Pipeline & Kalender Konten akun brand (menu Konten), izin content.create sama dgn halamannya.
 * Cakupan = halaman: pengelola lintas creator (super admin / content.manage) lihat semua, creator hanya kontennya
 * (ContentPost::terlihatOleh). Angka kartu tahap dari ContentPost::hitungTahap. Caption & catatan tidak dikirim.
 */
class PipelineKontenTool extends BaseTool
{
    /** Tahap untuk AI → kunci tahap halaman (ContentPost::scopeTahap). */
    private const TAHAP = ['semua' => 'all', 'draft' => 'draft', 'terjadwal' => 'scheduled', 'sedang_terbit' => 'publishing',
        'perlu_tindakan' => 'attention', 'selesai' => 'published'];

    public function name(): string
    {
        return 'pipeline_konten';
    }

    public function permission(): ?string
    {
        return 'content.create';
    }

    public function description(): string
    {
        return 'Pipeline & Kalender Konten akun brand SKINKU (menu Konten): jumlah konten per tahap (draft, terjadwal, '
            .'sedang terbit, perlu tindakan = gagal / terbit sebagian / menunggu posting manual, selesai) dan daftar konten '
            .'(judul, tipe, jadwal, status, platform + status terbit per platform, link postingan, alasan gagal). Saring '
            .'tahap, rentang tanggal jadwal dari–sampai (mis. jadwal minggu ini), platform, judul; pengelola bisa saring '
            .'kreator. Content creator hanya melihat kontennya sendiri. Untuk views/engagement pakai insight_konten.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tahap' => ['type' => 'string', 'enum' => array_keys(self::TAHAP), 'description' => 'Default semua.'],
                'dari' => ['type' => 'string', 'description' => 'YYYY-MM-DD — jadwal mulai tanggal ini (opsional).'],
                'sampai' => ['type' => 'string', 'description' => 'YYYY-MM-DD — jadwal sampai tanggal ini (opsional).'],
                'platform' => ['type' => 'string', 'enum' => array_keys(config('content.platforms')), 'description' => 'Opsional.'],
                'kreator' => ['type' => 'string', 'description' => 'Nama/username content creator (opsional, khusus pengelola).'],
                'cari' => ['type' => 'string', 'description' => 'Kata di judul konten (opsional).'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah konten di daftar, 1-30. Default 10.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $lintas = ContentPost::lintasCreator($user);
        $tahap = isset(self::TAHAP[$args['tahap'] ?? '']) ? $args['tahap'] : 'semua';
        [$dari, $sampai] = [$this->tanggal($args['dari'] ?? null), $this->tanggal($args['sampai'] ?? null)];
        if ($dari && $sampai && $sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $platform = isset(config('content.platforms')[$args['platform'] ?? '']) ? $args['platform'] : null;
        $cari = trim((string) ($args['cari'] ?? ''));
        $kreator = null;
        if ($lintas && trim((string) ($args['kreator'] ?? '')) !== '') {
            [$kreator, $err] = $this->cariKreator(trim((string) $args['kreator']));
            if (! $kreator) {
                return $err;
            }
        }

        // Filter & urutan sama dgn halaman: jadwal terdekat dulu, tanpa jadwal di belakang.
        $q = ContentPost::query()->terlihatOleh($user)->tahap(self::TAHAP[$tahap])
            ->when($kreator, fn ($q) => $q->where('user_id', $kreator->id))
            ->when($platform, fn ($q) => $q->whereHas('targets', fn ($t) => $t->where('platform', $platform)))
            ->when($cari !== '', fn ($q) => $q->where('title', 'like', '%'.$cari.'%'))
            ->when($dari, fn ($q) => $q->whereDate('scheduled_at', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('scheduled_at', '<=', $sampai));
        $jumlah = (clone $q)->count();
        $posts = $q->with(['targets', 'user'])->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->latest('id')
            ->limit(max(1, min(30, (int) ($args['limit'] ?? 10))))->get();
        $t = ContentPost::hitungTahap($user);

        return array_filter([
            'cakupan' => $lintas ? 'Seluruh content creator' : 'Hanya konten milik user ini',
            'jumlah_per_tahap' => [
                'semua' => (int) $t['per_status']->sum(),
                'draft' => (int) ($t['per_status'][ContentPost::DRAFT] ?? 0),
                'terjadwal' => (int) ($t['per_status'][ContentPost::SCHEDULED] ?? 0),
                'sedang_terbit' => (int) ($t['per_status'][ContentPost::PUBLISHING] ?? 0),
                'perlu_tindakan' => $t['perlu_tindakan'],
                'selesai' => (int) ($t['per_status'][ContentPost::DONE] ?? 0),
            ],
            'filter' => array_filter(['tahap' => $tahap !== 'semua' ? $tahap : null, 'dari' => $dari, 'sampai' => $sampai,
                'platform' => $platform, 'kreator' => $kreator?->displayName(), 'cari' => $cari ?: null]) ?: null,
            'jumlah_konten' => $jumlah,
            'konten' => $posts->map(fn (ContentPost $p) => array_filter([
                'judul' => $p->title,
                'tipe' => ContentPost::TYPES[$p->type] ?? $p->type,
                'jadwal' => $p->scheduled_at?->format('Y-m-d H:i'),
                'status' => $p->statusLabel(),
                'kreator' => $lintas ? $p->user?->displayName() : null,
                'platform' => $p->targets->map(fn (ContentPostTarget $tg) => array_filter([
                    'platform' => $tg->platformLabel(),
                    'status' => $tg->statusLabel(),
                    'terbit' => $tg->published_at?->format('Y-m-d H:i'),
                    'link' => $tg->permalink,
                    'alasan_gagal' => $tg->status === ContentPostTarget::FAILED && $tg->last_error ? Str::limit($tg->last_error, 150) : null,
                ], fn ($v) => $v !== null))->values()->all(),
            ], fn ($v) => $v !== null))->all(),
        ], fn ($v) => $v !== null);
    }

    /** @return array{0:?User,1:array} kreator (pemilik konten) dari nama/username; ambigu → minta AI bertanya balik. */
    private function cariKreator(string $q): array
    {
        $mirip = User::whereIn('id', ContentPost::select('user_id'))
            ->where(fn ($w) => $w->where('username', $q)->orWhere('fullname', 'like', "%{$q}%")->orWhere('username', 'like', "%{$q}%"))
            ->get();
        $persis = $mirip->first(fn (User $u) => strcasecmp((string) $u->username, $q) === 0);
        if ($persis || $mirip->count() === 1) {
            return [$persis ?? $mirip->first(), []];
        }

        return [null, $mirip->isEmpty()
            ? ['error' => "Tidak ada content creator \"{$q}\" yang punya konten."]
            : ['error' => "Ada beberapa creator yang mirip \"{$q}\" — tanyakan ke user yang mana.",
                'kandidat' => $mirip->map(fn (User $u) => $u->displayName())->values()->all()]];
    }
}
