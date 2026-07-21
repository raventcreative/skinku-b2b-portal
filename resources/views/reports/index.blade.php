@extends('layouts.app')
@php $isPartner = $user->isPartner(); @endphp
@section('title', $isPartner ? 'Laporan Pembelian Saya' : 'Laporan Penjualan')
@section('heading', $isPartner ? 'Laporan Pembelian Saya' : 'Intelijen Bisnis & Laporan')

@section('content')
@if($isPartner)
    <p class="text-xs text-stone-500 mb-4">Ringkasan pesanan (PO) Anda ke pusat SKINKU — total pembelian, jumlah PO, dan statusnya.</p>
@endif
{{-- Satu kendali saja: PERIODE. Grafik tren mengikutinya sendiri (satu bulan →
     per hari, semua periode → per bulan). Dropdown granularitas dibuang: dia
     berdiri sendiri di atas dan tampak seperti filter halaman padahal cuma
     mengubah bucket grafik. --}}
@php $per = $bulan ? $bulan->translatedFormat('M Y') : 'semua periode'; @endphp
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4 text-sm">
    <span class="text-stone-500">Periode</span>
    <input type="month" name="bulan" value="{{ $bulan?->format('Y-m') }}" onchange="this.form.submit()"
        class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
    @if($bulan)
        <a href="{{ route('reports.index', ['bulan' => \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
            class="text-xs text-indigo-600 hover:underline">semua periode</a>
    @else
        {{-- Jangan tambahkan input hidden bernama "bulan" di sini: namanya
             bentrok dengan picker di atas, yang belakang menang saat submit,
             sehingga memilih bulan dari mode ini malah terkirim sebagai "all". --}}
        <span class="text-xs text-stone-400">menampilkan semua periode — pilih bulan untuk mempersempit</span>
    @endif
    <a href="{{ route('reports.export', array_filter(['bulan' => request('bulan')])) }}"
        class="ml-auto px-3 py-1.5 text-xs bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⬇ Export Excel</a>
</form>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php
        // Halaman ini khusus PO — omzet barang, HPP & laba kotornya semua berbasis
        // PO. Labelnya diberi "PO" agar tak tertukar dengan kartu Dashboard yang
        // mencakup semua channel.
        $cards = $isPartner ? [
            ['Total Pembelian (selesai)', 'Rp ' . number_format($summary['total_sales'], 0, ',', '.'), $per],
            ['Jumlah PO Saya', number_format($summary['total_po'], 0, ',', '.'), $per],
            ['PO Pending', number_format($summary['pending_po'], 0, ',', '.'), $per],
            ['PO Selesai', number_format($summary['completed_po'], 0, ',', '.'), $per],
        ] : [
            ['Penjualan PO (tagihan)', 'Rp ' . number_format($summary['total_sales'], 0, ',', '.'), $per],
            ['Total PO', number_format($summary['total_po'], 0, ',', '.'), $per],
            ['Produk Aktif', number_format($summary['total_products'], 0, ',', '.'), 'saat ini'],
            ['Stok Pusat', number_format($summary['hq_stock_units'], 0, ',', '.'), 'saat ini'],
        ];
    @endphp
    @foreach($cards as [$l, $v, $note])
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <div class="flex items-baseline justify-between gap-1">
                <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">{{ $l }}</p>
                <span class="text-[9px] text-stone-300 shrink-0">{{ $note }}</span>
            </div>
            <p class="text-xl font-bold text-stone-900 mt-2">{{ $v }}</p>
        </div>
    @endforeach
</div>
@unless($isPartner)
    <p class="text-[11px] text-stone-400 -mt-4 mb-6">
        ℹ️ Halaman ini khusus <b>penjualan PO/distributor</b>. Untuk omzet <b>semua channel</b>
        (TikTok, Shopee, PO) lihat <a href="{{ route('dashboard') }}" class="text-indigo-600 hover:underline">Dashboard</a>.
    </p>
@endunless

