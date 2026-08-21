<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('join_transactions', function (Blueprint $table) {
            // Batal join: onboarding dibatalkan → clawback bonus join + balikin stok paket.
            $table->timestamp('cancelled_at')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('join_transactions', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
