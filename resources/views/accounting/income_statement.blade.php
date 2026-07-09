@extends('layouts.app')
@section('title', 'Laba Rugi')
@section('heading', 'Laporan Laba Rugi')

@section('content')
@include('accounting._nav')

@php $rp = fn ($n) => 'Rp '.number_format($n, 2, ',', '.'); @endphp

<div class="bg-white rounded-2xl border border-stone-200 max-w-3xl mx-auto overflow-hidden">
    <div class="px-6 py-4 border-b border-stone-100 text-center">
        <h2 class="text-base font-bold text-stone-900">Laba Rugi — {{ accPeriodLabel($report['period']) }}</h2>
        <p class="text-[11px] text-stone-400">SKINKU · Surabaya Timur</p>
    </div>
    <div class="p-6 text-sm">
        {{-- Penjualan --}}
        <div class="flex justify-between font-semibold text-stone-800"><span>Penjualan</span><span>{{ $rp($report['penjualan_bruto']) }}</span></div>
        @foreach($report['lines']['penjualan'] as $l)
            <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
        @endforeach
        @if($report['retur_potongan'] != 0)
            <div class="flex justify-between text-stone-600 mt-1"><span>Retur &amp; Potongan Penjualan</span><span>({{ $rp($report['retur_potongan']) }})</span></div>
        @endif
        <div class="flex justify-between font-semibold border-t border-stone-100 mt-2 pt-2"><span>Penjualan Bersih</span><span>{{ $rp($report['penjualan_bersih']) }}</span></div>

        {{-- HPP --}}
        <div class="flex justify-between font-semibold text-stone-800 mt-4"><span>Harga Pokok Penjualan (HPP)</span><span>({{ $rp($report['hpp']) }})</span></div>
        @foreach($report['lines']['hpp'] as $l)
            <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
        @endforeach

        <div class="flex justify-between font-bold text-stone-900 bg-stone-50 -mx-6 px-6 py-2 mt-3 border-y border-stone-100"><span>LABA KOTOR</span><span>{{ $rp($report['laba_kotor']) }}</span></div>

        {{-- Beban operasional --}}
        <div class="flex justify-between font-semibold text-stone-800 mt-4"><span>Beban Operasional</span><span>({{ $rp($report['beban_operasional']) }})</span></div>
        @foreach($report['lines']['beban_operasional'] as $l)
            <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
        @endforeach

        <div class="flex justify-between font-bold text-stone-900 border-t border-stone-100 mt-2 pt-2"><span>Laba Operasional</span><span>{{ $rp($report['operating_income']) }}</span></div>

        {{-- Non-operasional --}}
        @if($report['pendapatan_lain'] != 0)
            <div class="flex justify-between text-stone-700 mt-3"><span>Pendapatan Lain-lain</span><span>{{ $rp($report['pendapatan_lain']) }}</span></div>
            @foreach($report['lines']['pendapatan_lain'] as $l)
                <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
            @endforeach
        @endif
        @if($report['beban_non_operasional'] != 0)
            <div class="flex justify-between text-stone-700 mt-1"><span>Beban Non-operasional (bunga/pajak)</span><span>({{ $rp($report['beban_non_operasional']) }})</span></div>
            @foreach($report['lines']['beban_non_operasional'] as $l)
                <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
            @endforeach
        @endif

        <div class="flex justify-between font-bold text-white bg-emerald-600 -mx-6 px-6 py-3 mt-4"><span>LABA BERSIH</span><span>{{ $rp($report['net_income']) }}</span></div>
    </div>
</div>
@endsection
