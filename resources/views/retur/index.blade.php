@extends('layouts.app')
@section('title', 'Retur')
@section('heading', 'Retur PO')

@section('content')
@php
    $badge = ['pending' => 'bg-amber-100 text-amber-700', 'applied' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-rose-100 text-rose-700', 'void' => 'bg-stone-200 text-stone-500'];
@endphp
<div class="max-w-4xl">
    <div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-2">PO</th>
                    <th class="text-left px-4 py-2">Pembeli</th>
                    <th class="text-left px-4 py-2">Barang Diretur</th>
                    <th class="text-left px-4 py-2">Kondisi</th>
                    <th class="text-left px-4 py-2">Alasan</th>
                    <th class="text-left px-4 py-2">Status</th>
                    <th class="text-right px-4 py-2">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($returns as $r)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2 font-mono text-indigo-700">{{ $r->purchaseOrder->po_number ?? '—' }}</td>
                        <td class="px-4 py-2 text-stone-700">{{ $r->purchaseOrder?->user?->fullname ?? $r->purchaseOrder?->user?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-stone-700 whitespace-normal max-w-xs">
                            @forelse($r->items as $it)
                                <span class="inline-block">{{ $it->poItem->product_name ?? 'Produk #'.$it->purchase_order_item_id }} <b class="text-stone-900">×{{ $it->qty }}</b></span>@if(! $loop->last)<span class="text-stone-300">, </span>@endif
                            @empty
                                <span class="text-stone-300">—</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-2">
                            {{ $r->kondisi === 'rusak' ? '🔴 Rusak' : '✅ Normal' }}
                            @if($r->from_customer)<span class="block text-[9px] text-indigo-600 font-semibold mt-0.5">dari pelanggan · stok mitra tak dikurangi</span>@endif
                        </td>
                        <td class="px-4 py-2 text-stone-500">{{ $r->reason ?: '—' }}</td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $badge[$r->status] ?? '' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-2 text-right">
                            @if($canProcess && $r->status === 'pending')
                                <form method="POST" action="{{ route('retur.approve', $r) }}" class="inline" onsubmit="return confirm('Setujui & berlakukan retur ini?')">@csrf<button class="text-[11px] text-emerald-600 hover:text-emerald-800 font-semibold">setujui</button></form>
                                <form method="POST" action="{{ route('retur.reject', $r) }}" class="inline ml-2" onsubmit="return confirm('Tolak pengajuan retur ini?')">@csrf<button class="text-[11px] text-rose-500 hover:text-rose-700">tolak</button></form>
                            @elseif($r->status === 'applied' && auth()->user()->isSuperAdmin())
                                <form method="POST" action="{{ route('retur.void', $r) }}" class="inline" onsubmit="return confirm('Batalkan retur ini? Semua efek (stok & komisi) dikembalikan.')">@csrf<button class="text-[11px] text-stone-500 hover:text-rose-600">batalkan</button></form>
                            @else
                                <span class="text-stone-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Belum ada retur. Buat retur dari halaman detail PO yang sudah selesai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $returns->links() }}</div>
</div>
@endsection
