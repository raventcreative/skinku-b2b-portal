<?php

use App\Services\ReportBot\TikTokIncomeN8nService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Peta SKU parser Report Bot (TikTok Income) editable dari DB. Di-seed dari
 * konstanta SKU_MAP (TikTokIncomeN8nService) supaya perilaku identik pasca
 * deploy; konstanta tetap ada sebagai fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_sku_maps', function (Blueprint $table) {
            $table->id();
            $table->string('sku_id', 40)->index();
            $table->string('category', 50);
            $table->unsignedInteger('qty')->default(1);
            $table->string('note', 255)->nullable();  // nama produk (bantu manusia)
            $table->timestamps();
            $table->unique(['sku_id', 'category']);
        });

        // Seed dari konstanta SKU_MAP (1 baris per komponen kategori).
        $now = now();
        $rows = [];
        foreach (TikTokIncomeN8nService::SKU_MAP as $skuId => $comps) {
            foreach ($comps as $cat => $qty) {
                $rows[] = [
                    'sku_id' => (string) $skuId, 'category' => $cat, 'qty' => (int) $qty,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        if ($rows !== []) {
            DB::table('report_sku_maps')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_sku_maps');
    }
};
