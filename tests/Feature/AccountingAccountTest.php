<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountingAccountTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U{$n}", 'fullname' => "U{$n}", 'username' => "{$role}{$n}",
            'email' => "{$role}{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_admin_can_create_and_list_accounts(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/accounting/coa')->assertOk();

        $this->actingAs($admin)->post('/accounting/coa', [
            'code' => '6014', 'name' => 'Beban Internet', 'type' => 'expense',
            'subtype' => 'operating', 'normal_balance' => 'debit', 'is_active' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('acc_accounts', ['code' => '6014', 'name' => 'Beban Internet', 'type' => 'expense', 'subtype' => 'operating']);
    }

    public function test_duplicate_code_rejected(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        AccAccount::create(['code' => '6014', 'name' => 'X', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);

        $this->actingAs($admin)->post('/accounting/coa', [
            'code' => '6014', 'name' => 'Y', 'type' => 'expense', 'normal_balance' => 'debit',
        ])->assertSessionHasErrors('code');
    }

    public function test_admin_can_update_account(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a = AccAccount::create(['code' => '6014', 'name' => 'Beban Internet', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);

        $this->actingAs($admin)->put('/accounting/coa/'.$a->id, [
            'code' => '6014', 'name' => 'Beban Internet & Data', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit',
        ])->assertRedirect();

        $this->assertEquals('Beban Internet & Data', $a->fresh()->name);
    }

    public function test_unused_account_can_be_deleted(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a = AccAccount::create(['code' => '6099', 'name' => 'Sementara', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);

        $this->actingAs($admin)->delete('/accounting/coa/'.$a->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('acc_accounts', ['id' => $a->id]);
    }

    public function test_used_account_cannot_be_deleted(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $branch = AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);
        $kas = AccAccount::create(['code' => '1002', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'cash', 'normal_balance' => 'debit']);
        $rev = AccAccount::create(['code' => '4001', 'name' => 'Penjualan', 'type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']);

        app(AccountingService::class)->record(
            ['branch_id' => $branch->id, 'date' => '2026-06-01'],
            [['account_id' => $kas->id, 'debit' => 1000], ['account_id' => $rev->id, 'credit' => 1000]],
        );

        $this->actingAs($admin)->delete('/accounting/coa/'.$kas->id)->assertSessionHasErrors('account');
        $this->assertDatabaseHas('acc_accounts', ['id' => $kas->id]); // masih ada
    }

    public function test_reseller_cannot_access_coa(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/accounting/coa')->assertForbidden();
    }
}