@isset($grossProfit)
@php
    // Selisih Penjualan PO (total tagihan) vs Omzet Barang (subtotal produk) =
    // ongkir dikurangi diskon. Ditampilkan eksplisit supaya dua angka yang
    // sering kembar ini tidak terlihat seperti kartu duplikat tanpa guna.
    $selisih = round($summary['total_sales'] - $grossProfit['revenue']);
@endphp
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex items-baseline justify-between gap-1">
            <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">Omzet Barang (selesai)</p>
            <span class="text-[9px] text-stone-300 shrink-0">{{ $per }}</span>
        </div>
        <p class="text-xl font-bold text-stone-900 mt-2">Rp {{ number_format($grossProfit['revenue'], 0, ',', '.') }}</p>
        <p class="text-[10px] text-stone-400 mt-1">
            @if($selisih == 0)
                = Penjualan PO (belum ada ongkir/diskon tercatat)
            @else
                = Penjualan PO {{ $selisih > 0 ? '−' : '+' }} Rp {{ number_format(abs($selisih), 0, ',', '.') }} ongkir/diskon
            @endif
        </p>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex items-baseline justify-between gap-1">
            <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">HPP (COGS)</p>
            <span class="text-[9px] text-stone-300 shrink-0">{{ $per }}</span>
        </div>
        <p class="text-xl font-bold text-stone-700 mt-2">Rp {{ number_format($grossProfit['cogs'], 0, ',', '.') }}</p>
    </div>
    <div class="bg-white rounded-2xl border border-emerald-200 bg-emerald-50/40 p-5">
        <div class="flex items-baseline justify-between gap-1">
            <p class="text-[11px] uppercase tracking-wide text-emerald-600 font-semibold">Laba Kotor</p>
            <span class="text-[9px] text-emerald-500/50 shrink-0">{{ $per }}</span>
        </div>
        <p class="text-xl font-bold text-emerald-700 mt-2">Rp {{ number_format($grossProfit['profit'], 0, ',', '.') }}</p>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex items-baseline justify-between gap-1">
            <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">Margin Kotor</p>
            <span class="text-[9px] text-stone-300 shrink-0">{{ $per }}</span>
        </div>
        <p class="text-xl font-bold text-stone-900 mt-2">{{ number_format($grossProfit['margin'], 1, ',', '.') }}%</p>
    </div>
</div>
<p class="text-[11px] text-stone-400 -mt-2 mb-6">HPP memakai rata-rata bergerak terkini dari menu Stok Masuk. Omzet barang = nilai produk pada PO selesai (belum termasuk ongkir).</p>
@endisset

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">{{ $isPartner ? 'Tren Pembelian Saya' : 'Tren Penjualan' }}</h3><canvas id="trendChart" height="140"></canvas></div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">{{ $isPartner ? 'Produk Paling Sering Saya Beli' : 'Top 10 Produk' }}</h3><canvas id="productChart" height="140"></canvas></div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">{{ $isPartner ? 'Status PO Saya' : 'Distribusi Status PO' }}</h3>{{-- Tinggi wadah mengunci ukuran pie: tanpa ini canvas melebar sekolom
        penuh dan pie-nya ikut membesar setinggi lebarnya. --}}<div style="height:260px"><canvas id="statusChart"></canvas></div></div>
    @unless($isPartner)
    <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">Stok HQ vs Mitra</h3><canvas id="inventoryChart" height="140"></canvas></div>
    @endunless
    @if(isset($salesByDistributor))
        <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">Penjualan per Distributor</h3><canvas id="distChart" height="140"></canvas></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-5"><h3 class="text-sm font-bold text-stone-800 mb-3">Penjualan per Region</h3><div style="height:260px"><canvas id="regionChart"></canvas></div></div>
    @endif
</div>

{{-- Rincian per mitra: angka, bukan cuma grafik. Distributor + reseller +
     pembeli lepas sekaligus, bisa ditelusuri ke PO-nya. --}}
