<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM tambahan untuk KOL (additive, tetap single-account): nama tampilan, peran,
 * blacklist+alasan, manager, flag komersial, voucher/tracking-link/usage-rights,
 * + tabel log kontak. Multi-akun platform sengaja DITUNDA (tak menyentuh
 * tiktok_username yang dipakai screening/affiliate/pipeline/deal).
 */
return new class extends Migration
{
    public function up(): void
    {
        // status: enum(4) → string agar 'blacklist' (& nilai baru) muat. Validasi
        // tetap di app (Rule::in Kol::STATUSES). Aman lintas MySQL/sqlite.
        Schema::table('kols', function (Blueprint $table) {
            $table->string('status', 20)->default('prospek')->change();
        });

        Schema::table('kols', function (Blueprint $table) {
            $table->string('name')->nullable()->after('tiktok_username');   // nama tampilan
            $table->string('role', 10)->default('kol')->after('name');       // kol | affiliate | both
            $table->text('blacklist_reason')->nullable()->after('status');
            $table->string('manager_name')->nullable()->after('agency');
            $table->string('manager_contact')->nullable()->after('manager_name');
            $table->boolean('barter_ok')->default(false)->after('manager_contact');
            $table->boolean('tiktok_shop_active')->default(false)->after('barter_ok');
            $table->boolean('shopee_affiliate_active')->default(false)->after('tiktok_shop_active');
            $table->string('voucher_code')->nullable()->after('shopee_affiliate_active');
            $table->string('tracking_link')->nullable()->after('voucher_code');
            $table->text('usage_rights')->nullable()->after('tracking_link');
        });

        Schema::create('kol_contact_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('channel', 20)->default('wa'); // wa | dm | telp | email | lainnya
            $table->text('note');
            $table->date('contacted_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('kol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_contact_logs');
        Schema::table('kols', function (Blueprint $table) {
            $table->dropColumn(['name', 'role', 'blacklist_reason', 'manager_name', 'manager_contact',
                'barter_ok', 'tiktok_shop_active', 'shopee_affiliate_active', 'voucher_code', 'tracking_link', 'usage_rights']);
        });
    }
};
