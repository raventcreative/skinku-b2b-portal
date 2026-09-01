@extends('layouts.app')
@section('title', 'Dashboard')
@section('heading', 'Dashboard Utama')

@section('content')
{{-- Pengumuman dari super admin (per role): box catatan nempel (bisa >1) + popup banner. --}}
@foreach(($boxes ?? []) as $box)
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-3">
        @if($box->note_title)<p class="text-sm font-bold text-amber-900 mb-0.5">📢 {{ $box->note_title }}</p>@endif
        @if(filled($box->note_body))
            {{-- noteBodyHtml(): sudah di-escape + URL jadi tautan + newline jadi <br>. --}}
            <p class="text-sm text-amber-800">{!! $box->noteBodyHtml() !!}</p>
        @endif
        @if($box->note_link)
            <a href="{{ $box->note_link }}" target="_blank" rel="noopener"
                class="inline-block mt-2 px-4 py-1.5 text-xs font-semibold bg-amber-600 text-white rounded-lg hover:bg-amber-700">{{ $box->noteLinkLabel() }} →</a>
        @endif
    </div>
@endforeach

@if(! empty($showPopups) && isset($popups) && $popups->isNotEmpty())
    <div id="annBanner" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto"
        onclick="if (event.target === this) this.remove()">
        <div class="relative max-w-lg w-full my-8">
            <button type="button" onclick="document.getElementById('annBanner').remove()"
                class="absolute -top-3 -right-3 w-8 h-8 rounded-full bg-white text-stone-700 shadow flex items-center justify-center hover:bg-stone-100 z-10">✕</button>
            <div class="space-y-3">
                @foreach($popups as $p)
                    @if($p->banner_link)
                        <a href="{{ $p->banner_link }}" target="_blank" rel="noopener"><img src="{{ $p->bannerUrl() }}" alt="Pengumuman" class="w-full rounded-2xl shadow-2xl"></a>
                    @else
                        <img src="{{ $p->bannerUrl() }}" alt="Pengumuman" class="w-full rounded-2xl shadow-2xl">
                    @endif
                @endforeach
            </div>
        </div>
    </div>
