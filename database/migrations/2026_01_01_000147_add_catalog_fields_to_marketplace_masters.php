<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->string('name_key')->nullable()->after('name')->index();
            $t->boolean('is_bundle')->default(false)->after('name_key');
            $t->string('image_url')->nullable()->after('is_bundle');
        });

        // Reset master lama (dibentuk per-seller_sku, fragmented) → dibangun ulang
        // by NAMA lewat tombol "Siapkan Master". Ini data mirror marketplace yang
        // rebuildable; pada fresh DB (test) tabel kosong jadi no-op.
        if (Schema::hasTable('marketplace_listings')) {
            DB::table('marketplace_listings')->update(['master_id' => null]);
        }
        if (Schema::hasTable('marketplace_master_channels')) {
            DB::table('marketplace_master_channels')->delete();
        }
        DB::table('marketplace_masters')->delete();
    }

    public function down(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->dropColumn(['name_key', 'is_bundle', 'image_url']);
        });
    }
};
