<?php

namespace App\Services;

use App\Models\KolDeal;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ReportBot\ReportAi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generate Report bisnis (mingguan/bulanan/kuartal/tahunan/custom) dari data yang
 * sudah ada — MERANGKAI service laporan lain (channelSales, ProdukTerlaris, KOL,
 * akuntansi), bukan menghitung ulang. Tiap periode dibanding periode sebelumnya
 * sepanjang sama; periode berjalan dipotong s/d hari ini (adil, tak tampak "turun").
 *
 * Bagian ikut izin pengguna: KOL butuh kol.affiliate.view, keuangan butuh view_accounting.
 */
class BusinessReportService
{
    public const JENIS = ['mingguan' => 'Mingguan', 'bulanan' => 'Bulanan', 'kuartal' => 'Kuartal', 'tahunan' => 'Tahunan', 'custom' => 'Custom'];

    public function __construct(
        private ReportService $reports,
        private ProdukTerlarisService $produk,
        private KolAffiliateService $affiliate,
        private FinancialReportService $finance,
    ) {}

    /**
     * Rentang periode dari jenis + acuan. Acuan: tanggal (mingguan), Y-m (bulanan),
     * Y-Qn (kuartal), Y (tahunan); custom pakai $dari/$sampai.
     *
     * @return array{jenis:string, from:Carbon, to:Carbon, prevFrom:Carbon, prevTo:Carbon, label:string, prevLabel:string}
     */
    public function period(string $jenis, ?string $acuan, ?string $dari = null, ?string $sampai = null): array
    {
        $jenis = array_key_exists($jenis, self::JENIS) ? $jenis : 'bulanan';
        $today = Carbon::today();
        $parse = function (?string $v, Carbon $fallback) {
            try {
                return $v ? Carbon::parse($v)->startOfDay() : $fallback;
            } catch (Throwable) {
                return $fallback;
            }
        };

        switch ($jenis) {
            case 'mingguan':
                $from = $parse($acuan, $today)->startOfWeek();
                $to = $from->copy()->endOfWeek()->startOfDay();
                $prevFrom = $from->copy()->subWeek();
                break;
            case 'kuartal':
                [$y, $q] = preg_match('/^(\d{4})-Q([1-4])$/', (string) $acuan, $m) ? [(int) $m[1], (int) $m[2]] : [$today->year, $today->quarter];
                $from = Carbon::create($y, ($q - 1) * 3 + 1, 1)->startOfDay();
                $to = $from->copy()->addMonths(2)->endOfMonth()->startOfDay();
                $prevFrom = $from->copy()->subMonths(3);
                break;
            case 'tahunan':
                $y = preg_match('/^\d{4}$/', (string) $acuan) ? (int) $acuan : $today->year;
                $from = Carbon::create($y, 1, 1)->startOfDay();
                $to = $from->copy()->endOfYear()->startOfDay();
                $prevFrom = $from->copy()->subYear();
                break;
            case 'custom':
                $from = $parse($dari, $today->copy()->subDays(29));
                $to = $parse($sampai, $today);
                if ($from->gt($to)) {
                    [$from, $to] = [$to, $from];
                }
                $prevFrom = $from->copy()->subDays((int) $from->diffInDays($to) + 1);
                break;
            default: // bulanan
                $from = (preg_match('/^\d{4}-\d{2}$/', (string) $acuan) ? Carbon::parse($acuan.'-01') : $today->copy())->startOfMonth();
                $to = $from->copy()->endOfMonth()->startOfDay();
                $prevFrom = $from->copy()->subMonthNoOverflow();
        }

        // Periode berjalan → potong s/d hari ini; pembanding = panjang yang sama.
        $berjalan = $to->gt($today) && $from->lte($today);
        if ($berjalan) {
            $to = $today->copy();
        }
        $len = (int) $from->diffInDays($to);
        $prevTo = $prevFrom->copy()->addDays($len);
        if ($jenis !== 'custom' && ! $berjalan) {
            // Periode penuh → pembanding juga penuh (mis. Feb 28 hari vs Jan 31 hari).
            $prevTo = $from->copy()->subDay();
        }

        $label = match ($jenis) {
            'bulanan' => $from->translatedFormat('F Y'),
            'kuartal' => 'Q'.$from->quarter.' '.$from->year,
            'tahunan' => 'Tahun '.$from->year,
            default => $this->rangeLabel($from, $to),
        };
        if ($berjalan && $jenis !== 'custom') {
            $label .= ' (s/d '.$to->translatedFormat('d M').')';
        }

        return [
            'jenis' => $jenis, 'from' => $from, 'to' => $to, 'prevFrom' => $prevFrom, 'prevTo' => $prevTo,
            'label' => $label, 'prevLabel' => $this->rangeLabel($prevFrom, $prevTo),
        ];
    }

