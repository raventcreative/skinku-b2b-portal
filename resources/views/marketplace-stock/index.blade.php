@extends('layouts.app')
@section('title','Stok Master')
@section('heading','Stok Master')
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
        <h3 class="text-sm font-bold text-stone-800 mb-1">Kontrol Stok Marketplace</h3>
        <p class="text-xs text-stone-500 mb-4">Satu pool stok "siap jual" per produk, dibagi ke semua listing TikTok &amp; Shopee yang memuatnya (bundle-aware).</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.seed') }}" onsubmit="return confirm('Tarik stok TikTok saat ini sebagai nilai awal pool? Hanya listing 1:1 (bukan bundle) yang di-seed otomatis.')">
                @csrf
                <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">⬇ Tarik stok awal dari TikTok</button>
            </form>
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">
                @csrf
                <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Refresh listing</button>
            </form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">
                @csrf
                <button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button>
            </form>
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
                        <th class="text-left">Pool Stok</th>
                        <th class="text-left">TikTok</th>
                        <th class="text-left">Shopee</th>
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
                                    <form method="POST" action="{{ route('marketplace-stock.set', $p) }}" class="flex items-center gap-1.5">
                                        @csrf
                                        <input type="number" name="quantity" min="0" step="1"
                                            value="{{ $row['pool'] }}" placeholder="belum di-set"
                                            class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                        <button class="px-2.5 py-1 bg-stone-800 text-white rounded-lg hover:bg-stone-900 text-[11px]">Simpan</button>
                                    </form>
                                    @if($row['pool'] === null)
                                        <div class="text-[10px] text-amber-600 mt-1">belum di-set</div>
                                    @endif
                                </td>
                                <td class="py-2.5">
                                    @if($row['tiktok'])
                                        <div class="font-mono text-stone-700">{{ $row['tiktok']->seller_sku }}</div>
                                        <div class="text-[11px] text-stone-500">terkirim: {{ $row['tiktok']->last_pushed_qty ?? '—' }} · {{ $row['tiktok']->last_status ?? 'belum sinkron' }}</div>
                                        <div class="text-[10px] text-stone-400">{{ $row['tiktok']->last_pushed_at?->diffForHumans() ?? 'belum pernah' }}</div>
                                    @else
                                        <span class="text-stone-400">belum dipetakan</span>
                                    @endif
                                    @if($row['tiktok_override'] !== null)
                                        <div class="mt-1"><span class="inline-block px-1.5 py-0.5 rounded-sm bg-indigo-50 text-indigo-700 text-[10px] font-semibold">Override: {{ $row['tiktok_override'] }}</span></div>
                                    @endif
                                </td>
                                <td class="py-2.5">
                                    @if($row['shopee'])
                                        <div class="font-mono text-stone-700">{{ $row['shopee']->seller_sku }}</div>
                                        <div class="text-[11px] text-stone-500">terkirim: {{ $row['shopee']->last_pushed_qty ?? '—' }} · {{ $row['shopee']->last_status ?? 'belum sinkron' }}</div>
                                        <div class="text-[10px] text-stone-400">{{ $row['shopee']->last_pushed_at?->diffForHumans() ?? 'belum pernah' }}</div>
                                    @else
                                        <span class="text-stone-400">belum dipetakan</span>
                                    @endif
                                    @if($row['shopee_override'] !== null)
                                        <div class="mt-1"><span class="inline-block px-1.5 py-0.5 rounded-sm bg-indigo-50 text-indigo-700 text-[10px] font-semibold">Override: {{ $row['shopee_override'] }}</span></div>
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
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada produk yang dipetakan ke SKU marketplace. Petakan dulu di halaman TikTok/Shopee.</p>
        @endif
    </div>

    {{-- Listing belum terpetakan --}}
    @if(count($unmapped))
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Listing belum terpetakan — {{ count($unmapped) }}</div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs whitespace-nowrap">
                    <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                        <th class="text-left px-4 py-2">Channel</th><th class="text-left">Seller SKU</th><th class="text-left">Judul</th><th class="text-left pr-4">Tautkan ke Produk</th>
                    </tr></thead>
                    <tbody>
                        @foreach($unmapped as $l)
                            <tr class="border-t border-stone-100">
                                <td class="px-4 py-2 capitalize">{{ $l->channel }}</td>
                                <td class="font-mono text-stone-700">{{ $l->seller_sku }}</td>
                                <td class="text-stone-500">{{ $l->title ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    <form method="POST" action="{{ route('marketplace-stock.tautkan', ['channel' => $l->channel]) }}" class="flex items-center gap-1.5">
                                        @csrf
                                        <input type="hidden" name="seller_sku" value="{{ $l->seller_sku }}">
                                        <select name="product_id" required class="px-2 py-1 border border-stone-300 rounded-lg text-xs max-w-[180px]">
                                            <option value="">Pilih produk…</option>
                                            @foreach($products as $p)
                                                <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>
                                            @endforeach
                                        </select>
                                        <input type="number" name="qty" min="1" step="1" value="1" class="w-14 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                        <button class="px-2.5 py-1 bg-emerald-700 text-white rounded-lg hover:bg-emerald-800 text-[11px]">Tautkan</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-stone-100 text-[11px] text-stone-500">
                Petakan SKU ini supaya ikut disinkron:
                <a href="{{ route('tiktok.index') }}" class="text-indigo-700 hover:underline font-semibold">TikTok →</a>
                ·
                <a href="{{ route('shopee.index') }}" class="text-indigo-700 hover:underline font-semibold">Shopee →</a>
            </div>
        </div>
    @endif

</div>
@endsection
