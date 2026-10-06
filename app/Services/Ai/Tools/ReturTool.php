<?php

namespace App\Services\Ai\Tools;

use App\Models\PoReturn;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: retur barang PO (menu Retur, route retur.index). Gerbang & cakupan = ReturController@index: punya izin
 * process_return → semua retur; selain itu hanya mitra, dan hanya retur atas PO miliknya (sebagai pembeli).
 */
class ReturTool extends BaseTool
{
    private const STATUS = ['pending', 'applied', 'rejected', 'void'];

    public function name(): string
    {
        return 'retur';
    }

    public function availableFor(User $user): bool
    {
        return $user->canDo('process_return') || $user->isPartner();
    }

    public function description(): string
    {
        return 'Retur barang dari PO (menu Retur): status (pending = menunggu, applied = disetujui & jadi kredit, '
            .'rejected = ditolak, void = dibatalkan), barang & qty, kondisi (normal/rusak), alasan, nilai kredit. Mitra '
            .'hanya melihat retur atas PO miliknya; staf dgn izin Proses Retur melihat semua.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => self::STATUS, 'description' => 'Opsional.'],
                'dari' => ['type' => 'string', 'description' => 'Opsional: YYYY-MM-DD tanggal pengajuan.'],
                'sampai' => ['type' => 'string', 'description' => 'Opsional: YYYY-MM-DD.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah retur ditampilkan, 1-50. Default 20.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $semua = $user->canDo('process_return');
        $dari = $this->tanggal($args['dari'] ?? null);
        $sampai = $this->tanggal($args['sampai'] ?? null);

        $returs = PoReturn::with(['purchaseOrder.user', 'items.poItem'])
            ->when(! $semua, fn ($q) => $q->whereHas('purchaseOrder', fn ($w) => $w->where('user_id', $user->id)))
            ->when(in_array($args['status'] ?? null, self::STATUS, true), fn ($q) => $q->where('status', $args['status']))
            ->when($dari, fn ($q) => $q->where('created_at', '>=', Carbon::parse($dari)->startOfDay()))
            ->when($sampai, fn ($q) => $q->where('created_at', '<=', Carbon::parse($sampai)->endOfDay()))
            ->latest()->get();
        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));

        return array_filter([
            'cakupan' => $semua ? 'semua retur' : 'retur atas PO milikmu',
            'ringkasan' => [
                'jumlah' => $returs->count(),
                'per_status' => $returs->countBy('status')->all(),
                'total_kredit_disetujui' => round((float) $returs->where('status', 'applied')->sum('credit_amount'), 2),
            ],
            'retur' => $returs->take($limit)->map(fn (PoReturn $r) => array_filter([
                'tanggal' => $r->created_at?->toDateString(),
                'po' => $r->purchaseOrder?->po_number,
                'pembeli' => $r->purchaseOrder?->user?->fullname ?: $r->purchaseOrder?->user?->name,
                'barang' => $r->items->map(fn ($i) => ['produk' => $i->poItem?->product_name, 'qty' => (int) $i->qty])->all(),
                'kondisi' => $r->kondisi.($r->from_customer ? ' (dari pelanggan)' : ''),
                'alasan' => $r->reason ? mb_substr($r->reason, 0, 200) : null,
                'status' => $r->status,
                'nilai_kredit' => $r->status === 'applied' ? round((float) $r->credit_amount, 2) : null,
            ], fn ($v) => $v !== null))->values()->all(),
            'catatan' => $returs->count() > $limit ? "Menampilkan {$limit} dari {$returs->count()} retur (terbaru)." : null,
        ], fn ($v) => $v !== null);
    }
}
