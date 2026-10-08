@extends('layouts.app')
@section('title', $client->name)

@section('content')
    <div class="mb-4">@include('partials.period-picker')</div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Pendapatan', $snapshot['revenue'], 'text-emerald-700'],
            ['Beban', $snapshot['expense'], 'text-rose-700'],
            ['Laba / Rugi', $snapshot['net_income'], $snapshot['net_income'] >= 0 ? 'text-emerald-700' : 'text-rose-700'],
            ['Saldo Kas & Bank', $snapshot['cash'], 'text-ink-900'],
        ] as [$label, $value, $tone])
            <div class="card p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold font-mono tabular-nums {{ $tone }}">Rp{{ \App\Support\Rupiah::format($value) }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h2 class="mb-3 font-bold text-ink-900">Dokumen yang perlu ditindak</h2>
            @forelse ($pendingDocuments as $document)
                <a href="{{ route('documents.show', [$client, $document]) }}"
                   class="flex items-center justify-between gap-3 border-b border-ink-100 py-2 last:border-0 hover:bg-ink-50">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-ink-800">{{ $document->title ?: $document->original_name ?: 'Teks tempel' }}</p>
                        <p class="text-xs text-ink-400">{{ $document->kindLabel() }} · {{ $document->created_at->diffForHumans() }}</p>
                    </div>
                    @include('documents._status', ['document' => $document])
                </a>
            @empty
                <p class="py-6 text-center text-sm text-ink-500">
                    Bersih — tidak ada dokumen yang menggantung.
                    <a href="{{ route('documents.create', $client) }}" class="text-brand-700 font-medium hover:underline">Upload dokumen baru</a>
                </p>
            @endforelse
        </div>

        <div class="card p-5">
            <h2 class="mb-3 font-bold text-ink-900">Beban terbesar {{ $period }}</h2>
            @forelse ($snapshot['top_expenses'] as $row)
                <div class="flex items-baseline justify-between gap-2 border-b border-ink-100 py-1.5 last:border-0">
                    <span class="truncate text-sm text-ink-700">{{ $row['account']->name }}</span>
                    <span class="num text-sm">{{ \App\Support\Rupiah::format($row['value']) }}</span>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-ink-500">Belum ada beban tercatat di periode ini.</p>
            @endforelse
        </div>
    </div>

    <div class="card mt-5 overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-ink-200 px-5 py-3">
            <h2 class="font-bold text-ink-900">Jurnal terakhir</h2>
            <a href="{{ route('journals.index', $client) }}" class="text-sm font-medium text-brand-700 hover:underline">Lihat semua</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Tanggal</th>
                        <th class="th">Keterangan</th>
                        <th class="th num">Nilai</th>
                        <th class="th">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentJournals as $journal)
                        <tr class="hover:bg-ink-50">
                            <td class="td font-mono text-xs">{{ $journal->date->format('d/m/Y') }}</td>
                            <td class="td">{{ $journal->description ?: '—' }}</td>
                            <td class="td num">{{ \App\Support\Rupiah::format($journal->totalDebit()) }}</td>
                            <td class="td">
                                <span class="{{ ['posted' => 'badge-ok', 'draft' => 'badge-warn', 'void' => 'badge-bad'][$journal->status] }}">{{ $journal->status }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="td py-8 text-center text-sm text-ink-500">
                            Buku masih kosong. Mulai dari <a href="{{ route('documents.create', $client) }}" class="text-brand-700 font-medium hover:underline">upload dokumen</a>
                            atau <a href="{{ route('journals.create', $client) }}" class="text-brand-700 font-medium hover:underline">jurnal manual</a>.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-ink-400">COA klien ini: {{ $accountCount }} akun. <a href="{{ route('accounts.index', $client) }}" class="text-brand-700 hover:underline">Kelola COA</a></p>
@endsection
