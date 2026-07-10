<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CashFlowService;
use App\Services\ComparativeReportService;
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

        foreach (['/accounting/laporan', '/accounting/laba-rugi', '/accounting/neraca', '/accounting/arus-kas', '/accounting/neraca-saldo'] as $url) {
            $this->actingAs($admin)->get($url.'?period=2026-06')->assertOk();
        }
        $this->actingAs($admin)->get('/accounting/banding?a=2026-06&b=2026-05')->assertOk();
        $this->actingAs($admin)->get('/accounting/tren?year=2026')->assertOk();
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

    public function test_admin_can_hard_delete_journal(): void
    {
        $this->seedJune();
        $j = AccJournal::first();
        $this->actingAs($this->user(User::ROLE_ADMIN))->delete('/accounting/jurnal/'.$j->id)->assertRedirect();
        $this->assertDatabaseMissing('acc_journals', ['id' => $j->id]);
        $this->assertDatabaseMissing('acc_journal_lines', ['journal_id' => $j->id]); // baris ikut terhapus
    }

    public function test_reseller_cannot_delete_journal(): void
    {
        $this->seedJune();
        $j = AccJournal::first();
        $this->actingAs($this->user(User::ROLE_RESELLER))->delete('/accounting/jurnal/'.$j->id)->assertForbidden();
        $this->assertDatabaseHas('acc_journals', ['id' => $j->id]);
    }

    public function test_excel_import_creates_journals_and_dedups(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/accounting/impor-excel')->assertOk();

        $payload = [
            'branch_id' => $this->branch->id,
            'source_label' => 'Jurnal Penerimaan',
            'journals' => [
                [
                    'date' => '2026-06-05', 'reference' => 'Setoran tunai', 'type' => 'cash_in',
                    'lines' => [
                        ['account_id' => $this->acc['1002']->id, 'debit' => 5_000_000, 'credit' => 0],
                        ['account_id' => $this->acc['4001']->id, 'debit' => 0, 'credit' => 5_000_000],
                    ],
                ],
                [ // tidak balance → dilewati
                    'date' => '2026-06-06', 'reference' => 'Rusak', 'type' => 'general',
                    'lines' => [
                        ['account_id' => $this->acc['1002']->id, 'debit' => 1_000_000, 'credit' => 0],
                        ['account_id' => $this->acc['4001']->id, 'debit' => 0, 'credit' => 900_000],
                    ],
                ],
            ],
        ];

        $res = $this->actingAs($admin)->postJson('/accounting/impor-excel', $payload);
        $res->assertOk()->assertJson(['ok' => true, 'imported' => 1, 'duplicate' => 0, 'error' => 1]);
        $this->assertDatabaseHas('acc_journals', ['reference' => 'Setoran tunai', 'source_type' => 'excel_import']);
        $this->assertDatabaseCount('acc_journals', 1);

        // impor ulang payload yang sama → jurnal balance jadi duplikat (idempoten)
        $res2 = $this->actingAs($admin)->postJson('/accounting/impor-excel', $payload);
        $res2->assertOk()->assertJson(['imported' => 0, 'duplicate' => 1, 'error' => 1]);
        $this->assertDatabaseCount('acc_journals', 1);
    }

    public function test_reseller_cannot_import_excel(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))
            ->postJson('/accounting/impor-excel', ['branch_id' => $this->branch->id, 'journals' => []])
            ->assertForbidden();
    }

    public function test_excel_import_keeps_genuinely_identical_journals(): void
    {
        // Dua transaksi identik (tgl+akun+nominal sama) dalam satu batch = dua kejadian sah.
        $one = [
            'date' => '2026-06-05', 'reference' => 'Erin ongkir', 'type' => 'cash_in',
            'lines' => [
                ['account_id' => $this->acc['1002']->id, 'debit' => 30000, 'credit' => 0],
                ['account_id' => $this->acc['4001']->id, 'debit' => 0, 'credit' => 30000],
            ],
        ];
        $payload = ['branch_id' => $this->branch->id, 'journals' => [$one, $one]]; // dua kali persis

        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->postJson('/accounting/impor-excel', $payload)
            ->assertOk()->assertJson(['imported' => 2, 'duplicate' => 0]);
        $this->assertDatabaseCount('acc_journals', 2);

        // re-impor batch yang sama → keduanya terdeteksi duplikat (idempoten)
        $this->actingAs($admin)->postJson('/accounting/impor-excel', $payload)
            ->assertOk()->assertJson(['imported' => 0, 'duplicate' => 2]);
        $this->assertDatabaseCount('acc_journals', 2);
    }

    public function test_excel_import_purge_removes_only_excel_journals(): void
    {
        $this->seedJune(); // jurnal manual (source_type null)
        $admin = $this->user(User::ROLE_ADMIN);
        $manualCount = AccJournal::count();

        $this->actingAs($admin)->postJson('/accounting/impor-excel', [
            'branch_id' => $this->branch->id,
            'journals' => [[
                'date' => '2026-06-07', 'reference' => 'X', 'type' => 'cash_in',
                'lines' => [
                    ['account_id' => $this->acc['1002']->id, 'debit' => 1000, 'credit' => 0],
                    ['account_id' => $this->acc['4001']->id, 'debit' => 0, 'credit' => 1000],
                ],
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('acc_journals', ['source_type' => 'excel_import']);

        $this->actingAs($admin)->post('/accounting/impor-excel/hapus', ['period' => '2026-06'])->assertRedirect();
        $this->assertDatabaseMissing('acc_journals', ['source_type' => 'excel_import']);
        $this->assertEquals($manualCount, AccJournal::count()); // jurnal manual utuh
    }

    public function test_reseller_cannot_purge_excel_import(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))
            ->post('/accounting/impor-excel/hapus', ['period' => '2026-06'])->assertForbidden();
    }

    public function test_opening_balance_survives_purge_and_replaces_on_reimport(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $opening = fn (float $modal) => [
            'branch_id' => $this->branch->id,
            'is_opening' => true,
            'journals' => [[
                'date' => '2026-06-01', 'reference' => 'Saldo Awal', 'type' => 'general',
                'lines' => [
                    ['account_id' => $this->acc['1002']->id, 'debit' => $modal, 'credit' => 0],
                    ['account_id' => $this->acc['3001']->id, 'debit' => 0, 'credit' => $modal],
                ],
            ]],
        ];
        // impor saldo awal → source_type opening_balance
        $this->actingAs($admin)->postJson('/accounting/impor-excel', $opening(100_000))->assertOk();
        $this->assertDatabaseHas('acc_journals', ['source_type' => 'opening_balance']);

        // impor jurnal biasa
        $this->actingAs($admin)->postJson('/accounting/impor-excel', [
            'branch_id' => $this->branch->id,
            'journals' => [[
                'date' => '2026-06-10', 'reference' => 'Jual', 'type' => 'cash_in',
                'lines' => [
                    ['account_id' => $this->acc['1002']->id, 'debit' => 5000, 'credit' => 0],
                    ['account_id' => $this->acc['4001']->id, 'debit' => 0, 'credit' => 5000],
                ],
            ]],
        ])->assertOk();

        // Hapus impor Excel → saldo awal HARUS tetap ada
        $this->actingAs($admin)->post('/accounting/impor-excel/hapus', ['period' => '2026-06'])->assertRedirect();
        $this->assertDatabaseMissing('acc_journals', ['source_type' => 'excel_import']);
        $this->assertDatabaseHas('acc_journals', ['source_type' => 'opening_balance']);

        // impor ulang saldo awal periode sama → ganti, bukan dobel
        $this->actingAs($admin)->postJson('/accounting/impor-excel', $opening(120_000))->assertOk();
        $this->assertEquals(1, AccJournal::where('source_type', 'opening_balance')->count());
    }

    public function test_cash_flow_direct_classifies_and_reconciles(): void
    {
        $a = fn ($code) => $this->acc[$code]->id;
        $svc = app(AccountingService::class);
        // Saldo awal: kas 100jt (opening_balance → jadi kas awal, bukan arus)
        $svc->record(['branch_id' => $this->branch->id, 'date' => '2026-06-01', 'source_type' => 'opening_balance'], [
            ['account_id' => $a('1002'), 'debit' => 100_000_000],
            ['account_id' => $a('3001'), 'credit' => 100_000_000],
        ]);
        $this->je('2026-06-10', $a('1002'), $a('4001'), 50_000_000); // jual tunai → operasi +50
        $this->je('2026-06-15', $a('6001'), $a('1002'), 20_000_000); // bayar iklan → operasi -20
        $this->je('2026-06-20', $a('1002'), $a('2101'), 30_000_000); // terima pinjaman → pendanaan +30

        $cf = app(CashFlowService::class)->directCashFlow('2026-06');

        $this->assertEqualsWithDelta(30_000_000, $cf['totals']['operating'], 0.01);
        $this->assertEqualsWithDelta(30_000_000, $cf['totals']['financing'], 0.01);
        $this->assertEqualsWithDelta(0, $cf['totals']['investing'], 0.01);
        $this->assertEqualsWithDelta(60_000_000, $cf['net'], 0.01);
        $this->assertEqualsWithDelta(100_000_000, $cf['kas_awal'], 0.01); // dari saldo awal
        $this->assertEqualsWithDelta(160_000_000, $cf['kas_akhir'], 0.01);
        $this->assertTrue($cf['reconciled']);
        // Modal (saldo awal) TIDAK muncul sebagai arus pendanaan
        $codes = array_column($cf['sections']['financing'], 'code');
        $this->assertNotContains('3001', $codes);
        $this->assertContains('2101', $codes); // Hutang Bank = pendanaan
    }

    public function test_comparative_summary_aggregates_year_and_month(): void
    {
        $a = fn ($code) => $this->acc[$code]->id;
        // dua bulan berbeda di 2026
        $this->je('2026-06-10', $a('1002'), $a('4001'), 100_000_000);
        $this->je('2026-07-10', $a('1002'), $a('4001'), 40_000_000);

        $svc = app(ComparativeReportService::class);
        // setahun 2026 = jumlah semua bulan
        $year = $svc->summary('2026');
        $this->assertEqualsWithDelta(140_000_000, $year['is']['penjualan_bersih'], 0.01);
        // satu bulan
        $jun = $svc->summary('2026-06');
        $this->assertEqualsWithDelta(100_000_000, $jun['is']['penjualan_bersih'], 0.01);

        // tren: 12 baris, Juni & Juli terisi
        $rows = $svc->monthlyIncome('2026');
        $this->assertCount(12, $rows);
        $this->assertEqualsWithDelta(100_000_000, $rows[5]['penjualan_bersih'], 0.01); // Juni (index 5)
        $this->assertEqualsWithDelta(40_000_000, $rows[6]['penjualan_bersih'], 0.01);  // Juli
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
