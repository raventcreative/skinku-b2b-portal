<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bank')->nullable()->after('region');
            $table->string('no_rekening')->nullable()->after('bank');
            $table->string('atas_nama')->nullable()->after('no_rekening');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['bank', 'no_rekening', 'atas_nama']));
    }
};
