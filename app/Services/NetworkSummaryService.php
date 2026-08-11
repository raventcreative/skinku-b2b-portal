<?php

namespace App\Services;

use App\Models\PartnerSale;
use App\Models\User;
use App\Support\PartnerHierarchy;
use Illuminate\Support\Carbon;

/**
 * Ringkasan performa subtree mitra untuk halaman "Jaringan Saya".
 * Read-only, agregat; TANPA nama/kontak customer downline (privasi antar-mitra).
 */
class NetworkSummaryService
{
    private const BULAN_PENDEK = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];

    private const BULAN_PANJANG = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

    public function __construct(private PartnerHierarchyService $hierarchy) {}

    /**
     * @return array{
     *   tree: array,
     *   totalMembers: int,
     *   activeCount: int,
     *   networkOmzet: float,
     *   periode: string,
     *   trenLabels: array<int,string>
     * }
     */
    public function summarize(User $root): array
    {
        $members = $this->hierarchy->descendants($root); // Collection<User>, tanpa $root
        $ids = $members->pluck('id')->all();

        // Jendela 3 bulan (bulan ini + 2 sebelumnya). Menutup juga cek aktif-30-hari.
        $today = Carbon::today();
        $windowStart = $today->copy()->startOfMonth()->subMonthsNoOverflow(2);
        $activeSince = $today->copy()->subDays(30);
        $thisMonthKey = $today->format('Y-m');
        $monthKeys = [
            $today->copy()->subMonthsNoOverflow(2)->format('Y-m'),
            $today->copy()->subMonthsNoOverflow(1)->format('Y-m'),
            $thisMonthKey,
        ];

        // Satu query untuk seluruh subtree. Hanya kolom agregat — TIDAK menyeleksi
        // customer_name / notes (privasi). Agregasi di PHP (portabel SQLite/MySQL).
        $metrics = [];
        if ($ids) {
            $rows = PartnerSale::query()
                ->whereIn('user_id', $ids)
                ->where('sold_at', '>=', $windowStart->toDateString())
                ->get(['user_id', 'total_amount', 'sold_at']);

            foreach ($rows as $row) {
                $uid = (int) $row->user_id;
                $key = $row->sold_at->format('Y-m');
                $amt = (float) $row->total_amount;

                if (! isset($metrics[$uid])) {
                    $metrics[$uid] = ['omzet' => 0.0, 'trx' => 0, 'tren' => array_fill_keys($monthKeys, 0.0), 'aktif' => false];
                }
                if (array_key_exists($key, $metrics[$uid]['tren'])) {
                    $metrics[$uid]['tren'][$key] += $amt;
                }
                if ($key === $thisMonthKey) {
                    $metrics[$uid]['omzet'] += $amt;
                    $metrics[$uid]['trx']++;
                }
                if ($row->sold_at->gte($activeSince)) {
                    $metrics[$uid]['aktif'] = true;
                }
            }
        }

        // Jumlah downline langsung per anggota (dari koleksi, bukan query baru).
        $childCount = $members->groupBy('upline_id')->map->count();
        $childrenOf = $members->groupBy('upline_id');

        // View-model per node (tanpa children).
        $nodeOf = function (User $u) use ($metrics, $childCount, $monthKeys) {
            $m = $metrics[$u->id] ?? ['omzet' => 0.0, 'trx' => 0, 'tren' => array_fill_keys($monthKeys, 0.0), 'aktif' => false];
            $tren = array_values($m['tren']);
            $arah = $tren[2] > $tren[1] ? 'naik' : ($tren[2] < $tren[1] ? 'turun' : 'datar');

            return [
                'id' => $u->id,
                'name' => $u->fullname ?: $u->name,
                'member_id' => $u->member_id,
                'tier' => PartnerHierarchy::label($u->role),
                'level' => PartnerHierarchy::levelOf($u->role) ?? 9,
                'region' => $u->region,
                'nonaktif' => $u->status !== User::STATUS_ACTIVE,
                'omzet' => $m['omzet'],
                'trx' => $m['trx'],
                'tren' => $tren,
                'tren_arah' => $arah,
                'aktif' => $m['aktif'],
                'downline_count' => $childCount[$u->id] ?? 0,
            ];
        };

        // Bangun pohon nested rekursif mulai dari anak langsung $root.
        $build = function ($parentId) use (&$build, $childrenOf, $nodeOf) {
            return $childrenOf->get($parentId, collect())
                ->sortBy(fn (User $u) => sprintf('%d-%s', PartnerHierarchy::levelOf($u->role) ?? 9, $u->fullname))
                ->map(fn (User $u) => $nodeOf($u) + ['children' => $build($u->id)])
                ->values()
                ->all();
        };
        $tree = $build($root->id);

        // Roll-up jaringan.
        $activeCount = 0;
        $networkOmzet = 0.0;
        foreach ($members as $u) {
            $networkOmzet += $metrics[$u->id]['omzet'] ?? 0.0;
            if ($metrics[$u->id]['aktif'] ?? false) {
                $activeCount++;
            }
        }

        return [
            'tree' => $tree,
            'totalMembers' => $members->count(),
            'activeCount' => $activeCount,
            'networkOmzet' => $networkOmzet,
            'periode' => self::BULAN_PANJANG[(int) $today->format('n')].' '.$today->format('Y'),
            'trenLabels' => array_map(fn ($k) => self::BULAN_PENDEK[(int) substr($k, 5, 2)], $monthKeys),
        ];
    }
}
