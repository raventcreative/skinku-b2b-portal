<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /** Roles a normal admin may assign/manage. */
    private const ADMIN_MANAGEABLE_ROLES = [
        User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER,
    ];

    public function index(Request $request)
    {
        $filters = $request->only(['q', 'role', 'status']);

        $users = User::query()
            ->when($filters['q'] ?? null, function ($query, $q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('fullname', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('username', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('company_name', 'like', "%{$q}%");
                });
            })
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'role' => ['required', Rule::in(Role::pluck('name')->all())],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ]);

        $this->assertCanAssignRole($actor, $data['role']);

        $user = User::create([
            'name' => $data['fullname'],
            'fullname' => $data['fullname'],
            'email' => mb_strtolower($data['email']),
            'username' => mb_strtolower($data['username']),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'company_name' => $data['company_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'region' => $data['region'] ?? null,
            'status' => $data['status'],
            'created_by' => $actor->id,
        ]);

        AuditService::log(
            action: 'create_user',
            targetType: 'user',
            targetId: $user->id,
            after: ['username' => $user->username, 'email' => $user->email, 'role' => $user->role, 'status' => $user->status],
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return back()->with('status', "Akun {$user->fullname} berhasil dibuat.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->assertCanManageTarget($actor, $user);

        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'role' => ['required', Rule::in(Role::pluck('name')->all())],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ]);

        // Changing TO a privileged role requires super_admin.
        if ($data['role'] !== $user->role) {
            $this->assertCanAssignRole($actor, $data['role']);
        }

        $before = $user->only(['fullname', 'email', 'username', 'role', 'status', 'company_name']);

        $user->fill([
            'name' => $data['fullname'],
            'fullname' => $data['fullname'],
            'email' => mb_strtolower($data['email']),
            'username' => mb_strtolower($data['username']),
            'role' => $data['role'],
            'company_name' => $data['company_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'region' => $data['region'] ?? null,
            'status' => $data['status'],
            'updated_by' => $actor->id,
        ]);
        $user->disabled_at = $data['status'] === User::STATUS_INACTIVE ? ($user->disabled_at ?? now()) : null;
        $user->save();

        AuditService::log(
            action: 'update_user',
            targetType: 'user',
            targetId: $user->id,
            before: $before,
            after: $user->only(['fullname', 'email', 'username', 'role', 'status', 'company_name']),
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return back()->with('status', "Profil {$user->fullname} berhasil diperbarui.");
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->assertCanManageTarget($actor, $user);

        if ($user->status === User::STATUS_DELETED) {
            return back()->withErrors(['user' => 'User yang sudah dihapus tidak dapat diaktifkan.']);
        }

        $before = $user->status;
        $user->status = $user->status === User::STATUS_ACTIVE ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;
        $user->disabled_at = $user->status === User::STATUS_INACTIVE ? now() : null;
        $user->updated_by = $actor->id;
        $user->save();

        AuditService::log(
            action: $user->status === User::STATUS_ACTIVE ? 'enable_user' : 'disable_user',
            targetType: 'user',
            targetId: $user->id,
            before: ['status' => $before],
            after: ['status' => $user->status],
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return back()->with('status', "Status {$user->fullname} diubah menjadi {$user->status}.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->assertCanManageTarget($actor, $user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user->password = Hash::make($data['password']);
        $user->updated_by = $actor->id;
        $user->save();

        AuditService::log(
            action: 'admin_reset_password',
            targetType: 'user',
            targetId: $user->id,
            after: ['password' => '***'],
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return back()->with('status', "Password {$user->fullname} berhasil direset.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        // Gated by the configurable "delete_users" capability; never a super_admin / self.
        if (! $actor->canDo('delete_users')) {
            abort(403, 'Anda tidak memiliki hak akses untuk menghapus pengguna.');
        }
        if ($user->id === $actor->id) {
            return back()->withErrors(['user' => 'Anda tidak dapat menghapus akun Anda sendiri.']);
        }
        if ($user->isSuperAdmin()) {
            return back()->withErrors(['user' => 'Akun Super Admin tidak dapat dihapus.']);
        }

        $before = $user->only(['fullname', 'email', 'role', 'status']);

        // Soft delete: flag status + Eloquent soft delete (preserves PO history).
        $user->status = User::STATUS_DELETED;
        $user->updated_by = $actor->id;
        $user->save();
        $user->delete();

        AuditService::log(
            action: 'delete_user',
            targetType: 'user',
            targetId: $user->id,
            before: $before,
            after: ['status' => User::STATUS_DELETED],
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return back()->with('status', "Akun {$user->fullname} berhasil dihapus (soft delete).");
    }

    /* ----------------------- privilege helpers ----------------------- */

    private function assertCanAssignRole(User $actor, string $role): void
    {
        if (in_array($role, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true) && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'role' => 'Admin biasa hanya boleh mengelola gudang, distributor, atau reseller.',
            ]);
        }
    }

    private function assertCanManageTarget(User $actor, User $target): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        // A normal admin cannot touch super_admin or admin accounts.
        if (in_array($target->role, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true)) {
            throw ValidationException::withMessages([
                'user' => 'Hanya Super Admin yang berwenang mengelola akun admin/super_admin.',
            ]);
        }
    }
}
