<?php

namespace App\Services\Ai\Tools;

use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Alat BACA: menu Pesanan Downline (mitra stockist, izin process_downline_po) — HANYA PO di mana akun itu penjualnya
 * (seller_id = dia, kunci keamanan yang sama dgn halamannya). Tanpa catatan, alamat, atau bukti bayar.
 */
class PesananDownlineTool extends BaseTool
{
    /** Status yang tak butuh tindakan penjual (draft = belum diajukan downline). */
    private const BUKAN_TINDAKAN = [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DELETED];

    private const BAYAR = [
        PurchaseOrder::PAYMENT_UNPAID => 'belum bayar',
        PurchaseOrder::PAYMENT_AWAITING => 'bukti bayar menunggu verifikasi Anda',
        PurchaseOrder::PAYMENT_PAID => 'lunas',
        PurchaseOrder::PAYMENT_REJECTED => 'bukti bayar ditolak',
    ];

    public function name(): string
    {
        return 'pesanan_downline';
    }

    public function permission(): ?string
    {
        return 'process_downline_po';
    }

    public function availableFor(User $user): bool
    {
        return $user->isPartner();
    }

    public function description(): string
    {
        return 'Pesanan Downline (khusus mitra stockist): PO dari downline yang Anda proses sebagai penjual — jumlah & rupiah '
            .'per status, pesanan yang masih perlu tindakan (verifikasi bayar / kirim / selesaikan) beserta status bayarnya, '
            .'dan daftar terbaru. Hanya pesanan di mana Anda penjualnya.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function run(array $args, User $user): array
    {
        $milik = fn () => PurchaseOrder::query()->where('seller_id', $user->id); // KUNCI: hanya PO di mana dia penjual
        $baris = fn (PurchaseOrder $po) => [
            'no_po' => $po->po_number, 'downline' => $po->user?->fullname ?: ($po->company_name ?: '—'),
            'total' => (float) $po->total_amount, 'status' => $po->status,
            'pembayaran' => self::BAYAR[$po->payment_status] ?? $po->payment_status, 'tanggal' => $po->created_at?->format('Y-m-d'),
        ];

        return [
            'catatan' => 'Hanya pesanan di mana akun Anda penjualnya.',
            'per_status' => $milik()->groupBy('status')->selectRaw('status, COUNT(*) as n, COALESCE(SUM(total_amount), 0) as rupiah')
                ->get()->mapWithKeys(fn ($r) => [$r->status => ['jumlah' => (int) $r->n, 'rupiah' => (float) $r->rupiah]])->all(),
            'perlu_tindakan' => $milik()->whereNotIn('status', self::BUKAN_TINDAKAN)->with('user:id,fullname')->oldest()->limit(20)->get()->map($baris)->all(),
            // ponytail: 15 terbaru; riwayat lengkap & tombol proses di menu Pesanan Downline.
            'terbaru' => $milik()->with('user:id,fullname')->latest()->limit(15)->get()->map($baris)->all(),
        ];
    }
}
