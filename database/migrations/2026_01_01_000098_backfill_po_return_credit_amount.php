<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill credit_amount untuk retur yang sudah 'applied' SEBELUM fitur
        // potong-tagihan ada (dulu kolomnya belum ada → 0). Nilai = SUM(qty ×
        // unit_price item PO). Supaya retur lama pun ikut memotong sisa tagihan.
        $rows = DB::table('po_return_items')
            ->join('po_returns', 'po_returns.id', '=', 'po_return_items.po_return_id')
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'po_return_items.purchase_order_item_id')
            ->where('po_returns.status', 'applied')
            ->groupBy('po_returns.id')
            ->selectRaw('po_returns.id as rid, SUM(po_return_items.qty * purchase_order_items.unit_price) as credit')
            ->get();

        foreach ($rows as $r) {
            DB::table('po_returns')->where('id', $r->rid)->update(['credit_amount' => round((float) $r->credit, 2)]);
        }
    }

    public function down(): void
    {
        // Kolom credit_amount di-drop oleh rollback migrasi 000097; tak ada yang perlu dibalik di sini.
    }
};
