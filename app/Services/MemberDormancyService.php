<?php

namespace App\Services;

use App\Models\MemberDormancyRule;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Logika dormansi member: hitung aktivitas terakhir per basis, aktivitas efektif
 * (dengan masa tenggang anti beku-massal), status dorman, sisa hari, serta aksi
 * beku/aktifkan. Murni memakai data DB — aturan datang dari MemberDormancyRule.
 */
class MemberDormancyService
{
    /** Tanggal aktivitas terakhir sesuai basis; null bila tak ada. */
    public function lastActivityDate(User $user, string $basis): ?Carbon
    {
        $ts = match ($basis) {
            MemberDormancyRule::BASIS_ORDER => PurchaseOrder::where('user_id', $user->id)
                ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DELETED])
                ->max('created_at'),
            MemberDormancyRule::BASIS_LOGIN => $user->last_login_at,
            MemberDormancyRule::BASIS_RECRUIT => User::where(fn ($q) => $q
                ->where('sponsor_id', $user->id)->orWhere('upline_id', $user->id))
                ->max('created_at'),
            default => null,
        };

        return $ts ? Carbon::parse($ts) : null;
    }

    /** Aktivitas efektif = PALING BARU dari [aktivitas basis, activated_at, created_at]. */
    public function effectiveActivityDate(User $user, MemberDormancyRule $rule): Carbon
    {
        $candidates = array_filter([
            $this->lastActivityDate($user, $rule->basis),
            $rule->activated_at,
            $user->created_at,
        ]);

        $max = null;
        foreach ($candidates as $d) {
            $c = Carbon::parse($d);
            if ($max === null || $c->greaterThan($max)) {
                $max = $c;
            }
        }

        return $max ?? now();
    }

    public function isDormant(User $user, MemberDormancyRule $rule, ?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->effectiveActivityDate($user, $rule)
            ->lessThan($now->copy()->subMonths($rule->inactive_months));
    }

    /** Sisa hari sebelum beku (0 bila sudah lewat). */
    public function atRiskDays(User $user, MemberDormancyRule $rule, ?Carbon $now = null): int
    {
        $now ??= now();
        $freezeOn = $this->effectiveActivityDate($user, $rule)->copy()->addMonths($rule->inactive_months);
        $days = (int) ceil(($freezeOn->getTimestamp() - $now->getTimestamp()) / 86400);

        return max(0, $days);
    }

    /** Bekukan: nonaktif + disabled_at + audit. Login otomatis ketolak (AuthController). */
    public function freeze(User $user, MemberDormancyRule $rule): void
    {
        $last = optional($this->lastActivityDate($user, $rule->basis))->toDateString();
        $user->update(['status' => User::STATUS_INACTIVE, 'disabled_at' => now()]);

        AuditService::log(
            action: 'auto_freeze', targetType: 'user', targetId: $user->id,
            targetUserId: $user->id, targetEmail: $user->email,
            after: ['role' => $user->role, 'basis' => $rule->basis, 'inactive_months' => $rule->inactive_months, 'last_activity' => $last],
        );
    }

    /** Aktifkan kembali (manual dari HQ). */
    public function reactivate(User $user): void
    {
        $user->update(['status' => User::STATUS_ACTIVE, 'disabled_at' => null]);

        AuditService::log(
            action: 'reactivate_member', targetType: 'user', targetId: $user->id,
            targetUserId: $user->id, targetEmail: $user->email,
        );
    }
}
