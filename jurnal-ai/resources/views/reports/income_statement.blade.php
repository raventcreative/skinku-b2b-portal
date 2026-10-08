@extends('layouts.app')
@section('title', 'Laba Rugi')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold text-ink-900">Laporan Laba Rugi</h2>
            <p class="text-xs text-ink-500">{{ $client->name }} · periode {{ $period }}</p>
        </div>
        @include('partials.period-picker')
    </div>

    @php
        $sections = [
            ['Pendapatan', $report['groups']['revenue'], $report['totals']['revenue'], 'text-emerald-700'],
            ['Harga Pokok Penjualan', $report['groups']['cogs'], $report['totals']['cogs'], 'text-rose-700'],
        ];
    @endphp

    <div class="card max-w-3xl overflow-hidden">
        <table class="w-full">
            @foreach ($sections as [$label, $rows, $total, $tone])
                <tbody>
                    <tr><td class="th" colspan="2">{{ $label }}</td></tr>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="td pl-6"><span class="font-mono text-xs text-ink-400">{{ $row['account']->code }}</span> {{ $row['account']->name }}</td>
                            <td class="td num w-48">{{ \App\Support\Rupiah::format($row['value']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="td pl-6 text-sm text-ink-400" colspan="2">—</td></tr>
                    @endforelse
                    <tr class="font-semibold">
                        <td class="td pl-6">Total {{ $label }}</td>
                        <td class="td num {{ $tone }}">{{ \App\Support\Rupiah::format($total) }}</td>
                    </tr>
                </tbody>
            @endforeach

            <tbody>
                <tr class="bg-ink-50 font-bold">
                    <td class="td">LABA KOTOR</td>
                    <td class="td num {{ $report['gross_profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ \App\Support\Rupiah::format($report['gross_profit']) }}
                    </td>
                </tr>

                <tr><td class="th" colspan="2">Beban Operasional</td></tr>
                @forelse ($report['groups']['opex'] as $row)
                    <tr>
                        <td class="td pl-6"><span class="font-mono text-xs text-ink-400">{{ $row['account']->code }}</span> {{ $row['account']->name }}</td>
                        <td class="td num w-48">{{ \App\Support\Rupiah::format($row['value']) }}</td>
                    </tr>
                @empty
                    <tr><td class="td pl-6 text-sm text-ink-400" colspan="2">—</td></tr>
                @endforelse
                <tr class="font-semibold">
                    <td class="td pl-6">Total Beban Operasional</td>
                    <td class="td num text-rose-700">{{ \App\Support\Rupiah::format($report['totals']['opex']) }}</td>
                </tr>

                <tr class="bg-ink-50 font-bold">
                    <td class="td">LABA OPERASIONAL</td>
                    <td class="td num {{ $report['operating_income'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ \App\Support\Rupiah::format($report['operating_income']) }}
                    </td>
                </tr>

                @if ($report['groups']['other'])
                    <tr><td class="th" colspan="2">Beban Non-Operasional</td></tr>
                    @foreach ($report['groups']['other'] as $row)
                        <tr>
                            <td class="td pl-6"><span class="font-mono text-xs text-ink-400">{{ $row['account']->code }}</span> {{ $row['account']->name }}</td>
                            <td class="td num w-48">{{ \App\Support\Rupiah::format($row['value']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="td pl-6">Total Non-Operasional</td>
                        <td class="td num text-rose-700">{{ \App\Support\Rupiah::format($report['totals']['other']) }}</td>
                    </tr>
                @endif

                <tr class="bg-ink-900 font-bold text-white">
                    <td class="td border-0">LABA (RUGI) BERSIH</td>
                    <td class="td num border-0 {{ $report['net_income'] >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                        Rp{{ \App\Support\Rupiah::format($report['net_income']) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection
