<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign KOL: payung untuk beberapa deal (target views/GMV, periode, status).
 * Deal menunjuk ke campaign (set null bila campaign dihapus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('platform', 12)->default('multi'); // tiktok | shopee | instagram | multi
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('target_views')->nullable();
            $table->unsignedBigInteger('target_gmv')->nullable();
            $table->string('status', 10)->default('active'); // planned | active | done
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('kol_deals', function (Blueprint $table) {
            $table->foreignId('kol_campaign_id')->nullable()->after('kol_id')->constrained('kol_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kol_deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kol_campaign_id');
        });
        Schema::dropIfExists('kol_campaigns');
    }
};
