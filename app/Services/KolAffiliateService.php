<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolContent;
use App\Models\KolUsernameAlias;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Domain transaksi affiliate (Fase 3a): import + pencocokan username→KOL +
 * agregasi GMV bulanan/mingguan. Dedup by (platform, order_id) → re-import
 * periode sama = replace, bukan dobel.
 */
class KolAffiliateService
{
    /**
     * @param  array<int,array{order_id?:mixed,username?:mixed,gmv?:mixed,commission?:mixed,qty?:mixed,product?:mixed,status?:mixed,order_date?:mixed}>  $rows
     * @return array{imported:int,matched:int,unmatched:int}
     */
    public function import(array $rows, string $platform, ?int $actorId, string $source = 'import'): array
    {
        $imported = $matched = $unmatched = 0;

        foreach ($rows as $r) {
            $orderId = trim((string) ($r['order_id'] ?? ''));
            if ($orderId === '') {
                continue;
            }
            $username = ltrim(trim((string) ($r['username'] ?? '')), '@');
            // Cocokkan ke tiktok_username langsung, lalu ke alias tersimpan.
            $kolId = null;
            if ($username !== '') {
                $lower = mb_strtolower($username);
                $kolId = Kol::whereRaw('LOWER(tiktok_username) = ?', [$lower])->value('id')
                    ?? KolUsernameAlias::where('username', $lower)->value('kol_id');
            }

            KolAffiliateTransaction::updateOrCreate(
                ['platform' => $platform, 'order_id' => $orderId],
                [
                    'kol_id' => $kolId,
                    'raw_username' => $username ?: null,
                    'gmv' => (int) ($r['gmv'] ?? 0),
                    'commission' => isset($r['commission']) && $r['commission'] !== null ? (int) $r['commission'] : null,
                    'commission_settled' => isset($r['commission_settled']) && $r['commission_settled'] !== null ? (int) $r['commission_settled'] : null,
                    'qty' => isset($r['qty']) && $r['qty'] !== null ? (int) $r['qty'] : null,
                    'product' => $r['product'] ?? null,
                    'status' => $r['status'] ?? null,
                    'order_date' => $r['order_date'] ?? now()->toDateString(),
                    'source' => $source,
                    'created_by' => $actorId,
                ],
            );

            $imported++;
            $kolId ? $matched++ : $unmatched++;
        }

        return compact('imported', 'matched', 'unmatched');
    }

    /** Ranking per creator bulan berjalan: gmv, orders, commission (kecuali batal). */
    public function monthly(Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return KolAffiliateTransaction::matched()->notCancelled()
            ->whereBetween('order_date', [$start, $end])
            ->selectRaw('kol_id, SUM(gmv) as gmv, COUNT(*) as orders, SUM(commission) as commission, SUM(commission_settled) as commission_settled')
            ->groupBy('kol_id')->orderByDesc('gmv')->with('kol')->get();
    }

    /** Views konten per creator bulan tsb (untuk RPM). kol_id => views. */
    public function monthlyViews(Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return KolContent::whereBetween('posted_at', [$start, $end])->with('latestSnapshot')->get()
            ->groupBy('kol_id')->map(fn ($cs) => $cs->sum(fn ($c) => (int) ($c->latestSnapshot->views ?? 0)));
    }

    /** GMV per minggu (ISO) untuk satu KOL, lama → baru. Dipakai APS. */
    public function weeklyGmv(int $kolId, Carbon $upTo, int $weeks = 4): array
    {
        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $ws = $upTo->copy()->subWeeks($i)->startOfWeek();
            $we = $ws->copy()->endOfWeek();
            $out[] = (int) KolAffiliateTransaction::where('kol_id', $kolId)->notCancelled()
                ->whereBetween('order_date', [$ws, $we])->sum('gmv');
        }

