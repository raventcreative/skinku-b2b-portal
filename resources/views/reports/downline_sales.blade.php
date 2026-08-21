@extends('layouts.app')
@section('title', 'Penjualan ke Downline')
@section('heading', 'Laporan Penjualan ke Downline')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-sm text-stone-500 max-w-2xl">Penjualan Anda ke downline (distributor/reseller yang memesan ke Anda) — nilai <b>bersih</b>, sudah dikurangi retur yang disetujui.</p>
    <form method="GET" class="flex items-center gap-2 text-sm">
        <span class="text-stone-500">Periode</span>
        <input type="month" name="bulan" value="{{ $bulan?->format('Y-m') }}" onchange="this.form.submit()" class="px-3 py-2 border border-stone-300 rounded-lg">
        <a href="{{ route('reports.downline-sales', ['bulan' => 'all']) }}" class="text-red-600 hover:underline">semua periode</a>
    </form>
</div>

{{-- Ringkasan --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">Penjualan ke Downline (bersih)</div>
        <div class="text-2xl font-bold text-emerald-700 mt-1">{{ $rp($report['net']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">Jumlah Pesanan</div>
        <div class="text-2xl font-bold text-stone-800 mt-1">{{ number_format($report['po_count'], 0, ',', '.') }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">Pending</div>
        <div class="text-2xl font-bold text-amber-600 mt-1">{{ number_format($report['pending'], 0, ',', '.') }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">Selesai</div>
        <div class="text-2xl font-bold text-blue-600 mt-1">{{ number_format($report['completed'], 0, ',', '.') }}</div>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-4">
    {{-- Per downline --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100"><h3 class="text-sm font-bold text-stone-800">Per Downline (pembeli)</h3></div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                <th class="text-left px-4 py-2.5">Downline</th><th class="text-right">Pesanan</th><th class="text-right px-4">Total PO</th>
            </tr></thead>
            <tbody>
                @forelse($report['per_buyer'] as $b)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2.5"><span class="font-semibold text-stone-800">{{ $b['nama'] }}</span> <span class="text-stone-400">· {{ \App\Support\PartnerHierarchy::label($b['role']) }}</span></td>
                        <td class="text-right text-stone-600">{{ $b['po_count'] }}</td>
                        <td class="text-right px-4 font-semibold text-stone-800">{{ $rp($b['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-stone-400">Belum ada penjualan ke downline pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- Per produk --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100"><h3 class="text-sm font-bold text-stone-800">Produk Terlaris ke Downline</h3></div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                <th class="text-left px-4 py-2.5">Produk</th><th class="text-right">Qty</th><th class="text-right px-4">Total</th>
            </tr></thead>
            <tbody>
                @forelse($report['per_product'] as $pr)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2.5 font-semibold text-stone-800">{{ $pr['nama'] }}</td>
                        <td class="text-right text-stone-600">{{ number_format($pr['qty'], 0, ',', '.') }}</td>
                        <td class="text-right px-4 font-semibold text-stone-800">{{ $rp($pr['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-stone-400">Belum ada produk terjual ke downline.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

<p class="mt-4 text-[11px] text-stone-400">Kartu <b>bersih</b> = total PO downline yang selesai, dikurangi retur yang sudah disetujui. Tabel di atas menampilkan nilai PO kotor; detail tiap pesanan ada di menu <b>Pesanan Downline</b>, retur di <b>Riwayat PO</b>.</p>
@endsection
