<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolCreatorContent;
use App\Models\KolGapokPayment;
use App\Models\KolGapokSalary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tim Affiliate Gapok: rakit performa affiliate per anggota gapok untuk satu
 * bulan (GMV/order/komisi dari kol_affiliate_transactions, + split GMV per
 * content_type LIVE/VIDEO) + gaji bulan itu + ROI (GMV ÷ gaji). Sumber angka =
 * pipeline affiliate yang sama dipakai halaman Affiliate & GMV; gapok cuma
 * menyaring ke anggota bergaji + menempel gaji/ROI. Diisi manual (import) atau
 * otomatis (sync API, source='tiktok_api') — service ini tak peduli sumbernya.
 */
class KolGapokService
{
    /**
     * Baris performa per anggota gapok di bulan tsb, urut GMV terbesar. Anggota
     * tanpa transaksi tetap muncul (GMV 0) supaya roster lengkap.
     *
     * @return Collection<int,array{kol:Kol,gmv:int,orders:int,commission:int,gmv_live:int,gmv_video:int,salary:int,roi:?float}>
     */
    public function monthly(Carbon $month): Collection
    {
        return $this->range($month->copy()->startOfMonth(), $month->copy()->endOfMonth(), $month);
    }

    /**
     * Performa anggota gapok di rentang [from,to] (harian/custom). Gaji bersifat
     * bulanan → diambil dari bulan $salaryMonth; ROI = GMV rentang ÷ gaji bulan.
     *
     * @return Collection<int,array{kol:Kol,gmv:int,orders:int,commission:int,gmv_live:int,gmv_video:int,salary:int,roi:?float}>
     */
    public function range(Carbon $from, Carbon $to, Carbon $salaryMonth): Collection
    {
        $period = $salaryMonth->copy()->startOfMonth()->toDateString();

        $gapok = Kol::gapok()->orderBy('tiktok_username')->get();
        if ($gapok->isEmpty()) {
            return collect();
        }
        $ids = $gapok->pluck('id')->all();
        $perf = $this->performa($ids, $from, $to);

        $salaries = KolGapokSalary::where('period', $period)
            ->whereIn('kol_id', $ids)->get()->keyBy('kol_id');
        // Bulan tanpa gaji tersimpan → ikut gaji bulan terakhir sebelumnya (otomatis,
        // tidak disimpan). Simpan manual di bulan itu = mengunci/mengganti angkanya.
        $carried = KolGapokSalary::whereIn('kol_id', array_diff($ids, $salaries->keys()->all()))
            ->where('period', '<', $period)->orderByDesc('period')->get()->unique('kol_id')->keyBy('kol_id');

        // Pembayaran (cicilan) gaji bulan $period — banyak baris per kreator.
        $payments = KolGapokPayment::where('period', $period)
            ->whereIn('kol_id', $ids)->orderBy('paid_at')->get()->groupBy('kol_id');

        return $gapok->map(function ($kol) use ($perf, $salaries, $carried, $payments) {
            $p = $perf[$kol->id];
            $auto = ! isset($salaries[$kol->id]) && isset($carried[$kol->id]);
            $salary = (int) (($salaries[$kol->id] ?? $carried[$kol->id] ?? null)->monthly_salary ?? 0);
            $pmts = $payments[$kol->id] ?? collect();

            return ['kol' => $kol] + $p + [
                'salary' => $salary,
                'salary_auto' => $auto,
                'salary_from' => $auto ? $carried[$kol->id]->period : null,
                'roi' => $salary > 0 ? round($p['gmv'] / $salary, 1) : null,
                'joined_at' => $kol->gapok_joined_at,
                'paid' => (int) $pmts->sum('amount'),
                'payments' => $pmts->values(),
            ];
        })->sortByDesc('gmv')->values();
    }

    /**
     * Angka affiliate per KOL di rentang [from,to]: GMV/pesanan/komisi dari transaksi yang cocok & tak batal,
     * split GMV per content_type (LIVE/VIDEO), + jumlah video yang DIUNGGAH & LIVE yang DIMULAI di rentang
     * (jumlahKonten). Satu sumber untuk Tim Gapok & alat AI `data_kol`/`tim_gapok`, jadi angkanya selalu sama.
     *
     * @param  array<int,int>|null  $ids  null = semua KOL yang punya transaksi/konten
     * @return array<int,array{gmv:int,orders:int,commission:int,gmv_live:int,gmv_video:int,videos:int,lives:int}>
     */
    public function performa(?array $ids, Carbon $from, Carbon $to): array
    {
        $tx = fn () => KolAffiliateTransaction::matched()->notCancelled()
            ->when($ids !== null, fn ($q) => $q->whereIn('kol_id', $ids))
            ->whereBetween('order_date', [$from->copy(), $to->copy()]);
        $agg = $tx()->selectRaw('kol_id, SUM(gmv) as gmv, COUNT(*) as orders, SUM(commission) as commission')
            ->groupBy('kol_id')->get()->keyBy('kol_id');
        // GMV per content_type (LIVE/VIDEO) — dari mana penjualan datang.
        $byType = $tx()->selectRaw('kol_id, LOWER(content_type) as ct, SUM(gmv) as gmv')
            ->groupBy('kol_id', 'ct')->get()->groupBy('kol_id');
        $content = $this->jumlahKonten($ids, $from, $to);

        $out = [];
        foreach ($ids ?? $agg->keys()->merge($content->keys())->unique()->all() as $id) {
            $a = $agg[$id] ?? null;
            $types = $byType[$id] ?? collect();
            $gmvOf = fn (string $t) => (int) (optional($types->firstWhere('ct', $t))->gmv ?? 0);
            $c = $content[$id] ?? collect();
            $out[$id] = [
                'gmv' => (int) ($a->gmv ?? 0),
                'orders' => (int) ($a->orders ?? 0),
                'commission' => (int) ($a->commission ?? 0),
                'gmv_live' => $gmvOf('live'),
                'gmv_video' => $gmvOf('video'),
                'videos' => (int) $c->get('video', 0),
                'lives' => (int) $c->get('live', 0),
            ];
        }

        return $out;
    }

