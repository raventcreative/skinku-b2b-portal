@extends('layouts.app')
@section('title', 'Stock Movement')
@section('heading', 'Riwayat Pergerakan Stok')

@section('content')
@if(($focusProduct ?? null) || ($filters['from'] ?? null))
    <div class="mb-4 flex flex-wrap items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 border border-indigo-200 text-indigo-800 text-[12px]">
        <span>🔎 Detail dari <b>Laporan Stok HQ</b>:</span>
        @if($focusProduct ?? null)<span class="font-semibold">{{ $focusProduct->name }}</span>@endif
        @if($filters['from'] ?? null)<span>· periode <b>{{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }}</b>@if(($filters['to'] ?? null) && $filters['to'] !== $filters['from']) – <b>{{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d M Y') }}</b>@endif</span>@endif
        <a href="{{ route('stock-movements.index') }}" class="ml-auto underline hover:text-indigo-900">✕ Lihat semua</a>
    </div>
@endif

<form method="GET" class="flex flex-wrap gap-2 mb-4">
    @foreach(['product_id', 'from', 'to'] as $keep)
        @if($filters[$keep] ?? null)<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif
    @endforeach
    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari produk/SKU…" class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-56">
    <select name="type" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
        <option value="">Semua Tipe</option>
        @foreach($types as $t)<option value="{{ $t }}" @selected(($filters['type'] ?? '')===$t)>{{ $t }}</option>@endforeach
    </select>
    <button class="px-4 py-2 text-sm bg-stone-200 rounded-lg hover:bg-stone-300">Filter</button>
</form>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Waktu</th>
                <th class="text-left">Produk</th>
                <th class="text-left">Pemilik</th>
                <th class="text-left">Tipe</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Sebelum</th>
                <th class="text-right">Sesudah</th>
                <th class="text-left px-4">Referensi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($movements as $m)
                @php
                    $badge = match($m->movement_type) {
                        'IN','PO_FULFILLMENT' => 'bg-emerald-100 text-emerald-700',
                        'OUT' => 'bg-rose-100 text-rose-700',
                        default => 'bg-stone-100 text-stone-700',
                    };
                @endphp
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2 text-stone-500">{{ $m->created_at?->format('d M Y H:i') }}</td>
                    <td class="font-semibold text-stone-800">{{ $m->product->name ?? '-' }}</td>
                    <td class="text-stone-500">{{ $m->user->company_name ?? ($m->user->fullname ?? 'HQ / Pusat') }}</td>
                    <td><span class="px-2 py-0.5 rounded-full text-[10px] {{ $badge }}">{{ $m->movement_type }}</span></td>
                    <td class="text-right font-bold">{{ $m->quantity }}</td>
                    <td class="text-right text-stone-400">{{ $m->before_qty }}</td>
                    <td class="text-right text-stone-700">{{ $m->after_qty }}</td>
                    <td class="px-4 text-stone-500">{{ $m->notes ?? ($m->reference_type ? $m->reference_type.' #'.$m->reference_id : '-') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-stone-400">Belum ada pergerakan stok.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $movements->links() }}</div>
@endsection
