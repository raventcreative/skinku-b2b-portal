<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\PoReturnItem;
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

        // Total belanja GD ke HQ tahun ini (Σ subtotal−discount, PO completed seller null)
        // DIKURANGI nilai retur yang sudah applied (netTotal — biar volume ikut turun).
        $poTotal = (float) PurchaseOrder::where('user_id', $grand->id)
            ->whereNull('seller_id')
            ->where('status', PurchaseOrder::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(subtotal - discount), 0) as t')->value('t');

        $returned = (float) PoReturnItem::query()
            ->join('po_returns', 'po_returns.id', '=', 'po_return_items.po_return_id')
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'po_return_items.purchase_order_item_id')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'po_returns.purchase_order_id')
            ->where('purchase_orders.user_id', $grand->id)
            ->whereNull('purchase_orders.seller_id')
            ->where('po_returns.status', 'applied')
            ->whereBetween('purchase_orders.completed_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(po_return_items.qty * purchase_order_items.unit_price), 0) as r')->value('r');

        $total = $poTotal - $returned;

        // Rate = tier TERTINGGI yang thresholdnya <= total (0 kalau belum tembus).
        $rate = 0.0;
        foreach ($tiers as $tier) {
            if ($total >= (float) $tier->threshold) {
                $rate = (float) $tier->rate_percent;
            }
        }

        // Hak = total × rate; award = hak − yang sudah diberi tahun ini. Bisa NEGATIF
        // (clawback) kalau total turun pasca-retur di bawah yang sudah dibayar.
        $entitlement = round($total * $rate / 100, 2);
        $alreadyAwarded = (float) Commission::where('user_id', $grand->id)
            ->where('type', 'volume_bonus')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
        $award = round($entitlement - $alreadyAwarded, 2);
        if (abs($award) < 0.005) {
            return; // tak ada perubahan (idempoten)
        }

        // Catatan: amount = SELISIH (delta), sengaja BUKAN base×rate untuk baris ini.
        Commission::create([
            'user_id' => $grand->id, 'source_po_id' => $po->id, 'source_user_id' => $grand->id,
            'type' => 'volume_bonus', 'level' => 1, 'rate' => $rate, 'base_amount' => $total,
            'amount' => $award, 'status' => 'saldo',
        ]);
    }
}
