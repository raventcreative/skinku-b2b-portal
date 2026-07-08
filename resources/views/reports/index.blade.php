@extends('layouts.app')
@php $isPartner = $user->isPartner(); @endphp
@section('title', $isPartner ? 'Laporan Pembelian Saya' : 'Laporan Penjualan')
@section('heading', $isPartner ? 'Laporan Pembelian Saya' : 'Intelijen Bisnis & Laporan')

@section('content')
@if($isPartner)
    <p class="text-xs text-stone-500 mb-4">Ringkasan pesanan (PO) Anda ke pusat SKINKU — total pembelian, jumlah PO, dan statusnya.</p>
@endif
<div class="flex justify-between items-center mb-4">
    <form method="GET" class="flex gap-2 items-center text-sm">
        <span class="text-stone-500">Granularitas tren:</span>
        <select name="granularity" onchange="this.form.submit()" class="px-3 py-2 border border-stone-300 rounded-lg">
            @foreach(['day' => 'Harian', 'week' => 'Mingguan', 'month' => 'Bulanan'] as $val => $label)
                <option value="{{ $val }}" @selected($granularity===$val)>{{ $label }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php
        $cards = $isPartner ? [
            ['Total Pembelian (selesai)', 'Rp ' . number_format($summary['total_sales'], 0, ',', '.')],
            ['Jumlah PO Saya', number_format($summary['total_po'], 0, ',', '.')],
            ['PO Pending', number_format($summary['pending_po'], 0, ',', '.')],
            ['PO Selesai', number_format($summary['completed_po'], 0, ',', '.')],
        ] : [
            ['Total Penjualan', 'Rp ' . number_format($summary['total_sales'], 0, ',', '.')],
            ['Total PO', number_format($summary['total_po'], 0, ',', '.')],
            ['Produk Aktif', number_format($summary['total_products'], 0, ',', '.')],
            ['Stok Pusat', number_format($summary['hq_stock_units'], 0, ',', '.')],
        ];
    @endphp
    @foreach($cards as [$l, $v])
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">{{ $l }}</p>
            <p class="text-xl font-bold text-stone-900 mt-2">{{ $v }}</p>
        </div>
    @endforeach
</div>

@isset($grossProfit)
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">Omzet Barang (selesai)</p>
        <p class="text-xl font-bold text-stone-900 mt-2">Rp {{ number_format($grossProfit['revenue'], 0, ',', '.') }}</p>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">HPP (COGS)</p>
        <p class="text-xl font-bold text-stone-700 mt-2">Rp {{ number_format($grossProfit['cogs'], 0, ',', '.') }}</p>
    </div>
    <div class="bg-white rounded-2xl border border-emerald-200 bg-emerald-50/40 p-5">
        <p class="text-[11px] uppercase tracking-wide text-emerald-600 font-semibold">Laba Kotor</p>
        <p class="text-xl font-bold text-emerald-700 mt-2">Rp {{ number_format($grossProfit['profit'], 0, ',', '.') }}</p>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">Margin Kotor</p>
        <p class="text-xl font-bold text-stone-900 mt-2">{{ number_format($grossProfit['margin'], 1, ',', '.') }}%</p>
    </div>
</div>
<p class="text-[11px] text-stone-400 -mt-2 mb-6">HPP memakai rata-rata bergerak terkini dari menu Stok Masuk. Omzet barang = nilai produk pada PO selesai (belum termasuk ongkir).</p>
@endisset

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">{{ $isPartner ? 'Tren Pembelian Saya' : 'Tren Penjualan' }}</h3><canvas id="trendChart" height="140"></canvas></div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">{{ $isPartner ? 'Produk Paling Sering Saya Beli' : 'Top 10 Produk' }}</h3><canvas id="productChart" height="140"></canvas></div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">{{ $isPartner ? 'Status PO Saya' : 'Distribusi Status PO' }}</h3><canvas id="statusChart" height="140"></canvas></div>
    @unless($isPartner)
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">Stok HQ vs Mitra</h3><canvas id="inventoryChart" height="140"></canvas></div>
    @endunless
    @if(isset($salesByDistributor))
        <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">Penjualan per Distributor</h3><canvas id="distChart" height="140"></canvas></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">Penjualan per Region</h3><canvas id="regionChart" height="140"></canvas></div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    const D = {
        trend: @json($salesTrend),
        product: @json($salesByProduct),
        status: @json($poStatus),
        inventory: @json($inventory),
        @if(isset($salesByDistributor))
        dist: @json($salesByDistributor),
        region: @json($salesByRegion),
        @endif
    };
    const palette = ['#0f4c3a','#c8a96a','#3b82f6','#8b5cf6','#06b6d4','#10b981','#ef4444','#f59e0b','#a8a29e','#1c1917'];

    const trendLabel = @json($isPartner ? 'Pembelian' : 'Penjualan');
    new Chart(document.getElementById('trendChart'), { type:'line', data:{ labels:D.trend.map(r=>r.label), datasets:[{label:trendLabel,data:D.trend.map(r=>r.total),borderColor:'#0f4c3a',backgroundColor:'rgba(15,76,58,.1)',fill:true,tension:.3}]}, options:{plugins:{legend:{display:false}}}});
    new Chart(document.getElementById('productChart'), { type:'bar', data:{ labels:D.product.map(r=>r.label), datasets:[{label:'Nilai',data:D.product.map(r=>r.revenue),backgroundColor:'#c8a96a'}]}, options:{indexAxis:'y',plugins:{legend:{display:false}}}});
    new Chart(document.getElementById('statusChart'), { type:'pie', data:{ labels:D.status.map(r=>r.label), datasets:[{data:D.status.map(r=>r.total),backgroundColor:palette}]}, options:{plugins:{legend:{position:'right',labels:{font:{size:10}}}}}});
    @unless($isPartner)
    new Chart(document.getElementById('inventoryChart'), { type:'bar', data:{ labels:D.inventory.map(r=>r.label), datasets:[{label:'HQ',data:D.inventory.map(r=>r.hq_stock),backgroundColor:'#1c1917'},{label:'Mitra',data:D.inventory.map(r=>r.partner_stock),backgroundColor:'#c8a96a'}]}, options:{scales:{x:{stacked:false}}}});
    @endunless
    @if(isset($salesByDistributor))
    new Chart(document.getElementById('distChart'), { type:'bar', data:{ labels:D.dist.map(r=>r.label), datasets:[{label:'Revenue',data:D.dist.map(r=>r.revenue),backgroundColor:'#3b82f6'}]}, options:{plugins:{legend:{display:false}}}});
    new Chart(document.getElementById('regionChart'), { type:'doughnut', data:{ labels:D.region.map(r=>r.label), datasets:[{data:D.region.map(r=>r.revenue),backgroundColor:palette}]}, options:{plugins:{legend:{position:'right',labels:{font:{size:10}}}}}});
    @endif
</script>
@endpush
