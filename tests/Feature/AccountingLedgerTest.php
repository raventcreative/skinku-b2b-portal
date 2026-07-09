<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Services\AccountingService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private AccBranch $branch;

    private AccAccount $kas;

    private AccAccount $sales;

    private AccAccount $beban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);
        $this->kas = AccAccount::create(['code' => '1002', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'cash', 'normal_balance' => 'debit']);
        $this->sales = AccAccount::create(['code' => '4001', 'name' => 'Penjualan', 'type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']);
        $this->beban = AccAccount::create(['code' => '6001', 'name' => 'Beban Iklan', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);
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

    public function test_trial_balance_is_balanced_and_correct(): void
    {
        $this->je('2026-06-05', $this->kas->id, $this->sales->id, 100000);  // jual
        $this->je('2026-06-10', $this->beban->id, $this->kas->id, 30000);   // bayar iklan

        $tb = app(LedgerService::class)->trialBalance('2026-06');

        $this->assertTrue($tb['balanced']);
        $this->assertEquals(100000, $tb['total_debit']);
        $this->assertEquals(100000, $tb['total_credit']);

        $by = collect($tb['rows'])->keyBy('code');
        $this->assertEquals(70000, $by['1002']['debit']);   // kas 100k - 30k
        $this->assertEquals(0, $by['1002']['credit']);
        $this->assertEquals(30000, $by['6001']['debit']);   // beban
        $this->assertEquals(100000, $by['4001']['credit']); // penjualan (kredit)
    }

    public function test_void_excluded_from_trial_balance(): void
    {
        $this->je('2026-06-05', $this->kas->id, $this->sales->id, 100000);
        $j = app(AccountingService::class)->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-06'],
            [['account_id' => $this->kas->id, 'debit' => 999999], ['account_id' => $this->sales->id, 'credit' => 999999]],
        );
        app(AccountingService::class)->void($j);

        $tb = app(LedgerService::class)->trialBalance('2026-06');
        $by = collect($tb['rows'])->keyBy('code');
        $this->assertEquals(100000, $by['1002']['debit']); // void 999999 tidak ikut
    }

    public function test_general_ledger_running_balance_and_opening(): void
    {
        $this->je('2026-06-05', $this->kas->id, $this->sales->id, 100000);
        $this->je('2026-06-10', $this->beban->id, $this->kas->id, 30000);
        $this->je('2026-07-01', $this->kas->id, $this->sales->id, 50000);

        $svc = app(LedgerService::class);

        // Juni: opening 0, dua mutasi, closing 70k
        $juni = $svc->generalLedger($this->kas->id, '2026-06', '2026-06');
        $this->assertEquals(0, $juni['opening']);
        $this->assertCount(2, $juni['entries']);
        $this->assertEquals(100000, $juni['entries'][0]['running']);
        $this->assertEquals(70000, $juni['entries'][1]['running']);
        $this->assertEquals(70000, $juni['closing']);

        // Juli: opening = saldo akhir Juni (70k), +50k → closing 120k
        $juli = $svc->generalLedger($this->kas->id, '2026-07', '2026-07');
        $this->assertEquals(70000, $juli['opening']);
        $this->assertEquals(120000, $juli['closing']);

        // Semua periode: closing 120k
        $all = $svc->generalLedger($this->kas->id);
        $this->assertEquals(120000, $all['closing']);
    }
}
