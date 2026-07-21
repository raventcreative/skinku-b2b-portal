@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('heading', 'Purchase Orders')

@section('content')
@php $u = auth()->user(); @endphp
<div class="flex justify-between items-center mb-4">
    <form method="GET" class="flex flex-wrap gap-2">
        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari no PO / perusahaan…" class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-60">
        <select name="status" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
            <option value="">Semua Status</option>
            @foreach($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '')===$s)>{{ $s }}</option>@endforeach
        </select>
        <button class="px-4 py-2 text-sm bg-stone-200 rounded-lg hover:bg-stone-300">Filter</button>
        <a href="{{ route('purchase-orders.export') }}" class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⬇ Export Excel</a>
    </form>
    @if($u->isPartner())
        <a href="{{ route('purchase-orders.create') }}" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Buat PO</a>
    @endif
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">No. PO</th>
                <th class="text-left">Mitra</th>
                <th class="text-left">Tanggal</th>
                <th class="text-right">Total</th>
                <th class="text-left">Status</th>
                <th class="text-right px-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $po)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $po->po_number }}</td>
                    <td class="text-stone-600">{{ $po->company_name ?? ($po->user->fullname ?? '-') }}</td>
                    <td class="text-stone-500">{{ $po->created_at?->format('d M Y H:i') }}</td>
                    <td class="text-right">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</td>
                    <td><span class="px-2 py-0.5 rounded-full text-[10px] bg-stone-100 text-stone-700">{{ $po->status }}</span></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('purchase-orders.show', $po) }}" class="text-stone-600 hover:text-red-600 font-semibold">Detail</a>
                        @if($u->isStaff() && $u->canDo('delete_po'))
                            <form method="POST" action="{{ route('purchase-orders.force-destroy', $po) }}" class="inline ml-2" onsubmit="return confirm('Hapus PERMANEN PO {{ $po->po_number }}? Tidak bisa dikembalikan. Gunakan untuk membersihkan data test.')">
                                @csrf @method('DELETE')
                                <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-stone-400">Belum ada PO.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
