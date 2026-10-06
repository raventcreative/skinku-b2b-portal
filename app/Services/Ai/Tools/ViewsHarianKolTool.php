<?php

namespace App\Services\Ai\Tools;

use App\Models\KolContentDailySnapshot;
use App\Models\User;
use App\Services\KolViewsHarianService;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: Views Harian video SKINKU (menu KOL → Views Harian SKINKU). Izin sama dgn halamannya:
 * kol.affiliate.view + kol.view (route bersarang). Angka dari KolViewsHarianService → sama dgn tabel.
 */
class ViewsHarianKolTool extends BaseTool
{
    use CariKol;

    public function __construct(private KolViewsHarianService $svc) {}

    public function name(): string
    {
        return 'views_harian_kol';
    }

    public function permission(): ?string
    {
        return 'kol.affiliate.view';
    }

    public function availableFor(User $user): bool
    {
        return $user->canDo('kol.view');
    }

    public function description(): string
    {
        return 'Views HARIAN video SKINKU (keranjang kuning) per kreator dari menu KOL → Views Harian: views per hari, '
            .'jumlah video yang diupload di rentang (Diposting), GMV video, hari terbaik, kreator teratas. HANYA VIDEO — '
            .'LIVE tidak termasuk, jadi kreator yang jualan lewat LIVE bisa tak muncul. Untuk peringkat penjualan/GMV '
            .'SKINKU (video + LIVE) pakai data_kol urut GMV; untuk "siapa yang perform/terbaik" sebutkan ukurannya atau '
            .'gabungkan keduanya. Isi username untuk rincian per video kreator itu (judul, tanggal upload, views, GMV, link). '
            .'Angka kemarin baru lengkap setelah ±12:30.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dari' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Default 7 hari terakhir.'],
                'sampai' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Default kemarin. Rentang maks 62 hari.'],
                'username' => ['type' => 'string', 'description' => 'Username TikTok kreator (opsional) → rincian per video.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah kreator/video teratas, 1-30. Default 10.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        [$from, $to] = $this->rentang($args);
        $limit = max(1, min(30, (int) ($args['limit'] ?? 10)));
        $out = [
            'periode' => $from->toDateString().' s/d '.$to->toDateString(),
            'catatan' => 'Hanya VIDEO SKINKU kreator yang terdaftar di Database KOL — LIVE tidak termasuk (GMV di sini = GMV '
                .'dari video; penjualan SKINKU total video + LIVE ada di data_kol). Views per tanggal = views pada hari itu '
                .'(angka kemarin lengkap setelah ±12:30; 2 hari terakhir dikoreksi otomatis). Diposting = jumlah video yang diupload di rentang ini; views tetap '
                .'mencakup video lama yang masih ditonton.',
            'tercatat_sejak' => KolContentDailySnapshot::min('captured_on'),
        ];

        $username = trim((string) ($args['username'] ?? ''));
        if ($username === '') {
            $rep = $this->svc->report($from, $to);

            return $out + [
                'total' => [
                    'kreator' => $rep['rows']->count(),
                    'views' => array_sum($rep['totals']),
                    'diposting' => (int) $rep['rows']->sum('diposting'),
                    'gmv' => (int) $rep['rows']->sum('total_gmv'),
                ],
                'views_per_hari' => $rep['totals'],
                'kreator_teratas' => $rep['rows']->take($limit)->map(fn ($r) => [
                    'username' => '@'.$r['kol']->handle(),
                    'nama' => $r['kol']->name,
                    'total_views' => $r['total_views'],
                    'diposting' => $r['diposting'],
                    'gmv' => $r['total_gmv'],
                    'hari_terbaik' => $this->hariTerbaik($r['views']),
                ])->values()->all(),
            ];
        }

        [$kol, $err] = $this->cariKol($username);
        if (! $kol) {
            return $out + $err;
        }
        // Angka per video = baris kreator ini di halaman (KolViewsHarianService::videos menjamin).
        $vid = $this->svc->videos($kol, $from, $to);

        return $out + [
            'kreator' => ['username' => '@'.$kol->handle(), 'nama' => $kol->name],
            'total_views' => array_sum($vid['totals']),
            'diposting' => $vid['rows']->where('baru', true)->count(),
            'gmv' => (int) $vid['rows']->sum('total_gmv'),
            'views_per_hari' => $vid['totals'],
            'jumlah_video' => $vid['rows']->count(),
            'video_teratas' => $vid['rows']->take($limit)->map(fn ($v) => [
                'judul' => $v['title'] ? mb_substr($v['title'], 0, 80) : null,
                'tanggal_upload' => $v['posted_at']?->toDateString(),
                'diupload_di_rentang' => $v['baru'],
                'total_views' => $v['total_views'],
                'gmv' => $v['total_gmv'],
                'hari_terbaik' => $this->hariTerbaik($v['views']),
                'link' => 'https://www.tiktok.com/@'.$kol->handle().'/video/'.$v['content_id'],
            ])->values()->all(),
        ];
    }

    /** @return array{0:Carbon,1:Carbon} sama dgn halaman: maks 62 hari; default 7 hari s/d kemarin. */
    private function rentang(array $args): array
    {
        $to = Carbon::parse($this->tanggal($args['sampai'] ?? null) ?? now()->subDay()->toDateString())->startOfDay();
        $from = Carbon::parse($this->tanggal($args['dari'] ?? null) ?? $to->copy()->subDays(6)->toDateString())->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 61) {
            $from = $to->copy()->subDays(61);
        }

        return [$from, $to];
    }

    /** @param array<string,int> $views */
    private function hariTerbaik(array $views): ?array
    {
        $max = $views ? max($views) : 0;
        if ($max === 0) {
            return null;
        }

        return ['tanggal' => (string) array_search($max, $views, true), 'views' => $max];
    }
}
