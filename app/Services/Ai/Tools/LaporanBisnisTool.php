<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\BusinessReportService;

/**
 * Alat BACA: menu Generate Report (laporan bisnis per periode + pembanding) — memakai ringkasan angka yang sama dgn
 * analisis AI di halaman itu (`insight_input`: tanpa data pribadi). Izin = view_reports + staf (menu staf saja).
 * Bagian KOL & laba rugi ikut izin pengguna di BusinessReportService (laba rugi = Akuntansi + Lihat HPP).
 */
class LaporanBisnisTool extends BaseTool
{
    public function __construct(private BusinessReportService $laporan) {}

    public function name(): string
    {
        return 'laporan_bisnis';
    }

    public function permission(): ?string
    {
        return 'view_reports';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff(); // Generate Report khusus staf HQ
    }

    public function description(): string
    {
        return 'Laporan bisnis lengkap per periode dengan pembanding periode sebelumnya (menu Generate Report): omzet & order '
            .'per channel (PO mitra, TikTok, Shopee) + tingkat batal, produk terlaris, mitra (baru, yang order, tidak order, retur, '
            .'status PO), stok menipis/menumpuk, KOL & laba rugi (bila berizin). Pakai untuk evaluasi/perbandingan periode.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jenis' => ['type' => 'string', 'enum' => array_keys(BusinessReportService::JENIS), 'description' => 'Default bulanan.'],
                'acuan' => ['type' => 'string', 'description' => 'Periode acuan: bulanan YYYY-MM, mingguan YYYY-MM-DD (hari mana pun di minggu itu), kuartal YYYY-Q1..Q4, tahunan YYYY. Kosongkan = periode berjalan.'],
                'dari' => ['type' => 'string', 'description' => 'YYYY-MM-DD — hanya untuk jenis custom.'],
                'sampai' => ['type' => 'string', 'description' => 'YYYY-MM-DD — hanya untuk jenis custom.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $teks = fn (string $k) => is_string($args[$k] ?? null) ? $args[$k] : null;
        $p = $this->laporan->period($teks('jenis') ?? 'bulanan', $teks('acuan'), $this->tanggal($teks('dari')), $this->tanggal($teks('sampai')));
        $r = $this->laporan->build($p, $user);

        $akses = array_filter([
            $r['keuangan'] === null ? 'Laba rugi tidak ditampilkan (butuh izin Akuntansi + Lihat HPP).' : null,
            $r['kol'] === null ? 'Data KOL tidak ditampilkan (butuh izin affiliate KOL).' : null,
        ]);

        return $r['insight_input'] + ($akses ? ['catatan_akses' => implode(' ', $akses)] : []);
    }
}
