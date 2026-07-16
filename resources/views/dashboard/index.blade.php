@extends('layouts.app')
@section('title', 'Dashboard')
@section('heading', 'Dashboard Utama')

@section('content')
@if($limited ?? false)
    <div class="bg-white rounded-2xl border border-stone-200 p-8 max-w-2xl">
        <h3 class="text-lg font-bold text-stone-900">Selamat datang, {{ $user->displayName() }} 👋</h3>
        <p class="text-sm text-stone-500 mt-1">Berikut menu yang bisa Anda akses:</p>
        <div class="grid sm:grid-cols-2 gap-3 mt-5">
            @if($user->canDo('view_learning'))
                <a href="{{ route('learning.index') }}" class="flex items-center gap-3 p-4 rounded-xl border border-stone-200 hover:border-red-300 hover:bg-red-50 transition">
                    <span class="w-10 h-10 rounded-lg bg-red-600 text-white flex items-center justify-center text-lg">▶</span>
                    <div><p class="font-bold text-stone-800 text-sm">Pembelajaran</p><p class="text-[11px] text-stone-500">Materi video pelatihan</p></div>
                </a>
            @endif
            <a href="{{ route('account.password') }}" class="flex items-center gap-3 p-4 rounded-xl border border-stone-200 hover:border-stone-300 hover:bg-stone-50 transition">
                <span class="w-10 h-10 rounded-lg bg-stone-700 text-white flex items-center justify-center text-lg">🔑</span>
                <div><p class="font-bold text-stone-800 text-sm">Ubah Password</p><p class="text-[11px] text-stone-500">Ganti kata sandi akun</p></div>
            </a>
        </div>
    </div>
@else
@php
    $cards = [
        ['Total Penjualan', 'Rp ' . number_format($summary['total_sales'], 0, ',', '.'), 'emerald'],
        ['Total PO', number_format($summary['total_po'], 0, ',', '.'), 'stone'],
        ['PO Pending', number_format($summary['pending_po'], 0, ',', '.'), 'amber'],
        ['PO Selesai', number_format($summary['completed_po'], 0, ',', '.'), 'blue'],
    ];
    if ($user->isStaff()) {
        $cards[] = ['Mitra Aktif', number_format($summary['total_partners'], 0, ',', '.'), 'purple'];
        $cards[] = ['Produk Aktif', number_format($summary['total_products'], 0, ',', '.'), 'rose'];
        $cards[] = ['Stok Pusat (unit)', number_format($summary['hq_stock_units'], 0, ',', '.'), 'cyan'];
    } else {
        $cards[] = ['Stok Saya (unit)', number_format($summary['partner_stock_units'], 0, ',', '.'), 'cyan'];
    }
@endphp

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach($cards as [$label, $value, $color])
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">{{ $label }}</p>
            <p class="text-2xl font-bold text-stone-900 mt-2">{{ $value }}</p>
            <span class="inline-block mt-2 w-8 h-1 rounded bg-{{ $color }}-500"></span>
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-6">
    <div class="lg:col-span-2 bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Tren Penjualan (14 hari terakhir)</h3>
        <canvas id="salesTrendChart" height="110"></canvas>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Distribusi Status PO</h3>
        <canvas id="poStatusChart" height="200"></canvas>
    </div>
</div>

