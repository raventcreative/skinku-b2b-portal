<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate lanjutan: komisi settled (vs estimasi) di transaksi, statistik
 * mingguan manual per creator (riwayat), + log batch import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_affiliate_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('commission_settled')->nullable()->after('commission');
        });

        Schema::create('kol_weekly_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->date('week_start');
            $table->unsignedBigInteger('gmv')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedBigInteger('commission')->default(0);
            $table->unsignedInteger('content_count')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kol_id', 'week_start']);
        });

        Schema::create('kol_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->string('source', 10)->default('import'); // import | agent
            $table->string('filename')->nullable();
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('unmatched')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_import_batches');
        Schema::dropIfExists('kol_weekly_stats');
        Schema::table('kol_affiliate_transactions', function (Blueprint $table) {
            $table->dropColumn('commission_settled');
        });
    }
};