    /** Data laporan lengkap untuk satu periode. */
    public function build(array $p, User $viewer): array
    {
        $out = [
            'period' => $p,
            'penjualan' => $this->penjualan($p),
            'produk' => $this->produk->report($p['from'], 10, $p['from'], $p['to'], $p['prevFrom'], $p['prevTo']),
            'mitra' => $this->mitra($p),
            'stok' => $this->stok($p),
            'kol' => $viewer->canDo('kol.affiliate.view') ? $this->kol($p) : null,
            'keuangan' => $viewer->canDo('view_accounting') ? $this->keuangan($p) : null,
            'generated_at' => now(),
        ];
        $out['insight_input'] = $this->insightInput($out);

        return $out;
    }

    private function penjualan(array $p): array
    {
        $now = collect($this->reports->channelSales($p['from'], $p['from'], $p['to']));
        $prev = collect($this->reports->channelSales($p['prevFrom'], $p['prevFrom'], $p['prevTo']));
        $tot = function ($cs) {
            $omzet = $cs->sum('confirmed') + $cs->sum('pipeline');
            $orders = $cs->sum('confirmed_n') + $cs->sum('pipeline_n');
            $all = $cs->sum('orders_n');

            return [
                'omzet' => round($omzet, 2), 'terealisasi' => round($cs->sum('confirmed'), 2), 'orders' => (int) $orders,
                'aov' => $orders > 0 ? round($omzet / $orders) : 0,
                'cancel_rate' => $all > 0 ? round($cs->sum('cancelled_n') / $all * 100, 1) : 0.0,
                'batal' => round($cs->sum('cancelled'), 2),
            ];
        };
        $channels = $now->map(function ($c) use ($prev) {
            $pc = $prev->firstWhere('key', $c['key']);

            return [
                'key' => $c['key'], 'label' => $c['label'], 'color' => $c['color'],
                'omzet' => $c['confirmed'] + $c['pipeline'], 'prev' => ($pc['confirmed'] ?? 0) + ($pc['pipeline'] ?? 0),
                'orders' => $c['confirmed_n'] + $c['pipeline_n'], 'cancel_rate' => $c['cancel_rate'],
            ];
        })->values()->all();

        // Tren: harian s/d 93 hari, lebih panjang → per bulan biar grafik terbaca.
        $trend = $this->reports->salesTrendByChannel($p['from'], $p['from'], $p['to']);
        if (count($trend['labels']) > 93) {
            $byMonth = [];
            foreach ($trend['labels'] as $i => $d) {
                $byMonth[substr($d, 0, 7)][] = $i;
            }
            $trend['labels'] = array_keys($byMonth);
            foreach ($trend['channels'] as &$ch) {
                $ch['data'] = array_map(fn ($idx) => round(array_sum(array_intersect_key($ch['data'], array_flip($idx))), 2), array_values($byMonth));
            }
            unset($ch);
        }

        return ['now' => $tot($now), 'prev' => $tot($prev), 'channels' => $channels, 'trend' => $trend];
    }

    private function mitra(array $p): array
    {
        $range = fn ($q, $a, $b) => $q->whereRaw('COALESCE(order_date, DATE(created_at)) BETWEEN ? AND ?', [$a->toDateString(), $b->toDateString()]);
        $statuses = array_merge([PurchaseOrder::STATUS_COMPLETED], PurchaseOrder::PIPELINE_STATUSES);

        $top = $range(PurchaseOrder::query()->whereNull('seller_id')->whereIn('status', $statuses), $p['from'], $p['to'])
            ->selectRaw("COALESCE(NULLIF(company_name, ''), 'Tanpa nama') as nama, COUNT(*) as po, SUM(total_amount) as omzet")
            ->groupBy('nama')->orderByDesc('omzet')->limit(10)->get()
            ->map(fn ($r) => ['nama' => $r->nama, 'po' => (int) $r->po, 'omzet' => (float) $r->omzet])->all();

        $aktifIds = $range(PurchaseOrder::query()->whereNull('seller_id')->whereIn('status', $statuses), $p['from'], $p['to'])
            ->distinct()->pluck('user_id');
        $partners = User::whereIn('role', User::PARTNER_ROLES)->where('status', User::STATUS_ACTIVE);

        // Mitra aktif yang TIDAK order periode ini, urut order terakhir paling lama → kandidat follow-up.
        $diam = (clone $partners)->whereNotIn('id', $aktifIds)->get(['id', 'name', 'company_name', 'role'])
            ->map(function ($u) {
                $last = PurchaseOrder::where('user_id', $u->id)->whereNull('seller_id')->max(DB::raw('COALESCE(order_date, DATE(created_at))'));

                return ['nama' => $u->company_name ?: $u->name, 'role' => $u->role, 'terakhir' => $last ? Carbon::parse($last)->toDateString() : null];
            })->filter(fn ($r) => $r['terakhir'])->sortBy('terakhir')->values();

        return [
            'top' => $top,
            'po_status' => collect($this->reports->poStatusDistribution(null, $p['from'], $p['from'], $p['to']))->pluck('total', 'label')->filter()->all(),
            'mitra_baru' => (clone $partners)->whereBetween('created_at', [$p['from'], $p['to']->copy()->endOfDay()])->count(),
            'mitra_order' => $aktifIds->count(),
            'mitra_total' => (clone $partners)->count(),
            'diam' => $diam->take(10)->all(),
            'diam_n' => $diam->count(),
            'retur' => DB::table('po_returns')->where('status', 'applied')
                ->whereBetween('applied_at', [$p['from'], $p['to']->copy()->endOfDay()])->count(),
        ];
    }

