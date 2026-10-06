<?php

namespace App\Console\Commands;

use App\Models\KolContentDailySnapshot;
use App\Models\TiktokAffiliateConnection;
use App\Services\TikTokAffiliateService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Isi mundur report views harian (kol_content_daily_snapshots) utk tanggal sebelum pencatatan dimulai.
 * Hari D di report = potret (D+1) − potret D, jadi utk mengisi hari --dari s/d --sampai dibutuhkan potret
 * --dari s/d --sampai+1. Potret yang SUDAH ada (asli dari sync 04:00) tak pernah ditimpa. Default SIMULASI
 * (tak menulis apa pun) — tambah --simpan untuk menyimpan. Galat API per tanggal dilaporkan lalu lanjut,
 * sekaligus menunjukkan sejauh mana riwayat TikTok masih tersedia.
 */
class TikTokAffiliateViewsBackfillCommand extends Command
{
    protected $signature = 'tiktok:affiliate-views-backfill
        {--dari= : Hari pertama yang diisi (YYYY-MM-DD)}
        {--sampai= : Hari terakhir yang diisi (default: sehari sebelum potret asli pertama)}
        {--simpan : Benar-benar menyimpan (tanpa ini hanya simulasi)}';

    protected $description = 'Isi mundur views harian video SKINKU dari Analytics TikTok (default simulasi, tak menimpa potret asli)';

    public function handle(TikTokAffiliateService $svc): int
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            $this->error('App affiliate belum terhubung.');

            return self::FAILURE;
        }

        $kemarin = now()->subDay()->startOfDay();
        $pertama = KolContentDailySnapshot::min('captured_on');
        try {
            if (! $this->option('dari')) {
                throw new \InvalidArgumentException('isi --dari=YYYY-MM-DD');
            }
            $dari = Carbon::parse((string) $this->option('dari'))->startOfDay();
            $sampai = $this->option('sampai')
                ? Carbon::parse((string) $this->option('sampai'))->startOfDay()
                : ($pertama ? Carbon::parse($pertama)->subDay()->startOfDay() : $kemarin->copy());
        } catch (\Throwable $e) {
            $this->error('Tanggal tidak valid: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($sampai->gt($kemarin)) {
            $sampai = $kemarin->copy(); // hari ini belum selesai → tak bisa diisi
        }
        if ($dari->gt($sampai)) {
            $this->error("--dari ({$dari->toDateString()}) harus <= --sampai ({$sampai->toDateString()}).");

            return self::FAILURE;
        }
        if ($dari->diffInDays($sampai) > 61) {
            $this->error('Rentang maksimal 62 hari sekali jalan.');

            return self::FAILURE;
        }

        $simpan = (bool) $this->option('simpan');
        $this->info(($simpan ? 'MENYIMPAN' : 'SIMULASI (tak menulis apa pun)')." — isi hari {$dari->toDateString()} s/d {$sampai->toDateString()}.");

        $ada = KolContentDailySnapshot::whereBetween('captured_on', [$dari->toDateString(), $sampai->copy()->addDay()->toDateString()])
            ->distinct()->pluck('captured_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $gagal = 0;
        for ($c = $dari->copy(); $c->lte($sampai->copy()->addDay()); $c->addDay()) {
            $tgl = $c->toDateString();
            if ($ada->has($tgl)) {
                $this->line("  [LEWAT] {$tgl}: potret asli sudah ada - dilewati");

                continue;
            }
            try {
                $r = $svc->isiMundurPotretHarian($conn, $c->copy(), $simpan);
                // Penanda ASCII ([OK]/[GAGAL]/[LEWAT]): ✓/✗ tampil sbg kotak di terminal SSH hosting.
                $this->line(sprintf('  [OK] %s (data %s): %d kreator, %d video, views kumulatif %s%s',
                    $tgl, $r['rentang'], $r['kreator'], $r['videos'], number_format($r['views'], 0, ',', '.'),
                    $simpan ? " → {$r['ditulis']} potret disimpan" : ''));
            } catch (\Throwable $e) {
                $gagal++;
                $this->line("  [GAGAL] {$tgl}: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        if (! $simpan) {
            $this->warn('SIMULASI — belum ada yang disimpan. Bila angka di atas masuk akal, jalankan ulang dengan --simpan.');
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
