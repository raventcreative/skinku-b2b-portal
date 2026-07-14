@extends('layouts.app')
@section('title', 'Pesanan TikTok')
@section('heading', 'Pesanan TikTok — Pratinjau Stok')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

<div class="mt-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
    ℹ️ Stok <b>tidak dipotong otomatis</b>. Kamu klik <b>"Potong Stok"</b> sendiri per order (mode preview-approve). Hanya order yang <b>sudah dikirim</b> &amp; semua SKU-nya <b>sudah cocok</b> yang bisa dipotong. Bisa dibatalkan (stok balik).
</div>

@if(count($unmatchedSkus))
    <div class="mt-4 bg-white rounded-2xl border border-rose-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-1">Petakan SKU yang belum cocok ({{ count($unmatchedSkus) }})</h3>
        <p class="text-[11px] text-stone-500 mb-3">SKU TikTok ini beda dari SKU produk SKINKU. Pilih produknya sekali — diingat untuk semua order.</p>
        <div class="space-y-2">
            @foreach($unmatchedSkus as $sku => $name)
                <form method="POST" action="{{ route('tiktok.sku-map') }}" class="flex flex-wrap items-center gap-2 text-xs">@csrf
                    <input type="hidden" name="tiktok_sku" value="{{ $sku }}">
                    <span class="font-mono text-stone-700 w-36">{{ $sku }}</span>
                    <span class="text-stone-400 truncate max-w-[180px]">{{ $name }}</span>
                    <span class="text-stone-300">→</span>
                    <select name="product_id" required class="px-2 py-1 border border-stone-300 rounded w-60">
                        <option value="">— pilih produk SKINKU —</option>
                        @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                    </select>
                    <button class="px-3 py-1 bg-stone-800 text-white rounded hover:bg-stone-900">Simpan</button>
                </form>
            @endforeach
        </div>
    </div>
@endif

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
                    <td class="py-3 pr-4">
                        @if($o->stock_status === \App\Models\TiktokOrder::STATUS_DEDUCTED)
                            <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold">✓ sudah dipotong</span>
                            <form method="POST" action="{{ route('tiktok.reverse', $o) }}" class="inline" onsubmit="return confirm('Batalkan pemotongan stok? Stok akan dikembalikan.')">@csrf
                                <button class="ml-1 text-[10px] text-stone-500 hover:text-rose-600 underline">batalkan</button>
                            </form>
                        @elseif(! $o->isShipped())
                            <span class="text-[10px] text-stone-400">tunggu dikirim</span>
                        @elseif(! $pv['all_matched'])
                            <span class="text-[10px] text-rose-500">petakan SKU dulu ↑</span>
                        @else
                            <form method="POST" action="{{ route('tiktok.deduct', $o) }}" onsubmit="return confirm('Potong stok internal SKINKU untuk order ini?')">@csrf
                                <button class="px-3 py-1 text-[11px] bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">✂ Potong Stok</button>
                            </form>
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
