<?php

namespace App\Services;

use App\Models\User;
use App\Support\PartnerHierarchy;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Integritas pohon mitra + Member ID. Dipakai form Anggota (Kelola Anggota).
 */
class PartnerHierarchyService
{
    /** Validasi + set upline_id (TIDAK save). null = belum ditempatkan (boleh). */
    public function assignUpline(User $user, ?int $uplineId): void
    {
        if ($uplineId === null) {
            $user->upline_id = null;

            return;
        }

        if ($uplineId === $user->id) {
            $this->fail('Tidak bisa menjadikan diri sendiri sebagai upline.');
        }

        $upline = User::find($uplineId);
        if (! $upline) {
            $this->fail('Upline tidak ditemukan.');
        }

        $allowed = PartnerHierarchy::allowedParentRoles($user->role);
        if (! in_array($upline->role, $allowed, true)) {
            $this->fail($allowed
                ? 'Upline harus tepat satu tingkat di atas ('.implode('/', $allowed).').'
                : 'Tier ini tidak boleh punya upline (langsung di bawah HQ).');
        }

        // Catatan: dengan tangga-tetap (level induk = level anak - 1), siklus mustahil —
        // cek level di atas sudah mencegahnya. Tak perlu penelusuran siklus.
        $user->upline_id = $upline->id;
    }

    public function generateMemberId(): string
    {
        $max = (int) User::whereNotNull('member_id')
            ->where('member_id', 'like', 'SKN-%')
            ->get()
            ->map(fn (User $u) => (int) substr($u->member_id, 4))
            ->max();

        return 'SKN-'.str_pad($max + 1, 6, '0', STR_PAD_LEFT);
    }

    /** Isi member_id bila user mitra & belum punya (TIDAK save). */
    public function ensureMemberId(User $user): void
    {
        if ($user->member_id === null && $user->isPartner()) {
            $user->member_id = $this->generateMemberId();
        }
    }

    public function hasActiveDownline(User $user): bool
    {
        return User::where('upline_id', $user->id)
            ->where('status', User::STATUS_ACTIVE)
            ->exists();
    }

    /** Kandidat upline untuk sebuah role; region sama diutamakan. */
    public function eligibleUplines(string $role, ?string $region): Collection
    {
        $parents = PartnerHierarchy::allowedParentRoles($role);
        if (empty($parents)) {
            return collect();
        }

        return User::whereIn('role', $parents)
            ->where('status', User::STATUS_ACTIVE)
            ->orderByRaw('CASE WHEN region = ? THEN 0 ELSE 1 END', [$region])
            ->orderBy('fullname')
            ->get();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['upline_id' => $message]);
    }
}
