<?php

namespace App\Services;

use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OnboardingService
{
    public function __construct(
        private PartnerHierarchyService $hierarchy,
        private InventoryService $inventory,
        private CommissionService $commissions,
    ) {}

    /**
     * Daftarkan reseller baru via paket join. 1 transaksi atomik: buat user +
     * potong stok HQ (isi paket) + catat transaksi + bonus join ke upline.
     * Stok HQ tak cukup → RuntimeException (rollback total).
     *
     * @param  array<string,mixed>  $data  validated: fullname,email,username,password,company_name?,phone?,address?,region?
     */
    public function onboard(array $data, JoinPackage $paket, ?int $uplineId, int $adminId): User
    {
        $paket->loadMissing('items.product');

        return DB::transaction(function () use ($data, $paket, $uplineId, $adminId) {
            // Pre-check stok HQ (pesan paket-level yang jelas; adjustHqStock tetap
            // jadi guard sungguhan dgn lockForUpdate saat memotong).
            foreach ($paket->items as $item) {
                if (! $item->product) {
                    throw new RuntimeException("Produk dalam paket {$paket->name} sudah tidak tersedia — perbarui isi paket dulu.");
                }
                if ((int) $item->product->hq_stock < $item->qty) {
                    throw new RuntimeException("Stok HQ tidak cukup untuk paket {$paket->name} (produk {$item->product->name}).");
                }
            }

            $user = User::create([
                'name' => $data['fullname'],
                'fullname' => $data['fullname'],
                'email' => mb_strtolower($data['email']),
                'username' => mb_strtolower($data['username']),
                'password' => Hash::make($data['password']),
                'role' => $paket->target_role,
                'company_name' => $data['company_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'region' => $data['region'] ?? null,
                'status' => User::STATUS_ACTIVE,
                'created_by' => $adminId,
            ]);
            $this->hierarchy->assignUpline($user, $uplineId);
            $this->hierarchy->ensureMemberId($user);
            $user->save();

            $trx = JoinTransaction::create([
                'user_id' => $user->id,
                'join_package_id' => $paket->id,
                'inviter_id' => $uplineId,
                'price' => $paket->price,
                'created_by' => $adminId,
            ]);

            foreach ($paket->items as $item) {
                $this->inventory->adjustHqStock(
                    product: $item->product,
                    delta: -$item->qty,
                    movementType: 'paket_join',
                    notes: "Paket join {$paket->name} untuk {$user->fullname}",
                    referenceType: 'join_transaction',
                    referenceId: $trx->id,
                    occurredAt: now(),
                );
            }

            if ($user->upline) {
                $this->commissions->recordJoinBonus($user->upline, $user, (float) $paket->price);
            }

            return $user;
        });
    }
}
