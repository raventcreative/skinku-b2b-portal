<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_cycles', function (Blueprint $table) {
            $table->text('analysis_summary')->nullable()->after('direction');
            $table->json('analysis_evidence')->nullable()->after('analysis_summary');
            $table->json('analysis_assumptions')->nullable()->after('analysis_evidence');
            $table->json('analysis_conflicts')->nullable()->after('analysis_assumptions');
            $table->json('data_coverage')->nullable()->after('analysis_conflicts');
        });

        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->text('rationale')->nullable()->after('description');
        });

        Schema::table('okr_key_results', function (Blueprint $table) {
            $table->string('baseline_status', 30)->nullable()->after('target');
            $table->string('baseline')->nullable()->after('baseline_status');
            $table->string('baseline_source')->nullable()->after('baseline');
            $table->text('target_gap')->nullable()->after('baseline_source');
        });
    }

    public function down(): void
    {
        Schema::table('okr_key_results', function (Blueprint $table) {
            $table->dropColumn(['baseline_status', 'baseline', 'baseline_source', 'target_gap']);
        });

        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->dropColumn('rationale');
        });

        Schema::table('okr_cycles', function (Blueprint $table) {
            $table->dropColumn([
                'analysis_summary', 'analysis_evidence', 'analysis_assumptions',
                'analysis_conflicts', 'data_coverage',
            ]);
        });
    }
};
