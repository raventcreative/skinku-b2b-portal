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
 * Bonus join (10% dari nilai paket) DIHITUNG di sini via recordJoinBonus():
 * dipicu saat member beli paket (bukan saat order ke HQ), masuk ke saldo
 * upline LANGSUNG pembeli paket — appended, bukan auto-paid.
 */
class CommissionService
{
    /** Rate komisi (persen) per key AppSetting → default. SATU sumber utk engine + UI Pengaturan. */
    // Model A (2026-08-21): OVERRIDE DIPADAMKAN — rate 0 (dorman, bukan dihapus).
    // Untung mitra sekarang dari MARGIN (beli-dari-upline, selisih harga tier), bukan
    // override. Kode override (recordForCompletedPo + overrideRate) TETAP ADA supaya
    // bisa dihidupkan lagi tanpa build ulang (set rate > 0 via AppSetting/Pengaturan).
    // Bonus JOIN (10%) TETAP jalan.
    public const RATE_DEFAULTS = [
        'komisi_persen_grand_distributor' => 0.0,
        'komisi_persen_distributor' => 0.0,
        'komisi_persen_reseller_bronze' => 0.0,
        'komisi_persen_reseller_gold' => 0.0,
        'komisi_persen_reseller' => 0.0, // legacy generic reseller (masih assignable)
        'komisi_persen_join' => 10.0,
        'komisi_persen_ro_cashback' => 5.0, // Sponsor: % omzet restock GD ke HQ → perekrut (income pasif). Configurable.
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
        if (! $buyer) {
            return;
        }

        $base = (float) $po->subtotal - (float) $po->discount; // nilai barang bersih
        if ($base <= 0) {
            return;
        }

        // RO cashback (Sponsor): GD restock ke HQ → PEREKRUT GD dapat 5% (income pasif).
        // Dicek sebelum guard upline karena GD tak punya upline (upline null).
        $this->recordRoCashback($po, $buyer, $base);

        // Override 1 TINGKAT — DORMAN di Model A (rate default 0, dibuang; kode dibiarkan
        // biar revivable). Kalau rate dihidupkan: upline LANGSUNG pembeli dapat komisi.
        if ($buyer->upline_id) {
            $upline = $buyer->upline;
            if ($upline && $upline->isPartner()) {
                $rate = $this->overrideRate($upline->role);
                if ($rate > 0) {
                    $this->write($upline, $po, $buyer, 'override', 1, $rate, $base);
                }
            }
        }
    }

    /**
     * RO cashback Sponsor: tiap GD RESTOCK ke HQ (PO ke HQ), PEREKRUT-nya (`sponsor_id`)
     * dapat `komisi_persen_ro_cashback`% dari omzet → saldo (income pasif). Hanya Grand
     * Distributor yang memicu; GD tanpa perekrut → nol. Join (10% paket) bukan PO, jadi
     * tak pernah lewat sini — semua PO GD = restock. Idempoten via source_po_id (guard
     * di recordForCompletedPo). Append-only.
     */
    private function recordRoCashback(PurchaseOrder $po, User $buyer, float $base): void
    {
        if ($buyer->role !== User::ROLE_GRAND_DISTRIBUTOR || ! $buyer->sponsor_id) {
            return;
        }

        $sponsor = $buyer->sponsor;
        if (! $sponsor || ! $sponsor->isPartner()) {
            return;
        }

        $rate = AppSetting::float('komisi_persen_ro_cashback', self::RATE_DEFAULTS['komisi_persen_ro_cashback']);
        if ($rate <= 0) {
            return;
        }

        $this->write($sponsor, $po, $buyer, 'ro_cashback', 1, $rate, $base);
    }

    /**
     * Bonus join: saat member baru daftar via paket, PEREKRUT (member->sponsor)
     * dapat `komisi_persen_join`% dari nilai paket → saldo komisi (append-only,
     * TIDAK auto-cair). Tanpa perekrut → tak dibayar. Tanpa PO (source_po_id null).
     */
    public function recordJoinBonus(User $member, float $paketPrice): void
    {
        // Bonus join ke PEREKRUT (member->sponsor), BUKAN upline pasok. Kalau member
        // daftar mandiri / tanpa perekrut (sponsor_id null) → TAK ADA join dibayar
        // (HQ simpan penuh) — dikunci user 2026-08-21, konsisten dgn RO cashback.
        $sponsor = $member->sponsor;
        $rate = AppSetting::float('komisi_persen_join', self::RATE_DEFAULTS['komisi_persen_join']);
        if (! $sponsor || ! $sponsor->isPartner() || $rate <= 0 || $paketPrice <= 0) {
            return;
        }

        Commission::create([
            'user_id' => $sponsor->id, 'source_po_id' => null, 'source_user_id' => $member->id,
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
