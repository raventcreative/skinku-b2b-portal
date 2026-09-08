<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'adm', 'fullname' => 'ADM', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function po(User $admin, string $no, string $createdAt): void
    {
        $po = PurchaseOrder::create([
            'po_number' => $no, 'created_by' => $admin->id, 'user_id' => $admin->id,
            'status' => PurchaseOrder::STATUS_PENDING, 'total_amount' => 100_000, 'user_role' => 'super_admin',
        ]);
        // created_at bukan fillable + di-manage timestamps → set lewat query builder.
        PurchaseOrder::whereKey($po->id)->update(['created_at' => $createdAt]);
    }

    public function test_filter_rentang_tanggal_po(): void
    {
        $admin = $this->admin();
        $this->po($admin, 'PO-OLD', '2026-08-10 09:00:00');
        $this->po($admin, 'PO-NEW', '2026-09-05 09:00:00');

        // Rentang Agustus → hanya PO-OLD.
        $this->actingAs($admin)->get(route('purchase-orders.index', ['dari' => '2026-08-01', 'sampai' => '2026-08-31']))
            ->assertOk()->assertSee('PO-OLD')->assertDontSee('PO-NEW');

        // Rentang September → hanya PO-NEW.
        $this->actingAs($admin)->get(route('purchase-orders.index', ['dari' => '2026-09-01', 'sampai' => '2026-09-30']))
            ->assertOk()->assertSee('PO-NEW')->assertDontSee('PO-OLD');

        // Tanpa filter → dua-duanya.
        $this->actingAs($admin)->get(route('purchase-orders.index'))
            ->assertOk()->assertSee('PO-OLD')->assertSee('PO-NEW');
    }
}
