@extends('layouts.app')
@section('title', 'Neraca')
@section('heading', 'Neraca (Balance Sheet)')

@section('content')
@include('accounting._nav')

@php $rp = fn ($n) => 'Rp '.number_format($n, 2, ',', '.'); @endphp

<div class="max-w-3xl mx-auto">
    <div class="text-center mb-4">
        <h2 class="text-base font-bold text-stone-900">Neraca — per {{ accPeriodLabel($report['as_of']) }}</h2>
        <p class="text-[11px] text-stone-400">SKINKU · Surabaya Timur</p>
        @if($report['balanced'])
            <span class="inline-block mt-2 px-3 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-bold">✓ Seimbang (Aktiva = Pasiva)</span>
        @else
            <span class="inline-block mt-2 px-3 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[11px] font-bold">⚠ Tidak seimbang</span>
        @endif
    </div>

    <div class="grid md:grid-cols-2 gap-5">
        {{-- AKTIVA --}}
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 bg-stone-50 border-b border-stone-100 font-bold text-stone-800 text-sm">AKTIVA</div>
            <div class="p-5 text-sm space-y-1">
                @forelse($report['aktiva'] as $l)
                    <div class="flex justify-between"><span class="text-stone-600">{{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                @empty
                    <p class="text-stone-400 text-xs">—</p>
                @endforelse
                <div class="flex justify-between font-bold border-t border-stone-200 pt-2 mt-2 text-stone-900"><span>TOTAL AKTIVA</span><span>{{ $rp($report['total_aktiva']) }}</span></div>
            </div>
        </div>

        {{-- PASIVA --}}
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 bg-stone-50 border-b border-stone-100 font-bold text-stone-800 text-sm">PASIVA</div>
            <div class="p-5 text-sm space-y-1">
                <p class="font-semibold text-stone-700 text-xs uppercase tracking-wide">Liabilitas</p>
                @foreach($report['liabilitas'] as $l)
                    <div class="flex justify-between"><span class="text-stone-600">{{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                @endforeach
                <div class="flex justify-between font-semibold border-t border-stone-100 pt-1"><span>Total Liabilitas</span><span>{{ $rp($report['total_liabilitas']) }}</span></div>

                <p class="font-semibold text-stone-700 text-xs uppercase tracking-wide mt-3">Ekuitas</p>
                @foreach($report['ekuitas'] as $l)
                    <div class="flex justify-between"><span class="text-stone-600">{{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                @endforeach
                <div class="flex justify-between"><span class="text-stone-600">Laba (Rugi) Berjalan</span><span>{{ $rp($report['laba_berjalan']) }}</span></div>
                <div class="flex justify-between font-semibold border-t border-stone-100 pt-1"><span>Total Ekuitas</span><span>{{ $rp($report['total_ekuitas']) }}</span></div>

                <div class="flex justify-between font-bold border-t border-stone-200 pt-2 mt-2 text-stone-900"><span>TOTAL PASIVA</span><span>{{ $rp($report['total_pasiva']) }}</span></div>
            </div>
        </div>
    </div>
</div>
@endsection
