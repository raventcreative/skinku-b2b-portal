@extends('layouts.app')
@section('title', 'Neraca Saldo')
@section('heading', 'Neraca Saldo (Trial Balance)')

@section('content')
@include('accounting._nav')

@php $rp = fn ($n) => number_format($n, 0, ',', '.'); @endphp

<div class="max-w-3xl mx-auto">
    <div class="text-center mb-3">
        <h2 class="text-base font-bold text-stone-900">Neraca Saldo — per {{ accPeriodLabel($period) }}</h2>
        @if($report['balanced'])
            <span class="inline-block mt-1 px-3 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-bold">✓ Balance</span>
        @else
            <span class="inline-block mt-1 px-3 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[11px] font-bold">⚠ Tidak balance</span>
        @endif
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Kode</th>
                    <th class="text-left">Akun</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right pr-4">Kredit</th>
                </tr>
            </thead>
            <tbody>
                @forelse($report['rows'] as $r)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2 text-stone-500">{{ $r['code'] }}</td>
                        <td class="text-stone-800">{{ $r['name'] }}</td>
                        <td class="text-right text-stone-700">{{ $r['debit'] > 0 ? $rp($r['debit']) : '' }}</td>
                        <td class="text-right pr-4 text-stone-700">{{ $r['credit'] > 0 ? $rp($r['credit']) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-stone-400">Belum ada jurnal pada periode ini.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-stone-300 bg-stone-50 font-bold text-stone-900">
                    <td class="px-4 py-3" colspan="2">TOTAL</td>
                    <td class="text-right">{{ $rp($report['total_debit']) }}</td>
                    <td class="text-right pr-4">{{ $rp($report['total_credit']) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
@endsection
