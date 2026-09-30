<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_posts')
            ->whereIn('status', ['in_review', 'rejected'])
            ->update(['status' => 'draft']);
    }

    public function down(): void
    {
        // The former status cannot be reconstructed safely after edits/publishing.
    }
};
