<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi satu-arah: geser created_at gerakan stok marketplace (tiktok_order /
 * shopee_order) ke TANGGAL order (order_created_at), di-floor ke titik-nol opname
 * (deduct_from) per-platform supaya tak ada yang mendarat sebelum opname.
 *
 * Perlu karena dulu deduct()/reverse() tak meneruskan tanggal order ke gerakan
 * stok, jadi gerakan lama dicap now() (hari potong). Saldo TIDAK berubah (running
 * balance netral terhadap tanggal) — hanya tanggal digeser supaya Laporan Stok HQ
 * akurat. Mengenai KEDUA kaki (potong OUT + batal IN). Idempoten. Pure DB::table
 * (portabel SQLite/MySQL, aman dijalankan dalam migrasi).
 */
class MarketplaceMovementDateBackfill
{
    public static function run(): void
    {
        self::backfill('tiktok_orders', 'tiktok_order', self::cutoff('tiktok_connections'));
        self::backfill('shopee_orders', 'shopee_order', self::cutoff('shopee_connections'));
    }

    private static function backfill(string $ordersTable, string $referenceType, ?Carbon $cutoff): void
    {
        foreach (DB::table($ordersTable)->select('id', 'order_created_at')->get() as $o) {
            if (! $o->order_created_at) {
                continue; // tanpa tanggal → biarkan gerakan apa adanya
            }
            $date = Carbon::parse($o->order_created_at);
            if ($cutoff && $date->lt($cutoff)) {
                $date = $cutoff->copy();   // floor ke titik-nol opname
            }
            DB::table('stock_movements')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $o->id)
                ->update(['created_at' => $date]);   // kedua kaki (OUT + IN)
        }
    }

    private static function cutoff(string $connectionsTable): ?Carbon
    {
        $c = DB::table($connectionsTable)->orderByDesc('id')->first();

        return isset($c->deduct_from) && $c->deduct_from
            ? Carbon::parse($c->deduct_from)->startOfDay()
            : null;
    }
}
