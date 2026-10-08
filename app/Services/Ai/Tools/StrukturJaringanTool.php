<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\PartnerHierarchyService;
use App\Support\PartnerHierarchy;

/**
 * Alat BACA: menu Struktur Jaringan (staf, izin manage_users — sama dgn halamannya). Data mitra = tier, upline,
 * wilayah, member ID, status; TANPA kontak (telepon/email/alamat).
 */
class StrukturJaringanTool extends BaseTool
{
    public function __construct(private PartnerHierarchyService $hierarchy) {}

    public function name(): string
    {
        return 'struktur_jaringan';
    }

    public function permission(): ?string
    {
        return 'manage_users';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff();
    }

    public function description(): string
    {
        return 'Struktur jaringan mitra (menu Struktur Jaringan): jumlah mitra per tier & per wilayah, mitra yang belum '
            .'ditempatkan (belum punya upline), upline dengan downline langsung terbanyak. Isi "cari" (nama / member ID) '
            .'untuk detail satu mitra: tier, upline, downline langsung & total seluruh downline.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cari' => ['type' => 'string', 'description' => 'Nama atau member ID mitra (opsional) — hanya bila user menyebut mitra tertentu.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        // Himpunan mitra = halaman Struktur Jaringan (semua role tier).
        $mitra = User::whereIn('role', array_keys(PartnerHierarchy::TIERS))
            ->orderBy('fullname')->get(['id', 'fullname', 'name', 'member_id', 'role', 'upline_id', 'region', 'status']);
        $nama = fn (User $u) => $u->fullname ?: $u->name;
        $anak = $mitra->groupBy('upline_id')->toBase(); // koleksi biasa: except()/map() Eloquent mengira isinya model

        $cari = mb_strtolower(trim((string) ($args['cari'] ?? '')));
        if ($cari !== '') {
            $cocok = $mitra->filter(fn (User $u) => str_contains(mb_strtolower($nama($u).' '.$u->member_id), $cari))->values();
            if ($cocok->count() !== 1) {
                return ['cari' => $cari, 'catatan' => $cocok->isEmpty()
                    ? 'Tidak ada mitra yang cocok — cek ejaan atau ulangi tanpa cari.'
                    : 'Lebih dari satu mitra cocok — sebutkan yang mana.',
                    'kandidat' => $cocok->take(10)->map(fn (User $u) => $nama($u).' ('.PartnerHierarchy::label($u->role).')')->all()];
            }
            $m = $cocok->first();
            $upline = $m->upline_id ? $mitra->firstWhere('id', $m->upline_id) : null;

            return [
                'mitra' => $nama($m), 'member_id' => $m->member_id, 'tier' => PartnerHierarchy::label($m->role),
                'wilayah' => $m->region, 'aktif' => $m->status === User::STATUS_ACTIVE,
                'upline' => $upline ? $nama($upline).' ('.PartnerHierarchy::label($upline->role).')' : null,
                'downline_langsung' => $anak->get($m->id, collect())->take(30)
                    ->map(fn (User $u) => $nama($u).' ('.PartnerHierarchy::label($u->role).')')->values()->all(),
                'jumlah_downline_langsung' => $anak->get($m->id, collect())->count(),
                'total_seluruh_downline' => $this->hierarchy->descendants($m)->count(),
            ];
        }

        // Tier teratas (Grand Distributor) memang tanpa upline; tier di bawahnya tanpa upline = belum ditempatkan.
        $belum = $mitra->filter(fn (User $u) => $u->upline_id === null && (PartnerHierarchy::levelOf($u->role) ?? 1) > 1);

        return [
            'jumlah_mitra' => $mitra->count(),
            'per_tier' => $mitra->groupBy(fn (User $u) => PartnerHierarchy::label($u->role))
                ->map(fn ($g) => ['aktif' => $g->where('status', User::STATUS_ACTIVE)->count(), 'nonaktif' => $g->where('status', '!=', User::STATUS_ACTIVE)->count()])
                ->all(),
            'per_wilayah' => $mitra->groupBy(fn (User $u) => $u->region ?: 'Belum diisi')->map->count()->sortDesc()->take(15)->all(),
            'belum_ditempatkan' => ['jumlah' => $belum->count(),
                'mitra' => $belum->take(30)->map(fn (User $u) => $nama($u).' ('.PartnerHierarchy::label($u->role).')')->values()->all()],
            'upline_downline_terbanyak' => $anak->except([''])->map->count()->sortDesc()->take(10)
                ->map(fn ($n, $id) => ['upline' => ($u = $mitra->firstWhere('id', $id)) ? $nama($u) : '—', 'downline_langsung' => $n])
                ->values()->all(),
        ];
    }
}
