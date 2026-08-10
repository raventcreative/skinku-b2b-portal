<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Urutan tampil role (matriks Hak Akses & dropdown): HQ -> staf ->
        // non-MLM (KOL/Affiliator) -> spine MLM (Grand -> Dist -> Reseller).
        $order = [
            'super_admin' => 1,
            'admin' => 2,
            'gudang' => 3,
            'kol_specialist' => 4,
            'affiliator' => 5,
            'grand_distributor' => 6,
            'distributor' => 7,
            'reseller_bronze' => 8,
            'reseller_gold' => 9,
            'reseller' => 10,
        ];

        foreach ($order as $name => $sort) {
            DB::table('roles')->where('name', $name)->update(['sort_order' => $sort]);
        }
    }

    public function down(): void
    {
        // Urutan bersifat kosmetik; rollback tak mengembalikan urutan lama spesifik.
    }
};
