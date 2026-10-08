<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Document;
use App\Models\Journal;
use App\Services\Ai\AiProviderFactory;
use App\Services\Reporting\ReportService;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index()
    {
        $period = now()->format('Y-m');

        $clients = Client::active()->orderBy('type')->orderBy('name')->get()
            ->map(fn (Client $client) => [
                'client' => $client,
                'snapshot' => $this->reports->snapshot($client->id, $period),
                'pending' => $client->documents()
                    ->whereIn('status', [Document::STATUS_UPLOADED, Document::STATUS_EXTRACTED])
                    ->count(),
            ]);

        return view('dashboard', [
            'period' => $period,
            'rows' => $clients,
            'aiReady' => AiProviderFactory::configured(),
            'totals' => [
                'clients' => $clients->count(),
                'documents' => Document::count(),
                'journals' => Journal::posted()->count(),
                'pending' => $clients->sum('pending'),
            ],
        ]);
    }
}
