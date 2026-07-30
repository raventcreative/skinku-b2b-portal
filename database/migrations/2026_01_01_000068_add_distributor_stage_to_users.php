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
            $table->string('distributor_stage', 30)->nullable()->after('region')->index();
            $table->timestamp('distributor_stage_updated_at')->nullable()->after('distributor_stage');
        });

        DB::table('users')
            ->where('role', 'distributor')
            ->whereNull('distributor_stage')
            ->update([
                'distributor_stage' => 'registered',
                'distributor_stage_updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['distributor_stage']);
            $table->dropColumn(['distributor_stage', 'distributor_stage_updated_at']);
        });
    }
};
