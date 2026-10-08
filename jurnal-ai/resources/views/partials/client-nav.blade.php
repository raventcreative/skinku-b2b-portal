@if ($client?->exists)
    <div class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-bold text-ink-900">{{ $client->name }}</h1>
                    <span class="{{ $client->type === \App\Models\Client::TYPE_INTERNAL ? 'badge-info' : 'badge-mute' }}">
                        {{ $client->type === \App\Models\Client::TYPE_INTERNAL ? 'Brand sendiri' : 'Klien' }}
                    </span>
                    @unless ($client->is_active)<span class="badge-bad">Nonaktif</span>@endunless
                </div>
                @if ($client->legal_name)
                    <p class="text-xs text-ink-500 mt-0.5">{{ $client->legal_name }}</p>
                @endif
            </div>
            <a href="{{ route('documents.create', $client) }}" class="btn-primary">+ Dokumen baru</a>
        </div>

        <nav class="flex flex-wrap gap-1 border-b border-ink-200 text-sm">
            @php
                $tabs = [
                    ['clients.show', 'Ringkasan'],
                    ['documents.index', 'Dokumen'],
                    ['journals.index', 'Jurnal'],
                    ['reports.trial-balance', 'Neraca Saldo'],
                    ['reports.income-statement', 'Laba Rugi'],
                    ['reports.balance-sheet', 'Neraca'],
                    ['accounts.index', 'COA'],
                ];
            @endphp
            @foreach ($tabs as [$route, $label])
                <a href="{{ route($route, $client) }}"
                   class="px-3 py-2 -mb-px border-b-2 font-medium {{ request()->routeIs($route) ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-500 hover:text-ink-800' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>
@endif
