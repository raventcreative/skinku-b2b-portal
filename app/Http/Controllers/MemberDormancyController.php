<?php

namespace App\Http\Controllers;

use App\Models\MemberDormancyRule;
use App\Models\User;
use App\Services\MemberDormancyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Panel HQ Dormansi Member: atur aturan per-role, lihat member beku & akan-beku,
 * dan aktifkan kembali (manual). Gate manage_member_dormancy.
 */
class MemberDormancyController extends Controller
{
    /** Role member yang diatur dormansinya (Fase 1). */
    public const MANAGED_ROLES = [
        User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_DISTRIBUTOR,
        User::ROLE_RESELLER, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD,
        User::ROLE_SPONSOR,
    ];

    public function __construct(private MemberDormancyService $svc) {}

    public function index()
    {
        $rules = MemberDormancyRule::whereIn('role', self::MANAGED_ROLES)->get()->keyBy('role');
        $now = now();

        $frozen = User::whereIn('role', self::MANAGED_ROLES)
            ->where('status', User::STATUS_INACTIVE)->whereNotNull('disabled_at')
            ->orderByDesc('disabled_at')->get();

        $atRisk = collect();
        foreach ($rules->where('enabled', true) as $rule) {
            User::where('role', $rule->role)->where('status', User::STATUS_ACTIVE)->get()
                ->each(function (User $u) use ($rule, $now, $atRisk) {
                    if (! $this->svc->isDormant($u, $rule, $now)) {
                        $days = $this->svc->atRiskDays($u, $rule, $now);
                        if ($days <= 14) {
                            $atRisk->push(['user' => $u, 'days' => $days, 'basis' => $rule->basis]);
                        }
                    }
                });
        }

        return view('member_dormancy.index', [
            'rules' => $rules,
            'managedRoles' => self::MANAGED_ROLES,
            'bases' => MemberDormancyRule::BASES,
            'frozen' => $frozen,
            'atRisk' => $atRisk->sortBy('days')->values(),
        ]);
    }

    public function saveRules(Request $request): RedirectResponse
    {
        $request->validate([
            'rules' => ['array'],
            'rules.*.inactive_months' => ['required', 'integer', 'min:1', 'max:60'],
            'rules.*.basis' => ['required', Rule::in(MemberDormancyRule::BASES)],
        ]);

        foreach (self::MANAGED_ROLES as $role) {
            $enabled = $request->boolean("rules.{$role}.enabled");
            $rule = MemberDormancyRule::firstOrNew(['role' => $role]);
            if ($enabled && ! $rule->enabled) {
                $rule->activated_at = now(); // mulai masa tenggang saat OFF→ON
            }
            $rule->fill([
                'enabled' => $enabled,
                'inactive_months' => (int) $request->input("rules.{$role}.inactive_months", 3),
                'basis' => (string) $request->input("rules.{$role}.basis", MemberDormancyRule::BASIS_LOGIN),
                'updated_by' => $request->user()->id,
            ])->save();
        }

        return back()->with('status', 'Aturan dormansi disimpan.');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->svc->reactivate($user);

        return back()->with('status', "@{$user->username} diaktifkan kembali.");
    }
}
