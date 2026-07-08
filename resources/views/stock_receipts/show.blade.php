@extends('layouts.app')
@section('title', $receipt->receipt_number)
@section('heading', 'Detail Stok Masuk')

@section('content')
<a href="{{ route('stock-receipts.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar</a>

<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">
    <div class="flex flex-wrap justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-stone-900">{{ $receipt->receipt_number }}</h2>
            <p class="text-xs text-stone-500 mt-1">Diterima {{ $receipt->received_at?->format('d M Y') }} · dicatat oleh {{ $receipt->creator->fullname ?? 'System' }}</p>
        </div>
        <div class="text-right">
            <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">Total Biaya</p>
            <p class="text-2xl font-bold text-stone-900">Rp {{ number_format($receipt->total_cost, 0, ',', '.') }}</p>
        </div>
    </div>
    <div class="grid sm:grid-cols-2 gap-x-8 gap-y-1 mt-4 text-xs text-stone-600">
        <div><span class="text-stone-400">Supplier:</span> {{ $receipt->supplier_name ?: '—' }}</div>
        <div><span class="text-stone-400">No. Referensi:</span> {{ $receipt->reference_no ?: '—' }}</div>
        @if($receipt->notes)<div class="sm:col-span-2"><span class="text-stone-400">Catatan:</span> {{ $receipt->notes }}</div>@endif
    </div>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mt-5">
    <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Produk Diterima</div>
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Produk</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Harga Beli / unit</th>
                <th class="text-right">Subtotal</th>
                <th class="text-right">HPP Sebelum</th>
                <th class="text-right pr-4">HPP Sesudah</th>
            </tr>
        </thead>
        <tbody>
            @foreach($receipt->items as $item)
                <tr class="border-t border-stone-100">
                    <td class="px-4 py-2.5 font-semibold text-stone-800">{{ $item->product_name }}</td>
                    <td class="text-right text-stone-600">{{ number_format($item->quantity, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-600">Rp {{ number_format($item->unit_cost, 0, ',', '.') }}</td>
                    <td class="text-right font-semibold text-stone-800">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-400">Rp {{ number_format($item->cogs_before, 0, ',', '.') }}</td>
                    <td class="text-right pr-4 font-semibold text-emerald-700">Rp {{ number_format($item->cogs_after, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>
<p class="text-[11px] text-stone-400 mt-3">HPP dihitung ulang dengan metode rata-rata bergerak (moving average) setiap ada stok masuk.</p>
@endsection