@if(($channelSales ?? null))
    @php
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $sumConfirmed = collect($channelSales)->sum('confirmed');
        $sumPipeline = collect($channelSales)->sum('pipeline');
        $estimasi = $sumConfirmed + $sumPipeline;
    @endphp
    <div class="bg-white rounded-2xl border border-stone-200 p-5 mb-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
            <h3 class="text-sm font-bold text-stone-800">Penjualan per Channel — {{ now()->translatedFormat('F Y') }}</h3>
            <span class="text-[11px] text-stone-400">berdasarkan tanggal order masuk</span>
        </div>

        {{-- Ringkasan: sudah jadi + masih jalan = estimasi bulan ini --}}
        <div class="grid grid-cols-3 gap-3 mb-5">
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Terealisasi</p>
                <p class="text-lg font-bold text-emerald-800 mt-1">{{ $rp($sumConfirmed) }}</p>
                <p class="text-[10px] text-emerald-600">order selesai</p>
            </div>
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Masih Berjalan</p>
                <p class="text-lg font-bold text-amber-800 mt-1">{{ $rp($sumPipeline) }}</p>
                <p class="text-[10px] text-amber-600">sudah bayar, belum selesai</p>
            </div>
            <div class="rounded-xl bg-stone-800 p-3">
                <p class="text-[10px] uppercase tracking-wide text-stone-300 font-semibold">Estimasi Bulan Ini</p>
                <p class="text-lg font-bold text-white mt-1">{{ $rp($estimasi) }}</p>
                <p class="text-[10px] text-stone-400">terealisasi + berjalan</p>
            </div>
        </div>

        {{-- Dua pie: proporsi channel pada tiap tahap --}}
        <div class="grid sm:grid-cols-2 gap-4 mb-4">
            @foreach([['confirmed', 'Terealisasi', $sumConfirmed], ['pipeline', 'Masih Berjalan', $sumPipeline]] as [$bucket, $judul, $totalBucket])
                <div class="rounded-xl border border-stone-100 p-3">
                    <p class="text-[11px] font-semibold text-stone-600 text-center mb-2">{{ $judul }} · {{ $rp($totalBucket) }}</p>
                    @if($totalBucket > 0)
                        <div style="max-width:170px; margin:0 auto"><canvas id="channelChart-{{ $bucket }}" height="170"></canvas></div>
                    @else
                        <p class="text-[11px] text-stone-300 text-center py-10">belum ada</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Rincian per channel --}}
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="text-stone-400 uppercase text-[10px]">
                    <tr class="border-b border-stone-100">
                        <th class="text-left py-2">Channel</th>
                        <th class="text-right">Terealisasi</th>
                        <th class="text-right">Masih Berjalan</th>
                        <th class="text-right">Estimasi</th>
                        <th class="text-right w-24">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($channelSales as $ch)
                        @php
                            $est = $ch['confirmed'] + $ch['pipeline'];
                            $pct = $estimasi > 0 ? round($est / $estimasi * 100, 1) : 0;
                        @endphp
                        <tr class="border-b border-stone-50">
                            <td class="py-2">
                                <span class="flex items-center gap-2 font-semibold text-stone-700">
                                    <span class="w-2.5 h-2.5 rounded-full inline-block" style="background:{{ $ch['color'] }}"></span>
                                    {{ $ch['label'] }}
                                </span>
                            </td>
                            <td class="text-right text-emerald-700">{{ $ch['confirmed'] ? $rp($ch['confirmed']) : '·' }}</td>
                            <td class="text-right text-amber-700">{{ $ch['pipeline'] ? $rp($ch['pipeline']) : '·' }}</td>
                            <td class="text-right font-bold text-stone-800">{{ $est ? $rp($est) : '·' }}</td>
                            <td class="text-right">
                                <span class="text-stone-500">{{ $pct }}%</span>
                                <div class="h-1 rounded-full bg-stone-100 overflow-hidden mt-1">
                                    <div class="h-full rounded-full" style="width:{{ $pct }}%; background:{{ $ch['color'] }}"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($estimasi == 0)
            <p class="text-[11px] text-stone-400 pt-3">Belum ada order bulan ini.</p>
        @else
            <p class="text-[11px] text-stone-400 pt-3">
                ℹ️ Order <b>belum dibayar</b> &amp; <b>batal</b> tidak dihitung — belum tentu jadi uang, biar estimasi tidak menggelembung.
            </p>
        @endif
    </div>
@endif

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">PO Terbaru</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="text-stone-400 uppercase text-[10px]">
                    <tr class="border-b border-stone-100">
                        <th class="text-left py-2">No. PO</th>
                        <th class="text-left">Mitra</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentPo as $po)
                        <tr class="border-b border-stone-50 hover:bg-stone-50">
                            <td class="py-2"><a href="{{ route('purchase-orders.show', $po) }}" class="font-semibold text-stone-800 hover:text-red-600">{{ $po->po_number }}</a></td>
                            <td class="text-stone-600">{{ $po->company_name ?? '-' }}</td>
                            <td class="text-right text-stone-700">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</td>
                            <td class="text-right"><span class="px-2 py-0.5 rounded-full text-[10px] bg-stone-100 text-stone-600">{{ $po->status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-stone-400">Belum ada PO.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Peringatan Stok Rendah</h3>
        @forelse($lowStock as $line)
            <div class="flex justify-between items-center py-2 border-b border-stone-50 text-xs">
                <div>
                    <p class="font-semibold text-stone-800">{{ $line->product->name ?? 'Produk' }}</p>
                    <p class="text-[10px] text-stone-400">{{ $line->user->company_name ?? ($line->user->fullname ?? '-') }}</p>
                </div>
                <span class="text-rose-600 font-bold">{{ $line->quantity }} <span class="text-stone-400 font-normal">/ min {{ $line->minimum_stock }}</span></span>
            </div>
        @empty
            <p class="text-xs text-stone-400 py-4 text-center">Semua stok dalam kondisi normal.</p>
        @endforelse
    </div>
</div>
@endif
@endsection

@push('scripts')
@unless($limited ?? false)
<script>
    const trend = @json($salesTrend);
    const poStatus = @json($poStatus);

    new Chart(document.getElementById('salesTrendChart'), {
        type: 'line',
        data: {
            labels: trend.map(r => r.label),
            datasets: [{ label: 'Penjualan', data: trend.map(r => r.total), borderColor: '#0f4c3a', backgroundColor: 'rgba(15,76,58,.1)', fill: true, tension: .3 }]
        },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('poStatusChart'), {
        type: 'doughnut',
        data: {
            labels: poStatus.map(r => r.label),
            datasets: [{ data: poStatus.map(r => r.total), backgroundColor: ['#a8a29e','#f59e0b','#3b82f6','#8b5cf6','#06b6d4','#10b981','#ef4444','#1c1917'] }]
        },
        options: { plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } } }
    });

    @if(($channelSales ?? null))
    const channel = @json($channelSales);
    // Satu doughnut per tahap: terealisasi vs masih berjalan.
    ['confirmed', 'pipeline'].forEach(bucket => {
        const el = document.getElementById('channelChart-' + bucket);
        if (!el) return;   // tahap kosong → kanvasnya memang tak dirender
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: channel.map(c => c.label),
                datasets: [{ data: channel.map(c => c[bucket]), backgroundColor: channel.map(c => c.color) }]
            },
            options: {
                cutout: '58%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => c.label + ': Rp ' + c.raw.toLocaleString('id-ID') } }
                }
            }
        });
    });
    @endif
</script>
@endunless
@endpush
