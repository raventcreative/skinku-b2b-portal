<?php

namespace App\Services\Ai\Tools;

use App\Models\KolContent;
use App\Models\User;
use App\Services\KolKontenService;

/**
 * Alat BACA: Konten & Views KOL (menu KOL → Konten & Views), izin kol.view sama dgn halamannya. Angka dari
 * KolKontenService::bulan → sama dgn kartu ringkasan & tabel (views = snapshot terbaru). Catatan konten tidak dikirim.
 */
class KontenViewsKolTool extends BaseTool
{
    use CariKol;

    public function __construct(private KolKontenService $svc) {}

    public function name(): string
    {
        return 'konten_views_kol';
    }

    public function permission(): ?string
    {
        return 'kol.view';
    }

    public function description(): string
    {
        return 'Konten & Views KOL sebulan (menu KOL → Konten & Views): konten kreator yang diposting di bulan itu '
            .'(paid = konten deal berbayar, earned = organik), total views (snapshot terbaru), target views tim bulan itu, '
            .'% capaian, proyeksi akhir bulan, butuh views/hari, plus kreator & konten dengan views terbanyak. Isi username '
            .'untuk satu kreator. Beda dgn views_harian_kol (views HARIAN video affiliate SKINKU otomatis dari TikTok).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM. Default bulan ini.'],
                'username' => ['type' => 'string', 'description' => 'Username kreator (opsional) → konten kreator itu saja.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah kreator/konten teratas, 1-30. Default 10.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $bulan = $this->bulanLaporan($args['bulan'] ?? null) ?? now()->startOfMonth();
        $limit = max(1, min(30, (int) ($args['limit'] ?? 10)));
        $kol = null;
        if (trim((string) ($args['username'] ?? '')) !== '') {
            [$kol, $err] = $this->cariKol((string) $args['username']);
            if (! $kol) {
                return $err;
            }
        }

        $k = $this->svc->bulan($bulan, $kol ? ['creator' => $kol->id] : []);
        $konten = $k['contents'];
        $views = fn (KolContent $c) => (int) ($c->latestSnapshot->views ?? 0);
        $target = $kol ? [] : array_filter([
            'target_views' => $k['target'],
            'persen_target' => $k['target'] > 0 ? (int) round($k['total'] / $k['target'] * 100) : null,
            'proyeksi_akhir_bulan' => $k['isCurrent'] ? $k['proj'] : null,
            'status_target' => $k['target'] > 0 ? ($k['aman'] ? 'aman (proyeksi ≥ 95% target)' : 'di bawah target') : null,
            'sisa_hari' => $k['isCurrent'] ? $k['daysLeft'] : null,
            'butuh_views_per_hari' => $k['perDayNeeded'] ?: null,
        ], fn ($v) => $v !== null);

        return array_filter([
            'bulan' => $this->labelBulan($bulan),
            'kreator' => $kol ? ['username' => '@'.$kol->handle(), 'nama' => $kol->name] : null,
            'catatan' => 'Views = angka terbaru tiap konten yang DIPOSTING di bulan ini (konten lama tak dihitung). '
                .($kol ? 'Target views berlaku untuk seluruh tim, bukan per kreator.' : 'Target: override bulan itu bila diisi, jika tidak target global.'),
            'total_views' => $k['total'],
            'views_paid' => $k['paid'],
            'views_earned' => $k['earned'],
        ] + $target + [
            'jumlah_konten' => $konten->count(),
            'per_label' => $konten->countBy('label')->all(),
            'per_platform' => $konten->countBy('platform')->all(),
            'per_tipe' => $konten->countBy(fn (KolContent $c) => KolContent::TYPE_LABELS[$c->content_type] ?? ($c->content_type ?: 'tanpa tipe'))->all(),
            'kreator_teratas' => $kol ? null : $konten->groupBy('kol_id')->map(fn ($g) => [
                'username' => $g->first()->kol ? '@'.$g->first()->kol->handle() : '?',
                'konten' => $g->count(),
                'views' => (int) $g->sum($views),
            ])->sortByDesc('views')->take($limit)->values()->all(),
            'konten_teratas' => $konten->sortByDesc($views)->take($limit)->map(fn (KolContent $c) => array_filter([
                'judul' => $c->title ? mb_substr($c->title, 0, 80) : null,
                'kreator' => $c->kol ? '@'.$c->kol->handle() : null,
                'label' => $c->label,
                'tipe' => $c->content_type ? (KolContent::TYPE_LABELS[$c->content_type] ?? $c->content_type) : null,
                'platform' => $c->platform,
                'tanggal' => $c->posted_at?->toDateString(),
                'views' => $views($c),
                'like' => $c->latestSnapshot?->likes,
                'komen' => $c->latestSnapshot?->comments,
                'engagement_rate_persen' => $c->engagement_rate,
                'deal' => $c->deal?->kode,
                'link' => $c->url,
            ], fn ($v) => $v !== null))->values()->all(),
        ], fn ($v) => $v !== null);
    }
}
