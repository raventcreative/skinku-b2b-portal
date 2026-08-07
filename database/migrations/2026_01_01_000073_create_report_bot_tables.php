<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report Bot Telegram: gerbang akses per-chat + antrean file upload.
 * `telegram_bot_chats` menyimpan status otorisasi tiap chat_id (dibuka
 * dengan kode akses global — lihat AppSetting::get('report_bot_access_code')
 * via ReportBotGate). `telegram_bot_pending_files` menyimpan file csv/xlsx
 * yang diupload user dan menunggu diproses (task-task berikutnya) — cuma
 * created_at, tanpa updated_at, karena baris ini sekali-tulis lalu dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique();
            $table->string('name')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
        });

        Schema::create('telegram_bot_pending_files', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->index();
            $table->string('kind', 10); // csv | xlsx
            $table->string('path');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_pending_files');
        Schema::dropIfExists('telegram_bot_chats');
    }
};
