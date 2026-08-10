<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\PartnerHierarchyService;
use App\Support\PartnerHierarchy;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PartnerHierarchyController extends Controller
{
    public function __construct(private PartnerHierarchyService $hierarchy) {}

    public function index()
    {
        // Data node flat untuk kanvas (JS bangun pohon + kolam dari upline_id).
        $partners = User::whereIn('role', array_keys(PartnerHierarchy::TIERS))
            ->orderBy('fullname')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->fullname ?: $u->name,
                'member_id' => $u->member_id,
                'role' => $u->role,
                'tier' => PartnerHierarchy::label($u->role),
                'upline_id' => $u->upline_id,
                'region' => $u->region,
                'stockist' => PartnerHierarchy::holdsStock($u->role),
            ])
            ->values();

        return view('struktur_jaringan.index', ['partners' => $partners]);
    }

    /**
     * Drag & drop: set/ubah upline seorang mitra (upline_id null = lepas ke
     * "belum ditempatkan"). Mengubah MASTER user langsung (kelihatan di Kelola
     * Anggota). Integritas 1-tingkat & anti-siklus dijaga PartnerHierarchyService.
     */
    public function place(Request $request, User $user)
    {
        $data = $request->validate([
            'upline_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->hierarchy->assignUpline($user, $data['upline_id'] ?? null);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->validator->errors()->first('upline_id'),
            ], 422);
        }

        $this->hierarchy->ensureMemberId($user);
        $user->save();

        AuditService::log(
            action: 'place_partner',
            targetType: 'user',
            targetId: $user->id,
            after: ['upline_id' => $user->upline_id, 'member_id' => $user->member_id],
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return response()->json(['ok' => true, 'member_id' => $user->member_id]);
    }

    /**
     * Ubah tier (role) seorang mitra. Diblok bila masih punya downline aktif
     * (cegah struktur pecah). Upline lama di-reset bila tak valid utk tier baru.
     */
    public function changeTier(Request $request, User $user)
    {
        $role = (string) $request->input('role');
        if (! PartnerHierarchy::isTierRole($role)) {
            return response()->json(['ok' => false, 'error' => 'Tier tidak valid.'], 422);
        }

        if ($this->hierarchy->hasActiveDownline($user)) {
            return response()->json([
                'ok' => false,
                'error' => 'Mitra ini masih punya downline. Lepas/pindahkan downline-nya dulu sebelum ubah tier.',
            ], 422);
        }

        $user->role = $role;

        // Upline lama mungkin tak lagi sah untuk tier baru → lepaskan.
        if ($user->upline_id) {
            $upline = User::find($user->upline_id);
            $allowed = PartnerHierarchy::allowedParentRoles($user->role);
            if (! $upline || ! in_array($upline->role, $allowed, true)) {
                $user->upline_id = null;
            }
        }

        $this->hierarchy->ensureMemberId($user);
        $user->save();

        AuditService::log(
            action: 'change_tier',
            targetType: 'user',
            targetId: $user->id,
            after: ['role' => $user->role, 'upline_id' => $user->upline_id],
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return response()->json(['ok' => true]);
    }
}
