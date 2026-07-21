<?php

use App\Models\PurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbaiki data: PO back-date (order_date terisi) lama tercatat 'unpaid' karena
 * recordBackdatedSale dulu tak menyetel payment_status — muncul sebagai piutang
 * palsu di badge "Belum Lunas". Back-date = penjualan lampau, uangnya sudah masuk.
 *
 * Presisi sengaja SEMPIT supaya tak menyentuh yang bukan sasaran:
 *   - order_date NOT NULL  → penanda kanonik PO back-date (dipakai sistem sendiri)
 *   - payment_status = 'unpaid' → jangan sentuh yang awaiting/rejected (ada
 *     aktivitas bayar) atau yang sudah paid
 *   - is_tempo = false → hormati back-date yang sengaja ditandai cicilan
 *   - status != cancelled → PO batal tak relevan
 *
 * PO tempo baru (order_date NULL) TIDAK tersentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tandai baris tersasar dulu (untuk down() yang presisi), baru update.
        $ids = DB::table('purchase_orders')
            ->whereNotNull('order_date')
            ->where('payment_status', PurchaseOrder::PAYMENT_UNPAID)
            ->where(fn ($q) => $q->where('is_tempo', false)->orWhereNull('is_tempo'))
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('purchase_orders')->whereIn('id', $ids)->update([
            'payment_status' => PurchaseOrder::PAYMENT_PAID,
            // paid_at = tanggal transaksinya bila ada, jatuh ke created_at.
            'paid_at' => DB::raw('COALESCE(order_date, created_at)'),
            'payment_note' => DB::raw("CONCAT(COALESCE(payment_note,''), ' [auto: back-date lunas]')"),
        ]);
    }

    public function down(): void
    {
        // Hanya kembalikan yang JELAS ditandai oleh migrasi ini (jejak di note),
        // supaya rollback tak menganulir pelunasan lain.
        DB::table('purchase_orders')
            ->where('payment_note', 'like', '%[auto: back-date lunas]%')
            ->update([
                'payment_status' => PurchaseOrder::PAYMENT_UNPAID,
                'paid_at' => null,
                'payment_note' => DB::raw("REPLACE(payment_note, ' [auto: back-date lunas]', '')"),
            ]);
    }
};
