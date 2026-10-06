<?php

namespace App\Services\Ai\Tools;

use App\Models\Production;
use App\Models\User;

/**
 * Alat BACA: riwayat produksi & HPP (menu Produksi (HPP), route productions.index + detail productions.show). Izin
 * sama dgn halamannya (manage_production). Angka dibaca dari kolom tersimpan ProductionService (tak dihitung ulang).
 */
class ProduksiHppTool extends BaseTool
{
    public function name(): string
    {
        return 'produksi_hpp';
    }

    public function permission(): ?string
    {
        return 'manage_production';
    }

    public function description(): string
    {
        return 'Riwayat produksi (menu Produksi (HPP)): no. produksi, tanggal, produk, qty jadi, pencatat, ringkasan per '
            .'produk. Total biaya, HPP per pcs & HPP rata-rata khusus izin Lihat HPP. Isi nomor untuk rincian satu batch '
            .'(bahan yang dipakai; biaya & HPP khusus izin Lihat HPP).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nomor' => ['type' => 'string', 'description' => 'Opsional: no. produksi → rincian satu batch.'],
                'produk' => ['type' => 'string', 'description' => 'Opsional: kata di nama produk.'],
                'dari' => ['type' => 'string', 'description' => 'Opsional: YYYY-MM-DD tanggal produksi.'],
                'sampai' => ['type' => 'string', 'description' => 'Opsional: YYYY-MM-DD.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah batch ditampilkan, 1-50. Default 20.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $hpp = $this->bolehLihatHpp($user);
        $nomor = trim((string) ($args['nomor'] ?? ''));
        if ($nomor !== '') {
            return $this->rincian($nomor, $hpp);
        }

        $produk = trim((string) ($args['produk'] ?? ''));
        $dari = $this->tanggal($args['dari'] ?? null);
        $sampai = $this->tanggal($args['sampai'] ?? null);
        // Sama dgn ProductionController@index: urut tanggal produksi terbaru.
        $batch = Production::with(['product', 'creator'])
            ->when($produk !== '', fn ($q) => $q->where('product_name', 'like', "%{$produk}%"))
            ->when($dari, fn ($q) => $q->whereDate('produced_at', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('produced_at', '<=', $sampai))
            ->orderByDesc('produced_at')->orderByDesc('id')->get();
        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));

        return array_filter([
            'ringkasan' => [
                'jumlah_batch' => $batch->count(),
                'total_qty' => (int) $batch->sum('output_qty'),
            ] + ($hpp ? ['total_biaya' => round((float) $batch->sum('total_cost'), 2)] : []) + [
                'per_produk' => $batch->groupBy(fn (Production $p) => $this->namaProduk($p))->map(fn ($g, $nama) => [
                    'produk' => $nama,
                    'batch' => $g->count(),
                    'qty' => (int) $g->sum('output_qty'),
                ] + ($hpp ? [
                    'total_biaya' => round((float) $g->sum('total_cost'), 2),
                    'hpp_rata_rata_batch' => $g->sum('output_qty') > 0 ? round((float) $g->sum('total_cost') / $g->sum('output_qty'), 2) : null,
                ] : []))->values()->all(),
            ],
            'produksi' => $batch->take($limit)->map(fn (Production $p) => [
                'nomor' => $p->production_number,
                'tanggal' => $p->produced_at?->toDateString(),
                'produk' => $this->namaProduk($p),
                'qty_jadi' => (int) $p->output_qty,
            ] + ($hpp ? [
                'total_biaya' => (float) $p->total_cost,
                'hpp_per_pcs' => (float) $p->hpp_per_unit,
                'hpp_rata_rata_sesudah' => (float) $p->cogs_after,
            ] : []) + [
                'oleh' => $p->creator?->fullname ?: $p->creator?->name,
            ])->values()->all(),
            'catatan' => $batch->count() > $limit ? "Menampilkan {$limit} dari {$batch->count()} batch (terbaru)." : null,
            'catatan_akses' => $hpp ? null : self::CATATAN_HPP,
        ], fn ($v) => $v !== null);
    }

    /** Rincian satu batch = halaman productions.show; biaya & HPP hanya dgn izin Lihat HPP. */
    private function rincian(string $nomor, bool $hpp): array
    {
        $p = Production::with(['materials', 'costs', 'product', 'creator'])->where('production_number', $nomor)->first();
        if (! $p) {
            return ['error' => "Produksi {$nomor} tidak ditemukan."];
        }

        return array_filter([
            'nomor' => $p->production_number,
            'tanggal' => $p->produced_at?->toDateString(),
            'produk' => $this->namaProduk($p),
            'qty_jadi' => (int) $p->output_qty,
        ] + ($hpp ? [
            'biaya_bahan' => (float) $p->material_cost,
            'biaya_lain' => (float) $p->other_cost,
            'total_biaya' => (float) $p->total_cost,
            'hpp_per_pcs' => (float) $p->hpp_per_unit,
            'hpp_rata_rata_sebelum' => (float) $p->cogs_before,
            'hpp_rata_rata_sesudah' => (float) $p->cogs_after,
        ] : []) + [
            'bahan' => $p->materials->map(fn ($m) => [
                'nama' => $m->material_name,
                'qty' => (float) $m->quantity,
                'satuan' => $m->unit,
            ] + ($hpp ? ['harga_unit' => (float) $m->unit_cost, 'subtotal' => (float) $m->subtotal] : []))->all(),
            'biaya_lain_rinci' => $hpp ? $p->costs->map(fn ($c) => ['keterangan' => $c->label, 'nominal' => (float) $c->amount])->all() : null,
            'catatan' => $p->notes ? mb_substr($p->notes, 0, 200) : null,
            'dicatat_oleh' => $p->creator?->fullname ?: $p->creator?->name,
            'catatan_akses' => $hpp ? null : self::CATATAN_HPP,
        ], fn ($v) => $v !== null);
    }

    private function namaProduk(Production $p): ?string
    {
        return $p->product_name ?: $p->product?->name;
    }
}
