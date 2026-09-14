<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecom_chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('tiktok');
            $table->string('external_conversation_id');
            $table->string('buyer_name')->nullable();
            $table->string('buyer_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            $table->string('status')->default('open'); // open|needs_staff|replied|closed
            $table->text('ai_draft')->nullable();
            $table->string('ai_decision')->nullable();  // auto_send|to_staff
            $table->text('ai_reason')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_conversation_id']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('ecom_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ecom_chat_conversations')->cascadeOnDelete();
            $table->string('channel')->default('tiktok');
            $table->string('external_message_id');
            $table->string('sender');   // buyer|seller|system
            $table->string('via');      // ai|staff|buyer
            $table->text('text')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_message_id']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecom_chat_messages');
        Schema::dropIfExists('ecom_chat_conversations');
    }
};
