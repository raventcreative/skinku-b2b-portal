<?php

namespace App\Services\Ai\Tools;

use App\Http\Controllers\MemberDormancyController;
use App\Models\User;
use App\Services\MemberDormancyService;
use App\Support\PartnerHierarchy;

/**
 * Alat BACA: menu Dormansi Member (staf, izin manage_member_dormancy) — panel yang sama dgn halamannya
 * (MemberDormancyService::panel) + mitra aktif yang paling lama tidak order (definisi "order" = basis dormansi).
 */
class DormansiMemberTool extends BaseTool
{
    private const BASIS = ['order' => 'tidak order (PO)', 'login' => 'tidak login', 'recruit' => 'tidak merekrut'];

    public function __construct(private MemberDormancyService $dormansi) {}

    public function name(): string
    {
        return 'dormansi_member';
    }

    public function permission(): ?string
    {
        return 'manage_member_dormancy';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff();
    }

    public function description(): string
    {
        return 'Dormansi Member (menu Dormansi Member): aturan pembekuan otomatis per role, member yang sudah dibekukan, yang '
            .'akan dibekukan ≤ 14 hari lagi, yang ditahan karena masih punya downline aktif, dan mitra aktif yang paling lama '
            .'tidak order (tanggal PO terakhir). Pakai untuk "mitra mana yang lama tidak order / perlu di-follow up".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function run(array $args, User $user): array
    {
        $panel = $this->dormansi->panel(MemberDormancyController::managedRoles());
        $nama = fn (User $u) => $u->fullname ?: ($u->name ?: $u->username);
        $tier = fn (User $u) => PartnerHierarchy::label($u->role);
        $basis = fn (?string $b) => self::BASIS[$b] ?? $b;

        // Mitra aktif (role tier) — urut paling lama tidak order; belum pernah order = paling atas.
        $mitra = User::whereIn('role', array_keys(PartnerHierarchy::TIERS))->where('status', User::STATUS_ACTIVE)
            ->get(['id', 'fullname', 'name', 'username', 'role', 'created_at']);
        $terakhir = $this->dormansi->terakhirOrder($mitra->pluck('id')->all());
        $lama = $mitra->map(fn (User $u) => [
            'mitra' => $nama($u), 'tier' => $tier($u),
            'order_terakhir' => isset($terakhir[$u->id]) ? $terakhir[$u->id]->format('Y-m-d') : 'belum pernah order',
            'hari_sejak_order' => isset($terakhir[$u->id]) ? (int) $terakhir[$u->id]->diffInDays(now()) : null,
            'bergabung' => $u->created_at?->format('Y-m-d'),
        ])->sortByDesc(fn ($r) => $r['hari_sejak_order'] ?? PHP_INT_MAX)->values();

        return [
            'aturan' => $panel['rules']->mapWithKeys(fn ($r) => [PartnerHierarchy::label($r->role) => [
                'aktif' => (bool) $r->enabled, 'beku_setelah_bulan' => $r->inactive_months, 'basis' => $basis($r->basis),
            ]])->all(),
            'sudah_dibekukan' => ['jumlah' => $panel['frozen']->count(), 'member' => $panel['frozen']->take(20)
                ->map(fn (User $u) => ['nama' => $nama($u), 'role' => $tier($u), 'dibekukan' => $u->disabled_at?->format('Y-m-d')])->values()->all()],
            'akan_dibekukan_14_hari' => $panel['atRisk']->take(20)
                ->map(fn ($r) => ['nama' => $nama($r['user']), 'role' => $tier($r['user']), 'sisa_hari' => $r['days'], 'basis' => $basis($r['basis'])])->all(),
            'ditahan_punya_downline_aktif' => $panel['held']->take(20)
                ->map(fn ($r) => ['nama' => $nama($r['user']), 'role' => $tier($r['user']), 'basis' => $basis($r['basis'])])->all(),
            // ponytail: 30 terlama biar konteks AI tak meledak.
            'mitra_paling_lama_tidak_order' => $lama->take(30)->all(),
            'catatan' => 'Aturan yang aktif=false tidak membekukan siapa pun. "Order" = PO apa pun selain batal/terhapus.',
        ];
    }
}
