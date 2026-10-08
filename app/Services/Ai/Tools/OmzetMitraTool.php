<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\ReportService;

/**
 * Alat BACA: menu Omzet Mitra (khusus staf HQ, sama dgn halamannya) — per mitra: jual ke downline (PO yang mitra
 * proses sebagai penjual) + jual ke customer akhir (nota penjualan), dari ReportService::omzetPerMitra().
 */
class OmzetMitraTool extends BaseTool
{
    public function __construct(private ReportService $reports) {}

    public function name(): string
    {
        return 'omzet_mitra';
    }

    public function permission(): ?string
    {
        return 'view_reports';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff(); // halaman Omzet Mitra khusus staf HQ
    }

    public function description(): string
    {
        return 'Omzet per mitra per bulan (menu Omzet Mitra) = penjualan MILIK mitra: jual ke downline (PO yang mitra proses '
            .'sebagai penjual) + jual ke customer akhir (nota penjualan mitra), diurutkan terbesar. BUKAN pembelian mitra dari '
            .'HQ — ranking mitra berdasarkan belanja ke HQ ada di laporan_penjualan (per_mitra).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM, atau "semua" untuk semua periode. Kosongkan = bulan ini.'],
                'cari' => ['type' => 'string', 'description' => 'Nama mitra (opsional) — hanya bila user menyebut mitra tertentu.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $bulan = $this->bulanLaporan($args['bulan'] ?? null);
        $rows = collect($this->reports->omzetPerMitra($bulan));
        $cari = mb_strtolower(trim((string) ($args['cari'] ?? '')));
        $tampil = $cari === '' ? $rows : $rows->filter(fn ($r) => str_contains(mb_strtolower($r['nama']), $cari));

        return [
            'periode' => $this->labelBulan($bulan),
            'jumlah_mitra_berjualan' => $rows->count(),
            'total_omzet_semua_mitra' => (float) $rows->sum('total'),
            // ponytail: 30 teratas biar konteks AI tak meledak; daftar lengkap di halaman Omzet Mitra.
            'per_mitra' => $tampil->take(30)->map(fn ($r) => [
                'mitra' => $r['nama'], 'tier' => $r['tier'],
                'jual_downline' => $r['jual_downline'], 'jual_customer' => $r['jual_customer'], 'total' => $r['total'],
            ])->values()->all(),
        ] + match (true) {
            // Kosong total = mitra belum mencatat penjualan sendiri; arahkan AI ke data belanja mitra ke HQ.
            $rows->isEmpty() => ['catatan' => 'Belum ada penjualan MITRA (ke downline / customer akhir) yang tercatat di periode ini. '
                .'Kalau yang ditanyakan mitra dengan belanja/omzet terbesar untuk SKINKU, panggil laporan_penjualan (per_mitra = pembelian tiap mitra ke HQ).'],
            $cari !== '' && $tampil->isEmpty() => ['catatan' => "Tidak ada mitra berjualan yang cocok dengan \"{$cari}\" di periode ini — ulangi tanpa cari, atau mitra itu memang belum berjualan."],
            default => [],
        };
    }
}
