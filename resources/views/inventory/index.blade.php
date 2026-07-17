@extends('layouts.app')
@section('title', 'Pemantauan Stok')
@section('heading', 'Pemantauan Inventori')

@section('content')
@php
    $u = $user;
    $movementTypes = [\App\Models\StockMovement::TYPE_IN, \App\Models\StockMovement::TYPE_OUT, \App\Models\StockMovement::TYPE_ADJUSTMENT];
@endphp

@if($u->canDo('manage_hq_stock'))
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Stok Pusat (HQ)</div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr><th class="text-left px-4 py-2">Produk</th><th class="text-left">SKU</th><th class="text-right">Stok</th><th class="text-right px-4 w-72">Penyesuaian</th></tr>
            </thead>
            <tbody>
                @forelse($hqProducts as $p)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-3 font-semibold text-stone-800">{{ $p->name }}</td>
                        <td class="text-stone-500">{{ $p->sku }}</td>
                        <td class="text-right font-bold {{ $p->hq_stock <= 0 ? 'text-rose-600' : 'text-stone-800' }}">{{ $p->hq_stock }}</td>
                        <td class="px-4 py-2">
                            {{-- Alasan WAJIB: penyesuaian manual tanpa keterangan jadi
                                 gerakan stok yang tak bisa dijelaskan siapa pun selamanya.
                                 Dulu form ini tak punya kolomnya sama sekali. --}}
                            <form method="POST" action="{{ route('inventory.hq-adjust') }}" class="flex gap-1 justify-end items-center">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $p->id }}">
                                <select name="type" class="px-2 py-1 border border-stone-300 rounded text-[11px]">
                                    @foreach($movementTypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                                </select>
                                <input type="number" name="quantity" min="1" value="1" class="w-16 px-2 py-1 border border-stone-300 rounded text-center text-[11px]">
                                <input type="text" name="notes" required maxlength="500" placeholder="Alasan (wajib)"
                                    class="w-40 px-2 py-1 border border-stone-300 rounded text-[11px]">
                                <button class="px-3 py-1 bg-red-600 text-white rounded text-[11px]">Simpan</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Belum ada produk.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
@endif

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">{{ $u->isPartner() ? 'Stok Saya' : 'Stok Mitra' }}</div>
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                @if(!$u->isPartner())<th class="text-left px-4 py-2">Mitra</th>@endif
                <th class="text-left px-4 py-2">Produk</th><th class="text-right">Qty</th><th class="text-right">Min</th><th class="text-right px-4 w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($partnerStock as $line)
                <tr class="border-t border-stone-100 {{ $line->isLow() ? 'bg-rose-50/40' : '' }}">
                    @if(!$u->isPartner())<td class="px-4 py-3 text-stone-600">{{ $line->user->company_name ?? ($line->user->fullname ?? '-') }}</td>@endif
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $line->product->name ?? 'Produk' }}</td>
                    <td class="text-right font-bold {{ $line->isLow() ? 'text-rose-600' : 'text-stone-800' }}">{{ $line->quantity }}</td>
                    <td class="text-right text-stone-500">{{ $line->minimum_stock }}</td>
                    <td class="px-4 py-2">
                        <form method="POST" action="{{ route('inventory.partner-adjust') }}" class="flex gap-1 justify-end items-center">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $line->user_id }}">
                            <input type="hidden" name="product_id" value="{{ $line->product_id }}">
                            <select name="type" class="px-2 py-1 border border-stone-300 rounded text-[11px]">
                                @foreach($movementTypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                            </select>
                            <input type="number" name="quantity" min="1" value="1" class="w-14 px-2 py-1 border border-stone-300 rounded text-center text-[11px]">
                            <input type="text" name="notes" required maxlength="500" placeholder="Alasan (wajib)"
                                class="w-40 px-2 py-1 border border-stone-300 rounded text-[11px]">
                            <button class="px-3 py-1 bg-red-600 text-white rounded text-[11px]">OK</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-stone-400">Belum ada data stok.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $partnerStock->links() }}</div>
@endsection
