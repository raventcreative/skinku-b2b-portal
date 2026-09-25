@extends('layouts.app')
@section('title','Produk Master')
@section('heading','Produk Master E-commerce')
@section('content')
@php
    $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.');
    $badge = function ($override, $eff) use ($rp) {
        if ($override !== null) return '<span class="inline-block px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 text-[10px] font-semibold">Override: '.e($rp($override)).'</span>';
        return '<span class="inline-block px-1.5 py-0.5 rounded bg-stone-100 text-stone-500 text-[10px] font-semibold">Ikut Master</span>';
    };
@endphp
<div class="space-y-4">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input yang dimasukkan.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-1">Produk Master</h3>
        <p class="text-xs text-stone-500 mb-4">Tiap unit (satuan/varian/bundle) punya stok & harga sendiri. Set di Master → semua channel ikut, kecuali yang di-override. Terpisah dari stok gudang (HQ).</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.seed') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">⬇ Tarik stok awal TikTok</button></form>
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Refresh listing</button></form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <a href="{{ route('marketplace-stock.channel', 'tiktok') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga TikTok →</a>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga Shopee →</a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Master — {{ count($rows) }} unit</div>
        @if(count($rows))
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Unit (Master)</th>
                    <th class="text-left">Stok Master</th>
                    <th class="text-left">Harga Master</th>
                    <th class="text-left">TikTok</th>
                    <th class="text-left">Shopee</th>
                    <th class="text-left pr-4">Aksi</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php $m = $row['master']; @endphp
                    <tr class="border-t border-stone-100 align-top">
                        <td class="px-4 py-2.5"><div class="font-semibold text-stone-800">{{ $m->name }}</div><div class="text-[11px] text-stone-400 font-mono">{{ $m->master_sku }}</div></td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="quantity" min="0" step="1" value="{{ $m->base_stock }}" placeholder="belum di-set" class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2.5 py-1 bg-stone-800 text-white rounded-lg hover:bg-stone-900 text-[11px]">Simpan</button>
                            </form>
                        </td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="price" min="0" step="any" value="{{ $m->base_price !== null ? (int) $m->base_price : '' }}" placeholder="belum di-set" class="w-28 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2.5 py-1 bg-stone-800 text-white rounded-lg hover:bg-stone-900 text-[11px]">Simpan</button>
                            </form>
                        </td>
                        @foreach(['tiktok','shopee'] as $ch)
                            <td class="py-2.5">
                                <div class="text-stone-800">Stok: <span class="font-semibold">{{ $row[$ch]['eff_stock'] ?? '—' }}</span> {!! $badge($row[$ch]['override_stock'], $row[$ch]['eff_stock']) !!}</div>
                                <div class="text-stone-800">Harga: <span class="font-semibold">{{ $rp($row[$ch]['eff_price']) }}</span> {!! $badge($row[$ch]['override_price'] !== null ? (int) $row[$ch]['override_price'] : null, $row[$ch]['eff_price']) !!}</div>
                                @if($row[$ch]['listing'])
                                    <div class="text-[10px] text-stone-400">kirim: {{ $row[$ch]['listing']->last_status ?? '—' }}/{{ $row[$ch]['listing']->last_price_status ?? '—' }}</div>
                                @else
                                    <div class="text-[10px] text-stone-400">belum ada listing</div>
                                @endif
                            </td>
                        @endforeach
                        <td class="py-2.5 pr-4"><form method="POST" action="{{ route('marketplace-stock.push', $m) }}">@csrf<button class="px-2.5 py-1 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800 text-[11px]">Sinkron</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada master. Klik "Refresh listing" untuk menarik listing & membuat master otomatis.</p>
        @endif
    </div>

    @if(count($unmastered))
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Listing belum termaster — {{ count($unmastered) }}</div>
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr><th class="text-left px-4 py-2">Channel</th><th class="text-left">Seller SKU</th><th class="text-left">Judul</th><th class="text-left pr-4">Tautkan</th></tr></thead>
                <tbody>
                @foreach($unmastered as $l)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2">{{ ucfirst($l->channel) }}</td>
                        <td class="font-mono text-stone-700">{{ $l->seller_sku }}</td>
                        <td class="text-stone-500">{{ $l->title ?? '—' }}</td>
                        <td class="py-2 pr-4">
                            <form method="POST" action="{{ route('marketplace-stock.tautkan') }}" class="flex items-center gap-1.5">@csrf
                                <input type="hidden" name="listing_id" value="{{ $l->id }}">
                                <select name="master_id" class="px-2 py-1 border border-stone-300 rounded-lg text-xs max-w-[200px]">
                                    <option value="">— buat master baru ({{ $l->seller_sku }}) —</option>
                                    @foreach($masters as $mm)<option value="{{ $mm->id }}">{{ $mm->name }} ({{ $mm->master_sku }})</option>@endforeach
                                </select>
                                <button class="px-2.5 py-1 bg-emerald-700 text-white rounded-lg hover:bg-emerald-800 text-[11px]">Tautkan</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</div>
@endsection
