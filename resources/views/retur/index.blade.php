@extends('layouts.app')
@section('title', 'Retur')
@section('heading', 'Retur PO')

@section('content')
@php
    $badge = ['pending' => 'bg-amber-100 text-amber-700', 'applied' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-rose-100 text-rose-700', 'void' => 'bg-stone-200 text-stone-500'];
@endphp
<div class="w-full">
    <div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap retur-table">
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
                            <span class="inline-flex min-h-6 items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $r->kondisi === 'rusak' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800' }}">
                                {{ $r->kondisi === 'rusak' ? 'Rusak' : 'Normal' }}
                            </span>
                            @if($r->from_customer)<span class="block text-[9px] text-indigo-600 font-semibold mt-0.5">dari pelanggan · stok mitra tak dikurangi</span>@endif
                        </td>
                        <td class="px-4 py-2 text-stone-500">{{ $r->reason ?: '—' }}</td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $badge[$r->status] ?? '' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @php
                                $isSuper = auth()->user()->isSuperAdmin();
                                $showApproveReject = $canProcess && $r->status === 'pending';
                                $showVoid = $r->status === 'applied' && $isSuper;
                                $showDelete = $isSuper && $r->status !== 'applied';
                            @endphp
                            <div>
                            @if($showApproveReject)
                                <form method="POST" action="{{ route('retur.approve', $r) }}" class="inline" onsubmit="return confirm('Setujui & berlakukan retur ini?')">@csrf<button class="inline-flex min-h-8 items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 text-[11px] font-semibold text-emerald-800 hover:bg-emerald-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>Setujui</button></form>
                                <form method="POST" action="{{ route('retur.reject', $r) }}" class="inline" onsubmit="return confirm('Tolak pengajuan retur ini?')">@csrf<button class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18"/></svg>Tolak</button></form>
                            @endif
                            @if($showVoid)
                                <form method="POST" action="{{ route('retur.void', $r) }}" class="inline" onsubmit="return confirm('Batalkan retur ini? Semua efek (stok & komisi) dikembalikan.')">@csrf<button class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-2.5 text-[11px] font-semibold text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-stone-500"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7v6h6M5.6 5.6A9 9 0 1 1 3 13"/></svg>Batalkan</button></form>
                            @endif
                            @if($showDelete)
                                <form method="POST" action="{{ route('retur.force-destroy', $r) }}" class="inline" onsubmit="return confirm('Hapus PERMANEN retur ini? Tidak bisa dikembalikan. Untuk membersihkan data test / pengajuan batal.')">@csrf @method('DELETE')<button class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14M10 10v6m4-6v6"/></svg>Hapus</button></form>
                            @endif
                            @unless($showApproveReject || $showVoid || $showDelete)
                                <span class="text-stone-300">—</span>
                            @endunless
                            </div>
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
