@extends('layouts.app')
@section('title', 'Laba Rugi & Neraca')
@section('heading', 'Laporan Keuangan')

@section('content')
@include('accounting._nav')

@php $rp = fn ($n) => 'Rp '.number_format($n, 2, ',', '.'); @endphp

<div class="max-w-5xl mx-auto space-y-6">

    {{-- ===================== LABA RUGI ===================== --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100 text-center">
            <h2 class="text-base font-bold text-stone-900">Laba Rugi — {{ accPeriodLabel($is['period']) }}</h2>
            <p class="text-[11px] text-stone-400">SKINKU · Surabaya Timur</p>
        </div>
        <div class="p-6 text-sm max-w-3xl mx-auto">
            <div class="flex justify-between font-semibold text-stone-800"><span>Penjualan</span><span>{{ $rp($is['penjualan_bruto']) }}</span></div>
            @foreach($is['lines']['penjualan'] as $l)
                <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
            @endforeach
            @if($is['retur_potongan'] != 0)
                <div class="flex justify-between text-stone-600 mt-1"><span>Retur &amp; Potongan Penjualan</span><span>({{ $rp($is['retur_potongan']) }})</span></div>
            @endif
            <div class="flex justify-between font-semibold border-t border-stone-100 mt-2 pt-2"><span>Penjualan Bersih</span><span>{{ $rp($is['penjualan_bersih']) }}</span></div>

            <div class="flex justify-between font-semibold text-stone-800 mt-4"><span>Harga Pokok Penjualan (HPP)</span><span>({{ $rp($is['hpp']) }})</span></div>
            @foreach($is['lines']['hpp'] as $l)
                <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
            @endforeach

            <div class="flex justify-between font-bold text-stone-900 bg-stone-50 -mx-6 px-6 py-2 mt-3 border-y border-stone-100"><span>LABA KOTOR</span><span>{{ $rp($is['laba_kotor']) }}</span></div>

            <div class="flex justify-between font-semibold text-stone-800 mt-4"><span>Beban Operasional</span><span>({{ $rp($is['beban_operasional']) }})</span></div>
            @foreach($is['lines']['beban_operasional'] as $l)
                <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
            @endforeach

            <div class="flex justify-between font-bold text-stone-900 border-t border-stone-100 mt-2 pt-2"><span>Laba Operasional</span><span>{{ $rp($is['operating_income']) }}</span></div>

            @if($is['pendapatan_lain'] != 0)
                <div class="flex justify-between text-stone-700 mt-3"><span>Pendapatan Lain-lain</span><span>{{ $rp($is['pendapatan_lain']) }}</span></div>
                @foreach($is['lines']['pendapatan_lain'] as $l)
                    <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                @endforeach
            @endif
            @if($is['beban_non_operasional'] != 0)
                <div class="flex justify-between text-stone-700 mt-1"><span>Beban Non-operasional (bunga/pajak)</span><span>({{ $rp($is['beban_non_operasional']) }})</span></div>
                @foreach($is['lines']['beban_non_operasional'] as $l)
                    <div class="flex justify-between text-xs text-stone-500 pl-4"><span>{{ $l['code'] }} · {{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                @endforeach
            @endif

            <div class="flex justify-between font-bold text-white bg-emerald-600 -mx-6 px-6 py-3 mt-4"><span>LABA BERSIH</span><span>{{ $rp($is['net_income']) }}</span></div>
        </div>
    </div>

    {{-- ===================== NERACA ===================== --}}
    <div>
        <div class="text-center mb-4">
            <h2 class="text-base font-bold text-stone-900">Neraca — per {{ accPeriodLabel($bs['as_of']) }}</h2>
            @if($bs['balanced'])
                <span class="inline-block mt-2 px-3 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-bold">✓ Seimbang (Aktiva = Pasiva)</span>
            @else
                <span class="inline-block mt-2 px-3 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[11px] font-bold">⚠ Tidak seimbang — selisih {{ $rp($bs['total_aktiva'] - $bs['total_pasiva']) }}</span>
            @endif
        </div>

        <div class="grid md:grid-cols-2 gap-5">
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="px-5 py-3 bg-stone-50 border-b border-stone-100 font-bold text-stone-800 text-sm">AKTIVA</div>
                <div class="p-5 text-sm space-y-1">
                    @forelse($bs['aktiva'] as $l)
                        <div class="flex justify-between"><span class="text-stone-600">{{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                    @empty
                        <p class="text-stone-400 text-xs">—</p>
                    @endforelse
                    <div class="flex justify-between font-bold border-t border-stone-200 pt-2 mt-2 text-stone-900"><span>TOTAL AKTIVA</span><span>{{ $rp($bs['total_aktiva']) }}</span></div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="px-5 py-3 bg-stone-50 border-b border-stone-100 font-bold text-stone-800 text-sm">PASIVA</div>
                <div class="p-5 text-sm space-y-1">
                    <p class="font-semibold text-stone-700 text-xs uppercase tracking-wide">Liabilitas</p>
                    @foreach($bs['liabilitas'] as $l)
                        <div class="flex justify-between"><span class="text-stone-600">{{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                    @endforeach
                    <div class="flex justify-between font-semibold border-t border-stone-100 pt-1"><span>Total Liabilitas</span><span>{{ $rp($bs['total_liabilitas']) }}</span></div>

                    <p class="font-semibold text-stone-700 text-xs uppercase tracking-wide mt-3">Ekuitas</p>
                    @foreach($bs['ekuitas'] as $l)
                        <div class="flex justify-between"><span class="text-stone-600">{{ $l['name'] }}</span><span>{{ $rp($l['amount']) }}</span></div>
                    @endforeach
                    <div class="flex justify-between"><span class="text-stone-600">Laba (Rugi) Berjalan</span><span>{{ $rp($bs['laba_berjalan']) }}</span></div>
                    <div class="flex justify-between font-semibold border-t border-stone-100 pt-1"><span>Total Ekuitas</span><span>{{ $rp($bs['total_ekuitas']) }}</span></div>

                    <div class="flex justify-between font-bold border-t border-stone-200 pt-2 mt-2 text-stone-900"><span>TOTAL PASIVA</span><span>{{ $rp($bs['total_pasiva']) }}</span></div>
                </div>
            </div>
        </div>

        @unless($bs['balanced'])
            <div class="mt-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
                ℹ️ Neraca belum seimbang biasanya karena <b>saldo awal belum diisi</b> (kas, persediaan awal, modal, hutang di awal periode). Perlu jurnal Saldo Awal supaya Aktiva = Pasiva.
            </div>
        @endunless
    </div>

</div>
@endsection
