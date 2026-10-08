<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Journal;
use App\Services\Reporting\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function trialBalance(Client $client, Request $request)
    {
        [$period, $periods] = $this->period($client, $request);

        return view('reports.trial_balance', [
            'client' => $client, 'period' => $period, 'periods' => $periods,
            'report' => $this->reports->trialBalance($client->id, $period),
        ]);
    }

    public function incomeStatement(Client $client, Request $request)
    {
        [$period, $periods] = $this->period($client, $request);

        return view('reports.income_statement', [
            'client' => $client, 'period' => $period, 'periods' => $periods,
            'report' => $this->reports->incomeStatement($client->id, $period),
        ]);
    }

    public function balanceSheet(Client $client, Request $request)
    {
        [$period, $periods] = $this->period($client, $request);

        return view('reports.balance_sheet', [
            'client' => $client, 'period' => $period, 'periods' => $periods,
            'report' => $this->reports->balanceSheet($client->id, $period),
        ]);
    }

    /** @return array{0:string,1:array<int,string>} */
    private function period(Client $client, Request $request): array
    {
        $periods = Journal::periodsFor($client->id);
        $requested = $request->string('period')->toString();
        // Periode bebas diisi lewat query (mis. bookmark bulan lama) — validasi
        // bentuknya saja, tidak perlu ada jurnalnya (laporan kosong itu jawaban sah).
        $period = preg_match('/^\d{4}-\d{2}$/', $requested) === 1 ? $requested : $periods[0];

        return [$period, $periods];
    }
}