@endif

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
    // Elemen ke-6 = tautan tujuan: tiap kartu bisa diklik menuju halaman yang
    // angkanya berasal dari sana. PO Pending -> daftar PO terfilter pending, dst.
    // Null = kartu diam (pengguna tak punya akses ke halamannya).
    $bln = $bulan->format('Y-m');
    // Mitra: "Penjualan"/"PO Masuk" sebenarnya BELANJA & PO milik dia sendiri (dia
    // pembeli). Staff (HQ): tetap penjualan HQ. Relabel biar tak rancu (Model A).
    // GD selalu beli ke HQ; distributor beli ke GD-nya (atau HQ fallback) → "ke HQ"
    // cuma pas buat GD, distri/mitra lain pakai "Belanja" generik.
    $isPartner = $user->isPartner();
    $labelBeli = ! $isPartner
        ? 'Penjualan'
        : ($user->role === \App\Models\User::ROLE_GRAND_DISTRIBUTOR ? 'Belanja ke HQ' : 'Belanja');
    // Urutan kartu uang di depan: Grand Total Omzet (setahun) → Penjualan (bulan)
    // → Omzet Distributor/PO (bulan). Grand Total & Distributor/PO staff-only.
    $cards = [];
    if ($user->isStaff() && ($yearlyOmzet ?? null)) {
        $gtColors = ['reseller' => '#059669', 'tiktok' => '#e11d48', 'shopee' => '#f97316'];
        $gtBreakdown = collect($yearlyOmzet['channels'])->map(fn ($c) => [
            'label' => $c['label'], 'value' => $c['total'], 'color' => $gtColors[$c['key']] ?? '#78716c',
        ])->all();
        $cards[] = ['Grand Total Omzet', 'Rp ' . number_format($yearlyOmzet['total'], 0, ',', '.'), 'emerald',
            $yearlyOmzet['year'] . ' · setahun', $gtBreakdown,
            $user->canDo('view_reports') ? route('reports.index', ['bulan' => $bln]) : null];
    }
    $cards[] = [$labelBeli, 'Rp ' . number_format($summary['total_sales'], 0, ',', '.'), $isPartner ? 'rose' : 'emerald', $per, $salesBreakdown,
        $user->canDo('view_reports') ? route('reports.index', ['bulan' => $bln]) : null];
    if ($user->isStaff() && ($yearlyOmzet ?? null)) {
        $poBucket = collect($channelSales ?? [])->firstWhere('key', 'reseller');
        $poReal = (float) ($poBucket['confirmed'] ?? 0);
        $poPipe = (float) ($poBucket['pipeline'] ?? 0);
        $odBreakdown = [
            ['label' => 'Sudah Masuk', 'value' => $poReal, 'color' => '#059669'],
            ['label' => 'Pending / Berjalan', 'value' => $poPipe, 'color' => '#d97706'],
        ];
        $cards[] = ['Omzet Distributor / PO', 'Rp ' . number_format($poReal + $poPipe, 0, ',', '.'), 'emerald', $per, $odBreakdown,
            $user->canDo('view_reports') ? route('reports.index', ['bulan' => $bln]) : null];
    }
    $cards[] = [$isPartner ? 'PO Saya' : 'PO Masuk', number_format($summary['total_po'], 0, ',', '.'), 'stone', $per, null,
        route('purchase-orders.index')];
    $cards[] = ['PO Pending', number_format($summary['pending_po'], 0, ',', '.'), 'amber', $per, null,
        route('purchase-orders.index', ['status' => 'pending'])];
    $cards[] = ['PO Selesai', number_format($summary['completed_po'], 0, ',', '.'), 'blue', $per, null,
        route('purchase-orders.index', ['status' => 'completed'])];
    if ($user->isStaff()) {
        $cards[] = ['Mitra Aktif', number_format($summary['total_partners'], 0, ',', '.'), 'purple', 'saat ini', null,
            $user->canDo('manage_users') ? route('users.index') : null];
        $cards[] = ['Produk Aktif', number_format($summary['total_products'], 0, ',', '.'), 'rose', 'saat ini', null,
            $user->canDo('manage_products') ? route('products.index') : null];
        $cards[] = ['Stok Pusat (unit)', number_format($summary['hq_stock_units'], 0, ',', '.'), 'cyan', 'saat ini', null,
            route('inventory.index')];
    } else {
        // Model A: mitra stockist (GD/Distri) jual ke downline — omzet ini beda dari
        // belanja dia ke HQ. Reseller/sponsor tak pegang stok → tak ada kartu ini.
        if (\App\Support\PartnerHierarchy::holdsStock($user->role)) {
            $cards[] = ['Penjualan ke Downline', 'Rp ' . number_format($summary['downline_sales'], 0, ',', '.'), 'emerald', $per, null,
                $user->canDo('process_downline_po') ? route('pesanan-downline.index') : null];
        }
        $cards[] = ['Stok Saya (unit)', number_format($summary['partner_stock_units'], 0, ',', '.'), 'cyan', 'saat ini', null,
            route('inventory.index')];
    }
@endphp

{{-- Perlu Tindakan: PO yang butuh tindakan (baru masuk / verifikasi bayar) +
     penarikan komisi menunggu. Panel tampil untuk siapa pun yang berwenang atas
     salah satunya; tiap seksi punya empty state — bukan hilang sama sekali. --}}
@php
    $canPoInbox = $user->canDo('update_po_status') || $user->canDo('process_downline_po');
    $poInbox = $actionablePos ?? collect();
