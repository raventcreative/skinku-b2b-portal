@extends('layouts.app')
@section('title','Produk Master')
@section('heading','Produk Master E-commerce')
@section('content')
@php
    $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.');
    $tabUrl = fn ($t) => route('marketplace-stock.index', array_filter(['tab' => $t === 'semua' ? null : $t]));
@endphp
<div class="space-y-4">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.siapkan') }}">@csrf<button class="px-4 py-2 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">⚡ Siapkan Master (TikTok+Shopee)</button></form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <a href="{{ route('marketplace-stock.channel', 'tiktok') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga TikTok →</a>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga Shopee →</a>
        </div>
        @if($unmasteredCount > 0)
            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{{ $unmasteredCount }} listing belum termaster — klik "Siapkan Master" untuk merapikan.</p>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 text-sm">
        @foreach(['semua' => 'Semua', 'satuan' => 'Satuan', 'bundle' => 'Bundle'] as $key => $label)
            <a href="{{ $tabUrl($key) }}" class="px-4 py-2 rounded-lg {{ $tab === $key ? 'bg-stone-800 text-white' : 'bg-white border border-stone-200 text-stone-600 hover:bg-stone-50' }}">{{ $label }} <span class="opacity-70">({{ $counts[$key] }})</span></a>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        @if(count($rows))
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Produk</th>
                    <th class="text-left">Master SKU</th>
                    <th class="text-left">Harga</th>
                    <th class="text-left">Stok</th>
                    <th class="text-left">Channel Terkait</th>
                    <th class="text-left pr-4">Atur</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php $m = $row['master']; @endphp
                    <tr class="border-t border-stone-100 align-top">
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                @if($m->imageUrl())
                                    <img src="{{ $m->imageUrl() }}" alt="" class="w-9 h-9 rounded-lg object-cover border border-stone-200" loading="lazy">
                                @else
                                    <div class="w-9 h-9 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-300 text-[9px]">no img</div>
                                @endif
                                <div>
                                    <div class="font-semibold text-stone-800 whitespace-normal max-w-[260px]">{{ $m->name }}</div>
                                    @if($m->is_bundle)<span class="inline-block mt-0.5 px-1.5 py-0.5 rounded-sm bg-purple-50 text-purple-700 text-[9px] font-semibold">BUNDLE</span>@endif
                                </div>
                            </div>
                        </td>
                        <td class="py-2.5 font-mono text-stone-500">{{ $m->master_sku }}</td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1">@csrf
                                <input type="number" name="price" min="0" step="any" value="{{ $m->base_price !== null ? (int) $m->base_price : '' }}" placeholder="—" class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">✓</button>
                            </form>
                        </td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1">@csrf
                                <input type="number" name="quantity" min="0" step="1" value="{{ $m->base_stock }}" placeholder="—" class="w-20 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">✓</button>
                            </form>
                        </td>
                        <td class="py-2.5">
                            @foreach(['tiktok' => 'TikTok', 'shopee' => 'Shopee'] as $ch => $lbl)
                                @if($row[$ch]['listing'])
                                    <div class="text-[11px] text-stone-600">{{ $lbl }}: stok {{ $row[$ch]['listing']->last_status ?? '—' }} / harga {{ $row[$ch]['listing']->last_price_status ?? '—' }}</div>
                                @endif
                            @endforeach
                            @if(! $row['tiktok']['listing'] && ! $row['shopee']['listing'])<span class="text-stone-400 text-[11px]">belum ada listing</span>@endif
                        </td>
                        <td class="py-2.5 pr-4">
                            <details class="inline-block">
                                <summary class="cursor-pointer text-indigo-700 text-[11px]">Atur ▾</summary>
                                <div class="mt-1 space-y-1 bg-stone-50 border border-stone-200 rounded-lg p-2 min-w-[220px]">
                                    <form method="POST" action="{{ route('marketplace-stock.push', $m) }}">@csrf<button class="w-full text-left px-2 py-1 text-[11px] hover:bg-stone-100 rounded">⇪ Sinkron</button></form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.bundle', $m) }}">@csrf<button class="w-full text-left px-2 py-1 text-[11px] hover:bg-stone-100 rounded">{{ $m->is_bundle ? 'Jadikan Satuan' : 'Jadikan Bundle' }}</button></form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.foto', $m) }}" enctype="multipart/form-data" class="flex items-center gap-1 px-2 py-1">@csrf
                                        <input type="file" name="foto" accept="image/*" class="text-[10px] w-32">
                                        <button class="px-2 py-0.5 bg-stone-700 text-white rounded text-[10px]">Foto</button>
                                    </form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.gabung', $m) }}" class="flex items-center gap-1 px-2 py-1">@csrf
                                        <select name="target_master_id" required class="text-[10px] px-1 py-0.5 border border-stone-300 rounded max-w-[130px]">
                                            <option value="">Gabung ke…</option>
                                            @foreach($allMasters as $mm)@if($mm->id !== $m->id)<option value="{{ $mm->id }}">{{ $mm->name }}</option>@endif @endforeach
                                        </select>
                                        <button class="px-2 py-0.5 bg-amber-600 text-white rounded text-[10px]">Gabung</button>
                                    </form>
                                    <form method="POST" action="{{ route('marketplace-stock.master.hapus', $m) }}" onsubmit="return confirm('Hapus master ini?')">@csrf @method('DELETE')<button class="w-full text-left px-2 py-1 text-[11px] text-rose-600 hover:bg-rose-50 rounded">Hapus</button></form>
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <p class="px-5 py-8 text-center text-stone-400 text-sm">Belum ada master. Klik "Siapkan Master (TikTok+Shopee)" untuk menariknya otomatis.</p>
        @endif
    </div>
</div>
@endsection
