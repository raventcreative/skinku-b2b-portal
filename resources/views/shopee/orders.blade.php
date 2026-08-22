@extends('layouts.app')
@section('title', 'Order Shopee')
@section('heading', 'Order Shopee')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<a href="{{ route('shopee.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

@if(count($needMap))
    <div class="mt-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
        ⚠️ Ada <b>{{ count($needMap) }} SKU</b> Shopee yang belum dipetakan ke produk SKINKU — order dengan SKU itu tidak bisa dipotong stoknya.
        <a href="{{ route('shopee.stock') }}" class="underline font-semibold">Petakan sekarang →</a>
    </div>
@endif

<div class="mt-3">
    <form method="POST" action="{{ route('shopee.deduct-all') }}" onsubmit="return confirm('Potong stok untuk SEMUA order yang sudah dikirim & SKU-nya cocok?')">
        @csrf
        <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">✂ Potong Semua yang Siap</button>
    </form>
</div>

<div class="mt-4 bg-white rounded-2xl border border-stone-200 overflow-hidden">
    @if($orders->count())
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Order</th>
                    <th class="text-left px-2 py-2">Tanggal</th>
                    <th class="text-left px-2 py-2">Status</th>
                    <th class="text-right px-2 py-2">Total</th>
                    <th class="text-left px-2 py-2">Pratinjau Potong Stok</th>
                    <th class="text-left px-2 py-2">Stok</th>
                    <th class="text-right px-4 py-2">Aksi</th>
                </tr></thead>
                <tbody>
                    @foreach($orders as $o)
                        @php $pv = $previews[$o->id]; @endphp
                        <tr class="border-t border-stone-100 align-top">
                            <td class="px-4 py-2 font-mono text-stone-700 whitespace-nowrap">{{ $o->order_sn }}</td>
                            <td class="px-2 py-2 text-stone-500 whitespace-nowrap">{{ $o->order_created_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $o->isShipped() ? 'bg-emerald-100 text-emerald-700' : ($o->isCancelled() ? 'bg-rose-100 text-rose-600' : 'bg-stone-100 text-stone-500') }}">{{ $o->status }}</span>
                            </td>
                            <td class="px-2 py-2 text-right text-stone-700 whitespace-nowrap">{{ $rp($o->total_amount) }}</td>
                            <td class="px-2 py-2 min-w-[240px]">
                                @forelse($pv['lines'] as $l)
                                    <div class="py-0.5">
                                        <span class="font-mono text-stone-500">{{ $l['sku'] }}</span>
                                        <span class="text-stone-400">× {{ $l['qty'] }}</span>
                                        @if(count($l['components']))
                                            <span class="text-stone-300">→</span>
                                            @foreach($l['components'] as $c)
                                                <span class="text-emerald-700">{{ $c['product']->name }}</span><span class="text-stone-500 font-semibold"> −{{ $c['deduct'] }}</span>@if(!$loop->last)<span class="text-stone-300"> + </span>@endif
                                            @endforeach
                                        @else
                                            <span class="text-rose-600 font-semibold">❌ SKU belum ada resep</span>
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-stone-400">— tidak ada item —</span>
                                @endforelse
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                @if($o->stock_status === \App\Models\ShopeeOrder::STATUS_DEDUCTED)
                                    <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold">✓ dipotong</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold">belum</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                @if($o->stock_status === \App\Models\ShopeeOrder::STATUS_DEDUCTED)
                                    <span class="text-[10px] text-stone-400">—</span>
                                @elseif($o->isCancelled())
                                    <span class="text-[10px] text-stone-400">dibatalkan</span>
                                @elseif(! $o->isShipped())
                                    <span class="text-[10px] text-stone-400">tunggu dikirim</span>
                                @elseif(! $pv['all_matched'])
                                    <span class="text-[10px] text-rose-500">petakan SKU dulu</span>
                                @else
                                    <form method="POST" action="{{ route('shopee.deduct', $o) }}" onsubmit="return confirm('Potong stok internal SKINKU untuk order ini?')">
                                        @csrf
                                        <button class="px-3 py-1.5 text-[11px] bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">✂ Potong</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="px-5 py-8 text-center text-stone-400 text-sm">Belum ada order tersimpan. Klik "Tarik Order" di halaman Integrasi.</p>
    @endif
</div>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