@endphp
@if($user->canDo('process_withdrawal') || $canPoInbox)
<div class="bg-white rounded-2xl border border-amber-200 overflow-hidden mb-5">
    <button type="button" onclick="togglePerluTindakan()" class="w-full flex items-center gap-2 px-5 py-3 border-b border-amber-100 bg-amber-50 hover:bg-amber-100/70 transition text-left">
        <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
        <span class="text-sm font-bold text-amber-900">Perlu Tindakan</span>
        <svg id="perluChevron" class="w-4 h-4 text-amber-600 ml-auto shrink-0 transition-transform" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div id="perluTindakanBody" class="p-4 space-y-5">
        {{-- Pesanan (PO) perlu tindakan --}}
        @if($canPoInbox)
        <div>
            <div class="flex items-center gap-2 mb-3">
                <span class="text-sm font-semibold text-stone-800">Pesanan (PO) perlu tindakan</span>
                @if($poInbox->isNotEmpty())
                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ count($poInbox) }} menunggu</span>
                @endif
            </div>
            @if($poInbox->isNotEmpty())
                <div class="space-y-2">
                    @foreach($poInbox as $po)
                        <div class="flex items-center justify-between gap-3 px-3 py-2 border border-stone-100 rounded-lg">
                            <span class="text-sm text-stone-700 truncate">
                                <b class="text-stone-800">{{ $po->po_number }}</b><span class="text-stone-400"> · {{ $po->user->fullname ?? $po->user->name ?? 'Mitra #'.$po->user_id }} · {{ $po->created_at->diffForHumans() }}</span>
                            </span>
                            <span class="flex items-center gap-2 shrink-0">
                                @if($po->status === \App\Models\PurchaseOrder::STATUS_PENDING)
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">baru masuk</span>
                                @endif
                                @if($po->payment_status === \App\Models\PurchaseOrder::PAYMENT_AWAITING)
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">verifikasi bayar</span>
                                @endif
                                <a href="{{ route('purchase-orders.show', $po) }}" class="text-xs px-3 py-1 rounded-lg bg-red-600 text-white hover:bg-red-700 font-semibold">Tinjau</a>
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-stone-400 py-3 text-center">Tak ada PO menunggu tindakan — semua beres.</p>
            @endif
            <a href="{{ route('purchase-orders.index') }}" class="inline-block mt-3 text-xs text-indigo-600 hover:underline">Lihat semua Purchase Orders →</a>
        </div>
        @endif

        {{-- Penarikan komisi menunggu --}}
        @if($user->canDo('process_withdrawal'))
        <div @if($canPoInbox) class="pt-4 border-t border-stone-100" @endif>
            <div class="flex items-center gap-2 mb-3">
                <span class="text-sm font-semibold text-stone-800">Penarikan komisi menunggu</span>
                @if(($pendingWithdrawals ?? collect())->isNotEmpty())
                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ count($pendingWithdrawals) }} menunggu</span>
                @endif
            </div>
            @if(($pendingWithdrawals ?? collect())->isNotEmpty())
                <div class="space-y-2">
                    @foreach($pendingWithdrawals as $w)
                        <div class="flex items-center justify-between gap-3 px-3 py-2 border border-stone-100 rounded-lg">
                            <span class="text-sm text-stone-700">{{ $w->mitra->fullname ?? $w->mitra->name ?? 'Mitra #'.$w->user_id }}<span class="text-stone-400"> · {{ ($w->requested_at ?? $w->created_at)->diffForHumans() }}</span></span>
                            <span class="flex items-center gap-3 shrink-0">
                                <b class="text-sm text-stone-800">Rp {{ number_format((float) $w->amount, 0, ',', '.') }}</b>
                                <a href="{{ route('withdrawals.index') }}" class="text-xs px-3 py-1 rounded-lg bg-red-600 text-white hover:bg-red-700 font-semibold">Proses</a>
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-stone-400 py-4 text-center">Belum ada penarikan menunggu — semua sudah diproses.</p>
            @endif
            <a href="{{ route('withdrawals.index') }}" class="inline-block mt-3 text-xs text-indigo-600 hover:underline">Lihat semua di Penarikan →</a>
        </div>
        @endif
    </div>
</div>
<script>
(function () {
    var KEY = 'skinkuPerluTindakanCollapsed';
    var body = document.getElementById('perluTindakanBody');
    var chev = document.getElementById('perluChevron');
    function apply(collapsed) {
        if (! body) return;
        body.style.display = collapsed ? 'none' : '';
        if (chev) chev.style.transform = collapsed ? 'rotate(-90deg)' : '';
    }
    var saved = false;
    try { saved = localStorage.getItem(KEY) === '1'; } catch (e) {}
    apply(saved);
    window.togglePerluTindakan = function () {
        var collapsingNow = body.style.display !== 'none';
        apply(collapsingNow);
        try { localStorage.setItem(KEY, collapsingNow ? '1' : '0'); } catch (e) {}
    };
})();
</script>
@endif

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
    @foreach($cards as [$label, $value, $color, $note, $breakdown, $link])
        {{-- Kartu ber-tautan = <a> utuh (seluruh kartu bisa diklik), bukan cuma
             teks kecil tersembunyi. Hover memberi tanda bisa diklik. --}}
        <{{ $link ? 'a' : 'div' }} @if($link) href="{{ $link }}" @endif
            class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-col {{ $link ? 'hover:border-stone-400 hover:shadow-sm transition cursor-pointer' : '' }}">
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
        </{{ $link ? 'a' : 'div' }}>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-6">
    <div class="lg:col-span-2 bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Tren Penjualan — {{ $bulan->translatedFormat('F Y') }}</h3>
        <canvas id="salesTrendChart" height="110"></canvas>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Distribusi Status PO — {{ $bulan->translatedFormat('M Y') }}</h3>
        <div style="height:260px"><canvas id="poStatusChart"></canvas></div>
    </div>
