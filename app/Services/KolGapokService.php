<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
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
        $start = $from->copy();
        $end = $to->copy();
        $period = $salaryMonth->copy()->startOfMonth()->toDateString();

        $gapok = Kol::gapok()->orderBy('tiktok_username')->get();
        if ($gapok->isEmpty()) {
            return collect();
        }
        $ids = $gapok->pluck('id')->all();

        $agg = KolAffiliateTransaction::matched()->notCancelled()
            ->whereIn('kol_id', $ids)
            ->whereBetween('order_date', [$start, $end])
            ->selectRaw('kol_id, SUM(gmv) as gmv, COUNT(*) as orders, SUM(commission) as commission')
            ->groupBy('kol_id')->get()->keyBy('kol_id');

        // GMV per content_type (LIVE/VIDEO) — dari mana penjualan datang.
        $byType = KolAffiliateTransaction::matched()->notCancelled()
            ->whereIn('kol_id', $ids)
            ->whereBetween('order_date', [$start, $end])
            ->selectRaw('kol_id, LOWER(content_type) as ct, SUM(gmv) as gmv')
            ->groupBy('kol_id', 'ct')->get()->groupBy('kol_id');

        $salaries = KolGapokSalary::where('period', $period)
            ->whereIn('kol_id', $ids)->get()->keyBy('kol_id');

        return $gapok->map(function ($kol) use ($agg, $byType, $salaries) {
            $a = $agg[$kol->id] ?? null;
            $gmv = (int) ($a->gmv ?? 0);
            $salary = (int) ($salaries[$kol->id]->monthly_salary ?? 0);
            $types = $byType[$kol->id] ?? collect();
            $gmvOf = fn (string $t) => (int) (optional($types->firstWhere('ct', $t))->gmv ?? 0);

            return [
                'kol' => $kol,
                'gmv' => $gmv,
                'orders' => (int) ($a->orders ?? 0),
                'commission' => (int) ($a->commission ?? 0),
                'gmv_live' => $gmvOf('live'),
                'gmv_video' => $gmvOf('video'),
                'salary' => $salary,
                'roi' => $salary > 0 ? round($gmv / $salary, 1) : null,
            ];
        })->sortByDesc('gmv')->values();
    }

    /** Ringkasan total tim untuk footer tabel. */
    public function totals(Collection $rows): array
    {
        return [
            'gmv' => (int) $rows->sum('gmv'),
            'orders' => (int) $rows->sum('orders'),
            'commission' => (int) $rows->sum('commission'),
            'salary' => (int) $rows->sum('salary'),
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
}
