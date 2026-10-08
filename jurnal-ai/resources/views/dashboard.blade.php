@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
    @unless ($aiReady)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">Otak AI belum terpasang.</p>
            <p>Isi <code class="font-mono">OPENAI_API_KEY</code> (atau <code class="font-mono">ANTHROPIC_API_KEY</code> + set
            <code class="font-mono">AI_PROVIDER=anthropic</code>) di <code class="font-mono">.env</code>.
            Tanpa itu, upload dokumen tetap tersimpan tapi tidak bisa dibaca otomatis — jurnal manual tetap jalan.</p>
        </div>
    @endunless

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Klien aktif', $totals['clients'], 'buku yang dikelola'],
            ['Dokumen masuk', $totals['documents'], 'struk, invoice, mutasi, catatan'],
            ['Jurnal posted', $totals['journals'], 'sudah masuk buku'],
            ['Perlu direview', $totals['pending'], 'dokumen belum diposting'],
        ] as [$label, $value, $hint])
            <div class="card p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold text-ink-900 font-mono tabular-nums">{{ number_format($value, 0, ',', '.') }}</p>
                <p class="text-xs text-ink-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-bold text-ink-900">Posisi tiap klien — {{ $period }}</h2>
        @if (auth()->user()->isAdmin())
            <a href="{{ route('clients.create') }}" class="btn-primary">+ Klien baru</a>
        @endif
    </div>

    @if ($rows->isEmpty())
        <div class="card p-10 text-center">
            <p class="font-semibold text-ink-800">Belum ada klien.</p>
            <p class="mt-1 text-sm text-ink-500">Buat klien dulu — tiap klien dapat COA standar dan buku jurnalnya sendiri.</p>
            @if (auth()->user()->isAdmin())
                <a href="{{ route('clients.create') }}" class="btn-primary mt-4">Buat klien pertama</a>
            @endif
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Klien</th>
                        <th class="th num">Pendapatan</th>
                        <th class="th num">Beban</th>
                        <th class="th num">Laba/Rugi</th>
                        <th class="th num">Saldo Kas</th>
                        <th class="th num">Perlu review</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $s = $row['snapshot']; @endphp
                        <tr class="hover:bg-ink-50">
                            <td class="td">
                                <a href="{{ route('clients.show', $row['client']) }}" class="font-semibold text-brand-700 hover:underline">
                                    {{ $row['client']->name }}
                                </a>
                                <span class="ml-1 {{ $row['client']->type === \App\Models\Client::TYPE_INTERNAL ? 'badge-info' : 'badge-mute' }}">
                                    {{ $row['client']->type === \App\Models\Client::TYPE_INTERNAL ? 'sendiri' : 'klien' }}
                                </span>
                            </td>
                            <td class="td num text-emerald-700">{{ \App\Support\Rupiah::format($s['revenue']) }}</td>
                            <td class="td num text-rose-700">{{ \App\Support\Rupiah::format($s['expense']) }}</td>
                            <td class="td num font-semibold {{ $s['net_income'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ \App\Support\Rupiah::format($s['net_income']) }}
                            </td>
                            <td class="td num">{{ \App\Support\Rupiah::format($s['cash']) }}</td>
                            <td class="td num">
                                @if ($row['pending'] > 0)
                                    <a href="{{ route('documents.index', $row['client']) }}" class="badge-warn">{{ $row['pending'] }} dokumen</a>
                                @else
                                    <span class="text-ink-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-400">Angka pendapatan/beban/laba = mutasi bulan {{ $period }}. Saldo kas = akumulasi sampai akhir bulan itu.</p>
    @endif
@endsection