        return $out;
    }

    /**
     * GMV agregat per minggu di sepanjang bulan (semua creator, kecuali batal) —
     * untuk strip "Per minggu" di halaman Affiliate. Minggu ISO yang beririsan
     * dengan bulan, lama → baru.
     *
     * @return array<int,array{label:string,gmv:int}>
     */
    public function monthlyWeeklyGmv(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $out = [];
        $cur = $start->copy()->startOfWeek();
        while ($cur <= $end) {
            $we = $cur->copy()->endOfWeek();
            $out[] = [
                'label' => $cur->format('d M'),
                'gmv' => (int) KolAffiliateTransaction::notCancelled()
                    ->whereBetween('order_date', [$cur, $we])->sum('gmv'),
            ];
            $cur = $cur->copy()->addWeek();
        }

        return $out;
    }

    /** Username belum cocok, urut nilai GMV terbesar (calon affiliate belum terdata). */
    public function unmatched(): Collection
    {
        return KolAffiliateTransaction::unmatched()->whereNotNull('raw_username')
            ->selectRaw('raw_username, SUM(gmv) as gmv, COUNT(*) as orders')
            ->groupBy('raw_username')->orderByDesc('gmv')->get();
    }

    /**
     * Rakit input APS untuk satu KOL di bulan tsb (GMV mingguan dari affiliate +
     * jumlah konten mingguan & views bulan dari Fase 1). weeksOfData = minggu yang
     * punya GMV atau konten (paket <4 → "new").
     *
     * @return array{weeklyGmv:array<int>,weeklyContent:array<int>,weeksOfData:int,monthGmv:int,monthViews:?int}
     */
    public function apsInput(int $kolId, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $upTo = $month->isSameMonth(now()) ? now() : $end->copy();

        $weeklyGmv = $this->weeklyGmv($kolId, $upTo, 4);
        $weeklyContent = [];
        for ($i = 3; $i >= 0; $i--) {
            $ws = $upTo->copy()->subWeeks($i)->startOfWeek();
            $we = $ws->copy()->endOfWeek();
            $weeklyContent[] = KolContent::where('kol_id', $kolId)->whereBetween('posted_at', [$ws, $we])->count();
        }

        $weeksOfData = 0;
        for ($i = 0; $i < 4; $i++) {
            if (($weeklyGmv[$i] ?? 0) > 0 || ($weeklyContent[$i] ?? 0) > 0) {
                $weeksOfData++;
            }
        }

        $monthGmv = (int) KolAffiliateTransaction::where('kol_id', $kolId)->notCancelled()
            ->whereBetween('order_date', [$start, $end])->sum('gmv');
        $monthViews = (int) KolContent::where('kol_id', $kolId)
            ->whereBetween('posted_at', [$start, $end])->with('latestSnapshot')->get()
            ->sum(fn ($c) => (int) ($c->latestSnapshot->views ?? 0));

        return [
            'weeklyGmv' => $weeklyGmv,
            'weeklyContent' => $weeklyContent,
            'weeksOfData' => $weeksOfData,
            'monthGmv' => $monthGmv,
            'monthViews' => $monthViews ?: null,
        ];
    }

    /** Tautkan semua transaksi sebuah username ke KOL + simpan alias (auto-cocok
     *  import berikutnya). Return jumlah baris tertaut. */
    public function matchUsername(string $rawUsername, int $kolId, ?int $actorId = null): int
    {
        $norm = KolUsernameAlias::norm($rawUsername);
        $n = KolAffiliateTransaction::whereNull('kol_id')
            ->whereRaw('LOWER(raw_username) = ?', [$norm])
            ->update(['kol_id' => $kolId]);

        // Simpan alias hanya bila username asing (bukan tiktok_username KOL itu sendiri).
        $isOwnHandle = Kol::whereKey($kolId)->whereRaw('LOWER(tiktok_username) = ?', [$norm])->exists();
        if ($norm !== '' && ! $isOwnHandle) {
            KolUsernameAlias::updateOrCreate(['username' => $norm], ['kol_id' => $kolId, 'created_by' => $actorId]);
        }

        return $n;
    }
}
