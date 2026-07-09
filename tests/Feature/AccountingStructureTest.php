<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\AccJournalLine;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountingStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_tables_and_source_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('acc_accounts'));
        $this->assertTrue(Schema::hasTable('acc_journals'));
        $this->assertTrue(Schema::hasTable('acc_journal_lines'));
        $this->assertTrue(Schema::hasColumn('acc_journals', 'source_type'));
        $this->assertTrue(Schema::hasColumn('acc_journals', 'source_id'));
    }

    public function test_chart_of_account_seeds_cleanly(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Single branch (all ops in one location).
        $this->assertEquals(1, AccBranch::count());

        // Every account has a valid type + normal_balance.
        foreach (AccAccount::all() as $acc) {
            $this->assertContains($acc->type, AccAccount::TYPES, "type invalid for {$acc->code}");
            $this->assertContains($acc->normal_balance, ['debit', 'credit'], "normal_balance invalid for {$acc->code}");
        }

        // Inventory is split into bahan baku + barang jadi (no WIP, no single "Persediaan").
        $this->assertDatabaseHas('acc_accounts', ['code' => '1201', 'name' => 'Persediaan Bahan Baku', 'subtype' => 'inventory']);
        $this->assertDatabaseHas('acc_accounts', ['code' => '1202', 'name' => 'Persediaan Barang Jadi', 'subtype' => 'inventory']);
        $this->assertDatabaseMissing('acc_accounts', ['name' => 'Persediaan']);

        // Dedicated shipping income account (separate from Pendapatan Lain-lain).
        $this->assertDatabaseHas('acc_accounts', ['code' => '4004', 'name' => 'Pendapatan Ongkir', 'type' => 'revenue']);

        // Codes are unique.
        $this->assertEquals(AccAccount::count(), AccAccount::distinct('code')->count('code'));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $count = AccAccount::count();
        $this->seed(ChartOfAccountSeeder::class); // run again
        $this->assertEquals($count, AccAccount::count());
    }

    public function test_journal_balance_helper(): void
    {
        $branch = AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);
        $kas = AccAccount::create(['code' => '1002', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'cash', 'normal_balance' => 'debit']);
        $sales = AccAccount::create(['code' => '4001', 'name' => 'Penjualan', 'type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']);

        $journal = AccJournal::create([
            'branch_id' => $branch->id, 'date' => '2026-06-01', 'period' => '2026-06',
            'type' => 'sales', 'status' => 'draft',
        ]);
        AccJournalLine::create(['journal_id' => $journal->id, 'account_id' => $kas->id, 'branch_id' => $branch->id, 'debit' => 100000, 'credit' => 0]);
        AccJournalLine::create(['journal_id' => $journal->id, 'account_id' => $sales->id, 'branch_id' => $branch->id, 'debit' => 0, 'credit' => 100000]);

        $this->assertTrue($journal->load('lines')->isBalanced());

        // Add an unbalanced line -> no longer balanced.
        AccJournalLine::create(['journal_id' => $journal->id, 'account_id' => $sales->id, 'branch_id' => $branch->id, 'debit' => 0, 'credit' => 5000]);
        $this->assertFalse($journal->load('lines')->isBalanced());
    }
}
