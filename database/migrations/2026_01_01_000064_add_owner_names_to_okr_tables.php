<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('owner_user_id');
        });
        Schema::table('okr_key_results', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('owner_user_id');
        });

        $names = $this->specialistNames();
        DB::table('okr_objectives')->orderBy('id')->get(['id', 'specialist', 'owner_user_id'])
            ->each(function ($objective) use ($names) {
                $ownerName = $names[$objective->specialist] ?? $this->userName($objective->owner_user_id);
                if ($ownerName === null) {
                    return;
                }
                DB::table('okr_objectives')->where('id', $objective->id)->update(['owner_name' => $ownerName]);
                DB::table('okr_key_results')->where('okr_objective_id', $objective->id)->update(['owner_name' => $ownerName]);
            });
    }

    public function down(): void
    {
        Schema::table('okr_key_results', function (Blueprint $table) {
            $table->dropColumn('owner_name');
        });
        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->dropColumn('owner_name');
        });
    }

    /** @return array<string,string> */
    private function specialistNames(): array
    {
        $team = (string) (DB::table('ai_knowledge')->where('section', 'team')->value('content') ?? '');
        $names = [];
        foreach (['cmo' => 'CMO', 'cfo' => 'CFO', 'coo' => 'COO'] as $key => $label) {
            foreach (preg_split('/\R/u', $team) ?: [] as $line) {
                if (! preg_match('/\b'.preg_quote($label, '/').'\b/iu', $line)) {
                    continue;
                }
                if (preg_match('/^\s*[-*]?\s*([^—–\r\n]+?)\s*(?:—|–|-\s)\s*'.preg_quote($label, '/').'\b/iu', $line, $match)) {
                    $name = trim($match[1], " \t\n\r\0\x0B-");
                    if ($name !== '') {
                        $names[$key] = $name;

                        break;
                    }
                }
            }
        }

        return $names;
    }

    private function userName(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }
        $user = DB::table('users')->where('id', $userId)->first(['fullname', 'name', 'username']);
        if (! $user) {
            return null;
        }

        return $user->fullname ?: ($user->name ?: $user->username);
    }
};