</div>

<div id="channelSection" data-url="{{ route('dashboard.channel-sales') }}" class="transition-opacity">
@include('dashboard._channel-sales')
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
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } } }
    });

    // Channel charts — dibaca dari #channelData supaya bisa di-render ulang saat
    // filter tanggal via AJAX (tanpa reload). Instansi lama di-destroy dulu.
    let chChartC = null, chChartA = null;
    const chRupiah = v => 'Rp ' + Number(v).toLocaleString('id-ID');
    const chDoughnut = (el, labels, data, colors) => new Chart(el, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors }] },
        options: {
            maintainAspectRatio: false, cutout: '58%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: c => {
                    const total = c.dataset.data.reduce((a, b) => a + b, 0);
                    const porsi = total > 0 ? (c.raw / total * 100) : 0;
                    return chRupiah(c.raw) + ' · ' + porsi.toLocaleString('id-ID', { maximumFractionDigits: 1 }) + '%';
                } } },
            },
        },
    });
    window.renderChannelCharts = function () {
        const el = document.getElementById('channelData');
        if (!el) return;
        let channel;
        try { channel = JSON.parse(el.textContent); } catch (e) { return; }
        if (chChartC) { chChartC.destroy(); chChartC = null; }
        if (chChartA) { chChartA.destroy(); chChartA = null; }
        const elC = document.getElementById('channelChart-confirmed');
        if (elC) chChartC = chDoughnut(elC, channel.map(c => c.label), channel.map(c => c.confirmed), channel.map(c => c.color));
        const elA = document.getElementById('channelChart-all');
        if (elA) {
            const seg = [];
            channel.forEach(c => {
                if (c.confirmed > 0) seg.push([c.label + ' cair', c.confirmed, c.color]);
                if (c.pipeline > 0) seg.push([c.label + ' berjalan', c.pipeline, c.color_light]);
            });
            if (seg.length) chChartA = chDoughnut(elA, seg.map(s => s[0]), seg.map(s => s[1]), seg.map(s => s[2]));
        }
    };
    window.renderChannelCharts();

    // Filter tanggal per-channel via AJAX — update section di tempat, tanpa
    // reload/scroll. Delegasi dari #channelSection (stabil) supaya handler tetap
    // hidup setelah innerHTML-nya diganti.
    (function () {
        const section = document.getElementById('channelSection');
        if (!section) return;
        const fragUrl = section.getAttribute('data-url');
        function load(fullHref) {
            const qs = new URL(fullHref, location.origin).search;
            section.style.opacity = '.45'; section.style.pointerEvents = 'none';
            fetch(fragUrl + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.ok ? r.text() : Promise.reject(r))
                .then(html => {
                    section.innerHTML = html;
                    window.renderChannelCharts();
                    history.replaceState(null, '', fullHref);
                })
                .catch(() => { window.location.href = fullHref; })
                .finally(() => { section.style.opacity = ''; section.style.pointerEvents = ''; });
        }
        section.addEventListener('click', function (e) {
            const a = e.target.closest('a.ch-preset');
            if (a) { e.preventDefault(); load(a.href); }
        });
        section.addEventListener('submit', function (e) {
            const f = e.target.closest('form.ch-form');
            if (f) {
                e.preventDefault();
                load('{{ route('dashboard') }}?' + new URLSearchParams(new FormData(f)).toString());
            }
        });
    })();
</script>
@endunless
@endpush
