<?php

use App\Support\StockMovementDateBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Betulkan tanggal gerakan produksi/penerimaan lama = tanggal backdate parent.
        StockMovementDateBackfill::run();
    }

    public function down(): void
    {
        // Koreksi satu arah — tanggal input asli tak disimpan, tak bisa dibalik.
    }
};
