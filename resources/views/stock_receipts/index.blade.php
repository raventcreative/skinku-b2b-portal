@extends('layouts.app')
@section('title', 'Stok Masuk')
@section('heading', 'Stok Masuk (Penerimaan & HPP)')

@section('content')
<div class="flex justify-between items-center mb-5 gap-3 flex-wrap">
    <p class="text-xs text-stone-500 max-w-xl">Catat produk yang datang beserta harga belinya. Stok pusat bertambah otomatis dan HPP produk dihitung ulang memakai rata-rata bergerak (moving average).</p>
    <a href="{{ route('stock-receipts.create') }}" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 shrink-0">+ Catat Stok Masuk</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">No. Penerimaan</th>
                <th class="text-left">Tanggal</th>
                <th class="text-left">Supplier</th>
                <th class="text-left">Ref.</th>
                <th class="text-right">Jml Produk</th>
                <th class="text-right pr-4">Total Biaya</th>
                <th class="text-left">Dicatat</th>
            </tr>
        </thead>
        <tbody>
            @forelse($receipts as $r)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5"><a href="{{ route('stock-receipts.show', $r) }}" class="font-bold text-red-700 hover:underline">{{ $r->receipt_number }}</a></td>
                    <td class="text-stone-600">{{ $r->received_at?->format('d M Y') }}</td>
                    <td class="text-stone-600">{{ $r->supplier_name ?: '—' }}</td>
                    <td class="text-stone-400">{{ $r->reference_no ?: '—' }}</td>
                    <td class="text-right text-stone-600">{{ $r->items_count }}</td>
                    <td class="text-right pr-4 font-semibold text-stone-800">Rp {{ number_format($r->total_cost, 0, ',', '.') }}</td>
                    <td class="text-stone-400">{{ $r->creator->fullname ?? 'System' }}<br><span class="text-[10px]">{{ $r->created_at?->format('d M H:i') }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Belum ada catatan stok masuk. Klik "+ Catat Stok Masuk".</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $receipts->links() }}</div>
@endsection
