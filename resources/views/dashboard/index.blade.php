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
                    <div><p class="font-bold text-stone-800 text-sm">SKINKU Academy</p><p class="text-[11px] text-stone-500">Materi video pelatihan</p></div>
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
    $per = $bulan->translatedFormat('M Y');

    // Kartu Penjualan dipecah per channel — angka bulat menyembunyikan dari mana
    // omzetnya datang. Channel baru (Tokopedia/Lazada/offline) otomatis ikut
    // muncul begitu ditambahkan di ReportService::channelSales().
    $salesBreakdown = ($channelSales ?? null)
        ? collect($channelSales)->map(fn ($c) => [
            'label' => $c['label'], 'value' => $c['confirmed'], 'color' => $c['color'],
        ])->values()->all()
        : null;

    // Angka BERBASIS PERIODE ikut filter bulan; angka SAAT INI tidak —
    // memfilter "stok sekarang" per bulan tak punya arti.
    $cards = [
        ['Penjualan', 'Rp ' . number_format($summary['total_sales'], 0, ',', '.'), 'emerald', $per, $salesBreakdown],
        ['PO Masuk', number_format($summary['total_po'], 0, ',', '.'), 'stone', $per, null],
        ['PO Pending', number_format($summary['pending_po'], 0, ',', '.'), 'amber', $per, null],
        ['PO Selesai', number_format($summary['completed_po'], 0, ',', '.'), 'blue', $per, null],
    ];
    if ($user->isStaff()) {
        $cards[] = ['Mitra Aktif', number_format($summary['total_partners'], 0, ',', '.'), 'purple', 'saat ini', null];
        $cards[] = ['Produk Aktif', number_format($summary['total_products'], 0, ',', '.'), 'rose', 'saat ini', null];
        $cards[] = ['Stok Pusat (unit)', number_format($summary['hq_stock_units'], 0, ',', '.'), 'cyan', 'saat ini', null];
    } else {
        $cards[] = ['Stok Saya (unit)', number_format($summary['partner_stock_units'], 0, ',', '.'), 'cyan', 'saat ini', null];
    }
@endphp