@isset($partnerDetail)
    @php
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $totRev = collect($partnerDetail)->sum('revenue');
        $totOrd = collect($partnerDetail)->sum('orders');
    @endphp
    <div class="bg-white rounded-2xl border border-stone-200 mt-6 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex flex-wrap items-center gap-3">
            <h3 class="text-sm font-bold text-stone-800">Penjualan per Mitra</h3>
            {{-- Ikut filter periode di atas — satu kendali untuk seluruh halaman. --}}
            <span class="text-[11px] text-stone-400">
                {{ $bulan ? $bulan->translatedFormat('F Y') : 'semua periode' }} · PO selesai
            </span>
        </div>

        @if(count($partnerDetail))
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                        <tr>
                            <th class="text-left px-5 py-2">Mitra</th>
                            <th class="text-left">Peran</th>
                            <th class="text-right">Order</th>
                            <th class="text-right">Rata-rata / order</th>
                            <th class="text-right pr-5">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($partnerDetail as $p)
                            <tr class="border-t border-stone-100 hover:bg-stone-50">
                                <td class="px-5 py-2">
                                    {{-- Telusur: buka daftar PO yang tersaring ke mitra ini --}}
                                    <a href="{{ route('purchase-orders.index', ['q' => $p['label'], 'status' => 'completed']) }}"
                                        class="font-semibold text-indigo-700 hover:underline">{{ $p['label'] }}</a>
                                </td>
                                <td class="text-stone-500">{{ $p['role'] ?? '—' }}</td>
                                <td class="text-right text-stone-600">{{ number_format($p['orders'], 0, ',', '.') }}</td>
                                <td class="text-right text-stone-500">{{ $rp($p['avg']) }}</td>
                                <td class="text-right pr-5 font-bold text-stone-800">{{ $rp($p['revenue']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-stone-300 bg-stone-50 font-bold text-stone-800">
                            <td class="px-5 py-2">TOTAL</td>
                            <td></td>
                            <td class="text-right">{{ number_format($totOrd, 0, ',', '.') }}</td>
                            <td></td>
                            <td class="text-right pr-5">{{ $rp($totRev) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="px-5 py-2.5 text-[11px] text-stone-400 border-t border-stone-100">
                💡 Klik nama mitra untuk melihat daftar PO-nya. Pembeli sekali-beli ikut muncul di sini.
            </p>
        @else
            <p class="px-5 py-8 text-center text-xs text-stone-400">
                Belum ada PO selesai{{ $bulan ? ' pada '.$bulan->translatedFormat('F Y') : '' }}.
            </p>
        @endif
    </div>
@endisset
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
    new Chart(document.getElementById('statusChart'), { type:'pie', data:{ labels:D.status.map(r=>r.label), datasets:[{data:D.status.map(r=>r.total),backgroundColor:palette}]}, options:{maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{font:{size:10}}}}}});
    @unless($isPartner)
    new Chart(document.getElementById('inventoryChart'), { type:'bar', data:{ labels:D.inventory.map(r=>r.label), datasets:[{label:'HQ',data:D.inventory.map(r=>r.hq_stock),backgroundColor:'#1c1917'},{label:'Mitra',data:D.inventory.map(r=>r.partner_stock),backgroundColor:'#c8a96a'}]}, options:{scales:{x:{stacked:false}}}});
    @endunless
    @if(isset($salesByDistributor))
    new Chart(document.getElementById('distChart'), { type:'bar', data:{ labels:D.dist.map(r=>r.label), datasets:[{label:'Revenue',data:D.dist.map(r=>r.revenue),backgroundColor:'#3b82f6'}]}, options:{plugins:{legend:{display:false}}}});
    new Chart(document.getElementById('regionChart'), { type:'doughnut', data:{ labels:D.region.map(r=>r.label), datasets:[{data:D.region.map(r=>r.revenue),backgroundColor:palette}]}, options:{maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{font:{size:10}}}}}});
    @endif
</script>
@endpush
