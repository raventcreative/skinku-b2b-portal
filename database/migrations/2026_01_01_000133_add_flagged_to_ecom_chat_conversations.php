<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            // Ditandai manual oleh staf → masuk tab "Ditandai" untuk diprioritaskan.
            $table->boolean('flagged')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            $table->dropColumn('flagged');
        });
    }
};
