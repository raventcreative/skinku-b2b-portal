<?php

namespace Tests\Feature;

use App\Exceptions\AccountingException;
use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPostingTest extends TestCase
{
    use RefreshDatabase;

    private AccBranch $branch;

    private AccAccount $kas;

    private AccAccount $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);
        $this->kas = AccAccount::create(['code' => '1002', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'cash', 'normal_balance' => 'debit']);
        $this->sales = AccAccount::create(['code' => '4001', 'name' => 'Penjualan', 'type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']);
    }

    private function service(): AccountingService
    {
        return app(AccountingService::class);
    }

    private function balancedLines(float $amount = 100000): array
    {
        return [
            ['account_id' => $this->kas->id, 'debit' => $amount],
            ['account_id' => $this->sales->id, 'credit' => $amount],
        ];
    }

    public function test_records_balanced_journal_as_posted(): void
    {
        $j = $this->service()->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-15', 'type' => 'sales', 'reference' => 'INV-1'],
            $this->balancedLines(100000),
        );

        $this->assertEquals(AccJournal::STATUS_POSTED, $j->status);
        $this->assertEquals('2026-06', $j->period);           // derived from date
        $this->assertCount(2, $j->lines);
        $this->assertTrue($j->isBalanced());
    }

    public function test_rejects_unbalanced_journal(): void
    {
        $this->expectException(AccountingException::class);
        try {
            $this->service()->record(
                ['branch_id' => $this->branch->id, 'date' => '2026-06-15'],
                [
                    ['account_id' => $this->kas->id, 'debit' => 100000],
                    ['account_id' => $this->sales->id, 'credit' => 90000], // off by 10k
                ],
            );
        } finally {
            $this->assertDatabaseCount('acc_journals', 0); // nothing written
        }
    }

    public function test_rejects_line_with_both_debit_and_credit(): void
    {
        $this->expectException(AccountingException::class);
        $this->service()->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-15'],
            [
                ['account_id' => $this->kas->id, 'debit' => 100, 'credit' => 100],
                ['account_id' => $this->sales->id, 'credit' => 100],
            ],
        );
    }

    public function test_rejects_single_line_journal(): void
    {
        $this->expectException(AccountingException::class);
        $this->service()->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-15'],
            [['account_id' => $this->kas->id, 'debit' => 100000]],
        );
    }

    public function test_draft_then_post(): void
    {
        $j = $this->service()->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-15'],
            $this->balancedLines(50000),
            AccJournal::STATUS_DRAFT,
        );
        $this->assertEquals(AccJournal::STATUS_DRAFT, $j->status);
        $this->assertEquals(0.0, $this->service()->balanceOf($this->kas->id)); // draft not counted

        $this->service()->post($j);
        $this->assertEquals(AccJournal::STATUS_POSTED, $j->fresh()->status);
        $this->assertEquals(50000, $this->service()->balanceOf($this->kas->id)); // now counted
    }

    public function test_void_reverses_balance(): void
    {
        $j = $this->service()->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-15'],
            $this->balancedLines(100000),
        );
        $this->assertEquals(100000, $this->service()->balanceOf($this->kas->id));
        $this->assertEquals(-100000, $this->service()->balanceOf($this->sales->id)); // credit side

        $this->service()->void($j);
        $this->assertEquals(0.0, $this->service()->balanceOf($this->kas->id));   // reversed
        $this->assertEquals(0.0, $this->service()->balanceOf($this->sales->id));
    }

    public function test_source_link_and_period_filter(): void
    {
        $this->service()->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-06-15', 'source_type' => 'purchase_order', 'source_id' => 77],
            $this->balancedLines(100000),
        );
        $this->service()->record(
            ['branch_id' => $this->branch->id, 'date' => '2026-07-02'],
            $this->balancedLines(30000),
        );

        $this->assertDatabaseHas('acc_journals', ['source_type' => 'purchase_order', 'source_id' => 77]);
        $this->assertEquals(100000, $this->service()->balanceOf($this->kas->id, '2026-06'));
        $this->assertEquals(30000, $this->service()->balanceOf($this->kas->id, '2026-07'));
        $this->assertEquals(130000, $this->service()->balanceOf($this->kas->id)); // all periods
    }
}
