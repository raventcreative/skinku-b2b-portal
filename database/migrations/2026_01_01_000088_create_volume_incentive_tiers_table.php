<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tier insentif volume Grand: kalau total belanja GD ke HQ (per tahun kalender)
        // tembus `threshold`, hak insentif = total × `rate_percent`%. Fitur AKTIF hanya
        // kalau ada >=1 tier is_active. Nol tier = nol efek (dormant-safe).
        Schema::create('volume_incentive_tiers', function (Blueprint $table) {
            $table->id();
            $table->decimal('threshold', 16, 2);   // ambang total belanja tahunan (Rp)
            $table->decimal('rate_percent', 5, 2);  // % dari TOTAL belanja (0-100)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volume_incentive_tiers');
    }
};
