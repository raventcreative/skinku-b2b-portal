<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\PartnerHierarchy;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Mesin komisi MLM: override 1 TINGKAT. Tiap PO HQ selesai, HANYA upline
 * LANGSUNG pembeli yang dapat komisi, rate sesuai rank upline itu (Distributor
 * order → Grand 6%; Reseller order → Distributor 4%). TIDAK naik-pohon ke
 * atasan yang lebih tinggi. Hanya PO HQ langsung (seller_id null) yang memicu
 * komisi — Model X/inter-partner dorman.
 *
 * Bonus join (10% dari nilai paket) TIDAK dihitung di sini — menyusul bareng
 * fitur Onboarding, dipicu saat member beli paket, bukan saat order ke HQ.
 * Key `komisi_persen_join` di RATE_DEFAULTS/Pengaturan disiapkan untuk fitur itu.
 */
class CommissionService
{
    /** Rate komisi (persen) per key AppSetting → default. SATU sumber utk engine + UI Pengaturan. */
    public const RATE_DEFAULTS = [
        'komisi_persen_grand_distributor' => 6.0,
        'komisi_persen_distributor' => 4.0,
        'komisi_persen_reseller_bronze' => 2.0,
        'komisi_persen_reseller_gold' => 2.0,
        'komisi_persen_reseller' => 2.0, // legacy generic reseller (masih assignable)
        'komisi_persen_join' => 10.0,
    ];

    public function recordForCompletedPo(PurchaseOrder $po): void
    {
        if ($po->seller_id !== null) {
            return;
        } // hanya PO HQ langsung

        if (Commission::where('source_po_id', $po->id)->exists()) {
            return;
        } // idempoten

        $buyer = $po->user;
        if (! $buyer || ! $buyer->upline_id) {
            return;
        } // tak ada upline → nol

        $base = (float) $po->subtotal - (float) $po->discount; // nilai barang bersih
        if ($base <= 0) {
            return;
        }

        // Override 1 TINGKAT: hanya upline LANGSUNG yang dapat komisi, rate sesuai
        // rank-nya — TIDAK naik-pohon ke atasan yang lebih tinggi. Contoh: Distributor
        // order ke HQ → Grand (upline langsung) dapat 6%; kalau Reseller yang order →
        // Distributor (upline langsung) dapat 4%, Grand tidak.
        // (Bonus join 10% dari nilai PAKET menyusul bareng fitur Onboarding — bukan
        // dari order ke HQ.)
        $upline = $buyer->upline;
        if ($upline && $upline->isPartner()) {
            $rate = $this->overrideRate($upline->role);
            if ($rate > 0) {
                $this->write($upline, $po, $buyer, 'override', 1, $rate, $base);
            }
        }
    }

    /**
     * Bonus join: saat member baru daftar via paket, upline LANGSUNG (inviter)
     * dapat `komisi_persen_join`% dari nilai paket → saldo komisi (append-only,
     * TIDAK auto-cair). 1 tingkat, tanpa PO (source_po_id null).
     */
    public function recordJoinBonus(User $inviter, User $member, float $paketPrice): void
    {
        $rate = AppSetting::float('komisi_persen_join', self::RATE_DEFAULTS['komisi_persen_join']);
        if (! $inviter->isPartner() || $rate <= 0 || $paketPrice <= 0) {
            return;
        }

        Commission::create([
            'user_id' => $inviter->id, 'source_po_id' => null, 'source_user_id' => $member->id,
            'type' => 'join', 'level' => 1, 'rate' => $rate, 'base_amount' => $paketPrice,
            'amount' => round($paketPrice * $rate / 100, 2), 'status' => 'saldo',
        ]);
    }

    public function balance(User $mitra): float
    {
        return (float) Commission::where('user_id', $mitra->id)->where('status', 'saldo')->sum('amount');
    }

    /**
     * Saldo yang masih bisa ditarik: saldo komisi dikurangi withdrawal yang
     * belum ditolak (diajukan/disetujui/cair semua mengunci saldo). Commission
     * TETAP append-only — status-nya tidak pernah diubah jadi 'ditarik'.
     */
    public function availableBalance(User $mitra): float
    {
        $ditarik = (float) Withdrawal::where('user_id', $mitra->id)
            ->where('status', '!=', 'ditolak')->sum('amount');

        return $this->balance($mitra) - $ditarik;
    }

