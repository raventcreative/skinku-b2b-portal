<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge', function (Blueprint $table) {
            $table->string('group')->default('sistem')->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge', function (Blueprint $table) {
            $table->dropColumn('group');
        });
    }
};
