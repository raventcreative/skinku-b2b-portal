@extends('layouts.app')
@section('title', 'Neraca')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold text-ink-900">Neraca</h2>
            <p class="text-xs text-ink-500">{{ $client->name }} · posisi per akhir {{ $period }} (akumulasi semua jurnal posted s/d bulan itu)</p>
        </div>
        @include('partials.period-picker')
    </div>

    @unless ($report['balanced'])
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            Aktiva ≠ Pasiva, selisih Rp{{ \App\Support\Rupiah::format($report['difference'], true) }}.
            Biasanya karena ada jurnal pembuka yang belum dicatat (saldo awal kas, modal, persediaan).
        </div>
    @endunless

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        <div class="card overflow-hidden">
            <p class="border-b border-ink-200 bg-ink-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Aktiva</p>
            <table class="w-full">
                <tbody>
                    @forelse ($report['groups']['asset'] as $row)
                        <tr>
                            <td class="td"><span class="font-mono text-xs text-ink-400">{{ $row['account']->code }}</span> {{ $row['account']->name }}</td>
                            <td class="td num w-44">{{ \App\Support\Rupiah::format($row['value']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="td text-sm text-ink-400" colspan="2">Belum ada aset tercatat.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="bg-ink-900 font-bold text-white">
                        <td class="td border-0">TOTAL AKTIVA</td>
                        <td class="td num border-0">Rp{{ \App\Support\Rupiah::format($report['total_active']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="card overflow-hidden">
            <p class="border-b border-ink-200 bg-ink-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Pasiva</p>
            <table class="w-full">
                <tbody>
                    <tr><td class="th" colspan="2">Liabilitas</td></tr>
                    @forelse ($report['groups']['liability'] as $row)
                        <tr>
                            <td class="td pl-6"><span class="font-mono text-xs text-ink-400">{{ $row['account']->code }}</span> {{ $row['account']->name }}</td>
                            <td class="td num w-44">{{ \App\Support\Rupiah::format($row['value']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="td pl-6 text-sm text-ink-400" colspan="2">—</td></tr>
                    @endforelse
                    <tr class="font-semibold">
                        <td class="td pl-6">Total Liabilitas</td>
                        <td class="td num">{{ \App\Support\Rupiah::format($report['totals']['liability']) }}</td>
                    </tr>

                    <tr><td class="th" colspan="2">Ekuitas</td></tr>
                    @foreach ($report['groups']['equity'] as $row)
                        <tr>
                            <td class="td pl-6"><span class="font-mono text-xs text-ink-400">{{ $row['account']->code }}</span> {{ $row['account']->name }}</td>
                            <td class="td num w-44">{{ \App\Support\Rupiah::format($row['value']) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="td pl-6">
                            Laba (Rugi) Berjalan
                            <span class="block text-xs text-ink-400">akumulasi pendapatan − beban s/d {{ $period }}</span>
                        </td>
                        <td class="td num {{ $report['retained_earnings'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                            {{ \App\Support\Rupiah::format($report['retained_earnings']) }}
                        </td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="td pl-6">Total Ekuitas</td>
                        <td class="td num">{{ \App\Support\Rupiah::format($report['total_equity']) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-ink-900 font-bold text-white">
                        <td class="td border-0">TOTAL PASIVA</td>
                        <td class="td num border-0">Rp{{ \App\Support\Rupiah::format($report['total_passive']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="mt-3 text-xs {{ $report['balanced'] ? 'text-emerald-700' : 'text-rose-700' }}">
        {{ $report['balanced'] ? '✓ Aktiva = Pasiva — neraca balance.' : '✗ Neraca belum balance.' }}
    </p>
@endsection
