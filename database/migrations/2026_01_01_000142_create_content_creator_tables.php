<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal Content Creator (spec docs/superpowers/specs/2026-09-25-content-creator).
 * Role content_creator + konten (content_posts) + status per platform
 * (content_post_targets) + koneksi akun brand (social_connections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20)->unique(); // satu akun brand per platform (FR-46)
            $table->string('account_id');
            $table->string('account_name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('content_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('title');
            $table->string('type', 20);
            $table->text('caption')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->text('creator_note')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });

        Schema::create('content_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_post_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->text('caption_override')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('external_id')->nullable();
            $table->string('container_id')->nullable();
            $table->unsignedSmallInteger('container_polls')->default(0);
            $table->string('permalink')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['content_post_id', 'platform']);
        });

        $now = now();
        DB::table('roles')->insertOrIgnore([
            'name' => 'content_creator', 'label' => 'Content Creator', 'is_system' => false,
            'sort_order' => 11, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_post_targets');
        Schema::dropIfExists('content_posts');
        Schema::dropIfExists('social_connections');
        DB::table('role_permissions')->where('role', 'content_creator')->delete();
        DB::table('roles')->where('name', 'content_creator')->where('is_system', false)->delete();
    }
};
