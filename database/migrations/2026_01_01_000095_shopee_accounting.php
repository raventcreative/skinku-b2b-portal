<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('transit_journal_id')->nullable()->after('deducted_by');
            $table->unsignedBigInteger('sale_journal_id')->nullable()->after('transit_journal_id');
        });
        Schema::table('shopee_connections', function (Blueprint $table) {
            $table->boolean('journal_enabled')->default(false)->after('deduct_from');
        });
        Schema::create('shopee_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->string('transaction_type')->nullable()->index();
            $table->string('kind', 80)->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->decimal('current_balance', 16, 2)->default(0);
            $table->string('money_flow', 20)->nullable();
            $table->string('order_sn')->nullable()->index();
            $table->string('refund_sn')->nullable();
            $table->string('reason', 190)->nullable();
            $table->string('status')->nullable();
            $table->dateTime('transaction_time')->nullable();
            $table->json('raw')->nullable();
            $table->string('posting_status', 20)->default('pending')->index();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_wallet_transactions');
        Schema::table('shopee_connections', fn (Blueprint $t) => $t->dropColumn('journal_enabled'));
        Schema::table('shopee_orders', fn (Blueprint $t) => $t->dropColumn(['transit_journal_id', 'sale_journal_id']));
    }
};
