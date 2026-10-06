<?php

namespace App\Services\Ai\Tools;

use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\User;

/**
 * Alat BACA: bahan baku produksi (menu Bahan Baku, route materials.index). Izin sama dgn halamannya
 * (manage_production). Kolom = tabel halaman: stok, HPP rata-rata, nilai stok, status + "Riwayat Beli Bahan".
 * Kontak supplier (telepon/alamat, ada di menu Supplier) tidak dikirim — hanya nama supplier seperti di halaman.
 */
class BahanBakuTool extends BaseTool
{
    public function name(): string
    {
        return 'bahan_baku';
    }

    public function permission(): ?string
    {
        return 'manage_production';
    }

    public function description(): string
    {
        return 'Bahan baku produksi (menu Bahan Baku): stok, satuan, HPP rata-rata per unit, nilai stok, status, plus '
            .'riwayat pembelian bahan terbaru (qty, harga/unit, subtotal, HPP sebelum→sesudah, supplier). Bisa cari nama bahan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cari' => ['type' => 'string', 'description' => 'Opsional: kata di nama bahan.'],
                'riwayat' => ['type' => 'integer', 'description' => 'Jumlah pembelian terbaru yang ditampilkan, 0-30. Default 10.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $cari = trim((string) ($args['cari'] ?? ''));
        // Sama dgn MaterialController@index: semua bahan (aktif & nonaktif; terhapus tak ikut), urut nama.
        $bahan = Material::query()->when($cari !== '', fn ($q) => $q->where('name', 'like', "%{$cari}%"))->orderBy('name')->get();
        $nilai = fn (Material $m) => round((float) $m->stock * (float) $m->avg_cost, 2);
        $riwayat = max(0, min(30, (int) ($args['riwayat'] ?? 10)));

        return array_filter([
            'ringkasan' => [
                'jumlah_bahan' => $bahan->count(),
                'nilai_stok_total' => round($bahan->sum($nilai), 2),
                'stok_minus' => $bahan->filter(fn (Material $m) => (float) $m->stock < 0)->count(),
            ],
            'bahan' => $bahan->take(60)->map(fn (Material $m) => [
                'nama' => $m->name,
                'satuan' => $m->unit,
                'stok' => (float) $m->stock,
                'hpp_rata_rata' => (float) $m->avg_cost,
                'nilai_stok' => $nilai($m),
                'status' => $m->status,
            ])->values()->all(),
            'riwayat_beli' => $riwayat === 0 ? null : MaterialPurchase::query()
                ->when($cari !== '', fn ($q) => $q->where('material_name', 'like', "%{$cari}%"))
                ->orderByDesc('purchased_at')->orderByDesc('id')->limit($riwayat)->get()
                ->map(fn (MaterialPurchase $p) => [
                    'tanggal' => $p->purchased_at?->toDateString(),
                    'bahan' => $p->material_name,
                    'qty' => (float) $p->quantity,
                    'harga_unit' => (float) $p->unit_cost,
                    'subtotal' => (float) $p->subtotal,
                    'hpp_sebelum' => (float) $p->cost_before,
                    'hpp_sesudah' => (float) $p->cost_after,
                    'supplier' => $p->supplier_name,
                ])->all(),
            'catatan' => $bahan->count() > 60 ? "Menampilkan 60 dari {$bahan->count()} bahan (urut nama) — persempit dgn kata cari." : null,
        ], fn ($v) => $v !== null);
    }
}
