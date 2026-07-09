<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private AccBranch $branch;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);

        $defs = [
            ['1002', 'Bank', 'asset', 'cash', 'debit'],
            ['1202', 'Persediaan Barang Jadi', 'asset', 'inventory', 'debit'],
            ['2101', 'Hutang Bank', 'liability', 'long_term', 'credit'],
            ['3001', 'Modal Usaha', 'equity', null, 'credit'],
            ['4001', 'Penjualan', 'revenue', 'sales', 'credit'],
            ['5003', 'Beban HPP', 'expense', 'cogs', 'debit'],
            ['6001', 'Beban Iklan', 'expense', 'operating', 'debit'],
            ['7001', 'Beban Bunga', 'expense', 'non_operating', 'debit'],
        ];
        foreach ($defs as [$code, $name, $type, $sub, $nb]) {
            $this->acc[$code] = AccAccount::create([
                'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $sub, 'normal_balance' => $nb,
            ]);
        }
    }

    private function je(string $date, int $debitAcc, int $creditAcc, float $amount): void
    {
        app(AccountingService::class)->record(
            ['branch_id' => $this->branch->id, 'date' => $date],
            [
                ['account_id' => $debitAcc, 'debit' => $amount],
                ['account_id' => $creditAcc, 'credit' => $amount],
            ],
        );
    }

    private function seedJune(): void
    {
        $a = fn ($code) => $this->acc[$code]->id;

        // Saldo awal 1 Juni: aset 250jt = hutang 150jt + modal 100jt
        app(AccountingService::class)->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-01', 'description' => 'Saldo awal'],
            [
                ['account_id' => $a('1002'), 'debit' => 200_000_000],
                ['account_id' => $a('1202'), 'debit' => 50_000_000],
                ['account_id' => $a('2101'), 'credit' => 150_000_000],
                ['account_id' => $a('3001'), 'credit' => 100_000_000],
            ],
        );

        // Penjualan tunai 100jt + HPP 40jt
        $this->je('2026-06-10', $a('1002'), $a('4001'), 100_000_000);
        $this->je('2026-06-10', $a('5003'), $a('1202'), 40_000_000);
        // Beban iklan 20jt, bunga 1jt
        $this->je('2026-06-15', $a('6001'), $a('1002'), 20_000_000);
        $this->je('2026-06-30', $a('7001'), $a('1002'), 1_000_000);
    }

    public function test_income_statement_numbers(): void
    {
        $this->seedJune();
        $is = app(FinancialReportService::class)->incomeStatement('2026-06');

        $this->assertEquals(100_000_000, $is['penjualan_bersih']);
        $this->assertEquals(40_000_000, $is['hpp']);
        $this->assertEquals(60_000_000, $is['laba_kotor']);
        $this->assertEquals(20_000_000, $is['beban_operasional']);
        $this->assertEquals(40_000_000, $is['operating_income']);
        $this->assertEquals(1_000_000, $is['beban_non_operasional']);
        $this->assertEquals(39_000_000, $is['net_income']);
    }

    public function test_balance_sheet_balances(): void
    {
        $this->seedJune();
        $bs = app(FinancialReportService::class)->balanceSheet('2026-06');

        // Aset: Kas 279jt (200+100-20-1), Persediaan 10jt (50-40) = 289jt
        $this->assertEquals(289_000_000, $bs['total_aktiva']);
        $this->assertEquals(150_000_000, $bs['total_liabilitas']);
        $this->assertEquals(100_000_000, $bs['modal']);
        $this->assertEquals(39_000_000, $bs['laba_berjalan']);
        $this->assertEquals(139_000_000, $bs['total_ekuitas']);
        $this->assertEquals(289_000_000, $bs['total_pasiva']);
        $this->assertTrue($bs['balanced']);
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

    public function test_accounting_pages_render_for_admin(): void
    {
        $this->seedJune();
        $admin = $this->user(User::ROLE_ADMIN);

        foreach (['/accounting/laba-rugi', '/accounting/neraca', '/accounting/neraca-saldo'] as $url) {
            $this->actingAs($admin)->get($url.'?period=2026-06')->assertOk();
        }
        $this->actingAs($admin)->get('/accounting')->assertRedirect();
    }

    public function test_reseller_cannot_access_accounting(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/accounting/laba-rugi')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/accounting/jurnal')->assertForbidden();
    }

    public function test_admin_can_post_journal_via_form(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/accounting/jurnal')->assertOk();
        $this->actingAs($admin)->get('/accounting/jurnal/baru')->assertOk();

        $this->actingAs($admin)->post('/accounting/jurnal', [
            'branch_id' => $this->branch->id, 'date' => '2026-06-20', 'reference' => 'TEST', 'type' => 'general',
            'lines' => [
                ['account_id' => $this->acc['1002']->id, 'debit' => 500000, 'credit' => 0],
                ['account_id' => $this->acc['4001']->id, 'debit' => 0, 'credit' => 500000],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('accounting.journals'));

        $this->assertDatabaseHas('acc_journals', ['reference' => 'TEST', 'status' => 'posted']);
    }

    public function test_unbalanced_journal_via_form_rejected(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->post('/accounting/jurnal', [
            'branch_id' => $this->branch->id, 'date' => '2026-06-20',
            'lines' => [
                ['account_id' => $this->acc['1002']->id, 'debit' => 500000],
                ['account_id' => $this->acc['4001']->id, 'credit' => 400000],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('acc_journals', 0);
    }

    public function test_admin_can_void_journal_via_form(): void
    {
        $this->seedJune();
        $j = AccJournal::first();
        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/accounting/jurnal/'.$j->id.'/void')->assertRedirect();
        $this->assertEquals('void', $j->fresh()->status);
    }

    public function test_pnl_is_period_scoped_not_cumulative(): void
    {
        $this->seedJune();
        // Penjualan Juli tidak boleh muncul di Laba Rugi Juni
        $this->je('2026-07-05', $this->acc['1002']->id, $this->acc['4001']->id, 999_000_000);

        $is = app(FinancialReportService::class)->incomeStatement('2026-06');
        $this->assertEquals(100_000_000, $is['penjualan_bersih']); // Juli dikecualikan
    }
}
