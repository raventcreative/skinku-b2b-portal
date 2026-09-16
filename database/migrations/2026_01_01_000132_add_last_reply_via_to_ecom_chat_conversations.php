<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            // Sumber balasan TERAKHIR: 'ai' (auto-send) atau 'staff' (manual) —
            // dipakai untuk badge "Dibalas AI" vs "Dibalas staf" di inbox.
            $table->string('last_reply_via')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            $table->dropColumn('last_reply_via');
        });
    }
};
