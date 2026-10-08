<?php

namespace App\Services\Ai\Tools;

use App\Models\KolDeal;
use App\Models\User;
use App\Services\KolBudgetService;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: Deal KOL + Laporan Hasil Deal (menu KOL → Deal KOL). Izin sama dgn halamannya: kol.deal.manage saja
 * (penyetuju/admin boleh tanpa Database KOL). Biaya, status bayar, sisa tagihan, budget bulanan, revenue, CPM & ROMI
 * hanya utk kol.deal.finance — sama dgn kolom yang disembunyikan halaman. Rekening, catatan internal/pembayaran
 * & catatan hasil tidak pernah dikirim.
 */
class DealKolTool extends BaseTool
{
    public function __construct(private KolBudgetService $budget) {}

    public function name(): string
    {
        return 'deal_kol';
    }

    public function permission(): ?string
    {
        return 'kol.deal.manage';
    }

    public function description(): string
    {
        return 'Deal KOL (menu KOL → Deal KOL & Laporan Hasil): daftar deal endorse (kode, kreator, campaign, jenis '
            .'VT/LIVE, tipe paid/barter/affiliate, ratecard, periode, tenggat posting, PIC, status draft/berjalan/selesai/'
            .'batal, verdict hasil), jumlah per status, dan ringkasan Laporan Hasil Deal (views, video upload/FYP, verdict '
            .'Bagus/Cukup/Jelek). Isi bulan (YYYY-MM) untuk deal yang mulai di bulan itu — tanpa bulan = semua deal — '
            .'dan/atau status. Biaya, status bayar, sisa tagihan, budget bulanan, revenue, CPM & ROMI hanya untuk izin '
            .'"Finansial Deal KOL".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM (opsional): deal yang periodenya mulai di bulan itu. Kosong = semua deal.'],
                'status' => ['type' => 'string', 'enum' => KolDeal::STATUSES, 'description' => 'Opsional: draft / berjalan / selesai / batal.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah deal di daftar, 1-30. Default 10.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $fin = $user->canDo('kol.deal.finance');
        $limit = max(1, min(30, (int) ($args['limit'] ?? 10)));
        // Sama dgn halaman: tanpa bulan = semua deal; bulan = periode mulai (atau tanggal dibuat) di bulan itu.
        $bulan = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($args['bulan'] ?? ''))
            ? Carbon::createFromFormat('Y-m-d', $args['bulan'].'-01')->startOfMonth() : null;
        $status = in_array($args['status'] ?? null, KolDeal::STATUSES, true) ? $args['status'] : null;

        $semua = KolDeal::query()->with(['kol', 'pic', 'campaign'])->when($bulan, fn ($q) => $q->bulan($bulan))
            ->orderByDesc('id')->get();
        $deals = $status ? $semua->where('status', $status) : $semua;

        $out = array_filter([
            'periode' => $bulan ? $this->labelBulan($bulan) : 'Semua periode',
            'filter_status' => $status,
            'jumlah_deal' => $deals->count(),
            // Sebaran status selalu dari semua deal di periode (tanpa filter status) — biar AI tak menyimpulkan "tidak ada".
            'per_status' => $semua->countBy('status')->all(),
            'deal' => $deals->take($limit)->map(fn (KolDeal $d) => $this->baris($d, $fin))->values()->all(),
            'laporan_hasil' => $this->laporan($status, $fin),
            'budget_bulan' => $fin ? $this->budgetBulan($bulan ?? now()->startOfMonth()) : null,
        ], fn ($v) => $v !== null);
        if (! $fin) {
            $out['catatan_akses'] = 'Tidak ditampilkan sesuai hak akses user: biaya deal, status bayar, sisa tagihan, budget '
                .'bulanan, revenue, CPM & ROMI (izin "Finansial Deal KOL").';
        }

