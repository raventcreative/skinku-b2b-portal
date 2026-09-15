<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            $table->timestamp('last_incoming_at')->nullable()->after('last_message_at');
        });

        Schema::create('ecom_chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ecom_chat_conversations')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecom_chat_reads');
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            $table->dropColumn('last_incoming_at');
        });
    }
};
