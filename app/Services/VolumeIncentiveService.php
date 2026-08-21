<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VolumeIncentiveTier;
use Illuminate\Support\Carbon;

/**
 * Insentif Volume Grand: kalau total belanja Grand Distributor ke HQ per TAHUN
 * KALENDER tembus tier, hak insentif = total × rate-tier-TERTINGGI-yang-kelewat.
 * Model TOP-UP: tiap evaluasi kasih SELISIH (hak − yang sudah diberi tahun ini) →
 * idempoten (re-eval = 0) + append-only (tier turun → award=0, TAK narik balik).
 * Dormant-safe: nol tier aktif = nol efek. Khusus Grand.
 */
class VolumeIncentiveService
{
    /** Dipanggil dari PurchaseOrderService::complete() saat GD restock ke HQ. */
    public function evaluate(PurchaseOrder $po): void
    {
        if ($po->seller_id !== null || $po->user?->role !== User::ROLE_GRAND_DISTRIBUTOR) {
            return; // hanya restock GD ke HQ
        }

        $tiers = VolumeIncentiveTier::where('is_active', true)->orderBy('threshold')->get();
        if ($tiers->isEmpty()) {
            return; // fitur mati (nol tier aktif)
        }

        $grand = $po->user;
        $year = ($po->completed_at ? Carbon::parse($po->completed_at) : Carbon::now())->year;
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        // Total belanja GD ke HQ tahun ini (Σ subtotal−discount, PO completed seller null).
        $total = (float) PurchaseOrder::where('user_id', $grand->id)
            ->whereNull('seller_id')
            ->where('status', PurchaseOrder::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(subtotal - discount), 0) as t')->value('t');

        // Rate = tier TERTINGGI yang thresholdnya <= total.
        $rate = 0.0;
        foreach ($tiers as $tier) {
            if ($total >= (float) $tier->threshold) {
                $rate = (float) $tier->rate_percent;
            }
        }
        if ($rate <= 0) {
            return; // belum tembus tier mana pun
        }

        // Hak = total × rate; award = hak − yang sudah diberi tahun ini (SELISIH saja).
        $entitlement = round($total * $rate / 100, 2);
        $alreadyAwarded = (float) Commission::where('user_id', $grand->id)
            ->where('type', 'volume_bonus')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
        $award = round($entitlement - $alreadyAwarded, 2);
        if ($award <= 0) {
            return; // idempoten / tier turun → tak menambah, tak menarik balik
        }

        // Catatan: amount = SELISIH (delta), sengaja BUKAN base×rate untuk baris ini.
        Commission::create([
            'user_id' => $grand->id, 'source_po_id' => $po->id, 'source_user_id' => $grand->id,
            'type' => 'volume_bonus', 'level' => 1, 'rate' => $rate, 'base_amount' => $total,
            'amount' => $award, 'status' => 'saldo',
        ]);
    }
}
