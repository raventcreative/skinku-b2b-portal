<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_chat_messages', function (Blueprint $table) {
            // Jenis pesan channel: text | order_card | logistics_card | other.
            $table->string('type')->default('text')->after('via');
        });
    }

    public function down(): void
    {
        Schema::table('ecom_chat_messages', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