    /**
     * Video yang DIUNGGAH & LIVE yang DIMULAI di rentang, per kreator (kol_creator_contents = potret bulanan TikTok
     * Analytics, sync harian 04:00). Satu konten bisa ada di potret beberapa bulan → dihitung sekali (DISTINCT).
     * Sama dgn kolom "Diposting" Views Harian: video lama yang masih laku tidak ikut dihitung.
     *
     * @return Collection<int,Collection<string,int>> kol_id => [video|live => jumlah]
     */
    private function jumlahKonten(?array $ids, Carbon $from, Carbon $to): Collection
    {
        return KolCreatorContent::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('kol_id', $ids))
            ->whereBetween('occurred_at', [$from->copy(), $to->copy()])
            ->selectRaw('kol_id, type, COUNT(DISTINCT content_id) AS n')
            ->groupBy('kol_id', 'type')->get()
            ->groupBy('kol_id')->map(fn ($g) => $g->pluck('n', 'type'));
    }

    /**
     * Daftar konten satu kreator di rentang (= angka Video & LIVE di tabel, aturan jumlahKonten). Views/GMV/order =
     * total semua potret bulanan konten itu (sejak diunggah s/d sync terakhir), urut GMV terbesar.
     *
     * @return array{videos:Collection<int,KolCreatorContent>,lives:Collection<int,KolCreatorContent>}
     */
    public function konten(Kol $kol, Carbon $from, Carbon $to): array
    {
        $items = KolCreatorContent::where('kol_id', $kol->id)
            ->whereBetween('occurred_at', [$from->copy(), $to->copy()])
            ->orderByDesc('period')->get()
            ->groupBy(fn (KolCreatorContent $c) => $c->type.':'.$c->content_id)
            ->map(fn (Collection $g) => $g->first()->forceFill([ // potret terbaru (judul) + angka dijumlah
                'views' => $g->sum('views'), 'gmv' => $g->sum('gmv'),
                'items_sold' => $g->sum('items_sold'), 'sku_orders' => $g->sum('sku_orders'),
            ]))
            ->sortByDesc('gmv')->values();

        return ['videos' => $items->where('type', 'video')->values(), 'lives' => $items->where('type', 'live')->values()];
    }

    /** Ringkasan total tim untuk footer tabel. */
    public function totals(Collection $rows): array
    {
        return [
            'gmv' => (int) $rows->sum('gmv'),
            'orders' => (int) $rows->sum('orders'),
            'commission' => (int) $rows->sum('commission'),
            'videos' => (int) $rows->sum('videos'),
            'lives' => (int) $rows->sum('lives'),
            'salary' => (int) $rows->sum('salary'),
            'paid' => (int) $rows->sum('paid'),
            'members' => $rows->count(),
        ];
    }

    /** Simpan/ubah gaji satu anggota untuk bulan tsb (isi ulang = perbarui). */
    public function setSalary(int $kolId, Carbon $month, int $salary, ?string $note, ?int $actorId): void
    {
        KolGapokSalary::updateOrCreate(
            ['kol_id' => $kolId, 'period' => $month->copy()->startOfMonth()->toDateString()],
            ['monthly_salary' => max(0, $salary), 'note' => $note, 'created_by' => $actorId],
        );
    }

    /** Catat satu pembayaran (cicilan) gaji bulan tsb. */
    public function addPayment(int $kolId, Carbon $month, int $amount, Carbon $paidAt, ?string $note, ?int $actorId): KolGapokPayment
    {
        return KolGapokPayment::create([
            'kol_id' => $kolId,
            'period' => $month->copy()->startOfMonth()->toDateString(),
            'amount' => max(0, $amount),
            'paid_at' => $paidAt->toDateString(),
            'note' => $note,
            'created_by' => $actorId,
        ]);
    }

    /** Total sudah dibayar utk (kol, bulan). */
    public function paidTotal(int $kolId, Carbon $month): int
    {
        return (int) KolGapokPayment::where('kol_id', $kolId)
            ->where('period', $month->copy()->startOfMonth()->toDateString())
            ->sum('amount');
    }
}
