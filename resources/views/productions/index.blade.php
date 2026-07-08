@extends('layouts.app')
@section('title', 'Produksi')
@section('heading', 'Produksi (HPP)')

@section('content')
<div class="flex justify-between items-center mb-5 gap-3 flex-wrap">
    <p class="text-xs text-stone-500 max-w-xl">Catat batch produksi: pemakaian bahan + biaya lain, sistem hitung HPP/pcs otomatis. Stok produk jadi bertambah dan HPP produk (rata-rata bergerak) diperbarui.</p>
    <a href="{{ route('productions.create') }}" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 shrink-0">+ Produksi</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">No. Produksi</th>
                <th class="text-left">Tanggal</th>
                <th class="text-left">Produk</th>
                <th class="text-right">Qty Jadi</th>
                <th class="text-right">Total Biaya</th>
                <th class="text-right pr-4">HPP / Pcs</th>
                <th class="text-left">Oleh</th>
            </tr>
        </thead>
        <tbody>
            @forelse($productions as $p)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5"><a href="{{ route('productions.show', $p) }}" class="font-bold text-red-700 hover:underline">{{ $p->production_number }}</a></td>
                    <td class="text-stone-600">{{ $p->produced_at?->format('d M Y') }}</td>
                    <td class="font-semibold text-stone-800">{{ $p->product_name }}</td>
                    <td class="text-right text-stone-600">{{ number_format($p->output_qty, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-700">Rp {{ number_format($p->total_cost, 0, ',', '.') }}</td>
                    <td class="text-right pr-4 font-bold text-emerald-700">Rp {{ number_format($p->hpp_per_unit, 0, ',', '.') }}</td>
                    <td class="text-stone-400">{{ $p->creator->fullname ?? 'System' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Belum ada catatan produksi. Klik "+ Produksi".</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $productions->links() }}</div>
@endsection
