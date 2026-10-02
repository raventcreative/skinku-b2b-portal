<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingReklasTest extends TestCase
{
    use RefreshDatabase;

    public function test_reklas_saldo_per_bulan_lewat_jurnal_penyesuaian_dan_aman_diulang(): void
    {
        $b = AccBranch::create(['code' => 'SBY-T', 'name' => 'Surabaya Timur', 'is_active' => true]);
        $bank = AccAccount::create(['code' => '1002', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'cash', 'normal_balance' => 'debit']);
        $ecom = AccAccount::create(['code' => '6005', 'name' => 'Beban Biaya E-commerce', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);
        $tt = AccAccount::create(['code' => '6098', 'name' => 'Beban E-commerce (Tiktok)', 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);
        $acc = app(AccountingService::class);
        foreach ([['2026-08-10', 1_000_000], ['2026-09-05', 2_000_000], ['2026-09-20', 500_000]] as [$d, $n]) {
            $acc->record(['branch_id' => $b->id, 'date' => $d], [['account_id' => $tt->id, 'debit' => $n], ['account_id' => $bank->id, 'credit' => $n]]);
        }

        // Pratinjau: tak membuat jurnal.
        $this->artisan('akuntansi:reklas', ['dari' => '6098', 'ke' => '6005'])->assertSuccessful();
        $this->assertSame(3, AccJournal::count());

        $this->artisan('akuntansi:reklas', ['dari' => '6098', 'ke' => '6005', '--jalankan' => true, '--nonaktifkan' => true])->assertSuccessful();
        $this->assertSame(5, AccJournal::count());                 // 1 jurnal per bulan (Agu, Sep)
        $this->assertSame(1, AccJournal::where('type', 'adjustment')->where('period', '2026-09')->count());

        $sep = collect(app(FinancialReportService::class)->incomeStatement('2026-09')['lines']['beban_operasional'])->keyBy('code');
        $this->assertEqualsWithDelta(2_500_000, $sep['6005']['amount'], 0.01);
        $this->assertArrayNotHasKey('6098', $sep->all());          // saldo 6098 nol → tak tampil
        $this->assertFalse($tt->fresh()->is_active);

        // Diulang → tak ada jurnal baru.
        $this->artisan('akuntansi:reklas', ['dari' => '6098', 'ke' => '6005', '--jalankan' => true])->assertSuccessful();
        $this->assertSame(5, AccJournal::count());
    }
}
