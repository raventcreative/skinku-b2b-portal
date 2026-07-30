<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Scorecard faktual OKR yang urutan KPI dan definisinya ditentukan server.
 *
 * AI tidak memilih bukti yang tampil. Nilai boleh berubah setiap periode, tetapi
 * KPI, source path, definisi, dan aturan statusnya tetap sama.
 */
class OkrScorecardService
{
    /**
     * @param  array<string,array<string,mixed>>  $snapshots
     * @param  array<string,mixed>  $input
     * @return array{summary:string,evidence:array<int,array<string,mixed>>,gaps:array<int,string>}
     */
    public function build(array $snapshots, array $input): array
    {
        $period = (string) (
            data_get($snapshots, 'cmo.periode_referensi')
            ?? data_get($snapshots, 'cfo.periode_referensi')
            ?? data_get($snapshots, 'coo.periode_referensi')
            ?? ''
        );
        $periodStatus = (string) (
            data_get($snapshots, 'cmo.status_periode')
            ?? data_get($snapshots, 'cfo.status_periode')
            ?? data_get($snapshots, 'coo.status_periode')
            ?? 'status periode tidak tersedia'
        );
        $periodLabel = $this->periodLabel($period);
        $rows = [];
        $gaps = [];

        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CMO',
            key: 'penjualan_operasional',
            label: 'Penjualan operasional semua channel',
            definition: 'Total order selesai dari PO/reseller, TikTok, dan Shopee berdasarkan tanggal order; bukan khusus e-commerce.',
            sourcePath: 'cmo.penjualan.total_sales',
            period: $period,
            periodStatus: $periodStatus,
            trend: $this->trend($snapshots, 'cmo.tren_penjualan_3_bulan', 'semua_channel_confirmed'),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CMO',
            key: 'penjualan_ecommerce',
            label: 'Penjualan e-commerce terkonfirmasi',
            definition: 'Gabungan order TikTok dan Shopee berstatus selesai/delivered berdasarkan tanggal order.',
            sourcePath: $this->latestTrendPath($snapshots, 'cmo.tren_penjualan_3_bulan', 'ecommerce_confirmed'),
            period: $period,
            periodStatus: $periodStatus,
            trend: $this->trend($snapshots, 'cmo.tren_penjualan_3_bulan', 'ecommerce_confirmed'),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CMO',
            key: 'penjualan_distributor',
            label: 'Omzet distributor terkonfirmasi',
            definition: 'PO selesai milik akun berperan distributor; dipisahkan dari reseller dan marketplace.',
            sourcePath: 'cmo.distributor.omzet_selesai',
            period: $period,
            periodStatus: $periodStatus,
            trend: $this->trend($snapshots, 'cmo.tren_penjualan_3_bulan', 'distributor_po_confirmed'),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CMO',
            key: 'distributor_aktif',
            label: 'Distributor aktif bertransaksi',
            definition: 'Distributor dengan minimal satu PO selesai atau masih dalam pipeline pada bulan referensi.',
            sourcePath: 'cmo.distributor.aktif_bertransaksi',
            period: $period,
            periodStatus: $periodStatus,
            context: $this->context($snapshots, [
                'Terdaftar' => 'cmo.distributor.terdaftar',
                'Mencapai Rp100 juta' => 'cmo.distributor.mencapai_100_juta',
            ]),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CMO',
            key: 'affiliate_order',
            label: 'Affiliate menghasilkan order',
            definition: 'Jumlah affiliate/KOL yang mempunyai order pada input metrik bulanan affiliate.',
            sourcePath: 'cmo.kol.affiliate.menghasilkan_order',
            period: $period,
            periodStatus: $periodStatus,
            context: $this->context($snapshots, [
                'Metrik affiliate tercatat' => 'cmo.kol.affiliate.tercatat',
                'Aktif konten' => 'cmo.kol.affiliate.aktif_konten',
                'Jumlah order' => 'cmo.kol.affiliate.jumlah_order',
                'GMV' => 'cmo.kol.affiliate.gmv',
                'Retensi rata-rata (%)' => 'cmo.kol.affiliate.retention_rata_rata_persen',
            ]),
        );

