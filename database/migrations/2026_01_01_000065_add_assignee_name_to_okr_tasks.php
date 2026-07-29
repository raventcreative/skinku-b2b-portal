<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_tasks', function (Blueprint $table) {
            $table->string('assignee_name')->nullable()->after('assignee_user_id');
        });

        DB::table('okr_tasks')
            ->whereNotNull('assignee_user_id')
            ->orderBy('id')
            ->get(['id', 'assignee_user_id'])
            ->each(function ($task) {
                $user = DB::table('users')->where('id', $task->assignee_user_id)->first(['fullname', 'name', 'username']);
                $name = $user?->fullname ?: ($user?->name ?: $user?->username);
                if ($name) {
                    DB::table('okr_tasks')->where('id', $task->id)->update(['assignee_name' => $name]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('okr_tasks', function (Blueprint $table) {
            $table->dropColumn('assignee_name');
        });
    }
};
