<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komentar pada kartu kanban — pelengkap "benar-benar mirip Trello".
 * user_id nullOnDelete: komentar tetap terbaca walau penulisnya dihapus
 * (percakapan tim adalah konteks pekerjaan, bukan milik satu akun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_card_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('board_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_card_comments');
    }
};
