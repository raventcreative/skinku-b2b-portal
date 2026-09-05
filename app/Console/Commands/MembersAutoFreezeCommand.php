<?php

namespace App\Console\Commands;

use App\Models\MemberDormancyRule;
use App\Models\User;
use App\Services\MemberDormancyService;
use Illuminate\Console\Command;

/**
 * Bekukan otomatis akun member dorman sesuai aturan per-role yang aktif.
 * Staff tak pernah kena. Idempoten (yang sudah nonaktif otomatis terlewati).
 */
class MembersAutoFreezeCommand extends Command
{
    protected $signature = 'members:auto-freeze {--dry-run : Laporkan tanpa mengubah} {--limit=0 : Batasi jumlah (0 = semua)}';

    protected $description = 'Bekukan otomatis akun member yang dorman (per-role, sesuai aturan yang aktif).';

    public function handle(MemberDormancyService $svc): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $now = now();
        $frozen = 0;

        foreach (MemberDormancyRule::where('enabled', true)->get() as $rule) {
            $users = User::where('role', $rule->role)
                ->where('status', User::STATUS_ACTIVE)
                ->whereNotIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_GUDANG])
                ->get();

            foreach ($users as $user) {
                if (! $svc->isDormant($user, $rule, $now)) {
                    continue;
                }
                if ($dry) {
                    $this->line("  [dry] @{$user->username} ({$rule->role}) → akan dibekukan.");
                } else {
                    $svc->freeze($user, $rule);
                    $this->line("  \u{2713} @{$user->username} ({$rule->role}) dibekukan.");
                }
                $frozen++;

                if ($limit > 0 && $frozen >= $limit) {
                    $this->info(($dry ? '[dry] ' : '')."Batas {$limit} tercapai. Total: {$frozen}.");

                    return self::SUCCESS;
                }
            }
        }

        $this->info(($dry ? '[dry] ' : '')."Selesai. {$frozen} akun ".($dry ? 'akan dibekukan' : 'dibekukan').'.');

        return self::SUCCESS;
    }
}
