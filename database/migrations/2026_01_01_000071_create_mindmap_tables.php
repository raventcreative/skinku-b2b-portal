<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mindmaps: kanvas serbaguna (mindmap/diagram/campaign). Papan + anggota +
 * node + garis. Tiap elemen baris sendiri supaya berbagi tim tak saling
 * menimpa. Hapus node -> garisnya ikut; hapus papan -> semua isinya ikut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mindmaps', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('mindmap_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mindmap_id')->constrained('mindmaps')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_edit')->default(true);
            $table->timestamps();
            $table->unique(['mindmap_id', 'user_id']);
        });

        Schema::create('mindmap_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mindmap_id')->constrained('mindmaps')->cascadeOnDelete();
            $table->string('type', 20)->default('sticky');
            $table->float('x')->default(0);
            $table->float('y')->default(0);
            $table->float('width')->default(200);
            $table->float('height')->default(120);
            $table->text('text')->nullable();
            $table->string('color', 20)->default('kuning');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mindmap_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mindmap_id')->constrained('mindmaps')->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('mindmap_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('mindmap_nodes')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mindmap_edges');
        Schema::dropIfExists('mindmap_nodes');
        Schema::dropIfExists('mindmap_members');
        Schema::dropIfExists('mindmaps');
    }
};