    /** Stok HQ vs laju jual periode: hari cukup = stok ÷ (unit terjual/hari). */
    private function stok(array $p): array
    {
        $days = max(1, (int) $p['from']->diffInDays($p['to']) + 1);
        $sold = collect($this->produk->report($p['from'], 1000, $p['from'], $p['to'])['channels']['semua']['rows'])
            ->filter(fn ($r) => ! $r['unmapped'])->pluck('qty', 'label');

        $rows = Product::where('status', Product::STATUS_ACTIVE)->orderBy('name')->get(['name', 'hq_stock'])
            ->map(function ($pr) use ($sold, $days) {
                $perHari = ($sold[$pr->name] ?? 0) / $days;

                return [
                    'nama' => $pr->name, 'stok' => (int) $pr->hq_stock, 'terjual' => (int) ($sold[$pr->name] ?? 0),
                    'hari_cukup' => $perHari > 0 ? (int) floor($pr->hq_stock / $perHari) : null,
                ];
            });

        return [
            'menipis' => $rows->filter(fn ($r) => $r['hari_cukup'] !== null && $r['hari_cukup'] < 21)->sortBy('hari_cukup')->values()->all(),
            'menumpuk' => $rows->filter(fn ($r) => $r['stok'] > 0 && ($r['hari_cukup'] === null || $r['hari_cukup'] > 120))->sortByDesc('stok')->take(8)->values()->all(),
            'semua' => $rows->values()->all(),
        ];
    }

    private function kol(array $p): array
    {
        $end = $p['to']->copy()->endOfDay();
        $now = $this->affiliate->between($p['from'], $end);
        $prev = $this->affiliate->between($p['prevFrom'], $p['prevTo']->copy()->endOfDay());
        $deals = KolDeal::where('status', '!=', 'batal')
            ->whereBetween(DB::raw('COALESCE(periode_mulai, DATE(created_at))'), [$p['from']->toDateString(), $p['to']->toDateString()])
            ->get(['total_biaya']);

        return [
            'gmv' => (float) $now->sum('gmv'), 'gmv_prev' => (float) $prev->sum('gmv'),
            'orders' => (int) $now->sum('orders'), 'komisi' => (float) $now->sum('commission'),
            'kreator_aktif' => $now->count(),
            'top' => $now->take(10)->map(fn ($r) => [
                'nama' => '@'.($r->kol->tiktok_username ?? '?'), 'gmv' => (float) $r->gmv, 'orders' => (int) $r->orders,
            ])->values()->all(),
            'deal_n' => $deals->count(), 'deal_biaya' => (float) $deals->sum('total_biaya'),
        ];
    }

    /** Laba rugi = jumlah bulan akuntansi yang disentuh periode (akuntansi per bulan). */
    private function keuangan(array $p): array
    {
        $keys = ['penjualan_bersih', 'hpp', 'laba_kotor', 'beban_operasional', 'operating_income', 'net_income'];
        $sum = array_fill_keys($keys, 0.0);
        $beban = [];
        $months = [];
        for ($m = $p['from']->copy()->startOfMonth(); $m->lte($p['to']); $m->addMonth()) {
            $months[] = $m->format('Y-m');
            $is = $this->finance->incomeStatement($m->format('Y-m'));
            foreach ($keys as $k) {
                $sum[$k] += $is[$k];
            }
            foreach ($is['lines']['beban_operasional'] as $l) {
                $beban[$l['name']] = ($beban[$l['name']] ?? 0) + $l['amount'];
            }
        }
        arsort($beban);

        return [
            'bulan' => count($months) === 1 ? Carbon::parse($months[0].'-01')->translatedFormat('F Y')
                : Carbon::parse(reset($months).'-01')->translatedFormat('M Y').' – '.Carbon::parse(end($months).'-01')->translatedFormat('M Y'),
            'is' => array_map(fn ($v) => round($v, 2), $sum),
            'margin_kotor' => $sum['penjualan_bersih'] > 0 ? round($sum['laba_kotor'] / $sum['penjualan_bersih'] * 100, 1) : null,
            'margin_bersih' => $sum['penjualan_bersih'] > 0 ? round($sum['net_income'] / $sum['penjualan_bersih'] * 100, 1) : null,
            'beban_top' => array_slice($beban, 0, 6, true),
        ];
    }

