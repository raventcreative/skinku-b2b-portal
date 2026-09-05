<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan dormansi per role (bisa disetting dari Setelan): role mana, aktif/tidak,
 * berapa bulan tanpa aktivitas, dan sinyal aktifnya (order/login/recruit).
 * activated_at = kapan aturan di-ON-kan → dasar masa tenggang (anti beku massal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_dormancy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('role', 50)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('inactive_months')->default(3);
            $table->string('basis', 20)->default('login'); // order | login | recruit
            $table->dateTime('activated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['role' => 'grand_distributor', 'basis' => 'order', 'inactive_months' => 6],
            ['role' => 'distributor', 'basis' => 'order', 'inactive_months' => 3],
            ['role' => 'reseller', 'basis' => 'login', 'inactive_months' => 3],
            ['role' => 'reseller_bronze', 'basis' => 'login', 'inactive_months' => 3],
            ['role' => 'reseller_gold', 'basis' => 'login', 'inactive_months' => 3],
            ['role' => 'sponsor', 'basis' => 'login', 'inactive_months' => 3],
        ];
        foreach ($defaults as $d) {
            DB::table('member_dormancy_rules')->insert($d + [
                'enabled' => false, 'activated_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_dormancy_rules');
    }
};