        return $out;
    }

    private function baris(KolDeal $d, bool $fin): array
    {
        return array_filter([
            'kode' => $d->kode,
            'kreator' => $d->kol ? '@'.$d->kol->handle() : null,
            'campaign' => $d->campaign?->name,
            'jenis' => $d->jenis ? strtoupper($d->jenis).($d->jenis === 'vt' && $d->jumlah_slot ? ' ×'.$d->jumlah_slot : '') : null,
            'tipe' => $d->deal_type ? $d->dealTypeLabel() : null,
            'ratecard' => $d->ratecard_deal,
            'periode' => $d->periode_mulai ? $d->periode_mulai->toDateString().' s/d '.($d->periode_selesai?->toDateString() ?? '?') : null,
            'tenggat_posting' => $d->posting_deadline_effective?->toDateString(),
            'pic' => $d->pic?->fullname,
            'status' => $d->status,
            'hasil' => $d->hasil_terisi ? $this->verdict($d) : null,
            'total_biaya' => $fin ? $d->total_biaya : null,
            'status_bayar' => $fin ? $d->status_bayar : null,
            'sisa_tagihan' => $fin ? $d->remainingUnpaid() : null,
        ], fn ($v) => $v !== null);
    }

    /** Ringkasan Laporan Hasil Deal (KolDeal::laporanHasil — sumber yang sama dgn halamannya), semua periode. */
    private function laporan(?string $status, bool $fin): array
    {
        $l = KolDeal::laporanHasil(null, $status);
        $t = $l['totals'];

        return array_filter([
            'catatan' => 'Deal yang laporan hasilnya sudah diisi (semua periode) — sama dgn halaman Laporan Hasil Deal, urut verdict terbaik.',
            'jumlah_deal' => $l['deals']->count(),
            'per_verdict' => $l['deals']->countBy(fn (KolDeal $d) => $this->verdict($d))->all(),
            'video_upload' => $t['video_upload'],
            'video_fyp' => $t['video_fyp'],
            'total_views' => $t['views'],
            'total_biaya' => $fin ? $t['biaya'] : null,
            'total_revenue' => $fin ? $t['revenue'] : null,
            'cpm' => $fin ? $t['cpm'] : null,
            'romi' => $fin ? $t['romi'] : null,
            'teratas' => $l['deals']->take(10)->map(fn (KolDeal $d) => array_filter([
                'kode' => $d->kode,
                'kreator' => $d->kol ? '@'.$d->kol->handle() : null,
                'tujuan' => $d->hasil_tujuan,
                'video_upload' => $d->hasil_video_upload,
                'video_fyp' => $d->hasil_video_fyp,
                'views' => $d->hasil_views,
                'rata_views_per_video' => $d->hasil_avg_views,
                'verdict' => $this->verdict($d),
                'biaya' => $fin ? $d->total_biaya : null,
                'revenue' => $fin ? $d->hasil_revenue : null,
                'cpm' => $fin ? $d->hasil_cpm : null,
                'romi' => $fin ? $d->hasil_romi : null,
            ], fn ($v) => $v !== null))->values()->all(),
        ], fn ($v) => $v !== null);
    }

    /** Panel budget halaman Deal (KolBudgetService::summary) — finance saja. */
    private function budgetBulan(Carbon $bulan): array
    {
        $b = $this->budget->summary($bulan);

        return [
            'bulan' => $this->labelBulan($bulan),
            'catatan' => 'spent_lunas = biaya deal lunas + pengeluaran tambahan (boost/hadiah); sisa = budget − spent − committed; '
                .'CPM paid = biaya deal ÷ (views konten paid bulan itu ÷ 1.000).',
            'budget' => $b['budget'],
            'spent_lunas' => $b['spent'],
            'pengeluaran_tambahan' => $b['extras'],
            'committed_belum_lunas' => $b['committed'],
            'sisa' => $b['sisa'],
            'cpm_paid' => $b['cpm'],
            'cpm_patokan' => $b['anchor'],
            'cpm_di_atas_patokan' => $b['overAnchor'],
            'porsi_kreator_terbesar_persen' => $b['topSharePct'],
            'batas_porsi_persen' => $b['shareLimitPct'],
            'terlalu_terpusat' => $b['overConcentration'],
            'per_kreator' => $b['perCreator']->take(8)->map(fn ($p) => [
                'kreator' => $p['name'], 'deal' => $p['deals'], 'biaya' => $p['cost'], 'porsi_persen' => $p['sharePct'],
            ])->values()->all(),
        ];
    }

    /** Verdict tanpa emoji: Bagus / Cukup / Jelek / belum (sama dgn tampilan halaman). */
    private function verdict(KolDeal $d): string
    {
        return preg_replace('/^[^\pL\pN]+/u', '', $d->hasil_verdict);
    }
}
