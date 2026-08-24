<?php

namespace App\Services;

use App\Models\ShopeeSettlement;
use Illuminate\Support\Carbon;

/**
 * Simpan penyelesaian escrow per-order Shopee. Field order_income tervalidasi ke
 * sandbox (order 2608247FYHUBMG). Simpan yang relevan-ID ke kolom + raw penuh.
 * BELUM ada jurnal — itu Fase 4 (baca posting_status).
 */
class ShopeeSettlementService
{
    /** @param array $apiDetails elemen = {order_sn, order_income{...}, escrow_release_time?} */
    public function store(array $apiDetails): int
    {
        $n = 0;
        foreach ($apiDetails as $d) {
            $sn = $d['order_sn'] ?? null;
            if (! $sn) {
                continue;
            }
            $existing = ShopeeSettlement::where('order_sn', $sn)->first();
            $rt = $d['escrow_release_time'] ?? null;

            ShopeeSettlement::updateOrCreate(
                ['order_sn' => (string) $sn],
                array_merge($this->mapIncome($d['order_income'] ?? []), [
                    'currency' => $d['currency'] ?? (($d['order_income']['currency'] ?? null)),
                    'escrow_release_time' => $rt ? Carbon::createFromTimestamp((int) $rt) : null,
                    'raw' => $d,
                    'posting_status' => $existing->posting_status ?? ShopeeSettlement::POST_PENDING,
                ]),
            );
            $n++;
        }

        return $n;
    }

    /** order_income Shopee → kolom kita (defensif, nol bila absen). */
    public function mapIncome(array $income): array
    {
        $num = fn (string $k) => (float) ($income[$k] ?? 0);

        return [
            'escrow_amount' => $num('escrow_amount'),
            'buyer_total_amount' => $num('buyer_total_amount'),
            'commission_fee' => $num('commission_fee'),
            'service_fee' => $num('service_fee'),
            'campaign_fee' => $num('campaign_fee'),
            'seller_transaction_fee' => $num('seller_transaction_fee'),
            'actual_shipping_fee' => $num('actual_shipping_fee'),
            'buyer_paid_shipping_fee' => $num('buyer_paid_shipping_fee'),
            'shopee_shipping_rebate' => $num('shopee_shipping_rebate'),
            'escrow_tax' => $num('escrow_tax'),
            'withholding_tax' => $num('withholding_tax'),
            'total_adjustment_amount' => $num('total_adjustment_amount'),
        ];
    }
}
