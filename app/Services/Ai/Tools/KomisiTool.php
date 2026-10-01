<?php

namespace App\Services\Ai\Tools;

use App\Models\Commission;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CommissionService;
use App\Support\Permissions;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: komisi. Mitra → HANYA saldo & riwayat miliknya (menu Komisi Saya).
 * Staff → rekap semua mitra, khusus yang punya izin view_commission_report.
 */
class KomisiTool extends BaseTool
{
    public function __construct(private CommissionService $commissions) {}

    public function name(): string
    {
        return 'komisi';
    }

    public function availableFor(User $user): bool
    {
        return $user->isPartner() || ($user->isStaff() && Permissions::roleHas($user->role, 'view_commission_report'));
    }

    public function description(): string
    {
        return 'Ambil data komisi: saldo, saldo bisa ditarik, riwayat komisi & penarikan. '
            .'Untuk mitra = komisi miliknya sendiri; untuk admin = rekap komisi per mitra per bulan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'description' => 'YYYY-MM untuk rekap admin. Kosongkan = bulan ini.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        // Cabang mitra DULU: mitra tak pernah dapat data mitra lain.
        if ($user->isPartner()) {
            return [
                'catatan' => 'Komisi KHUSUS akun Anda.',
                'saldo' => $this->commissions->balance($user),
                'bisa_ditarik' => $this->commissions->availableBalance($user),
                'komisi_terbaru' => Commission::where('user_id', $user->id)->latest()->limit(10)->get()
                    ->map(fn (Commission $c) => [
                        'tanggal' => $c->created_at?->format('Y-m-d'),
                        'jenis' => $c->type, 'level' => $c->level,
                        'jumlah' => (float) $c->amount, 'status' => $c->status,
                    ])->all(),
                'penarikan_terbaru' => Withdrawal::where('user_id', $user->id)->latest()->limit(5)->get()
                    ->map(fn (Withdrawal $w) => [
                        'tanggal' => $w->created_at?->format('Y-m-d'),
                        'jumlah' => (float) $w->amount, 'status' => $w->status,
                    ])->all(),
            ];
        }

        $v = (string) ($args['bulan'] ?? '');
        $bulan = preg_match('/^\d{4}-\d{2}$/', $v) ? Carbon::parse($v.'-01') : Carbon::now()->startOfMonth();

        return [
            'bulan' => $bulan->translatedFormat('F Y'),
            'ringkasan' => $this->commissions->reportSummary($bulan),
            // ponytail: 20 mitra dengan komisi periode terbesar; rincian lengkap di Laporan Komisi.
            'per_mitra' => collect($this->commissions->reportPerMitra($bulan))
                ->sortByDesc('komisi')->take(20)
                ->map(fn ($r) => [
                    'mitra' => $r['user']->displayName(), 'tier' => $r['tier'],
                    'komisi_periode' => $r['komisi'], 'transaksi' => $r['transaksi'],
                    'saldo' => $r['saldo'], 'tertahan' => $r['tertahan'], 'tersedia' => $r['tersedia'],
                ])->values()->all(),
        ];
    }
}
