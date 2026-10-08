<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Models\Withdrawal;

/**
 * Alat BACA: menu Penarikan (staf HQ yang memproses penarikan komisi mitra, izin process_withdrawal). Mitra melihat
 * penarikannya sendiri lewat alat `komisi`. Nomor rekening, nama bank, atas nama & catatan TIDAK dikirim ke AI.
 */
class PenarikanTool extends BaseTool
{
    private const STATUS = ['diajukan', 'disetujui', 'cair', 'ditolak'];

    public function name(): string
    {
        return 'penarikan';
    }

    public function permission(): ?string
    {
        return 'process_withdrawal';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff();
    }

    public function description(): string
    {
        return 'Antrean & riwayat penarikan komisi mitra (menu Penarikan): jumlah & total rupiah per status (diajukan = '
            .'menunggu disetujui, disetujui = menunggu dicairkan, cair, ditolak — termasuk yang dibatalkan mitra) dan daftar '
            .'penarikan terbaru. Data rekening bank sengaja tidak dikirim.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => self::STATUS, 'description' => 'Saring daftar (opsional). Kosongkan = semua status.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $status = in_array($args['status'] ?? null, self::STATUS, true) ? $args['status'] : null;
        $rekap = Withdrawal::query()->groupBy('status')
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->get()->keyBy('status');
        $perStatus = collect(self::STATUS)->mapWithKeys(fn ($s) => [$s => [
            'jumlah' => (int) ($rekap[$s]->n ?? 0), 'total' => (float) ($rekap[$s]->total ?? 0),
        ]]);

        return [
            'per_status' => $perStatus->all(),
            'perlu_diproses' => [
                'jumlah' => $perStatus['diajukan']['jumlah'] + $perStatus['disetujui']['jumlah'],
                'total' => $perStatus['diajukan']['total'] + $perStatus['disetujui']['total'],
            ],
            'filter_status' => $status ?? 'semua',
            // ponytail: 20 terbaru; riwayat lengkap & tombol proses di menu Penarikan.
            'daftar_terbaru' => Withdrawal::with('mitra:id,fullname,name,username')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn (Withdrawal $w) => [
                    'id' => $w->id, 'mitra' => $w->mitra?->displayName() ?? '—', 'jumlah' => (float) $w->amount,
                    'status' => $w->status, 'diajukan' => ($w->requested_at ?? $w->created_at)?->format('Y-m-d'),
                    'diproses' => $w->processed_at?->format('Y-m-d'),
                ])->all(),
            'catatan_privasi' => 'Nomor rekening, nama bank, atas nama & catatan sengaja tidak dikirim ke AI — lihat di menu Penarikan.',
        ];
    }
}
