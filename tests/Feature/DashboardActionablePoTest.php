<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardActionablePoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin PO', 'fullname' => 'Admin PO', 'username' => 'poadmin',
            'email' => 'poadmin@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function partner(): User
    {
        return User::create([
            'name' => 'Erin', 'fullname' => 'Erin Reseller', 'username' => 'erin',
            'email' => 'erin@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_po_pending_muncul_di_perlu_tindakan(): void
    {
        $admin = $this->admin();
        $erin = $this->partner();

        PurchaseOrder::create([
            'po_number' => 'SKN-PO-INBOX-1', 'created_by' => $admin->id, 'user_id' => $erin->id,
            'status' => PurchaseOrder::STATUS_PENDING, 'total_amount' => 500_000, 'user_role' => 'reseller',
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Pesanan (PO) perlu tindakan')
            ->assertSee('SKN-PO-INBOX-1')
            ->assertSee('baru masuk');
    }

    public function test_po_awaiting_verification_muncul_dengan_badge_verifikasi_bayar(): void
    {
        $admin = $this->admin();
        $erin = $this->partner();

        PurchaseOrder::create([
            'po_number' => 'SKN-PO-INBOX-2', 'created_by' => $admin->id, 'user_id' => $erin->id,
            'status' => PurchaseOrder::STATUS_APPROVED, 'payment_status' => PurchaseOrder::PAYMENT_AWAITING,
            'total_amount' => 750_000, 'user_role' => 'reseller',
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('SKN-PO-INBOX-2')
            ->assertSee('verifikasi bayar');
    }

    public function test_distributor_lihat_po_downline_di_inbox(): void
    {
        // Distributor (punya process_downline_po) melihat PO downline-nya
        // (seller_id = dia) yang masih pending — bukan PO langsung-HQ.
        $distributor = User::create([
            'name' => 'Distri', 'fullname' => 'Distri Bali', 'username' => 'distri',
            'email' => 'distri@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
        $downline = $this->partner();

        PurchaseOrder::create([
            'po_number' => 'SKN-PO-DL-1', 'created_by' => $distributor->id, 'user_id' => $downline->id,
            'seller_id' => $distributor->id, 'status' => PurchaseOrder::STATUS_PENDING,
            'total_amount' => 300_000, 'user_role' => 'reseller',
        ]);

        $this->actingAs($distributor)->get('/dashboard')
            ->assertOk()
            ->assertSee('Pesanan (PO) perlu tindakan')
            ->assertSee('SKN-PO-DL-1');
    }

    public function test_po_completed_tidak_masuk_inbox(): void
    {
        // PO completed boleh muncul di daftar "PO terbaru", tapi TIDAK boleh masuk
        // inbox "Perlu Tindakan" — jadi diuji lewat data view actionablePos.
        $admin = $this->admin();
        $erin = $this->partner();

        PurchaseOrder::create([
            'po_number' => 'SKN-PO-DONE', 'created_by' => $admin->id, 'user_id' => $erin->id,
            'status' => PurchaseOrder::STATUS_COMPLETED, 'payment_status' => PurchaseOrder::PAYMENT_PAID,
            'total_amount' => 900_000, 'user_role' => 'reseller',
        ]);

        $pos = $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('actionablePos');

        $this->assertFalse($pos->contains(fn ($po) => $po->po_number === 'SKN-PO-DONE'));
    }
}
