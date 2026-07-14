@extends('layouts.app')
@section('title', 'Konversi Stok TikTok')
@section('heading', 'Konversi Stok per Item')

@section('content')
<a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

<div class="mt-3 px-4 py-2.5 rounded-xl bg-teal-50 border border-teal-200 text-teal-800 text-[11px]">
    ℹ️ Dihitung dari order TikTok yang <b>sudah dipotong stok</b>, dikelompokkan per status kirim.
    <b>Sisa</b> = stok gudang sekarang · <b>Dalam Perjalanan</b> = IN_TRANSIT · <b>Terkirim</b> = DELIVERED/COMPLETED · <b>Total</b> = sisa + keluar.
</div>

<div class="mt-4 space-y-2">
    @forelse($rows as $r)
        @php
            $total = max(1, $r['total']);
            $pSisa = round($r['sisa'] / $total * 100);
            $pTransit = round($r['transit'] / $total * 100);
            $pDeliv = 100 - $pSisa - $pTransit;
        @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                <div class="font-semibold text-sm text-stone-800">{{ $r['product']->name }} <span class="text-[10px] text-stone-400 font-mono">{{ $r['product']->sku }}</span></div>
                <div class="text-xs text-stone-500">Total <b class="text-stone-800">{{ number_format($r['total'], 0, ',', '.') }}</b></div>
            </div>
            {{-- bar funnel --}}
            <div class="flex h-2.5 rounded-full overflow-hidden bg-stone-100 mb-2">
                <div class="bg-emerald-500" style="width: {{ $pDeliv }}%"></div>
                <div class="bg-amber-400" style="width: {{ $pTransit }}%"></div>
                <div class="bg-stone-300" style="width: {{ $pSisa }}%"></div>
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs">
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span> Terkirim <b class="text-stone-800">{{ number_format($r['delivered'], 0, ',', '.') }}</b></div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-400 inline-block"></span> Dalam Perjalanan <b class="text-stone-800">{{ number_format($r['transit'], 0, ',', '.') }}</b></div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-stone-300 inline-block"></span> Sisa <b class="text-stone-800">{{ number_format($r['sisa'], 0, ',', '.') }}</b></div>
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-stone-200 px-4 py-8 text-center text-stone-400 text-sm">
            Belum ada data. Konversi stok muncul setelah ada order yang kamu <b>Potong Stok</b> di halaman Pesanan TikTok.
        </div>
    @endforelse
</div>
@endsection
