<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_chat_messages', function (Blueprint $table) {
            // JSON kecil: order_id/package_id untuk kartu pesanan/logistik (render kaya).
            $table->text('meta')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('ecom_chat_messages', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
