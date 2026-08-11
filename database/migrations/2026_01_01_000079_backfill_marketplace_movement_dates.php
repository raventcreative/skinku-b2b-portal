<?php

use App\Support\MarketplaceMovementDateBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Betulkan tanggal gerakan marketplace lama = tanggal order (floor ke deduct_from).
        MarketplaceMovementDateBackfill::run();
    }

    public function down(): void
    {
        // Koreksi satu arah — timestamp now() asli tak disimpan, tak bisa dibalik.
    }
};
