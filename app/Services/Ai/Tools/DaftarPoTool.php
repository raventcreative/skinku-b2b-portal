<?php

namespace App\Services\Ai\Tools;

use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Alat BACA: daftar Purchase Order. Aturan sama dengan menu PO: khusus staff &
 * mitra (middleware 'business'); mitra HANYA melihat PO miliknya sendiri.
 */
class DaftarPoTool extends BaseTool
{
    public function name(): string
    {
        return 'daftar_po';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff() || $user->isPartner();
    }

    public function description(): string
    {
        return 'Cari & ringkas Purchase Order (PO) mitra ke HQ: status, status bayar, total, sisa tagihan. '
            .'Bisa disaring status, belum/sudah lunas, nomor PO/nama mitra, dan rentang tanggal.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['draft', 'pending', 'approved', 'processing', 'shipped', 'completed', 'cancelled']],
                'bayar' => ['type' => 'string', 'enum' => ['belum', 'lunas']],
                'cari' => ['type' => 'string', 'description' => 'Nomor PO atau nama perusahaan mitra.'],
                'dari' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'sampai' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $dari = $this->tanggal($args['dari'] ?? null);
        $sampai = $this->tanggal($args['sampai'] ?? null);
        $bayar = $args['bayar'] ?? null;

        $orders = PurchaseOrder::query()
            ->with('user')
            ->withSum('payments', 'amount')
            ->withSum('appliedReturns', 'credit_amount')
            ->where('status', '!=', PurchaseOrder::STATUS_DELETED)
            // MITRA: hanya PO miliknya — sama dengan PurchaseOrderController::index.
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->when($args['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($bayar === 'belum', fn ($q) => $q->where('payment_status', '!=', PurchaseOrder::PAYMENT_PAID)
                ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DRAFT]))
            ->when($bayar === 'lunas', fn ($q) => $q->where('payment_status', PurchaseOrder::PAYMENT_PAID))
            ->when(trim((string) ($args['cari'] ?? '')), fn ($q, $term) => $q->where(fn ($s) => $s
                ->where('po_number', 'like', "%{$term}%")->orWhere('company_name', 'like', "%{$term}%")))
            ->when($dari, fn ($q) => $q->whereDate('created_at', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('created_at', '<=', $sampai))
            ->latest()
            ->get();

        $sisa = fn (PurchaseOrder $po) => max(0, (float) $po->total_amount
            - (float) ($po->payments_sum_amount ?? 0) - (float) ($po->applied_returns_sum_credit_amount ?? 0));

        return [
            'catatan' => $user->isPartner() ? 'Hanya PO milik akun Anda.' : 'Semua PO mitra.',
            'jumlah_po' => $orders->count(),
            'total_nilai' => round((float) $orders->sum('total_amount'), 2),
            'total_sisa_tagihan' => round((float) $orders->sum($sisa), 2),
            'per_status' => $orders->countBy('status')->all(),
            // ponytail: 20 PO terbaru cukup untuk chat; daftar lengkap tetap di menu PO.
            'po_terbaru' => $orders->take(20)->map(fn (PurchaseOrder $po) => array_filter([
                'no_po' => $po->po_number,
                'mitra' => $user->isPartner() ? null : ($po->company_name ?: $po->user?->displayName()),
                'tanggal' => $po->created_at?->format('Y-m-d'),
                'status' => $po->status,
                'status_bayar' => $po->payment_status,
                'total' => (float) $po->total_amount,
                'sisa_tagihan' => round($sisa($po), 2),
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }
}
