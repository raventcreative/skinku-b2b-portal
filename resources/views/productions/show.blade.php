@extends('layouts.app')
@section('title', $production->production_number)
@section('heading', 'Detail Produksi')

@section('content')
<a href="{{ route('productions.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar</a>

<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">
    <div class="flex flex-wrap justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-stone-900">{{ $production->production_number }}</h2>
            <p class="text-xs text-stone-500 mt-1">{{ $production->produced_at?->format('d M Y') }} · {{ $production->product_name }} · dicatat oleh {{ $production->creator->fullname ?? 'System' }}</p>
        </div>
        <div class="text-right">
            <p class="text-[11px] uppercase tracking-wide text-emerald-600 font-semibold">HPP / Pcs</p>
            <p class="text-2xl font-bold text-emerald-700">Rp {{ number_format($production->hpp_per_unit, 0, ',', '.') }}</p>
            <p class="text-[11px] text-stone-400 mt-1">{{ number_format($production->output_qty, 0, ',', '.') }} pcs jadi</p>
        </div>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
        <div class="bg-stone-50 rounded-xl p-3"><p class="text-[10px] uppercase text-stone-400 font-semibold">Biaya Bahan</p><p class="text-sm font-bold text-stone-800 mt-1">Rp {{ number_format($production->material_cost, 0, ',', '.') }}</p></div>
        <div class="bg-stone-50 rounded-xl p-3"><p class="text-[10px] uppercase text-stone-400 font-semibold">Biaya Lain</p><p class="text-sm font-bold text-stone-800 mt-1">Rp {{ number_format($production->other_cost, 0, ',', '.') }}</p></div>
        <div class="bg-stone-50 rounded-xl p-3"><p class="text-[10px] uppercase text-stone-400 font-semibold">Total Biaya</p><p class="text-sm font-bold text-stone-800 mt-1">Rp {{ number_format($production->total_cost, 0, ',', '.') }}</p></div>
        <div class="bg-stone-50 rounded-xl p-3"><p class="text-[10px] uppercase text-stone-400 font-semibold">HPP Produk</p><p class="text-sm font-bold text-stone-800 mt-1">Rp {{ number_format($production->cogs_before, 0, ',', '.') }} → <span class="text-emerald-700">Rp {{ number_format($production->cogs_after, 0, ',', '.') }}</span></p></div>
    </div>
    @if($production->notes)<p class="text-xs text-stone-500 mt-4"><span class="text-stone-400">Catatan:</span> {{ $production->notes }}</p>@endif
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mt-5">
    <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Pemakaian Bahan</div>
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Bahan</th>
                <th class="text-right">Qty Pakai</th>
                <th class="text-right">HPP / unit</th>
                <th class="text-right pr-4">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($production->materials as $m)
                <tr class="border-t border-stone-100">
                    <td class="px-4 py-2.5 font-semibold text-stone-800">{{ $m->material_name }}</td>
                    <td class="text-right text-stone-600">{{ rtrim(rtrim(number_format($m->quantity, 3, ',', '.'), '0'), ',') }} {{ $m->unit }}</td>
                    <td class="text-right text-stone-600">Rp {{ number_format($m->unit_cost, 0, ',', '.') }}</td>
                    <td class="text-right pr-4 font-semibold text-stone-800">Rp {{ number_format($m->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            @forelse($production->costs as $c)
                <tr class="border-t border-stone-100 bg-stone-50/40">
                    <td class="px-4 py-2.5 text-stone-600 italic">{{ $c->label }}</td>
                    <td></td><td></td>
                    <td class="text-right pr-4 font-semibold text-stone-700">Rp {{ number_format($c->amount, 0, ',', '.') }}</td>
                </tr>
            @empty
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<p class="text-[11px] text-stone-400 mt-3">HPP produk dihitung ulang dengan rata-rata bergerak setiap produksi. Stok bahan otomatis berkurang sesuai pemakaian — sisa bahan tetap tercatat di stok.</p>
@endsection
