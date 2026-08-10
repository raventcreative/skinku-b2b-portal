<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('upline_id')->nullable()->after('region')->constrained('users')->nullOnDelete();
            $table->string('member_id')->nullable()->unique()->after('upline_id');
        });

        $now = now();
        DB::table('roles')->insertOrIgnore([
            ['name' => 'grand_distributor', 'label' => 'Grand Distributor', 'is_system' => false, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'reseller_bronze', 'label' => 'Reseller Bronze', 'is_system' => false, 'sort_order' => 21, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'reseller_gold', 'label' => 'Reseller Gold', 'is_system' => false, 'sort_order' => 22, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['upline_id']);
            $table->dropColumn(['upline_id', 'member_id']);
        });

        $names = ['grand_distributor', 'reseller_bronze', 'reseller_gold'];
        DB::table('role_permissions')->whereIn('role', $names)->delete();
        DB::table('roles')->whereIn('name', $names)->where('is_system', false)->delete();
    }
};
