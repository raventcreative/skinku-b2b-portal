<?php

namespace App\Services;

use App\Models\ShopeeWalletTransaction;
use Illuminate\Support\Carbon;

class ShopeeWalletService
{
    public function store(array $apiTx): int
    {
        $n = 0;
        foreach ($apiTx as $t) {
            $id = $t['transaction_id'] ?? null;
            if (! $id) {
                continue;
            }
            $existing = ShopeeWalletTransaction::where('transaction_id', $id)->first();
            $type = $t['transaction_type'] ?? null;
            $ct = $t['create_time'] ?? null;

            ShopeeWalletTransaction::updateOrCreate(
                ['transaction_id' => (string) $id],
                [
                    'transaction_type' => $type,
                    'kind' => $this->kindFromType((string) $type),
                    'amount' => (float) ($t['amount'] ?? 0),
                    'current_balance' => (float) ($t['current_balance'] ?? 0),
                    'money_flow' => $t['money_flow'] ?? null,
                    'order_sn' => $t['order_sn'] ?? null,
                    'refund_sn' => $t['refund_sn'] ?? null,
                    'reason' => $t['reason'] ?? null,
                    'status' => $t['status'] ?? null,
                    'transaction_time' => $ct ? Carbon::createFromTimestamp((int) $ct) : null,
                    'raw' => $t,
                    'posting_status' => $existing->posting_status ?? ShopeeWalletTransaction::POST_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** Peta transaction_type Shopee → label ID (Shopee kasih tipe eksplisit — bukan tebak). */
    public function kindFromType(string $type): string
    {
        return match ($type) {
            'ESCROW_VERIFIED_ADD', 'FAST_ESCROW_DISBURSE', 'FAST_ESCROW_DISBURSE_REMAIN' => 'Order cair (ke saldo)',
            'ESCROW_VERIFIED_MINUS', 'FAST_ESCROW_DEDUCT' => 'Koreksi escrow',
            'WITHDRAWAL_COMPLETED' => 'Tarik ke bank',
            'WITHDRAWAL_CREATED' => 'Tarik dibuat',
            'WITHDRAWAL_CANCELLED' => 'Tarik dibatalkan',
            'PAID_ADS_CHARGE', 'AFFILIATE_ADS_SELLER_FEE', 'AFFILIATE_FEE_DEDUCT' => 'Biaya iklan',
            'PAID_ADS_REFUND', 'AFFILIATE_ADS_SELLER_FEE_REFUND' => 'Refund iklan',
            'ADJUSTMENT_ADD', 'ADJUSTMENT_CENTER_ADD', 'FBS_ADJUSTMENT_ADD' => 'Penyesuaian (+)',
            'ADJUSTMENT_MINUS', 'ADJUSTMENT_CENTER_DEDUCT', 'FBS_ADJUSTMENT_MINUS', 'FSF_COST_PASSING_DEDUCT' => 'Penyesuaian (−)',
            default => 'Lainnya',
        };
    }
}