    /**
     * Ringkasan HQ (baca-saja, commissions APPEND-ONLY): komisi periode +
     * saldo/tersedia/tertahan/cair all-time. Identitas: saldo = tersedia +
     * tertahan + cair.
     */
    public function reportSummary(?Carbon $month = null): array
    {
        $periodQ = Commission::where('status', 'saldo');
        $this->scopeMonth($periodQ, $month);

        $totalSaldo = (float) Commission::where('status', 'saldo')->sum('amount');
        $totalDitarik = (float) Withdrawal::where('status', '!=', 'ditolak')->sum('amount');

        return [
            'komisi_periode' => (float) $periodQ->sum('amount'),
            'total_saldo' => $totalSaldo,
            'total_tersedia' => $totalSaldo - $totalDitarik,
            'total_tertahan' => (float) Withdrawal::whereIn('status', ['diajukan', 'disetujui'])->sum('amount'),
            'total_cair' => (float) Withdrawal::where('status', 'cair')->sum('amount'),
            'jumlah_mitra' => (int) Commission::where('status', 'saldo')->distinct('user_id')->count('user_id'),
        ];
    }

    /** Baris per mitra (hanya yang pernah dapat komisi), urut nama. */
    public function reportPerMitra(?Carbon $month = null): array
    {
        $saldo = Commission::where('status', 'saldo')
            ->selectRaw('user_id, SUM(amount) as total')->groupBy('user_id')->pluck('total', 'user_id');

        $periodQ = Commission::where('status', 'saldo');
        $this->scopeMonth($periodQ, $month);
        $period = $periodQ->selectRaw('user_id, SUM(amount) as komisi, COUNT(*) as transaksi')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $ditarik = Withdrawal::where('status', '!=', 'ditolak')
            ->selectRaw('user_id, SUM(amount) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $tertahan = Withdrawal::whereIn('status', ['diajukan', 'disetujui'])
            ->selectRaw('user_id, SUM(amount) as total')->groupBy('user_id')->pluck('total', 'user_id');

        $rows = [];
        foreach (User::whereIn('id', $saldo->keys())->orderBy('name')->get() as $u) {
            $s = (float) ($saldo[$u->id] ?? 0);
            $rows[] = [
                'user' => $u,
                'tier' => PartnerHierarchy::label($u->role),
                'komisi' => (float) ($period[$u->id]->komisi ?? 0),
                'transaksi' => (int) ($period[$u->id]->transaksi ?? 0),
                'saldo' => $s,
                'tertahan' => (float) ($tertahan[$u->id] ?? 0),
                'tersedia' => $s - (float) ($ditarik[$u->id] ?? 0),
            ];
        }

        return $rows;
    }

    /** Baris Commission mitra tsb dalam periode, terbaru dulu (drill-down). */
    public function mitraCommissions(User $mitra, ?Carbon $month = null): Collection
    {
        $q = Commission::where('user_id', $mitra->id)->where('status', 'saldo')->with(['downline', 'sourcePo']);
        $this->scopeMonth($q, $month);

        return $q->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    private function overrideRate(string $role): float
    {
        $key = 'komisi_persen_'.$role;

        return AppSetting::float($key, self::RATE_DEFAULTS[$key] ?? 0.0);
    }

    private function scopeMonth($query, ?Carbon $month, string $col = 'created_at')
    {
        if (! $month) {
            return $query;
        }

        return $query->whereBetween($col, [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
    }

    private function write(User $penerima, PurchaseOrder $po, User $downline, string $type, int $level, float $rate, float $base): void
    {
        Commission::create([
            'user_id' => $penerima->id, 'source_po_id' => $po->id, 'source_user_id' => $downline->id,
            'type' => $type, 'level' => $level, 'rate' => $rate, 'base_amount' => $base,
            'amount' => round($base * $rate / 100, 2), 'status' => 'saldo',
        ]);
    }
}
