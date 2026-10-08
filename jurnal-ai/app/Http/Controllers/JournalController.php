<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountingException;
use App\Models\Client;
use App\Models\Journal;
use App\Services\Accounting\JournalPoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(private JournalPoster $poster) {}

    public function index(Client $client, Request $request)
    {
        $journals = $client->journals()
            ->with(['lines.account', 'document'])
            ->when($request->filled('period'), fn ($q) => $q->where('period', $request->string('period')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('description', 'like', $term)->orWhere('reference', 'like', $term));
            })
            ->latest('date')->latest('id')
            ->paginate(25)->withQueryString();

        return view('journals.index', [
            'client' => $client,
            'journals' => $journals,
            'periods' => Journal::periodsFor($client->id),
            'filters' => $request->only('period', 'status', 'q'),
        ]);
    }

    public function create(Client $client)
    {
        return view('journals.form', [
            'client' => $client,
            'accounts' => $client->accounts()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Client $client, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'string', 'max:30'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $journal = $this->poster->record([
                'client_id' => $client->id,
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'general',
                'created_by' => $request->user()->id,
            ], $data['lines']);
        } catch (AccountingException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('journals.index', $client)
            ->with('status', "Jurnal #{$journal->id} diposting.");
    }

    public function void(Client $client, Journal $journal): RedirectResponse
    {
        $this->assertOwned($client, $journal);
        $this->poster->void($journal);

        return back()->with('status', "Jurnal #{$journal->id} di-void — tidak lagi dihitung di laporan.");
    }

    public function destroy(Client $client, Journal $journal): RedirectResponse
    {
        $this->assertOwned($client, $journal);
        $id = $journal->id;
        $journal->delete();

        return back()->with('status', "Jurnal #{$id} dihapus permanen.");
    }

    private function assertOwned(Client $client, Journal $journal): void
    {
        abort_unless($journal->client_id === $client->id, 404);
    }
}
