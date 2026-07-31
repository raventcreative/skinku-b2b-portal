<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_cycles', function (Blueprint $table) {
            // Status siklus GENERATE (asinkron, di background job):
            //   ready      = draf sudah selesai disusun (default; termasuk draf lama)
            //   generating = job masih memproses panel AI
            //   failed     = generate gagal (pesan di generation_error)
            $table->string('generation_status', 20)->default('ready')->after('status');
            $table->text('generation_error')->nullable()->after('generation_status');
        });
    }

    public function down(): void
    {
        Schema::table('okr_cycles', function (Blueprint $table) {
            $table->dropColumn(['generation_status', 'generation_error']);
        });
    }
};
