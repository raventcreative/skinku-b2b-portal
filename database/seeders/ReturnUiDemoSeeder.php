<?php

namespace Database\Seeders;

use App\Models\PoReturn;
use App\Models\PoReturnItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReturnUiDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('ReturnUiDemoSeeder hanya berjalan di environment local.');

            return;
        }

        $reviewer = User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])->first();
        $orders = PurchaseOrder::where('status', PurchaseOrder::STATUS_COMPLETED)
            ->whereHas('items')
            ->with('items')
            ->orderBy('id')
            ->limit(4)
            ->get();

        if ($orders->count() < 4) {
            $this->command?->warn('Butuh 4 PO selesai yang memiliki item. Jalankan DevDataSeeder di lokal terlebih dahulu.');

            return;
        }

        $samples = [
            ['pending', 'normal', 'Contoh lokal: menunggu persetujuan', null, null],
            ['applied', 'normal', 'Contoh lokal: barang layak jual', $reviewer?->id, now()],
            ['rejected', 'rusak', 'Contoh lokal: kemasan rusak', $reviewer?->id, null],
            ['void', 'normal', 'Contoh lokal: retur dibatalkan', $reviewer?->id, now()],
        ];

        foreach ($orders as $index => $order) {
            $item = $order->items->first();
            [$status, $condition, , $approvedBy, $appliedAt] = $samples[$index];

            $return = PoReturn::updateOrCreate(
                ['purchase_order_id' => $order->id, 'reason' => $samples[$index][2]],
                [
                    'status' => $status,
                    'kondisi' => $condition,
                    'from_customer' => false,
                    'credit_amount' => $status === 'applied' ? $item->unit_price : 0,
                    'requested_by' => $order->user_id,
                    'approved_by' => $approvedBy,
                    'applied_at' => $appliedAt,
                ],
            );

            PoReturnItem::updateOrCreate(
                ['po_return_id' => $return->id, 'purchase_order_item_id' => $item->id],
                ['qty' => 1],
            );
        }

        $this->command?->info('4 contoh retur berhasil disiapkan untuk preview lokal.');
    }
}
