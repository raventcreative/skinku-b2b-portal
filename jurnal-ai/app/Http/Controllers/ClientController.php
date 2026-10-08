<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Document;
use App\Models\Journal;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Reporting\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function __construct(
        private ChartOfAccounts $coa,
        private ReportService $reports,
    ) {}

    public function index(Request $request)
    {
        $clients = Client::query()
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->withCount(['journals' => fn ($q) => $q->posted()])
            ->orderBy('type')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'q' => $request->string('q')->toString(),
            'type' => $request->string('type')->toString(),
        ]);
    }

    public function create()
    {
        return view('clients.form', ['client' => new Client(['type' => Client::TYPE_EXTERNAL, 'is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = Client::create($this->validated($request));
        $created = $this->coa->seedFor($client);

        return redirect()->route('clients.show', $client)
            ->with('status', "Klien \"{$client->name}\" dibuat dengan {$created} akun COA standar. Silakan sesuaikan COA-nya kalau perlu.");
    }

    public function show(Client $client, Request $request)
    {
        $period = $request->string('period')->toString() ?: Journal::periodsFor($client->id)[0];

        return view('clients.show', [
            'client' => $client,
            'period' => $period,
            'periods' => Journal::periodsFor($client->id),
            'snapshot' => $this->reports->snapshot($client->id, $period),
            'recentJournals' => $client->journals()->with('lines')->latest('date')->latest('id')->limit(8)->get(),
            'pendingDocuments' => $client->documents()
                ->whereIn('status', [Document::STATUS_UPLOADED, Document::STATUS_EXTRACTED, Document::STATUS_FAILED])
                ->latest()->limit(8)->get(),
            'accountCount' => $client->accounts()->count(),
        ]);
    }

    public function edit(Client $client)
    {
        return view('clients.form', ['client' => $client]);
    }

    public function update(Client $client, Request $request): RedirectResponse
    {
        $client->update($this->validated($request, $client));

        return redirect()->route('clients.show', $client)->with('status', 'Data klien diperbarui.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        // Buku yang sudah berisi jurnal posted tidak boleh hilang dari sistem —
        // nonaktifkan saja supaya riwayat & laporan periode lalu tetap utuh.
        if ($client->journals()->posted()->exists()) {
            $client->update(['is_active' => false]);

            return redirect()->route('clients.index')
                ->with('status', "Klien \"{$client->name}\" sudah punya jurnal posted, jadi dinonaktifkan (tidak dihapus) supaya riwayatnya utuh.");
        }

        $name = $client->name;
        $client->delete();

        return redirect()->route('clients.index')->with('status', "Klien \"{$name}\" dihapus.");
    }

    /** Pasang akun template yang belum ada (mis. setelah template diperbarui). */
    public function syncCoa(Client $client): RedirectResponse
    {
        $created = $this->coa->seedFor($client);

        return redirect()->route('accounts.index', $client)->with('status', $created > 0
            ? "{$created} akun baru dari template ditambahkan."
            : 'COA klien sudah lengkap — tidak ada akun template yang perlu ditambah.');
    }

    private function validated(Request $request, ?Client $client = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(Client::TYPES))],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'fiscal_year_start' => ['nullable', 'string', 'max:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