        $posted = $this->number($snapshots, 'cfo.status_pencatatan.jurnal_posted');
        $draftJournals = $this->number($snapshots, 'cfo.status_pencatatan.jurnal_draft');
        $accountingStatus = $posted === null
            ? 'missing'
            : ($posted > 0 ? ($periodStatus === 'bulan berjalan' ? 'partial' : 'available') : 'needs_validation');
        $accountingNote = $posted === null
            ? 'Status jurnal tidak dapat dibaca.'
            : "{$posted} jurnal posted dan ".($draftJournals ?? 0).' jurnal draft pada periode ini.';

        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CFO',
            key: 'laba_rugi_bulan',
            label: 'Laba bersih bulan referensi',
            definition: 'Pendapatan dikurangi HPP, beban operasional, dan beban non-operasional dari jurnal posted dalam satu bulan.',
            sourcePath: 'cfo.laba_rugi.net_income',
            period: $period,
            periodStatus: $periodStatus,
            status: $accountingStatus,
            note: $accountingNote,
            trend: $this->trend($snapshots, 'cfo.tren_keuangan_3_bulan', 'laba_bersih'),
            context: $this->context($snapshots, [
                'Penjualan bersih' => 'cfo.laba_rugi.penjualan_bersih',
                'HPP' => 'cfo.laba_rugi.hpp',
                'Laba kotor' => 'cfo.laba_rugi.laba_kotor',
                'Beban operasional' => 'cfo.laba_rugi.beban_operasional',
            ]),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CFO',
            key: 'laba_berjalan_kumulatif',
            label: 'Laba berjalan kumulatif',
            definition: 'Akumulasi pendapatan dikurangi beban sampai akhir periode referensi berdasarkan seluruh jurnal posted.',
            sourcePath: 'cfo.neraca.laba_berjalan',
            period: $period,
            periodStatus: $periodStatus,
            status: $accountingStatus,
            note: $accountingNote,
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CFO',
            key: 'arus_kas_bersih',
            label: 'Arus kas bersih',
            definition: 'Perubahan kas bersih dari aktivitas operasi, investasi, dan pendanaan pada periode referensi.',
            sourcePath: 'cfo.arus_kas.bersih',
            period: $period,
            periodStatus: $periodStatus,
            status: $accountingStatus,
            note: $accountingNote,
            trend: $this->trend($snapshots, 'cfo.tren_keuangan_3_bulan', 'arus_kas_bersih'),
            context: $this->context($snapshots, [
                'Kas akhir' => 'cfo.arus_kas.kas_akhir',
                'Operasi' => 'cfo.arus_kas.operasi',
                'Investasi' => 'cfo.arus_kas.investasi',
                'Pendanaan' => 'cfo.arus_kas.pendanaan',
            ]),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'CFO',
            key: 'piutang_tempo',
            label: 'Sisa piutang PO tempo',
            definition: 'Nilai tagihan PO tempo yang belum tertutup pembayaran.',
            sourcePath: 'cfo.piutang_tempo.sisa_tagihan',
            period: $period,
            periodStatus: $periodStatus,
            context: $this->context($snapshots, [
                'Jumlah PO tempo' => 'cfo.piutang_tempo.jumlah_po',
                'Lewat jatuh tempo' => 'cfo.piutang_tempo.jatuh_tempo',
            ]),
        );

        $this->addMetric(
            $rows,
            $snapshots,
            section: 'COO',
            key: 'stok_hq',
            label: 'Stok produk aktif di HQ',
            definition: 'Jumlah unit stok HQ pada seluruh produk master berstatus aktif.',
            sourcePath: 'coo.stok.total_hq',
            period: $period,
            periodStatus: $periodStatus,
            context: $this->context($snapshots, [
                'Stok di partner' => 'coo.stok.total_partner',
            ]),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'COO',
            key: 'produksi',
            label: 'Output produksi',
            definition: 'Jumlah unit hasil produksi yang tanggal produksinya berada pada periode referensi.',
            sourcePath: 'coo.produksi.output_unit',
            period: $period,
            periodStatus: $periodStatus,
            trend: $this->trend($snapshots, 'coo.tren_produksi_3_bulan', 'output_unit'),
            context: $this->context($snapshots, [
                'Batch' => 'coo.produksi.batch',
                'Total biaya' => 'coo.produksi.total_biaya',
            ]),
        );
        $this->addMetric(
            $rows,
            $snapshots,
            section: 'COO',
            key: 'pipeline_produk_baru',
            label: 'Item dalam pipeline produk baru',
            definition: 'Calon produk yang sudah dicatat pada pipeline pengembangan, bukan jumlah produk yang diasumsikan akan launch.',
            sourcePath: 'coo.pipeline_produk_baru.total_item',
            period: $period,
            periodStatus: $periodStatus,
            context: $this->context($snapshots, [
                'Di luar perfume/acne' => 'coo.pipeline_produk_baru.item_di_luar_perfume_acne',
                'Sudah launch/evaluasi' => 'coo.pipeline_produk_baru.sudah_launch',
                'Target launch terisi' => 'coo.pipeline_produk_baru.target_launch_terisi',
            ]),
        );

        $this->appendReconciliation($rows, $gaps, $snapshots, $period, $periodStatus);
        $this->appendCoverageGaps($rows, $gaps, $snapshots, $input, $periodLabel);

        if ($rows === []) {
            $gaps[] = 'Snapshot sistem belum menyediakan metrik numerik; baseline lain tidak boleh diasumsikan dan wajib divalidasi.';
        }

        return [
            'summary' => $this->summary($rows, $periodLabel, $periodStatus, $gaps),
            'evidence' => array_values($rows),
            'gaps' => array_values(array_unique($gaps)),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,array<string,mixed>>  $snapshots
     * @param  array<int,array{period:string,value:int|float}>  $trend
     * @param  array<string,int|float>  $context
     */
    private function addMetric(
        array &$rows,
        array $snapshots,
        string $section,
        string $key,
        string $label,
        string $definition,
        ?string $sourcePath,
        string $period,
        string $periodStatus,
        string $status = 'available',
        string $note = '',
        array $trend = [],
        array $context = [],
    ): void {
        if (! $sourcePath || ! Arr::has($snapshots, $sourcePath)) {
            return;
        }
        $value = data_get($snapshots, $sourcePath);
        if (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            return;
        }

        $rows[] = [
            'section' => $section,
            'metric_key' => $key,
            'label' => $label,
            'definition' => $definition,
            'value' => $value,
            'period' => $period,
            'period_status' => $periodStatus,
            'data_status' => $status,
            'note' => $note,
            'trend' => $trend,
            'context' => $context,
            'source_path' => $sourcePath,
            'specialist' => $section,
            'interpretation' => $definition,
            'blocking' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $gaps
     * @param  array<string,array<string,mixed>>  $snapshots
     */
    private function appendReconciliation(
        array &$rows,
        array &$gaps,
        array $snapshots,
        string $period,
        string $periodStatus,
    ): void {
        $operational = $this->number($snapshots, 'cmo.penjualan.total_sales');
        $accounting = $this->number($snapshots, 'cfo.laba_rugi.penjualan_bersih');
        if ($operational === null || $accounting === null) {
            return;
        }

        $difference = round($operational - $accounting, 2);
        $tolerance = max(1000, abs($operational) * 0.05);
        $conflict = abs($difference) > $tolerance;
        $status = $conflict ? 'conflict' : ($periodStatus === 'bulan berjalan' ? 'partial' : 'reconciled');
        $note = $conflict
            ? 'Penjualan operasional dan penjualan bersih dari jurnal posted belum cocok. Jangan menyimpulkan pertumbuhan/penurunan sebelum rekonsiliasi.'
            : 'Selisih penjualan operasional dan jurnal posted berada dalam toleransi 5%.';

        $rows[] = [
            'section' => 'VALIDASI',
            'metric_key' => 'rekonsiliasi_penjualan',
            'label' => 'Selisih operasional vs akuntansi',
            'definition' => 'Penjualan semua channel dibandingkan dengan penjualan bersih jurnal posted pada bulan yang sama.',
            'value' => $difference,
            'period' => $period,
            'period_status' => $periodStatus,
            'data_status' => $status,
            'note' => $note,
            'trend' => [],
            'context' => [
                'Operasional semua channel' => $operational,
                'Akuntansi jurnal posted' => $accounting,
            ],
            'source_path' => 'server.rekonsiliasi_penjualan',
            'source_paths' => [
                'cmo.penjualan.total_sales',
                'cfo.laba_rugi.penjualan_bersih',
            ],
            'specialist' => 'VALIDASI',
            'interpretation' => $note,
            'blocking' => $conflict,
        ];

        if ($conflict) {
            $gaps[] = 'Rekonsiliasi wajib: penjualan operasional '
                .$this->rupiah($operational).' berbeda dari penjualan bersih jurnal posted '
                .$this->rupiah($accounting).' pada '.$this->periodLabel($period).'.';
        }
    }

    /**
     * @param  array<int,string>  $gaps
     * @param  array<string,array<string,mixed>>  $snapshots
     * @param  array<string,mixed>  $input
     */
    private function appendCoverageGaps(
        array &$rows,
        array &$gaps,
        array $snapshots,
        array $input,
        string $periodLabel,
    ): void {
        $direction = Str::of((string) ($input['direction'] ?? ''))->lower()->ascii()->toString();
        $affiliateRequested = str_contains($direction, 'affiliate') || str_contains($direction, 'affiliator');
        $productRequested = preg_match('/\b15\b/u', $direction)
            && collect(['produk', 'item', 'master'])->contains(fn (string $word) => str_contains($direction, $word));

        if ($affiliateRequested && $this->number($snapshots, 'cmo.kol.affiliate.tercatat') === 0.0) {
            $message = "Metrik affiliate {$periodLabel} belum diinput. Nilai order, GMV, conversion, dan retention tidak boleh dianggap nol aktual.";
            $gaps[] = $message;
            $this->markBlocking($rows, 'affiliate_order', 'missing', $message);
        }
        if ($productRequested && $this->number($snapshots, 'coo.pipeline_produk_baru.total_item') === 0.0) {
            $message = 'Belum ada item yang dicatat pada pipeline produk baru. Sistem tidak boleh menyimpulkan bahwa produk tidak tersedia atau siap launch.';
            $gaps[] = $message;
            $this->markBlocking($rows, 'pipeline_produk_baru', 'missing', $message);
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function markBlocking(
        array &$rows,
        string $metricKey,
        string $status,
        string $note,
    ): void {
        foreach ($rows as &$row) {
            if (($row['metric_key'] ?? null) !== $metricKey) {
                continue;
            }
            $row['data_status'] = $status;
            $row['note'] = $note;
            $row['blocking'] = true;

            break;
        }
        unset($row);
    }

    /** @param array<string,array<string,mixed>> $snapshots */
    private function latestTrendPath(array $snapshots, string $basePath, string $metric): ?string
    {
        $rows = data_get($snapshots, $basePath);
        if (! is_array($rows) || $rows === []) {
            return null;
        }
        $index = array_key_last($rows);

        return Arr::has($snapshots, "{$basePath}.{$index}.{$metric}")
            ? "{$basePath}.{$index}.{$metric}"
            : null;
    }

    /**
     * @param  array<string,array<string,mixed>>  $snapshots
     * @return array<int,array{period:string,value:int|float}>
     */
    private function trend(array $snapshots, string $basePath, string $metric): array
    {
        return collect((array) data_get($snapshots, $basePath, []))
            ->filter(fn ($row) => is_array($row)
                && is_string($row['bulan'] ?? null)
                && (is_int($row[$metric] ?? null) || is_float($row[$metric] ?? null)))
            ->map(fn (array $row) => [
                'period' => $row['bulan'],
                'value' => $row[$metric],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,array<string,mixed>>  $snapshots
     * @param  array<string,string>  $paths
     * @return array<string,int|float>
     */
    private function context(array $snapshots, array $paths): array
    {
        $context = [];
        foreach ($paths as $label => $path) {
            $value = data_get($snapshots, $path);
            if (is_int($value) || is_float($value)) {
                $context[$label] = $value;
            }
        }

        return $context;
    }

    /** @param array<string,array<string,mixed>> $snapshots */
    private function number(array $snapshots, string $path): ?float
    {
        $value = data_get($snapshots, $path);

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $gaps
     */
    private function summary(array $rows, string $periodLabel, string $periodStatus, array $gaps): string
    {
        $metrics = collect($rows)->keyBy('metric_key');
        $parts = [
            "Scorecard server memakai KPI tetap dengan periode referensi {$periodLabel} ({$periodStatus}).",
        ];

        if ($row = $metrics->get('penjualan_operasional')) {
            $parts[] = 'Penjualan operasional semua channel tercatat '.$this->rupiah((float) $row['value']).'.';
        }
        if ($row = $metrics->get('penjualan_ecommerce')) {
            $parts[] = 'Bagian e-commerce terkonfirmasi tercatat '.$this->rupiah((float) $row['value']).'.';
        }
        if ($row = $metrics->get('laba_rugi_bulan')) {
            $parts[] = 'Laba bersih bulan referensi dari jurnal posted tercatat '.$this->rupiah((float) $row['value']).'.';
        }
        if ($row = $metrics->get('laba_berjalan_kumulatif')) {
            $parts[] = 'Laba berjalan kumulatif tercatat '.$this->rupiah((float) $row['value']).'.';
        }
        if ($row = $metrics->get('rekonsiliasi_penjualan')) {
            $parts[] = match ($row['data_status']) {
                'conflict' => 'Sumber operasional dan akuntansi belum terrekonsiliasi; penilaian naik/turun ditahan.',
                'partial' => 'Belum ada selisih yang terdeteksi, tetapi periode masih berjalan; penilaian naik/turun tidak dibuat.',
                'needs_validation' => 'Status rekonsiliasi belum dapat diverifikasi; penilaian naik/turun ditahan.',
                default => 'Sumber operasional dan akuntansi berada dalam toleransi rekonsiliasi.',
            };
        }
        if ($gaps !== []) {
            $parts[] = count($gaps).' kebutuhan data/validasi masih terbuka dan tidak diubah menjadi asumsi oleh AI.';
        }

        return implode(' ', $parts);
    }

    private function periodLabel(string $period): string
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $period !== '' ? $period : 'periode tidak diketahui';
        }

        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        $date = Carbon::createFromFormat('Y-m', $period);

        return $months[$date->month].' '.$date->year;
    }

    private function rupiah(float $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }
}
