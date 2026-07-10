@extends('layouts.app')
@section('title', 'Arus Kas')
@section('heading', 'Laporan Arus Kas')

@section('content')
@include('accounting._nav')

@php
    $rp = fn ($n) => 'Rp '.number_format(abs($n), 2, ',', '.');
    // tampilkan arus: masuk (+) biasa, keluar (−) dalam kurung
    $flow = fn ($n) => $n < 0 ? '('.$rp($n).')' : $rp($n);
    $sections = [
        'operating' => ['A. Arus Kas dari Aktivitas Operasi', 'Kas Bersih dari Operasi'],
        'investing' => ['B. Arus Kas dari Aktivitas Investasi', 'Kas Bersih dari Investasi'],
        'financing' => ['C. Arus Kas dari Aktivitas Pendanaan', 'Kas Bersih dari Pendanaan'],
    ];
@endphp

<div class="bg-white rounded-2xl border border-stone-200 max-w-3xl mx-auto overflow-hidden">
    <div class="px-6 py-4 border-b border-stone-100 text-center">
        <h2 class="text-base font-bold text-stone-900">Arus Kas (Metode Langsung) — {{ accPeriodLabel($report['period']) }}</h2>
        <p class="text-[11px] text-stone-400">SKINKU · Surabaya Timur</p>
    </div>
    <div class="p-6 text-sm">
        @foreach($sections as $key => [$title, $subtotalLabel])
            <div class="{{ !$loop->first ? 'mt-5' : '' }}">
                <div class="font-bold text-stone-800">{{ $title }}</div>
                @forelse($report['sections'][$key] as $l)
                    <div class="flex justify-between text-stone-600 pl-4 py-0.5">
                        <span class="text-xs">{{ $l['amount'] < 0 ? 'Pembayaran' : 'Penerimaan' }} — {{ $l['code'] }} · {{ $l['name'] }}</span>
                        <span class="{{ $l['amount'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $flow($l['amount']) }}</span>
                    </div>
                @empty
                    <div class="pl-4 text-xs text-stone-400 py-0.5">— tidak ada —</div>
                @endforelse
                <div class="flex justify-between font-semibold border-t border-stone-100 mt-1 pt-1.5">
                    <span>{{ $subtotalLabel }}</span>
                    <span class="{{ $report['totals'][$key] < 0 ? 'text-rose-600' : 'text-stone-900' }}">{{ $flow($report['totals'][$key]) }}</span>
                </div>
            </div>
        @endforeach

        <div class="flex justify-between font-bold text-white bg-stone-800 -mx-6 px-6 py-2.5 mt-5">
            <span>KENAIKAN (PENURUNAN) KAS BERSIH</span>
            <span>{{ $flow($report['net']) }}</span>
        </div>

        <div class="flex justify-between text-stone-600 mt-3 pt-1">
            <span>Kas &amp; Setara Kas — Awal Periode</span><span>{{ $rp($report['kas_awal']) }}</span>
        </div>
        <div class="flex justify-between font-bold text-stone-900 border-t border-stone-200 mt-1 pt-2">
            <span>Kas &amp; Setara Kas — Akhir Periode</span><span>{{ $rp($report['kas_akhir']) }}</span>
        </div>

        @if($report['reconciled'])
            <p class="text-center mt-4"><span class="inline-block px-3 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-bold">✓ Cocok dengan saldo kas di Neraca</span></p>
        @else
            <p class="text-center mt-4"><span class="inline-block px-3 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[11px] font-bold">⚠ Tidak cocok dengan saldo kas — cek data</span></p>
        @endif
    </div>
</div>

<p class="max-w-3xl mx-auto text-[11px] text-stone-400 mt-3 text-center">Metode langsung: tiap mutasi kas/bank dikelompokkan berdasarkan akun lawannya. Saldo Awal dihitung sebagai kas awal, bukan arus periode.</p>
@endsection
