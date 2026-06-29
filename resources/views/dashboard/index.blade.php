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
</script>
@endunless
@endpush
