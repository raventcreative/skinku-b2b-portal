@extends('layouts.app')
@section('title', 'Rincian Komisi')
@section('heading', 'Rincian Komisi — '.$mitra->name)

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="mb-4">
    <a href="{{ route('reports.komisi', ['bulan' => $bulan ? $bulan->format('Y-m') : \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
        class="text-xs text-indigo-600 hover:underline">&larr; Laporan Komisi</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 p-5 mb-4 max-w-md">
    <div class="text-[11px] text-stone-500">Total Komisi {{ $bulan ? $bulan->format('M Y') : '(semua periode)' }}</div>
    <div class="text-2xl font-bold text-stone-800 mt-1">{{ $rp($totalKomisi) }}</div>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    @if(count($rows))
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-2">Tanggal</th>
                    <th class="text-left">Tipe</th>
                    <th class="text-left">Dari Downline</th>
                    <th class="text-left">Level</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Basis</th>
                    <th class="text-right px-4">Komisi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $c)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2 text-stone-600">{{ $c->created_at->format('d M Y') }}</td>
                        <td class="text-stone-600">{{ ['join' => 'Join', 'ro_cashback' => 'RO Cashback', 'override' => 'Override'][$c->type] ?? ucfirst(str_replace('_', ' ', $c->type)) }}</td>
                        <td class="text-stone-600">{{ $c->downline?->name ?? '—' }}</td>
                        <td class="text-stone-500">Lv{{ $c->level }}</td>
                        <td class="text-right text-stone-500">{{ rtrim(rtrim(number_format((float) $c->rate, 2, ',', '.'), '0'), ',') }}%</td>
                        <td class="text-right text-stone-500">{{ $rp($c->base_amount) }}</td>
                        <td class="text-right px-4 font-semibold text-stone-800">{{ $rp($c->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @else
        <p class="px-4 py-8 text-center text-xs text-stone-400">Belum ada komisi pada periode ini.</p>
    @endif
</div>
@endsection
