<?php

namespace App\Services\Ai\Tools;

use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\User;
use App\Support\PartnerHierarchy;

/**
 * Alat BACA: menu Paket Join (staf, izin manage_join_packages): paket pendaftaran mitra + isinya, ditambah berapa
 * mitra bergabung lewat tiap paket (join batal tak dihitung) — hitungan saja, tanpa nama member.
 */
class PaketJoinTool extends BaseTool
{
    public function name(): string
    {
        return 'paket_join';
    }

    public function permission(): ?string
    {
        return 'manage_join_packages';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff();
    }

    public function description(): string
    {
        return 'Paket Join (menu Paket Join): daftar paket pendaftaran mitra baru — untuk tier apa, harga, aktif/tidak, isi '
            .'produknya — plus jumlah mitra yang bergabung lewat tiap paket di periode itu & sepanjang waktu (join batal tidak dihitung).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM untuk hitungan bergabung, atau "semua". Kosongkan = bulan ini.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $bulan = $this->bulanLaporan($args['bulan'] ?? null);
        $join = JoinTransaction::query()->whereNull('cancelled_at');
        $total = (clone $join)->groupBy('join_package_id')->selectRaw('join_package_id, COUNT(*) as n')->pluck('n', 'join_package_id');
        $periode = (clone $join)
            ->when($bulan, fn ($q) => $q->whereBetween('created_at', [$bulan->copy()->startOfMonth(), $bulan->copy()->endOfMonth()]))
            ->groupBy('join_package_id')->selectRaw('join_package_id, COUNT(*) as n, COALESCE(SUM(price), 0) as rupiah')
            ->get()->keyBy('join_package_id');

        return [
            'periode' => $this->labelBulan($bulan),
            'paket' => JoinPackage::with('items.product:id,name')->orderBy('name')->get()->map(fn (JoinPackage $p) => [
                'nama' => $p->name, 'untuk_tier' => PartnerHierarchy::label((string) $p->target_role), 'harga' => (float) $p->price,
                'aktif' => (bool) $p->is_active,
                'isi' => $p->items->map(fn ($it) => ($it->product?->name ?? 'Produk terhapus').' × '.$it->qty)->all(),
                'bergabung_periode' => (int) ($periode[$p->id]->n ?? 0),
                'rupiah_periode' => (float) ($periode[$p->id]->rupiah ?? 0),
                'bergabung_total' => (int) ($total[$p->id] ?? 0),
            ])->all(),
        ];
    }
}
