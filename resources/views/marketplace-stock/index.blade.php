@extends('layouts.app')
@section('title','Produk Master')
@section('heading','Produk Master E-commerce')
@section('content')
@php
    $tabUrl = fn ($t) => route('marketplace-stock.index', array_filter(['tab' => $t === 'semua' ? null : $t]));
@endphp
<div class="space-y-4">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('marketplace-stock.create') }}" class="px-4 py-2 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">+ Tambah Produk Baru</a>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">↻ Refresh Listing</button></form>
            <a href="{{ route('marketplace-stock.channel', 'tiktok') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga TikTok →</a>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok & Harga Shopee →</a>
        </div>
        @if($unlinkedCount > 0)
            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{{ $unlinkedCount }} listing belum ditautkan ke master — pakai "Tambah ke Marketplace" pada produk untuk menautkan.</p>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 text-sm">
        @foreach(['semua' => 'Semua', 'satuan' => 'Satuan', 'bundle' => 'Bundle'] as $key => $label)
            <a href="{{ $tabUrl($key) }}" class="px-4 py-2 rounded-lg {{ $tab === $key ? 'bg-stone-800 text-white' : 'bg-white border border-stone-200 text-stone-600 hover:bg-stone-50' }}">{{ $label }} <span class="opacity-70">({{ $counts[$key] }})</span></a>
        @endforeach
    </div>

    @if($masters->isEmpty())
        <div class="bg-white rounded-2xl border border-stone-200">
            <p class="px-5 py-12 text-center text-stone-400 text-sm">Belum ada produk master. Klik <span class="font-medium text-stone-600">+ Tambah Produk Baru</span> untuk mulai.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-stone-200 overflow-visible">
            <table class="w-full text-sm">
                <thead class="text-left text-stone-500 border-b border-stone-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">Informasi Produk</th>
                        <th class="px-4 py-3 font-medium">Master SKU</th>
                        <th class="px-4 py-3 font-medium">Harga</th>
                        <th class="px-4 py-3 font-medium">Stok</th>
                        <th class="px-4 py-3 font-medium">Produk Terkait</th>
                        <th class="px-4 py-3 font-medium">Toko Terkait</th>
                        <th class="px-4 py-3 font-medium text-right">Atur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($masters as $m)
                        @php
                            $produkTerkait = $m->listings->count();
                            $tokoTerkait = $m->listings->pluck('channel')->unique()->count();
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @if($m->imageUrl())
                                        <img src="{{ $m->imageUrl() }}" alt="" class="w-10 h-10 rounded-lg object-cover border border-stone-200">
                                    @else
                                        <div class="w-10 h-10 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-300 text-[10px]">no img</div>
                                    @endif
                                    <div>
                                        <div class="font-medium text-stone-800">{{ $m->name }}</div>
                                        @if($m->is_bundle)<span class="text-[10px] uppercase tracking-wide text-amber-700 bg-amber-100 rounded px-1.5 py-0.5">Bundle</span>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $m->master_sku }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1">@csrf
                                    <input type="number" step="0.01" min="0" name="price" value="{{ $m->base_price }}" placeholder="—" class="w-24 px-2 py-1 border border-stone-200 rounded">
                                    <button class="text-xs text-indigo-600 hover:underline">set</button>
                                </form>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1">@csrf
                                    <input type="number" min="0" name="quantity" value="{{ $m->base_stock }}" placeholder="—" class="w-20 px-2 py-1 border border-stone-200 rounded">
                                    <button class="text-xs text-indigo-600 hover:underline">set</button>
                                </form>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $produkTerkait > 0 ? $produkTerkait.' Produk' : '—' }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ $tokoTerkait > 0 ? $tokoTerkait.' Toko' : '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <details class="relative inline-block text-left">
                                    <summary class="cursor-pointer list-none px-3 py-1.5 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-700">Atur ▾</summary>
                                    <div class="absolute right-0 mt-1 w-56 bg-white border border-stone-200 rounded-lg shadow-lg z-20 py-1 text-left">
                                        <a href="{{ route('marketplace-stock.edit', $m) }}" class="block px-4 py-2 hover:bg-stone-50">Ubah</a>
                                        <form method="POST" action="{{ route('marketplace-stock.duplikat', $m) }}">@csrf<button class="w-full text-left px-4 py-2 hover:bg-stone-50">Duplikat Produk</button></form>
                                        <details class="group">
                                            <summary class="cursor-pointer list-none px-4 py-2 hover:bg-stone-50">Tambah ke Marketplace</summary>
                                            <form method="POST" action="{{ route('marketplace-stock.tautkan') }}" class="px-4 py-2 space-y-1 bg-stone-50">@csrf
                                                <input type="hidden" name="master_id" value="{{ $m->id }}">
                                                <select name="listing_id" required class="w-full px-2 py-1 border border-stone-200 rounded text-xs">
                                                    <option value="">Pilih listing…</option>
                                                    @foreach($unlinkedListings as $l)
                                                        <option value="{{ $l->id }}">{{ strtoupper($l->channel) }} · {{ $l->seller_sku }}{{ $l->title ? ' — '.\Illuminate\Support\Str::limit($l->title, 30) : '' }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="w-full px-2 py-1 bg-indigo-600 text-white rounded text-xs">Tautkan</button>
                                            </form>
                                        </details>
                                        <form method="POST" action="{{ route('marketplace-stock.master.bundle', $m) }}">@csrf<button class="w-full text-left px-4 py-2 hover:bg-stone-50">{{ $m->is_bundle ? 'Jadikan Satuan' : 'Jadikan Bundle' }}</button></form>
                                        <form method="POST" action="{{ route('marketplace-stock.master.hapus', $m) }}" onsubmit="return confirm('Hapus produk master ini?')">@csrf @method('DELETE')<button class="w-full text-left px-4 py-2 text-rose-600 hover:bg-rose-50">Hapus</button></form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
