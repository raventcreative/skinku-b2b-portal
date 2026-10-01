<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\HqStockReportService;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: Laporan Mutasi Stok HQ (sama persis dengan menu Laporan Stok HQ).
 * Izin = izin route menunya (manage_hq_stock) — role tanpa akses menu tak dapat alat ini.
 */
class LaporanStokHqTool extends BaseTool
{
    public function __construct(private HqStockReportService $report) {}

    public function name(): string
    {
        return 'laporan_stok_hq';
    }

    public function permission(): ?string
    {
        return 'manage_hq_stock';
    }

    public function description(): string
    {
        return 'Ambil Laporan Mutasi Stok HQ (gudang pusat) per produk: stok awal, produksi, penyesuaian, '
            .'keluar TikTok, Shopee, Reseller/Distributor (PO mitra + Paket Join), keluar lain, stok akhir. '
            .'Harian atau bulanan. Pakai untuk pertanyaan stok gudang/HQ.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['harian', 'bulanan'], 'description' => 'Default harian.'],
                'tanggal' => ['type' => 'string', 'description' => 'YYYY-MM-DD (harian) atau YYYY-MM (bulanan). Kosongkan untuk hari/bulan ini.'],
                'produk' => ['type' => 'string', 'description' => 'Saring nama/SKU produk (opsional).'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $mode = ($args['mode'] ?? '') === 'bulanan' ? 'bulanan' : 'harian';
        $t = (string) ($args['tanggal'] ?? '');
        $anchor = match (true) {
            (bool) $this->tanggal($t) => Carbon::parse($t),
            (bool) preg_match('/^\d{4}-\d{2}$/', $t) => Carbon::parse($t.'-01'),
            default => Carbon::now(),
        };

        $rep = $this->report->report($mode, $anchor);
        $cari = mb_strtolower(trim((string) ($args['produk'] ?? '')));

        $rows = collect($rep['rows'])
            ->filter(fn ($r) => $cari === '' || str_contains(mb_strtolower($r['product']->name.' '.$r['product']->sku), $cari))
            ->map(fn ($r) => [
                'produk' => $r['product']->name,
                'sku' => $r['product']->sku,
                'awal' => $r['awal'],
                'produksi' => $r['produksi'],
                'masuk_lain' => $r['masuk_lain'],
                'penyesuaian' => $r['penyesuaian'],
                'tiktok' => $r['tiktok'],
                'shopee' => $r['shopee'],
                'reseller_distributor' => $r['reseller'],
                'keluar_lain' => $r['keluar_lain'],
                'akhir' => $r['akhir'],
            ]);

        return [
            'periode' => $rep['label'],
            'jumlah_produk' => $rows->count(),
            'total' => $rep['totals'],
            // ponytail: dipotong 50 baris biar konteks AI tak meledak; saring pakai 'produk' bila perlu.
            'produk' => $rows->take(50)->values()->all(),
        ];
    }
}
