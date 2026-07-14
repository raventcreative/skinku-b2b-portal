@extends('layouts.app')
@section('title', 'Pesanan TikTok')
@section('heading', 'Pesanan TikTok — Pratinjau Stok')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

<div class="mt-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
    ℹ️ <b>Pratinjau (read-only)</b> — ini cuma menampilkan order + produk SKINKU yang cocok per item. <b>Belum memotong stok apa pun.</b> Tombol "Potong Stok" dibangun di langkah berikut setelah kamu konfirmasi pencocokan SKU-nya sudah benar.
</div>

<div class="mt-4 bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-2.5">Order</th>
                <th class="text-left">Status TikTok</th>
                <th class="text-left">Item → Produk SKINKU (dampak stok)</th>
                <th class="text-left">Potong Stok</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $o)
                @php $pv = $previews[$o->id]; @endphp
                <tr class="border-t border-stone-100 align-top">
                    <td class="px-4 py-3">
                        <div class="font-mono text-stone-700">{{ $o->tiktok_order_id }}</div>
                        <div class="text-[10px] text-stone-400">{{ $o->order_created_at?->format('d M Y') ?? '—' }} · {{ $rp($o->total_amount) }}</div>
                    </td>
                    <td class="py-3">
                        @if($o->isShipped())
                            <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">{{ $o->status }}</span>
                            <div class="text-[10px] text-stone-400 mt-0.5">barang keluar</div>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold">{{ $o->status }}</span>
                            <div class="text-[10px] text-stone-400 mt-0.5">belum dikirim</div>
                        @endif
                    </td>
                    <td class="py-3">
                        @forelse($pv['lines'] as $l)
                            <div class="flex items-center gap-1.5 py-0.5">
                                <span class="font-mono text-stone-500">{{ $l['sku'] }}</span>
                                <span class="text-stone-400">× {{ $l['qty'] }}</span>
                                <span class="text-stone-300">→</span>
                                @if($l['product'])
                                    <span class="text-emerald-700">{{ $l['product']->name }}</span>
                                    <span class="text-[10px] text-stone-400">(stok: {{ (int) $l['product']->hq_stock }})</span>
                                @else
                                    <span class="text-rose-600 font-semibold">❌ SKU belum cocok</span>
                                @endif
                            </div>
                        @empty
                            <span class="text-stone-400">— tidak ada item —</span>
                        @endforelse
                    </td>
                    <td class="py-3">
                        @if($o->stock_status === \App\Models\TiktokOrder::STATUS_DEDUCTED)
                            <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold">sudah dipotong</span>
                        @elseif(! $o->isShipped())
                            <span class="text-[10px] text-stone-400">tunggu dikirim</span>
                        @elseif(! $pv['all_matched'])
                            <span class="text-[10px] text-rose-500">ada SKU belum cocok</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold">siap dipotong</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-stone-400">Belum ada order tersimpan. Klik "Tarik &amp; Simpan Order" di halaman Integrasi.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
