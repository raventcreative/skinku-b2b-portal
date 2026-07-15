<?php

namespace App\Services;

use App\Models\TiktokSettlement;
use Illuminate\Support\Carbon;

/**
 * Simpan data pencairan (settlement) TikTok Finance API. M3a: read-only —
 * cuma menyimpan agregat + respons mentah supaya bentuk field asli bisa dilihat
 * sebelum jurnal dibangun (M3b/M3c). Field dipetakan defensif (nama bisa beda).
 */
class TikTokSettlementService
{
    /** @return int jumlah tersimpan/terbarui */
    public function store(array $apiStatements): int
    {
        $n = 0;
        foreach ($apiStatements as $s) {
            $id = $s['id'] ?? ($s['statement_id'] ?? null);
            if (! $id) {
                continue;
            }

            $existing = TiktokSettlement::where('tiktok_statement_id', $id)->first();

            TiktokSettlement::updateOrCreate(
                ['tiktok_statement_id' => (string) $id],
                [
                    'payment_status' => $s['payment_status'] ?? ($s['status'] ?? null),
                    'currency' => $s['currency'] ?? null,
                    'revenue_amount' => $this->num($s['revenue_amount'] ?? ($s['net_sales_amount'] ?? 0)),
                    'fee_amount' => abs($this->num($s['fee_amount'] ?? 0)),
                    'adjustment_amount' => $this->num($s['adjustment_amount'] ?? 0),
                    'settlement_amount' => $this->num($s['settlement_amount'] ?? 0),
                    'statement_time' => $this->toTime($s['statement_time'] ?? null),
                    'paid_time' => $this->toTime($s['payment_time'] ?? ($s['paid_time'] ?? null)),
                    'raw' => $s,
                    // jangan reset status posting kalau sudah pernah dijurnal
                    'posting_status' => $existing->posting_status ?? TiktokSettlement::POST_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    private function num($v): float
    {
        if (is_string($v)) {
            $v = str_replace([',', ' '], '', $v);
        }

        return (float) $v;
    }

    private function toTime($v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::createFromTimestamp((int) $v);
        }

        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
