@extends('layouts.app')
@section('title','Stok '.ucfirst($channel))
@section('heading','Stok '.ucfirst($channel))
@section('content')

<div class="space-y-4">

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>
    @endif

    {{-- Aksi --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-1">Stok {{ ucfirst($channel) }}</h3>
        <p class="text-xs text-stone-500 mb-4">Stok efektif = Override {{ ucfirst($channel) }} kalau ada, kalau tidak ikut pool Master. Set override untuk melepas produk ini dari Master; "Ikut Master" mengembalikannya.</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">
                @csrf
                <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Refresh listing</button>
            </form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">
                @csrf
                <button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button>
            </form>
            <a href="{{ route('marketplace-stock.index') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">← Stok Master</a>
        </div>
    </div>

    {{-- Tabel produk --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Produk Terpetakan — {{ count($rows) }} produk</div>
        @if(count($rows))
            <div class="overflow-x-auto">
                <table class="w-full text-xs whitespace-nowrap">
                    <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                        <th class="text-left px-4 py-2">Produk</th>
                        <th class="text-left">Stok Efektif</th>
                        <th class="text-left">Set Override</th>
                        <th class="text-left">Listing {{ ucfirst($channel) }}</th>
                        <th class="text-left pr-4">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php $p = $row['product']; @endphp
                            <tr class="border-t border-stone-100 align-top">
                                <td class="px-4 py-2.5">
                                    <div class="font-semibold text-stone-800">{{ $p->name }}</div>
                                    <div class="text-[11px] text-stone-400 font-mono">{{ $p->sku }}</div>
                                </td>
                                <td class="py-2.5">
                                    <div class="font-semibold text-stone-800">{{ $row['effective'] ?? '—' }}</div>
                                    @if($row['override'] !== null)
                                        <span class="inline-block mt-1 px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 text-[10px] font-semibold">Override: {{ $row['override'] }}</span>
                                    @else
                                        <span class="inline-block mt-1 px-1.5 py-0.5 rounded bg-stone-100 text-stone-500 text-[10px] font-semibold">Ikut Master</span>
                                    @endif
                                </td>
                                <td class="py-2.5">
                                    <form method="POST" action="{{ route('marketplace-stock.override', ['channel' => $channel, 'product' => $p]) }}" class="flex items-center gap-1.5">
                                        @csrf
                                        <input type="number" name="quantity" min="0" step="1"
                                            value="{{ $row['override'] }}" placeholder="ikut master"
                                            class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                        <button class="px-2.5 py-1 bg-stone-800 text-white rounded-lg hover:bg-stone-900 text-[11px]">Simpan</button>
                                    </form>
                                    @if($row['override'] !== null)
                                        <form method="POST" action="{{ route('marketplace-stock.ikut-master', ['channel' => $channel, 'product' => $p]) }}" class="mt-1">
                                            @csrf
                                            <button class="px-2.5 py-1 bg-amber-600 text-white rounded-lg hover:bg-amber-700 text-[11px]">Ikut Master</button>
                                        </form>
                                    @endif
                                </td>
                                <td class="py-2.5">
                                    @if($row['listing'])
                                        <div class="font-mono text-stone-700">{{ $row['listing']->seller_sku }}</div>
                                        <div class="text-[11px] text-stone-500">terkirim: {{ $row['listing']->last_pushed_qty ?? '—' }} · {{ $row['listing']->last_status ?? 'belum sinkron' }}</div>
                                        <div class="text-[10px] text-stone-400">{{ $row['listing']->last_pushed_at?->diffForHumans() ?? 'belum pernah' }}</div>
                                    @else
                                        <span class="text-stone-400">belum dipetakan</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4">
                                    <form method="POST" action="{{ route('marketplace-stock.push', $p) }}">
                                        @csrf
                                        <button class="px-2.5 py-1 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800 text-[11px]">Sinkron</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada produk yang dipetakan ke SKU {{ ucfirst($channel) }}.</p>
        @endif
    </div>

    {{-- Listing belum terpetakan (channel ini) --}}
    @if(count($unmapped))
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Listing {{ ucfirst($channel) }} belum terpetakan — {{ count($unmapped) }}</div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs whitespace-nowrap">
                    <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                        <th class="text-left px-4 py-2">Seller SKU</th><th class="text-left">Judul</th>
                    </tr></thead>
                    <tbody>
                        @foreach($unmapped as $l)
                            <tr class="border-t border-stone-100">
                                <td class="px-4 py-2 font-mono text-stone-700">{{ $l->seller_sku }}</td>
                                <td class="text-stone-500">{{ $l->title ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-stone-100 text-[11px] text-stone-500">
                Petakan SKU ini supaya ikut disinkron:
                <a href="{{ route($channel === 'tiktok' ? 'tiktok.index' : 'shopee.index') }}" class="text-indigo-700 hover:underline font-semibold">{{ ucfirst($channel) }} →</a>
            </div>
        </div>
    @endif

</div>
@endsection
