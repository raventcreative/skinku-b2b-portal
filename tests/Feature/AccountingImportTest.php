<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountingImportTest extends TestCase
{
    use RefreshDatabase;

    private AccBranch $branch;

    private AccAccount $bank;

    private AccAccount $iklan;

    private AccAccount $penjualan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);
        $this->bank = AccAccount::create(['code' => '1002', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank', 'normal_balance' => 'debit']);
        $this->iklan = AccAccount::create(['code' => '6001', 'name' => 'Beban Iklan', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);
        $this->penjualan = AccAccount::create(['code' => '4001', 'name' => 'Penjualan', 'type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']);
    }

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

    public function test_admin_can_import_bank_rows(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/accounting/impor')->assertOk();

        $this->actingAs($admin)->post('/accounting/impor', [
            'branch_id' => $this->branch->id,
            'bank_account_id' => $this->bank->id,
            'rows' => [
                ['date' => '2026-06-15', 'description' => 'Bayar Iklan', 'amount' => 20000, 'direction' => 'keluar', 'account_id' => $this->iklan->id],
                ['date' => '2026-06-20', 'description' => 'Settlement Shopee', 'amount' => 50000, 'direction' => 'masuk', 'account_id' => $this->penjualan->id],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('accounting.journals'));

        $this->assertDatabaseCount('acc_journals', 2);

        $acc = app(AccountingService::class);
        // keluar: D iklan / K bank ; masuk: D bank / K penjualan
        $this->assertEquals(30000, $acc->balanceOf($this->bank->id));       // -20000 + 50000
        $this->assertEquals(20000, $acc->balanceOf($this->iklan->id));      // debit
        $this->assertEquals(-50000, $acc->balanceOf($this->penjualan->id)); // credit
    }

    public function test_ignored_and_incomplete_rows_are_skipped(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->post('/accounting/impor', [
            'branch_id' => $this->branch->id,
            'bank_account_id' => $this->bank->id,
            'rows' => [
                ['date' => '2026-06-15', 'description' => 'ok', 'amount' => 10000, 'direction' => 'keluar', 'account_id' => $this->iklan->id],
                ['date' => '2026-06-16', 'description' => 'diabaikan', 'amount' => 99999, 'direction' => 'keluar', 'account_id' => $this->iklan->id, 'ignore' => '1'],
                ['date' => '2026-06-17', 'description' => 'tanpa coa', 'amount' => 88888, 'direction' => 'keluar', 'account_id' => null],
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('acc_journals', 1); // hanya baris pertama
    }

    public function test_reseller_cannot_import(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/accounting/impor')->assertForbidden();
    }
}
