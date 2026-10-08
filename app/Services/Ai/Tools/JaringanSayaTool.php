<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\NetworkSummaryService;

/**
 * Alat BACA: menu Jaringan Saya (mitra yang punya downline) — HANYA pohon downline akun itu, dari
 * NetworkSummaryService yang sama dgn halamannya (agregat, tanpa nama/kontak customer downline).
 */
class JaringanSayaTool extends BaseTool
{
    public function __construct(private NetworkSummaryService $summary) {}

    public function name(): string
    {
        return 'jaringan_saya';
    }

    public function availableFor(User $user): bool
    {
        return $user->isPartner() && $user->downlines()->exists(); // syarat menu Jaringan Saya muncul
    }

    public function description(): string
    {
        return 'Jaringan Saya (khusus mitra): ringkasan seluruh downline akun Anda — jumlah anggota, yang aktif jualan 30 hari '
            .'terakhir, omzet jaringan bulan ini (penjualan downline ke customer akhir), dan per anggota: tier, omzet & '
            .'transaksi bulan ini, tren 3 bulan, jumlah downline-nya. Hanya jaringan akun Anda.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function run(array $args, User $user): array
    {
        $s = $this->summary->summarize($user);
        $baris = [];
        $ratakan = function (array $nodes, string $upline) use (&$ratakan, &$baris, $s) {
            foreach ($nodes as $n) {
                $baris[] = [
                    'nama' => $n['name'], 'tier' => $n['tier'], 'member_id' => $n['member_id'], 'upline' => $upline,
                    'nonaktif' => $n['nonaktif'], 'omzet_bulan_ini' => $n['omzet'], 'transaksi_bulan_ini' => $n['trx'],
                    'tren_3_bulan' => array_combine($s['trenLabels'], $n['tren']), 'arah' => $n['tren_arah'],
                    'aktif_jualan_30_hari' => $n['aktif'], 'jumlah_downline' => $n['downline_count'],
                ];
                $ratakan($n['children'], $n['name']);
            }
        };
        $ratakan($s['tree'], 'Anda');
        usort($baris, fn ($a, $b) => $b['omzet_bulan_ini'] <=> $a['omzet_bulan_ini']);

        return [
            'periode' => $s['periode'],
            'catatan' => 'Hanya jaringan akun Anda. Omzet = penjualan downline ke customer akhir (nota penjualan), bukan pembelian ke Anda.',
            'total_anggota' => $s['totalMembers'],
            'aktif_jualan_30_hari' => $s['activeCount'],
            'omzet_jaringan_bulan_ini' => $s['networkOmzet'],
            // ponytail: 50 anggota omzet terbesar biar konteks AI tak meledak; pohon lengkap di halaman Jaringan Saya.
            'anggota' => array_slice($baris, 0, 50),
        ];
    }
}
