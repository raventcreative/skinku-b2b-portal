<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Mesin komisi MLM: join (order pertama, ke upline langsung) vs override
 * differensial naik-pohon (order berikutnya, tiap level partner di atas
 * pembeli dapat rate sesuai role-nya sendiri). Hanya PO HQ langsung
 * (seller_id null) yang memicu komisi — Model X/inter-partner dorman.
 */
class CommissionService
{
    private const DEFAULT_RATES = [
        User::ROLE_GRAND_DISTRIBUTOR => 6.0,
        User::ROLE_DISTRIBUTOR => 4.0,
        User::ROLE_RESELLER_BRONZE => 2.0,
        User::ROLE_RESELLER_GOLD => 2.0,
        User::ROLE_RESELLER => 2.0,
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

        $isFirst = ! PurchaseOrder::where('user_id', $po->user_id)
            ->where('status', PurchaseOrder::STATUS_COMPLETED)
            ->where('id', '!=', $po->id)->exists();

        if ($isFirst) {
            $upline = $buyer->upline;
            if ($upline && $upline->isPartner()) {
                $rate = AppSetting::float('komisi_persen_join', 10.0);
                if ($rate > 0) {
                    $this->write($upline, $po, $buyer, 'join', 1, $rate, $base);
                }
            }

            return;
        }

        $node = $buyer->upline;
        $level = 1;
        while ($node && $level <= 10) {
            if ($node->isPartner()) {
                $rate = $this->overrideRate($node->role);
                if ($rate > 0) {
                    $this->write($node, $po, $buyer, 'override', $level, $rate, $base);
                }
            }
            $node = $node->upline;
            $level++;
        }
    }

    public function balance(User $mitra): float
    {
        return (float) Commission::where('user_id', $mitra->id)->where('status', 'saldo')->sum('amount');
    }

    private function overrideRate(string $role): float
    {
        $default = self::DEFAULT_RATES[$role] ?? 0.0;

        return AppSetting::float('komisi_persen_'.$role, $default);
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
