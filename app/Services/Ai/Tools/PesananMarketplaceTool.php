<?php

namespace App\Services\Ai\Tools;

use App\Models\ShopeeOrder;
use App\Models\TiktokOrder;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Carbon;

/**
 * Alat BACA: ringkasan pesanan TikTok/Shopee yang tersinkron. Tiap channel hanya
 * muncul bila user punya izin menu channel itu (manage_tiktok / manage_shopee).
 */
class PesananMarketplaceTool extends BaseTool
{
    public function name(): string
    {
        return 'pesanan_marketplace';
    }

    public function availableFor(User $user): bool
    {
        return $this->channels($user) !== [];
    }

    public function description(): string
    {
        return 'Ringkas pesanan marketplace (TikTok Shop / Shopee) dalam rentang tanggal: jumlah, omzet, '
            .'per status, status potong stok, dan pesanan terbaru.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channel' => ['type' => 'string', 'enum' => ['tiktok', 'shopee', 'semua']],
                'dari' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Default awal bulan ini.'],
                'sampai' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Default hari ini.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $dari = Carbon::parse($this->tanggal($args['dari'] ?? null) ?? now()->startOfMonth()->toDateString())->startOfDay();
        $sampai = Carbon::parse($this->tanggal($args['sampai'] ?? null) ?? now()->toDateString())->endOfDay();
        $minta = $args['channel'] ?? 'semua';

        $out = ['periode' => $dari->format('Y-m-d').' s/d '.$sampai->format('Y-m-d')];
        foreach ($this->channels($user) as $ch => $model) {
            if ($minta !== 'semua' && $minta !== $ch) {
                continue;
            }
            $orders = $model::query()->whereBetween('order_created_at', [$dari, $sampai])->latest('order_created_at')->get();
            $idKey = $ch === 'tiktok' ? 'tiktok_order_id' : 'order_sn';
            $out[$ch] = [
                'jumlah_pesanan' => $orders->count(),
                'omzet' => round((float) $orders->sum('total_amount'), 2),
                'per_status' => $orders->countBy('status')->all(),
                'status_stok' => $orders->countBy('stock_status')->all(),
                'terbaru' => $orders->take(10)->map(fn ($o) => [
                    'id' => $o->{$idKey},
                    'tanggal' => $o->order_created_at?->format('Y-m-d H:i'),
                    'status' => $o->status,
                    'total' => (float) $o->total_amount,
                ])->values()->all(),
            ];
        }
        if ($minta !== 'semua' && ! isset($out[$minta])) {
            $out['error'] = "Anda tidak punya akses ke pesanan {$minta}.";
        }

        return $out;
    }

    /** @return array<string,class-string> channel yang boleh dilihat user ini. */
    private function channels(User $user): array
    {
        return array_filter([
            'tiktok' => Permissions::roleHas($user->role, 'manage_tiktok') ? TiktokOrder::class : null,
            'shopee' => Permissions::roleHas($user->role, 'manage_shopee') ? ShopeeOrder::class : null,
        ]);
    }
}
