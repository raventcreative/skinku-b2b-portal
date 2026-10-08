<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\KolGapokService;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: Tim Affiliate Gapok (menu KOL → Tim Gapok). Izin sama dgn halamannya: kol.affiliate.view + kol.view
 * (route bersarang). Angka dari KolGapokService::range/totals → sama dgn tabel (GMV, pesanan, komisi, video/LIVE,
 * gaji, pembayaran, ROI). Catatan gaji & pembayaran tidak dikirim.
 */
class TimGapokTool extends BaseTool
{
    public function __construct(private KolGapokService $svc) {}

    public function name(): string
    {
        return 'tim_gapok';
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
        return 'Tim Affiliate Gapok (menu KOL → Tim Gapok): kreator bergaji pokok bulanan. Per anggota: GMV SKINKU '
            .'(LIVE/video), pesanan, komisi, jumlah video & LIVE, gaji, sudah dibayar / kurang bayar, ROI (GMV ÷ gaji), '
            .'tanggal gabung; plus total & ROI tim. Default bulan ini; isi bulan (YYYY-MM) atau rentang dari–sampai '
            .'(mis. 7 hari terakhir). Gaji selalu gaji BULANAN dari bulan tanggal mulai. Untuk kreator di luar Tim Gapok pakai data_kol.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM. Default bulan ini.'],
                'dari' => ['type' => 'string', 'description' => 'YYYY-MM-DD (opsional, menggantikan bulan) — rentang harian/custom.'],
                'sampai' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Default sama dengan dari.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        [$from, $to, $label] = $this->rentang($args);
        $rows = $this->svc->range($from, $to, $from); // gaji dari bulan tanggal-mulai, sama dgn halaman
        $t = $this->svc->totals($rows);

        return [
            'periode' => $label,
            'bulan_gaji' => $from->format('Y-m'),
            'catatan' => 'GMV = semua pesanan affiliate SKINKU anggota (LIVE + video + lainnya), sama dgn halaman Tim Gapok. '
                .'Gaji = gaji pokok BULANAN '.$from->translatedFormat('F Y').'; gaji_otomatis = belum disimpan untuk bulan itu, '
                .'ikut gaji bulan sebelumnya. Video = jumlah video yang DIUNGGAH & LIVE = jumlah LIVE yang DIMULAI di periode itu (video lama yang masih laku tidak dihitung). ROI = GMV periode ÷ gaji (≥3× bagus, 1–3× cukup, <1× rugi).',
            'total' => [
                'anggota' => $t['members'],
                'gmv' => $t['gmv'],
                'pesanan' => $t['orders'],
                'komisi' => $t['commission'],
                'video' => $t['videos'],
                'live' => $t['lives'],
                'gaji' => $t['salary'],
                'dibayar' => $t['paid'],
                'kurang_bayar' => (int) $rows->sum(fn ($r) => max(0, $r['salary'] - $r['paid'])),
                'roi_tim' => $t['salary'] > 0 ? round($t['gmv'] / $t['salary'], 1) : null,
            ],
            'anggota' => $rows->map(fn ($r) => [
                'username' => '@'.$r['kol']->handle(),
                'nama' => $r['kol']->name,
                'gabung_sejak' => $r['joined_at']?->toDateString(),
                'gmv' => $r['gmv'],
                'gmv_live' => $r['gmv_live'],
                'gmv_video' => $r['gmv_video'],
                'pesanan' => $r['orders'],
                'komisi' => $r['commission'],
                'video' => $r['videos'],
                'live' => $r['lives'],
                'gaji' => $r['salary'],
                'gaji_otomatis' => $r['salary_auto'],
                'dibayar' => $r['paid'],
                'kurang_bayar' => max(0, $r['salary'] - $r['paid']),
                'status_bayar' => match (true) {
                    $r['salary'] <= 0 => 'gaji belum diisi',
                    $r['paid'] >= $r['salary'] => 'lunas',
                    $r['paid'] > 0 => 'kurang',
                    default => 'belum dibayar',
                },
                'roi' => $r['roi'],
            ])->values()->all(),
        ];
    }

    /** @return array{0:Carbon,1:Carbon,2:string} aturan sama dgn filter halaman: rentang dari–sampai, atau satu bulan. */
    private function rentang(array $args): array
    {
        if ($dari = $this->tanggal($args['dari'] ?? null)) {
            $from = Carbon::parse($dari)->startOfDay();
            $to = Carbon::parse($this->tanggal($args['sampai'] ?? null) ?? $dari)->endOfDay();
            if ($to->lt($from)) {
                $to = $from->copy()->endOfDay();
            }

            return [$from, $to, $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y')];
        }
        $bulan = $this->bulanLaporan($args['bulan'] ?? null) ?? now()->startOfMonth();

        return [$bulan->copy(), $bulan->copy()->endOfMonth(), $bulan->translatedFormat('F Y')];
    }
}
