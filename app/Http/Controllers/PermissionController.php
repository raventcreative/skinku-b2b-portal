<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PermissionController extends Controller
{
    public function index()
    {
        return view('permissions.index', [
            'definitions' => Permissions::DEFINITIONS,
            'roles' => Role::ordered()->get(),
            'matrix' => Permissions::matrix(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Permissions::save($request->input('perm', []));

        AuditService::log(action: 'update_permissions', targetType: 'system', after: ['updated' => true]);

        return back()->with('status', 'Hak akses tiap role berhasil diperbarui.');
    }

    /** Create a custom role. */
    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
        ]);

        $name = Str::slug($data['label'], '_');
        if ($name === '' || Role::where('name', $name)->exists()) {
            return back()->withErrors(['label' => 'Nama role tidak valid atau sudah ada. Coba label lain.']);
        }

        $role = Role::create([
            'name' => $name,
            'label' => $data['label'],
            'is_system' => false,
            'sort_order' => (int) Role::max('sort_order') + 1,
        ]);
        Permissions::flushCache();

        AuditService::log(action: 'create_role', targetType: 'role', targetId: $role->id, after: ['name' => $role->name]);

        return back()->with('status', "Role \"{$role->label}\" dibuat. Atur hak aksesnya di tabel, lalu Simpan.");
    }

    /** Delete a custom role (system roles & roles in use are protected). */
    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->withErrors(['role' => 'Role bawaan tidak dapat dihapus.']);
        }
        if (User::where('role', $role->name)->exists()) {
            return back()->withErrors(['role' => "Role \"{$role->label}\" masih dipakai user. Pindahkan user-nya dulu."]);
        }

        RolePermission::where('role', $role->name)->delete();
        $name = $role->label;
        $role->delete();
        Permissions::flushCache();

        AuditService::log(action: 'delete_role', targetType: 'role', after: ['name' => $name]);

        return back()->with('status', "Role \"{$name}\" dihapus.");
    }
}
