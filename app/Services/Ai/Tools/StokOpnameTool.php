<?php

namespace App\Services\Ai\Tools;

use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: riwayat stok opname gudang pusat (menu Stok Opname, route stok-opname.index). Izin sama dgn halamannya
 * (manage_hq_stock). Opname tak punya tabel sendiri: StockOpnameController@store menulis satu mutasi HQ ADJUSTMENT
 * (reference_type 'opname') per produk yang BERUBAH, bertanggal 1 detik sebelum tanggal opname (= saldo awal hari itu).
 */
class StokOpnameTool extends BaseTool
{
    public function name(): string
    {
        return 'stok_opname';
    }

    public function permission(): ?string
    {
        return 'manage_hq_stock';
    }

    public function description(): string
    {
        return 'Riwayat stok opname gudang pusat (menu Stok Opname): tanggal opname, jumlah produk yang disesuaikan, '
            .'selisih per produk (hitungan fisik − stok sistem). Hanya produk yang berubah yang tercatat. Stok saat ini & '
            .'mutasi lengkap ada di laporan_stok_hq.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sesi' => ['type' => 'integer', 'description' => 'Jumlah opname terakhir yang ditampilkan, 1-10. Default 3.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $mutasi = StockMovement::with('product')->whereNull('user_id')->where('reference_type', 'opname')
            ->orderByDesc('created_at')->orderBy('id')->get();
        if ($mutasi->isEmpty()) {
            return ['jumlah_opname_tercatat' => 0, 'catatan' => 'Belum ada stok opname yang tercatat.'];
        }
        // Dicatat 1 detik sebelum tanggal opname → +1 detik = tanggal opname yang diisi user.
        $sesi = $mutasi->groupBy(fn (StockMovement $m) => Carbon::parse($m->created_at)->addSecond()->toDateString());
        $selisih = fn (StockMovement $m) => (int) $m->after_qty - (int) $m->before_qty;

        return [
            'jumlah_opname_tercatat' => $sesi->count(),
            'opname_terakhir' => $sesi->keys()->first(),
            'opname' => $sesi->take(max(1, min(10, (int) ($args['sesi'] ?? 3))))->map(fn ($g, $tgl) => [
                'tanggal' => $tgl,
                'produk_disesuaikan' => $g->count(),
                'total_selisih' => $g->sum($selisih),
                'produk_lebih' => $g->filter(fn ($m) => $selisih($m) > 0)->count(),
                'produk_kurang' => $g->filter(fn ($m) => $selisih($m) < 0)->count(),
                'rincian' => $g->sortByDesc(fn ($m) => abs($selisih($m)))->take(30)->map(fn (StockMovement $m) => [
                    'produk' => $m->product?->name,
                    'sku' => $m->product?->sku,
                    'stok_sistem' => (int) $m->before_qty,
                    'hitungan_fisik' => (int) $m->after_qty,
                    'selisih' => $selisih($m),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
