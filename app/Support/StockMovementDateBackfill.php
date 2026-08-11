<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Koreksi satu-arah: geser created_at gerakan stok produksi & penerimaan ke
 * TANGGAL parent-nya (productions.produced_at / stock_receipts.received_at).
 *
 * Perlu karena dulu ProductionService/StockReceiptService tak meneruskan tanggal
 * backdate ke gerakan stok, jadi gerakan lama dicap tanggal input. Saldo TIDAK
 * berubah (running balance netral terhadap tanggal) — hanya tanggal digeser
 * supaya Laporan Stok HQ akurat. Idempoten.
 */
class StockMovementDateBackfill
{
    public static function run(): void
    {
        foreach (DB::table('productions')->select('id', 'produced_at')->get() as $p) {
            DB::table('stock_movements')
                ->where('reference_type', 'production')
                ->where('reference_id', $p->id)
                ->update(['created_at' => $p->produced_at]);
        }

        foreach (DB::table('stock_receipts')->select('id', 'received_at')->get() as $r) {
            DB::table('stock_movements')
                ->where('reference_type', 'stock_receipt')
                ->where('reference_id', $r->id)
                ->update(['created_at' => $r->received_at]);
        }
    }
}
