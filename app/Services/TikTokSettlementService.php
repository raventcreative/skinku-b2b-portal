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
                    // Penjualan langsung berketerangan; potongan diisi belakangan via detail.
                    'kind' => $this->num($s['revenue_amount'] ?? ($s['net_sales_amount'] ?? 0)) > 0
                        ? 'Penjualan'
                        : ($existing->kind ?? null),
                    // jangan reset status posting kalau sudah pernah dijurnal
                    'posting_status' => $existing->posting_status ?? TiktokSettlement::POST_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** Jenis dominan dari rincian transaksi → ['raw' => ..., 'label' => ...]. */
    public function deriveKind(array $transactions): array
    {
        $counts = [];
        foreach ($transactions as $t) {
            $type = $t['type'] ?? ($t['transaction_type'] ?? ($t['adjustment_type'] ?? ($t['sub_type'] ?? null)));
            if ($type) {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }
        if (! $counts) {
            return ['raw' => null, 'label' => 'Potongan lain'];
        }
        arsort($counts);
        $raw = array_key_first($counts);

        return ['raw' => $raw, 'label' => self::translateType($raw)];
    }

    /** Terjemahkan jenis transaksi TikTok → keterangan Indonesia (best-effort). */
    public static function translateType(?string $type): string
    {
        $t = strtoupper((string) $type);

        return match (true) {
            str_contains($t, 'AFFILIATE') && str_contains($t, 'AD') => 'Iklan afiliasi',
            str_contains($t, 'AFFILIATE') || str_contains($t, 'COMMISSION') => 'Komisi afiliasi/platform',
            str_contains($t, 'ADS') || str_contains($t, 'ADVERTIS') || str_contains($t, 'GMV_MAX') => 'Biaya iklan',
            str_contains($t, 'REFUND') || str_contains($t, 'RETURN') => 'Refund / retur',
            str_contains($t, 'SHIP') || str_contains($t, 'LOGISTIC') || str_contains($t, 'FREIGHT') => 'Ongkir / logistik',
            str_contains($t, 'PENALTY') || str_contains($t, 'FINE') => 'Denda / penalti',
            str_contains($t, 'SUBSID') => 'Subsidi',
            str_contains($t, 'LOAN') || str_contains($t, 'INSTALLMENT') => 'Cicilan / pinjaman',
            str_contains($t, 'ORDER') || str_contains($t, 'SETTLE') => 'Penjualan order',
            str_contains($t, 'ADJUST') => 'Penyesuaian',
            $t === '' => 'Potongan lain',
            default => $type,
        };
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
