<?php

namespace App\Services\Ai\Tools;

use App\Models\MaterialPurchase;
use App\Models\Supplier;
use App\Models\User;

/**
 * Alat BACA: Supplier (menu Produksi → Supplier), izin manage_production sama dgn halamannya. Daftar supplier +
 * ringkasan pembelian bahan dari supplier itu (bahan, jumlah pembelian, terakhir beli). Nilai pembelian (harga beli)
 * hanya utk view_hpp. Telepon, alamat & catatan supplier tidak dikirim ke AI.
 */
class SupplierTool extends BaseTool
{
    public function name(): string
    {
        return 'supplier';
    }

    public function permission(): ?string
    {
        return 'manage_production';
    }

    public function description(): string
    {
        return 'Daftar Supplier bahan baku (menu Produksi → Supplier): nama, status aktif/nonaktif, bahan yang pernah '
            .'dibeli dari supplier itu, jumlah pembelian, tanggal beli terakhir; nilai pembelian (Rp) hanya bila boleh '
            .'lihat HPP. Isi cari untuk satu supplier. Kontak supplier (telepon/alamat) tidak tersedia. Riwayat beli per '
            .'bahan ada di bahan_baku.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cari' => ['type' => 'string', 'description' => 'Nama supplier (opsional, sebagian boleh).'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $hpp = $this->bolehLihatHpp($user);
        $cari = trim((string) ($args['cari'] ?? ''));
        $suppliers = Supplier::ordered()->when($cari !== '', fn ($q) => $q->where('name', 'like', '%'.$cari.'%'))->get();
        $beli = MaterialPurchase::with('material:id,name')->whereIn('supplier_id', $suppliers->pluck('id'))
            ->orderByDesc('purchased_at')->orderByDesc('id')->get()->groupBy('supplier_id');

        $out = array_filter([
            'filter_cari' => $cari ?: null,
            'jumlah_supplier' => $suppliers->count(),
            'aktif' => $suppliers->where('status', Supplier::STATUS_ACTIVE)->count(),
            'catatan' => $cari !== '' && $suppliers->isEmpty() ? "Tidak ada supplier yang cocok dengan \"{$cari}\"." : null,
            'supplier' => $suppliers->map(function (Supplier $s) use ($beli, $hpp) {
                $p = $beli[$s->id] ?? collect();

                return array_filter([
                    'nama' => $s->name,
                    'status' => $s->status === Supplier::STATUS_ACTIVE ? 'aktif' : 'nonaktif',
                    'jumlah_pembelian' => $p->count(),
                    'bahan' => $p->map(fn ($x) => $x->material?->name ?? $x->material_name)->filter()->unique()->values()->all() ?: null,
                    'pembelian_terakhir' => $p->first()?->purchased_at?->toDateString(),
                    'total_pembelian' => $hpp ? (int) $p->sum('subtotal') : null,
                ], fn ($v) => $v !== null);
            })->values()->all(),
        ], fn ($v) => $v !== null);
        if (! $hpp) {
            $out['catatan_akses'] = self::CATATAN_HPP;
        }

        return $out;
    }
}