    /** Ringkasan ringkas (angka saja) yang dikirim ke AI — hemat token, tanpa data pribadi. */
    private function insightInput(array $r): array
    {
        $pj = $r['penjualan'];

        return [
            'periode' => $r['period']['label'], 'pembanding' => $r['period']['prevLabel'],
            'penjualan' => ['sekarang' => $pj['now'], 'sebelumnya' => $pj['prev'],
                'per_channel' => array_map(fn ($c) => array_intersect_key($c, array_flip(['label', 'omzet', 'prev', 'orders', 'cancel_rate'])), $pj['channels'])],
            'produk_top' => array_map(fn ($x) => ['produk' => $x['label'], 'unit' => $x['qty'], 'unit_sebelumnya' => $x['prev']],
                array_slice($r['produk']['channels']['semua']['rows'], 0, 8)),
            'produk_per_channel_total_unit' => array_map(fn ($c) => $c['total'], $r['produk']['channels']),
            'mitra' => array_intersect_key($r['mitra'], array_flip(['mitra_baru', 'mitra_order', 'mitra_total', 'diam_n', 'retur', 'po_status'])),
            'stok_menipis' => array_map(fn ($s) => [$s['nama'], $s['hari_cukup'].' hari'], $r['stok']['menipis']),
            'stok_menumpuk' => array_map(fn ($s) => [$s['nama'], $s['stok'].' unit'], $r['stok']['menumpuk']),
            'kol' => $r['kol'] ? array_diff_key($r['kol'], ['top' => 1]) : null,
            'keuangan' => $r['keuangan'] ? array_diff_key($r['keuangan'], ['bulan' => 1]) : null,
        ];
    }

    /**
     * Analisis AI → {ringkasan, sorotan[], perhatian[], aksi[{judul, detail, dampak}]}.
     * Di-cache 12 jam per isi data (generate ulang = tanpa biaya bila data sama).
     *
     * @return array{ok:bool, data?:array, error?:string}
     */
    public function insight(array $input): array
    {
        $key = 'bizreport:ai:'.md5(json_encode($input));
        if ($hit = Cache::get($key)) {
            return ['ok' => true, 'data' => $hit];
        }

        $system = <<<'TXT'
Kamu analis bisnis senior untuk SKINKU (brand body care Jepang; jualan lewat reseller/distributor (PO), TikTok Shop, Shopee, dan KOL/affiliate TikTok).
Baca data laporan (JSON, angka Rupiah) dan beri analisis tajam, spesifik ke angka, Bahasa Indonesia, tanpa basa-basi.
Fokus: apa yang mendorong / menahan omzet, dan aksi konkret untuk menaikkan omzet periode berikutnya.
Jangan mengarang data yang tidak ada. Bila data kosong/nol, sebut itu sebagai keterbatasan data, bukan kesimpulan.
Balas HANYA JSON valid:
{"ringkasan":"2-3 kalimat","sorotan":["hal positif, sebut angka"],"perhatian":["risiko/masalah, sebut angka"],"aksi":[{"judul":"aksi singkat","detail":"langkah konkret","dampak":"tinggi|sedang|rendah"}]}
Maks 4 sorotan, 4 perhatian, 5 aksi (urut dampak terbesar).
TXT;

        try {
            $text = trim(app(ReportAi::class)->analyze($system, $input));
            if (preg_match('/^```[a-zA-Z]*\s*(.*?)\s*```$/s', $text, $m) === 1) {
                $text = $m[1];
            }
            $data = json_decode($text, true);
            if (! is_array($data) || ! isset($data['ringkasan'])) {
                return ['ok' => false, 'error' => 'Balasan AI tidak terbaca. Coba lagi.'];
            }
            Cache::put($key, $data, now()->addHours(12));

            return ['ok' => true, 'data' => $data];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'AI sedang tidak tersedia: '.$e->getMessage()];
        }
    }

    private function rangeLabel(Carbon $a, Carbon $b): string
    {
        return $a->isSameDay($b) ? $a->translatedFormat('d M Y')
            : ($a->year === $b->year ? $a->translatedFormat('d M') : $a->translatedFormat('d M Y')).' – '.$b->translatedFormat('d M Y');
    }
}
