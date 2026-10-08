@extends('layouts.app')
@section('title', 'Neraca Saldo')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold text-ink-900">Neraca Saldo</h2>
            <p class="text-xs text-ink-500">Mutasi debit/kredit per akun di periode {{ $period }}. Hanya jurnal <em>posted</em>.</p>
        </div>
        @include('partials.period-picker')
    </div>

    @unless ($report['balanced'])
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            Total debit tidak sama dengan kredit. Ini seharusnya tidak mungkin terjadi — laporkan sebagai bug.
        </div>
    @endunless

    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th w-24">Kode</th>
                    <th class="th">Nama Akun</th>
                    <th class="th w-28">Tipe</th>
                    <th class="th num w-40">Debit</th>
                    <th class="th num w-40">Kredit</th>
                    <th class="th num w-40">Saldo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['rows'] as $row)
                    <tr class="hover:bg-ink-50">
                        <td class="td font-mono text-xs">{{ $row['account']->code }}</td>
                        <td class="td">{{ $row['account']->name }}</td>
                        <td class="td text-xs text-ink-500">{{ \App\Models\Account::TYPES[$row['account']->type] }}</td>
                        <td class="td num">{{ $row['debit'] ? \App\Support\Rupiah::format($row['debit']) : '' }}</td>
                        <td class="td num">{{ $row['credit'] ? \App\Support\Rupiah::format($row['credit']) : '' }}</td>
                        <td class="td num font-medium">{{ \App\Support\Rupiah::format($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="td py-10 text-center text-sm text-ink-500">Tidak ada mutasi di periode {{ $period }}.</td></tr>
                @endforelse
            </tbody>
            @if ($report['rows'])
                <tfoot>
                    <tr class="bg-ink-50 font-bold">
                        <td class="td" colspan="3">TOTAL</td>
                        <td class="td num">{{ \App\Support\Rupiah::format($report['total_debit']) }}</td>
                        <td class="td num">{{ \App\Support\Rupiah::format($report['total_credit']) }}</td>
                        <td class="td num">
                            <span class="{{ $report['balanced'] ? 'badge-ok' : 'badge-bad' }}">{{ $report['balanced'] ? 'Balance' : 'Selisih' }}</span>
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endsection
