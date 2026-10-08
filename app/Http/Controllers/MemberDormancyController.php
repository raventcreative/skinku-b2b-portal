<?php

namespace App\Http\Controllers;

use App\Models\MemberDormancyRule;
use App\Models\Role;
use App\Models\User;
use App\Services\MemberDormancyService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Panel HQ Dormansi Member: atur aturan per-role, lihat member beku & akan-beku,
 * dan aktifkan kembali (manual). Gate manage_member_dormancy.
 */
class MemberDormancyController extends Controller
{
    public function __construct(private MemberDormancyService $svc) {}

    /**
     * Semua role yang bisa diatur dormansinya = SELURUH role KECUALI super_admin
     * (super_admin tak boleh dibekukan). Dinamis dari tabel roles → role baru
     * (termasuk custom) otomatis ikut, tanpa ubah kode.
     *
     * @return array<int,string>
     */
    public static function managedRoles(): array
    {
        $roles = Role::ordered()->pluck('name')->all();
        if ($roles === []) {
            $roles = Permissions::roleNames(); // fallback pra-seed
        }

        return array_values(array_filter($roles, fn ($r) => $r !== User::ROLE_SUPER_ADMIN));
    }

    public function index()
    {
        $managed = self::managedRoles();
        $panel = $this->svc->panel($managed); // sama dgn alat Asisten AI dormansi_member

        return view('member_dormancy.index', [
            'rules' => $panel['rules'],
            'managedRoles' => $managed,
            'roleLabels' => Role::ordered()->pluck('label', 'name'),
            'bases' => MemberDormancyRule::BASES,
            'frozen' => $panel['frozen'],
            'atRisk' => $panel['atRisk'],
            'held' => $panel['held'],
        ]);
    }

    public function saveRules(Request $request): RedirectResponse
    {
        $request->validate([
            'rules' => ['array'],
            'rules.*.inactive_months' => ['required', 'integer', 'min:1', 'max:60'],
            'rules.*.basis' => ['required', Rule::in(MemberDormancyRule::BASES)],
        ]);

        foreach (self::managedRoles() as $role) {
            if (! $request->has("rules.{$role}")) {
                continue;
            }
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
        // super_admin tak pernah jadi target dormansi → tak bisa di-reaktivasi lewat sini.
        abort_unless(in_array($user->role, self::managedRoles(), true), 403);

        $this->svc->reactivate($user);

        return back()->with('status', "@{$user->username} diaktifkan kembali.");
    }
}
