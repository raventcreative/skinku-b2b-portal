<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('join_package_id')->nullable()->constrained('join_packages')->nullOnDelete();
            $table->foreignId('inviter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('price', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('user_id');
            $table->index('inviter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_transactions');
    }
};