{{-- Filter periode — berlaku untuk seluruh dashboard --}}
<div class="flex flex-wrap items-center gap-2 mb-4">
    <span class="text-xs text-stone-500">Periode</span>
    <form method="GET" class="flex items-center gap-2">
        <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}" onchange="this.form.submit()"
            class="px-3 py-1.5 border border-stone-300 rounded-lg text-xs">
    </form>
    @if(! $bulan->isSameMonth(now()))
        <a href="{{ route('dashboard') }}" class="text-xs text-indigo-600 hover:underline">← bulan ini</a>
    @endif
    <span class="text-[11px] text-stone-400 ml-auto">Kartu bertanda “saat ini” tidak ikut filter</span>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach($cards as [$label, $value, $color, $note, $breakdown])
        <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-col">
            <div class="flex items-baseline justify-between gap-1">
                <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">{{ $label }}</p>
                <span class="text-[9px] text-stone-300 shrink-0">{{ $note }}</span>
            </div>
            <p class="text-2xl font-bold text-stone-900 mt-2">{{ $value }}</p>

            @if($breakdown)
                {{-- Rincian per channel — dari mana omzetnya datang --}}
                <div class="mt-3 pt-3 border-t border-stone-100 space-y-1.5">
                    @foreach($breakdown as $b)
                        <div class="flex items-center justify-between gap-2 text-[11px]">
                            <span class="flex items-center gap-1.5 text-stone-500 truncate">
                                <span class="w-2 h-2 rounded-full inline-block shrink-0" style="background:{{ $b['color'] }}"></span>
                                <span class="truncate">{{ $b['label'] }}</span>
                            </span>
                            <span class="font-semibold shrink-0 {{ $b['value'] > 0 ? 'text-stone-700' : 'text-stone-300' }}">
                                {{ $b['value'] > 0 ? 'Rp '.number_format($b['value'], 0, ',', '.') : '·' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            <span class="inline-block mt-3 w-8 h-1 rounded bg-{{ $color }}-500"></span>
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-6">
    <div class="lg:col-span-2 bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Tren Penjualan — {{ $bulan->translatedFormat('F Y') }}</h3>
        <canvas id="salesTrendChart" height="110"></canvas>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Distribusi Status PO — {{ $bulan->translatedFormat('M Y') }}</h3>
        <canvas id="poStatusChart" height="200"></canvas>
    </div>
</div>

@if(($channelSales ?? null))
    @php
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $cs = collect($channelSales);
        $sumConfirmed = $cs->sum('confirmed');
        $sumConfirmedN = $cs->sum('confirmed_n');
        $sumPipeline = $cs->sum('pipeline');
        $sumPipelineN = $cs->sum('pipeline_n');
        $sumCancelled = $cs->sum('cancelled');
        $sumCancelledN = $cs->sum('cancelled_n');
        $sumUnpaid = $cs->sum('unpaid');
        $sumUnpaidN = $cs->sum('unpaid_n');
        $estimasi = $sumConfirmed + $sumPipeline;
        $allOrders = $cs->sum('orders_n');
        $cancelRate = $allOrders > 0 ? round($sumCancelledN / $allOrders * 100, 1) : 0;
    @endphp
    <div class="bg-white rounded-2xl border border-stone-200 p-5 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
            <h3 class="text-sm font-bold text-stone-800">Penjualan per Channel</h3>
            <span class="text-[11px] text-stone-400 ml-auto">{{ $bulan->translatedFormat('F Y') }} · berdasarkan tanggal order masuk</span>
        </div>

        {{-- Ringkasan: sudah jadi + masih jalan = estimasi; batal/belum-bayar dipisah --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Terealisasi</p>
                <p class="text-lg font-bold text-emerald-800 mt-1">{{ $rp($sumConfirmed) }}</p>
                <p class="text-[10px] text-emerald-600">{{ $sumConfirmedN }} order selesai</p>
            </div>
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Masih Berjalan</p>
                <p class="text-lg font-bold text-amber-800 mt-1">{{ $rp($sumPipeline) }}</p>
                <p class="text-[10px] text-amber-600">{{ $sumPipelineN }} order jalan</p>
            </div>
            <div class="rounded-xl bg-stone-800 p-3">
                <p class="text-[10px] uppercase tracking-wide text-stone-300 font-semibold">Estimasi {{ $bulan->translatedFormat('M Y') }}</p>
                <p class="text-lg font-bold text-white mt-1">{{ $rp($estimasi) }}</p>
                <p class="text-[10px] text-stone-400">terealisasi + berjalan</p>
            </div>
            <div class="rounded-xl bg-rose-50 border border-rose-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-rose-700 font-semibold">Batal &amp; Belum Bayar</p>
                <p class="text-lg font-bold text-rose-800 mt-1">{{ $rp($sumCancelled + $sumUnpaid) }}</p>
                <p class="text-[10px] text-rose-600">
                    cancel rate <b>{{ $cancelRate }}%</b> · {{ $sumCancelledN }} batal, {{ $sumUnpaidN }} blm bayar
                </p>
            </div>
        </div>

        {{-- Kiri: proporsi channel dari yang sudah cair.
             Kanan: SEMUA (cair + berjalan) — warna tua = cair, muda = berjalan,
             jadi komposisi total terbaca dalam satu lingkaran. --}}
        <div class="grid sm:grid-cols-2 gap-4 mb-4">
            <div class="rounded-xl border border-stone-100 p-3">
                <p class="text-[11px] font-semibold text-stone-600 text-center mb-2">Terealisasi · {{ $rp($sumConfirmed) }}</p>
                {{-- Canvas dibiarkan selebar panel, tinggi yang mengunci ukuran donat
                     (radius = min(lebar,tinggi)/2, jadi donatnya tetap 170px dan
                     terpusat). Dulu dikurung max-width:170px — tooltip Chart.js
                     digambar DI ATAS canvas, jadi teks yang lebih lebar dari 170px
                     terpotong dan angkanya tak terbaca. --}}
                @if($sumConfirmed > 0)
                    <div style="height:170px"><canvas id="channelChart-confirmed"></canvas></div>
                @else
                    <p class="text-[11px] text-stone-300 text-center py-10">belum ada</p>
                @endif
            </div>
            <div class="rounded-xl border border-stone-100 p-3">
                <p class="text-[11px] font-semibold text-stone-600 text-center mb-2">Semua (cair + berjalan) · {{ $rp($estimasi) }}</p>
                @if($estimasi > 0)
                    <div style="height:170px"><canvas id="channelChart-all"></canvas></div>
                    <div class="flex flex-wrap justify-center gap-x-3 gap-y-1 mt-3">
                        @foreach($channelSales as $ch)
                            @if($ch['confirmed'] > 0)
                                <span class="flex items-center gap-1 text-[10px] text-stone-500">
                                    <span class="w-2 h-2 rounded-full inline-block" style="background:{{ $ch['color'] }}"></span>{{ $ch['label'] }} cair
                                </span>
                            @endif
                            @if($ch['pipeline'] > 0)
                                <span class="flex items-center gap-1 text-[10px] text-stone-500">
                                    <span class="w-2 h-2 rounded-full inline-block" style="background:{{ $ch['color_light'] }}"></span>{{ $ch['label'] }} berjalan
                                </span>
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="text-[11px] text-stone-300 text-center py-10">belum ada</p>
                @endif
            </div>
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
                        <th class="text-right">Batal</th>
                        <th class="text-right">Blm Bayar</th>
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
                            <td class="text-right text-rose-600">
                                @if($ch['cancelled_n'])
                                    {{ $rp($ch['cancelled']) }}
                                    <span class="block text-[10px] text-rose-400">{{ $ch['cancelled_n'] }} order · {{ $ch['cancel_rate'] }}%</span>
                                @else
                                    <span class="text-stone-300">·</span>
                                @endif
                            </td>
                            <td class="text-right text-stone-500">
                                @if($ch['unpaid_n'])
                                    {{ $rp($ch['unpaid']) }}
                                    <span class="block text-[10px] text-stone-400">{{ $ch['unpaid_n'] }} order</span>
                                @else
                                    <span class="text-stone-300">·</span>
                                @endif
                            </td>
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
    const rupiah = v => 'Rp ' + v.toLocaleString('id-ID');
    const doughnut = (el, labels, data, colors) => new Chart(el, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors }] },
        options: {
            // Tinggi wadah yang mengunci ukuran donat; canvas boleh selebar panel
            // supaya tooltip punya ruang. Tanpa ini donat memenuhi lebar penuh.
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        // Judul tooltip SUDAH menampilkan label; mengulanginya di isi
                        // bikin teks dua kali lebih lebar dan terpotong canvas.
                        label: c => {
                            const total = c.dataset.data.reduce((a, b) => a + b, 0);
                            const porsi = total > 0 ? (c.raw / total * 100) : 0;
                            return rupiah(c.raw) + ' · ' + porsi.toLocaleString('id-ID', { maximumFractionDigits: 1 }) + '%';
                        },
                    },
                },
            },
        },
    });

    // Kiri: proporsi channel dari yang sudah cair.
    const elC = document.getElementById('channelChart-confirmed');
    if (elC) doughnut(elC, channel.map(c => c.label), channel.map(c => c.confirmed), channel.map(c => c.color));

    // Kanan: SEMUA — tiap channel dipecah cair (warna tua) & berjalan (muda).
    // Segmen nol dibuang supaya legenda tidak penuh entri kosong.
    const elA = document.getElementById('channelChart-all');
    if (elA) {
        const seg = [];
        channel.forEach(c => {
            if (c.confirmed > 0) seg.push([c.label + ' cair', c.confirmed, c.color]);
            if (c.pipeline > 0) seg.push([c.label + ' berjalan', c.pipeline, c.color_light]);
        });
        if (seg.length) doughnut(elA, seg.map(s => s[0]), seg.map(s => s[1]), seg.map(s => s[2]));
    }
    @endif
</script>
@endunless
@endpush
