@extends('layouts.app')
@section('title', 'Pesanan Downline')
@section('heading', 'Pesanan Downline')

@section('content')
<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">No. PO</th>
                <th class="text-left">Downline</th>
                <th class="text-right">Total</th>
                <th class="text-left">Status</th>
                <th class="text-left">Status Bayar</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $o)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-3 font-semibold text-stone-800">
                        @if(Route::has('pesanan-downline.show'))
                            <a href="{{ route('pesanan-downline.show', $o) }}" class="hover:text-red-600">{{ $o->po_number }}</a>
                        @else
                            {{ $o->po_number }}
                        @endif
                    </td>
                    <td class="text-stone-600">{{ $o->user->fullname ?? '-' }}</td>
                    <td class="text-right">Rp {{ number_format($o->total_amount, 0, ',', '.') }}</td>
                    <td><span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $o->statusColor() }}">{{ $o->status }}</span></td>
                    <td>
                        @if($o->payment_status === \App\Models\PurchaseOrder::PAYMENT_PAID)
                            <span class="px-2 py-0.5 rounded-full text-[10px] bg-emerald-100 text-emerald-700 font-semibold">🟢 Lunas</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] bg-rose-100 text-rose-700 font-semibold">🔴 Belum Lunas</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-stone-400">Belum ada pesanan dari downline.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
