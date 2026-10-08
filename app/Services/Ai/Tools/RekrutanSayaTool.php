<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\CommissionService;
use App\Support\PartnerHierarchy;

/**
 * Alat BACA: menu Rekrutan Saya (mitra yang punya rekrutan) — HANYA rekrutan akun itu + penghasilan dari mereka,
 * dari CommissionService::ringkasanRekrutan() yang sama dgn halamannya. Tanpa kontak rekrutan.
 */
class RekrutanSayaTool extends BaseTool
{
    public function __construct(private CommissionService $commissions) {}

    public function name(): string
    {
        return 'rekrutan_saya';
    }

    public function availableFor(User $user): bool
    {
        return $user->isPartner() && $user->recruits()->exists(); // syarat menu Rekrutan Saya muncul
    }

    public function description(): string
    {
        return 'Rekrutan Saya (khusus mitra): mitra yang Anda rekrut (Anda sponsornya) + penghasilan dari tiap rekrutan '
            .'(bonus join + RO cashback), total bonus, rekrutan baru bulan ini, dan saldo komisi yang bisa ditarik.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function run(array $args, User $user): array
    {
        $r = $this->commissions->ringkasanRekrutan($user);
        $awalBulan = now()->startOfMonth();

        return [
            'catatan' => 'Hanya rekrutan akun Anda.',
            'jumlah_rekrutan' => $r['recruits']->count(),
            'rekrutan_baru_bulan_ini' => $r['recruits']->filter(fn (User $u) => $u->created_at?->gte($awalBulan))->count(),
            'total_bonus_join' => $r['totalJoin'],
            'total_ro_cashback' => $r['totalRo'],
            'saldo_bisa_ditarik' => $r['available'],
            // ponytail: 50 terbaru; daftar lengkap di halaman Rekrutan Saya.
            'rekrutan' => $r['recruits']->take(50)->map(fn (User $u) => [
                'nama' => $u->fullname ?: $u->name, 'tier' => PartnerHierarchy::label($u->role), 'member_id' => $u->member_id,
                'bergabung' => $u->created_at?->format('Y-m-d'), 'penghasilan' => (float) ($r['earnByRecruit'][$u->id] ?? 0),
            ])->values()->all(),
        ];
    }
}
