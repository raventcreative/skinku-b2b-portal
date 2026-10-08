@extends('layouts.app')
@section('title', 'Jurnal')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="label" for="period">Periode</label>
                <select id="period" name="period" class="field w-36">
                    <option value="">Semua</option>
                    @foreach ($periods as $p)
                        <option value="{{ $p }}" @selected(($filters['period'] ?? '') === $p)>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="status">Status</label>
                <select id="status" name="status" class="field w-32">
                    <option value="">Semua</option>
                    @foreach (['posted' => 'Posted', 'draft' => 'Draft', 'void' => 'Void'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="q">Cari</label>
                <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="field w-56" placeholder="keterangan / referensi">
            </div>
            <button class="btn-ghost">Filter</button>
        </form>
        <a href="{{ route('journals.create', $client) }}" class="btn-primary">+ Jurnal manual</a>
    </div>

    <div class="space-y-3">
        @forelse ($journals as $journal)
            <div class="card p-4 {{ $journal->status === 'void' ? 'opacity-60' : '' }}">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div class="text-sm">
                        <span class="font-mono text-xs text-ink-400">#{{ $journal->id }}</span>
                        <span class="ml-1 font-semibold text-ink-900">{{ $journal->date->format('d/m/Y') }}</span>
                        <span class="ml-1 text-ink-700">{{ $journal->description ?: '—' }}</span>
                        @if ($journal->reference)<span class="ml-1 font-mono text-xs text-ink-400">({{ $journal->reference }})</span>@endif
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge-mute">{{ $journal->type }}</span>
                        <span class="{{ ['posted' => 'badge-ok', 'draft' => 'badge-warn', 'void' => 'badge-bad'][$journal->status] }}">{{ $journal->status }}</span>
                        @if ($journal->document)
                            <a href="{{ route('documents.show', [$client, $journal->document]) }}" class="text-xs font-medium text-brand-700 hover:underline">dokumen</a>
                        @endif
                        @if ($journal->status !== 'void')
                            <form method="POST" action="{{ route('journals.void', [$client, $journal]) }}"
                                  onsubmit="return confirm('Void jurnal ini? Nilainya berhenti dihitung di laporan.')">
                                @csrf
                                <button class="btn-ghost btn-sm">Void</button>
                            </form>
                        @endif
                        @if (auth()->user()->isAdmin())
                            <form method="POST" action="{{ route('journals.destroy', [$client, $journal]) }}"
                                  onsubmit="return confirm('Hapus permanen? Lebih aman pakai Void supaya jejaknya tetap ada.')">
                                @csrf @method('DELETE')
                                <button class="btn-danger btn-sm">Hapus</button>
                            </form>
                        @endif
                    </div>
                </div>

                <table class="w-full text-sm">
                    @foreach ($journal->lines as $line)
                        <tr>
                            <td class="py-0.5 text-ink-600">
                                <span class="font-mono text-xs">{{ $line->account->code }}</span> {{ $line->account->name }}
                                @if ($line->memo)<span class="text-xs text-ink-400"> — {{ $line->memo }}</span>@endif
                            </td>
                            <td class="num w-32 py-0.5">{{ (float) $line->debit ? \App\Support\Rupiah::format((float) $line->debit) : '' }}</td>
                            <td class="num w-32 py-0.5">{{ (float) $line->credit ? \App\Support\Rupiah::format((float) $line->credit) : '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-ink-200 font-semibold">
                        <td class="py-1 text-xs uppercase tracking-wide text-ink-500">Total</td>
                        <td class="num py-1">{{ \App\Support\Rupiah::format($journal->totalDebit()) }}</td>
                        <td class="num py-1">{{ \App\Support\Rupiah::format($journal->totalCredit()) }}</td>
                    </tr>
                </table>
            </div>
        @empty
            <div class="card p-10 text-center text-sm text-ink-500">
                Belum ada jurnal yang cocok.
                <a href="{{ route('documents.create', $client) }}" class="font-medium text-brand-700 hover:underline">Upload dokumen</a> atau
                <a href="{{ route('journals.create', $client) }}" class="font-medium text-brand-700 hover:underline">catat manual</a>.
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $journals->links() }}</div>
@endsection
