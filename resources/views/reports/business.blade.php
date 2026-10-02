@extends('layouts.app')
@section('title', 'Generate Report')
@section('heading', 'Generate Report')

@push('head')
<style>
    @page { size: A4; margin: 12mm; }
    @media print {
        .portal-sidebar, .portal-main > header, .no-print, #sidebarBackdrop, .skip-link { display: none !important; }
        .portal-main { margin-left: 0 !important; }
        body { background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .rpt-card { break-inside: avoid; box-shadow: none !important; }
        .rpt-break { break-before: page; }
        main, .portal-content { padding: 0 !important; }
    }
</style>
@endpush

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    // Delta vs periode lalu. $inverse = turun itu bagus (cancel rate, biaya).
    $delta = function ($a, $b, bool $inverse = false, string $suffix = '%') {
        if (abs((float) $b) < 0.005) {
            return '<span class="text-stone-400">—</span>';
        }
        $pct = ($a - $b) / abs($b) * 100;
        $good = $inverse ? $pct < 0 : $pct > 0;
        $cls = abs($pct) < 0.05 ? 'text-stone-400' : ($good ? 'text-emerald-600' : 'text-rose-600');

        return '<span class="'.$cls.'">'.($pct > 0 ? '▲' : ($pct < 0 ? '▼' : '')).' '.number_format(abs($pct), 1, ',', '.').$suffix.'</span>';
    };
    $q = request()->only(['jenis', 'acuan', 'dari', 'sampai']);
    $today = now();
@endphp

{{-- Form pilih periode --}}
<form method="GET" action="{{ route('reports.business') }}" id="rptForm" class="no-print bg-white rounded-2xl border border-stone-200 p-4 mb-5 flex flex-wrap items-end gap-3 text-sm">
    <div>
        <label class="block text-xs font-semibold mb-1">Jenis laporan</label>
        <select name="jenis" id="rptJenis" class="px-3 py-2 border border-stone-300 rounded-lg">
            @foreach($jenisList as $k => $v)<option value="{{ $k }}" @selected($jenis === $k)>{{ $v }}</option>@endforeach
        </select>
    </div>
    <div data-jenis="mingguan">
        <label class="block text-xs font-semibold mb-1">Tanggal dalam minggu</label>
        <input type="date" name="acuan" value="{{ $jenis === 'mingguan' ? request('acuan') : $today->toDateString() }}" max="{{ $today->toDateString() }}" class="px-3 py-2 border border-stone-300 rounded-lg">
    </div>
    <div data-jenis="bulanan">
        <label class="block text-xs font-semibold mb-1">Bulan</label>
        <input type="month" name="acuan" value="{{ $jenis === 'bulanan' && request('acuan') ? request('acuan') : $today->format('Y-m') }}" max="{{ $today->format('Y-m') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
    </div>
    <div data-jenis="kuartal">
        <label class="block text-xs font-semibold mb-1">Kuartal</label>
        <select name="acuan" class="px-3 py-2 border border-stone-300 rounded-lg">
            @for($y = $today->year; $y >= $today->year - 2; $y--)
                @for($k = 4; $k >= 1; $k--)
                    @continue($y === $today->year && $k > $today->quarter)
                    @php $val = $y.'-Q'.$k; @endphp
                    <option value="{{ $val }}" @selected(request('acuan') === $val || (! request('acuan') && $y === $today->year && $k === $today->quarter))>Q{{ $k }} {{ $y }}</option>
                @endfor
            @endfor
        </select>
    </div>
    <div data-jenis="tahunan">
        <label class="block text-xs font-semibold mb-1">Tahun</label>
        <select name="acuan" class="px-3 py-2 border border-stone-300 rounded-lg">
            @for($y = $today->year; $y >= $today->year - 3; $y--)<option value="{{ $y }}" @selected((string) request('acuan') === (string) $y)>{{ $y }}</option>@endfor
        </select>
    </div>
    <div data-jenis="custom" class="flex items-end gap-2">
        <div>
            <label class="block text-xs font-semibold mb-1">Dari</label>
            <input type="date" name="dari" value="{{ request('dari', $today->copy()->subDays(29)->toDateString()) }}" max="{{ $today->toDateString() }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Sampai</label>
            <input type="date" name="sampai" value="{{ request('sampai', $today->toDateString()) }}" max="{{ $today->toDateString() }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        </div>
    </div>
    <button class="px-4 py-2 bg-red-600 text-white rounded-lg font-semibold hover:bg-red-700">Generate →</button>
    @if($report)
        <div class="ml-auto flex gap-2">
            <button type="button" onclick="window.print()" class="px-4 py-2 bg-stone-800 text-white rounded-lg hover:bg-stone-900">Download PDF</button>
            <a href="{{ route('reports.business.excel', $q) }}" class="px-4 py-2 bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">Download Excel</a>
        </div>
    @endif
</form>

@if(! $report)
    <div class="bg-white rounded-2xl border border-dashed border-stone-300 p-10 text-center text-sm text-stone-500">
        Pilih jenis laporan &amp; periode, lalu klik <b>Generate</b>. Laporan berisi penjualan per channel, produk terlaris, mitra &amp; PO,
        stok, KOL/affiliate, dan keuangan — lengkap dengan grafik dan <b>analisis AI</b> untuk menaikkan omzet.
    </div>
@else
@php
    $p = $report['period'];
    $pj = $report['penjualan'];
    $now = $pj['now'];
    $prev = $pj['prev'];
    $cmp = $p['compare'];   // false = Semua Periode (tanpa pembanding)
@endphp
<div id="laporan" class="space-y-5">
    {{-- Kop laporan --}}
    <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xl font-extrabold tracking-tight text-red-700">SKINKU.</p>
            <h2 class="text-lg font-bold text-stone-900 mt-1">Laporan Bisnis {{ $jenisList[$p['jenis']] }} — {{ $p['label'] }}</h2>
            <p class="text-xs text-stone-500">Periode {{ $p['from']->translatedFormat('d M Y') }} – {{ $p['to']->translatedFormat('d M Y') }} @if($cmp) · dibanding {{ $p['prevLabel'] }} @else · seluruh data sejak transaksi pertama @endif</p>
        </div>
        <p class="text-[11px] text-stone-400 text-right">Dibuat {{ $report['generated_at']->translatedFormat('d M Y H:i') }}<br>oleh {{ auth()->user()->fullname ?? auth()->user()->name }}</p>
    </div>

    {{-- KPI utama --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 print:grid-cols-5 gap-3">
        @foreach([
            ['Omzet (selesai + berjalan)', $rp($now['omzet']), $delta($now['omzet'], $prev['omzet']), $rp($prev['omzet'])],
            ['Terealisasi', $rp($now['terealisasi']), $delta($now['terealisasi'], $prev['terealisasi']), $rp($prev['terealisasi'])],
            ['Jumlah order', $num($now['orders']), $delta($now['orders'], $prev['orders']), $num($prev['orders'])],
            ['Rata-rata per order (AOV)', $rp($now['aov']), $delta($now['aov'], $prev['aov']), $rp($prev['aov'])],
            ['Cancel rate', number_format($now['cancel_rate'], 1, ',', '.').'%', $delta($now['cancel_rate'], $prev['cancel_rate'], true), number_format($prev['cancel_rate'], 1, ',', '.').'%'],
        ] as [$lbl, $val, $d, $pv])
            <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">{{ $lbl }}</p>
                <p class="text-lg font-bold text-stone-900 mt-1">{{ $val }}</p>
                @if($cmp)<p class="text-[11px] mt-0.5">{!! $d !!} <span class="text-stone-400">vs {{ $pv }}</span></p>@endif
            </div>
        @endforeach
    </div>

    {{-- Analisis AI --}}
    <div class="rpt-card bg-gradient-to-br from-red-50 to-amber-50 rounded-2xl border border-red-200 p-5" id="aiBox"
         data-url="{{ route('reports.business.ai') }}">
        <div class="flex items-center justify-between gap-2 mb-2">
            <h3 class="text-sm font-bold text-red-800">Analisis &amp; Rekomendasi AI</h3>
            <button type="button" id="aiRetry" class="no-print hidden text-xs px-3 py-1 rounded-lg border border-red-300 text-red-700 hover:bg-white">Coba lagi</button>
        </div>
        <div id="aiBody" class="text-sm text-stone-700">
            <p class="text-stone-500 animate-pulse">AI sedang membaca data laporan… (± 10–30 detik)</p>
        </div>
        <p class="text-[10px] text-stone-400 mt-3">Dibuat otomatis oleh AI dari angka di laporan ini — cek ulang sebelum dijadikan keputusan.</p>
    </div>

    {{-- Penjualan per channel --}}
    <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-4">Penjualan per Channel</h3>
        <div class="grid lg:grid-cols-3 print:grid-cols-3 gap-5 items-center">
            @if($now['omzet'] > 0)<div style="height:220px"><canvas id="chChannel"></canvas></div>@else<p class="text-xs text-stone-400 text-center">Belum ada penjualan di periode ini.</p>@endif
            <div class="lg:col-span-2 print:col-span-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-stone-500"><tr>
                        <th class="text-left px-3 py-2">Channel</th><th class="text-right">Omzet</th>@if($cmp)<th class="text-right">Sebelumnya</th><th class="text-right">Δ</th>@endif<th class="text-right">Order</th><th class="text-right pr-3">Cancel</th>
                    </tr></thead>
                    <tbody>
                    @foreach($pj['channels'] as $c)
                        <tr class="border-t border-stone-100">
                            <td class="px-3 py-2"><span class="inline-block w-2.5 h-2.5 rounded-full mr-1.5" style="background: {{ $c['color'] }}"></span>{{ $c['label'] }}</td>
                            <td class="text-right font-semibold">{{ $rp($c['omzet']) }}</td>
                            @if($cmp)
                            <td class="text-right text-stone-500">{{ $rp($c['prev']) }}</td>
                            <td class="text-right text-xs">{!! $delta($c['omzet'], $c['prev']) !!}</td>
                            @endif
                            <td class="text-right">{{ $num($c['orders']) }}</td>
                            <td class="text-right pr-3 {{ $c['cancel_rate'] >= 10 ? 'text-rose-600 font-semibold' : '' }}">{{ number_format($c['cancel_rate'], 1, ',', '.') }}%</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <h4 class="text-xs font-semibold text-stone-500 mt-5 mb-2">Tren penjualan terealisasi {{ count($pj['trend']['labels']) > 0 && strlen($pj['trend']['labels'][0]) === 7 ? '(per bulan)' : '(per hari)' }}</h4>
        <div style="height:240px"><canvas id="chTrend"></canvas></div>
    </div>

    {{-- Produk terlaris --}}
    <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-1">Produk Terlaris</h3>
        <p class="text-[11px] text-stone-400 mb-4">Unit terjual (order berbayar). Bundle dihitung per isi. @if($cmp) Abu-abu = {{ $report['produk']['prev_label'] }}. @endif</p>
        @if($report['produk']['channels']['semua']['rows'])<div style="height:{{ max(160, count($report['produk']['channels']['semua']['rows']) * 26) }}px" class="mb-5"><canvas id="chProduk"></canvas></div>@endif
        <div class="grid sm:grid-cols-3 print:grid-cols-3 gap-4">
            @foreach(['reseller' => 'Reseller / PO', 'tiktok' => 'TikTok', 'shopee' => 'Shopee'] as $k => $lbl)
                @php $ch = $report['produk']['channels'][$k]; @endphp
                <div class="rounded-xl border border-stone-200 p-3">
                    <p class="text-xs font-bold text-stone-700 mb-2">{{ $lbl }} <span class="font-normal text-stone-400">· {{ $num($ch['total']) }} unit</span></p>
                    @forelse(array_slice($ch['rows'], 0, 5) as $i => $x)
                        <div class="flex justify-between gap-2 text-xs py-1 {{ $i ? 'border-t border-stone-100' : '' }}">
                            <span class="text-stone-700">{{ $i + 1 }}. {{ $x['label'] }}</span>
                            <span class="tabular-nums font-semibold shrink-0">{{ $num($x['qty']) }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-stone-400">Belum ada penjualan.</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>

    {{-- Mitra & PO --}}
    @php $m = $report['mitra']; @endphp
    <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-4">Mitra &amp; PO</h3>
        <div class="grid grid-cols-2 lg:grid-cols-4 print:grid-cols-4 gap-3 mb-5">
            @foreach([['Mitra order periode ini', $m['mitra_order'].' / '.$m['mitra_total']], ['Mitra baru', $m['mitra_baru']], ['Mitra tidak order', $m['diam_n']], ['Retur PO disetujui', $m['retur']]] as [$l, $v])
                <div class="rounded-xl bg-stone-50 border border-stone-200 p-3">
                    <p class="text-[10px] uppercase text-stone-400 font-semibold">{{ $l }}</p>
                    <p class="text-lg font-bold text-stone-900">{{ $v }}</p>
                </div>
            @endforeach
        </div>
        <div class="grid lg:grid-cols-2 print:grid-cols-2 gap-5">
            <div>
                <p class="text-xs font-semibold text-stone-500 mb-2">Top mitra (omzet PO)</p>
                <table class="w-full text-xs">
                    <tbody>
                    @forelse($m['top'] as $i => $t)
                        <tr class="border-t border-stone-100"><td class="py-1.5">{{ $i + 1 }}. {{ $t['nama'] }}</td><td class="text-right text-stone-500">{{ $t['po'] }} PO</td><td class="text-right font-semibold">{{ $rp($t['omzet']) }}</td></tr>
                    @empty
                        <tr><td class="py-3 text-stone-400">Belum ada PO di periode ini.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                <p class="text-xs font-semibold text-stone-500 mb-2">Mitra tidak order (paling lama dulu) — kandidat follow-up</p>
                <table class="w-full text-xs">
                    <tbody>
                    @forelse($m['diam'] as $d)
                        <tr class="border-t border-stone-100"><td class="py-1.5">{{ $d['nama'] }}</td><td class="text-stone-500">{{ str_replace('_', ' ', $d['role']) }}</td><td class="text-right">terakhir {{ \Illuminate\Support\Carbon::parse($d['terakhir'])->translatedFormat('d M Y') }}</td></tr>
                    @empty
                        <tr><td class="py-3 text-stone-400">Semua mitra aktif sudah order. 👍</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Stok --}}
    @php $s = $report['stok']; @endphp
    <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-1">Stok HQ vs Laju Penjualan</h3>
        <p class="text-[11px] text-stone-400 mb-4">"Cukup" = stok HQ ÷ rata-rata unit terjual per hari di periode ini.</p>
        <div class="grid lg:grid-cols-2 print:grid-cols-2 gap-5">
            <div>
                <p class="text-xs font-semibold text-rose-600 mb-2">Menipis (&lt; 21 hari)</p>
                @forelse($s['menipis'] as $x)
                    <div class="flex justify-between text-xs py-1.5 border-t border-stone-100"><span>{{ $x['nama'] }}</span><span><b>{{ $x['stok'] > 0 ? $x['hari_cukup'].' hari' : 'HABIS' }}</b> <span class="text-stone-400">· stok {{ $num($x['stok']) }}</span></span></div>
                @empty
                    <p class="text-xs text-stone-400">Tidak ada produk yang menipis.</p>
                @endforelse
            </div>
            <div>
                <p class="text-xs font-semibold text-amber-600 mb-2">Menumpuk (&gt; 120 hari / tidak terjual)</p>
                @forelse($s['menumpuk'] as $x)
                    <div class="flex justify-between text-xs py-1.5 border-t border-stone-100"><span>{{ $x['nama'] }}</span><span><b>{{ $num($x['stok']) }} unit</b> <span class="text-stone-400">· {{ $x['hari_cukup'] ? $x['hari_cukup'].' hari' : 'tidak terjual' }}</span></span></div>
                @empty
                    <p class="text-xs text-stone-400">Tidak ada stok menumpuk.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- KOL / Affiliate --}}
    @if($k = $report['kol'])
        <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-4">KOL / Affiliate (TikTok)</h3>
            <div class="grid grid-cols-2 lg:grid-cols-5 print:grid-cols-5 gap-3 mb-5">
                @foreach([
                    ['GMV affiliate', $rp($k['gmv']), $cmp ? $delta($k['gmv'], $k['gmv_prev']) : ''],
                    ['Order affiliate', $num($k['orders']), ''],
                    ['Kreator menghasilkan', $num($k['kreator_aktif']), ''],
                    ['Komisi', $rp($k['komisi']), ''],
                    ['Deal KOL baru · biaya', $k['deal_n'].' · '.$rp($k['deal_biaya']), ''],
                ] as [$l, $v, $d])
                    <div class="rounded-xl bg-stone-50 border border-stone-200 p-3">
                        <p class="text-[10px] uppercase text-stone-400 font-semibold">{{ $l }}</p>
                        <p class="text-base font-bold text-stone-900">{{ $v }}</p>
                        @if($d)<p class="text-[11px]">{!! $d !!}</p>@endif
                    </div>
                @endforeach
            </div>
            @if($k['top'])<p class="text-xs font-semibold text-stone-500 mb-2">Top kreator (GMV)</p><div style="height:{{ max(140, count($k['top']) * 26) }}px"><canvas id="chKol"></canvas></div>@else<p class="text-xs text-stone-400">Belum ada GMV affiliate di periode ini.</p>@endif
        </div>
    @endif

    {{-- Keuangan --}}
    @if($f = $report['keuangan'])
        <div class="rpt-card bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-1">Keuangan — Laba Rugi</h3>
            <p class="text-[11px] text-stone-400 mb-4">Akuntansi dicatat per bulan → memakai {{ $f['bulan'] }}.</p>
            <div class="grid lg:grid-cols-2 print:grid-cols-2 gap-5">
                <table class="w-full text-sm">
                    <tbody>
                    @foreach([['Penjualan bersih', 'penjualan_bersih', false], ['HPP', 'hpp', false], ['Laba kotor', 'laba_kotor', true], ['Beban operasional', 'beban_operasional', false], ['Laba operasional', 'operating_income', true], ['Laba bersih', 'net_income', true]] as [$l, $key, $bold])
                        <tr class="border-t border-stone-100 {{ $bold ? 'font-bold' : '' }}"><td class="py-1.5">{{ $l }}</td><td class="text-right {{ $f['is'][$key] < 0 ? 'text-rose-600' : '' }}">{{ $rp($f['is'][$key]) }}</td></tr>
                    @endforeach
                    <tr class="border-t border-stone-100 text-xs text-stone-500"><td class="py-1.5">Margin kotor · margin bersih</td><td class="text-right">{{ $f['margin_kotor'] ?? '—' }}% · {{ $f['margin_bersih'] ?? '—' }}%</td></tr>
                    </tbody>
                </table>
                <div>
                    <p class="text-xs font-semibold text-stone-500 mb-2">Beban operasional terbesar</p>
                    @if($f['beban_top'])<div style="height:200px"><canvas id="chBeban"></canvas></div>@else<p class="text-xs text-stone-400">Belum ada jurnal beban di periode ini.</p>@endif
                </div>
            </div>
        </div>
    @endif

    <p class="text-[10px] text-stone-400 text-center">SKINKU B2B Portal · Laporan dibuat otomatis dari data sistem. Angka marketplace = order berbayar (selesai + berjalan), berdasarkan tanggal order masuk.</p>
</div>
@endif
@endsection

@push('scripts')
<script>
(function () {
    // Tampilkan input acuan sesuai jenis; input yang tersembunyi di-disable supaya tak ikut terkirim.
    const sel = document.getElementById('rptJenis');
    function sync() {
        document.querySelectorAll('#rptForm [data-jenis]').forEach(el => {
            const on = el.dataset.jenis === sel.value;
            el.classList.toggle('hidden', ! on);
            el.querySelectorAll('input,select').forEach(i => i.disabled = ! on);
        });
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
@if($report)
@php
    $r = $report;
    $semua = $r['produk']['channels']['semua']['rows'];
    $chartData = [
        'channel' => array_map(fn ($c) => [$c['label'], $c['omzet'], $c['color']], $r['penjualan']['channels']),
        'trend' => $r['penjualan']['trend'],
        'produk' => ['labels' => array_column($semua, 'label'), 'now' => array_column($semua, 'qty'), 'prev' => $r['period']['compare'] ? array_column($semua, 'prev') : null],
        'kol' => $r['kol'] ? ['labels' => array_column($r['kol']['top'], 'nama'), 'data' => array_column($r['kol']['top'], 'gmv')] : null,
        'beban' => $r['keuangan'] ? ['labels' => array_keys($r['keuangan']['beban_top']), 'data' => array_values($r['keuangan']['beban_top'])] : null,
    ];
    $aiParams = request()->only(['jenis', 'acuan', 'dari', 'sampai']);
@endphp
<script>
(function () {
    const D = @json($chartData);
    const rp = v => 'Rp ' + Math.round(v).toLocaleString('id-ID');
    if (window.Chart) {
        Chart.defaults.animation = false;           // grafik langsung jadi → aman untuk print/PDF
        Chart.defaults.font.size = 11;
        Chart.defaults.maintainAspectRatio = false;
        const mk = (id, cfg) => { const el = document.getElementById(id); if (el) new Chart(el, cfg); };
        mk('chChannel', { type: 'doughnut', data: { labels: D.channel.map(c => c[0]), datasets: [{ data: D.channel.map(c => c[1]), backgroundColor: D.channel.map(c => c[2]) }] },
            options: { plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => c.label + ': ' + rp(c.raw) } } } } });
        mk('chTrend', { type: 'line', data: { labels: D.trend.labels, datasets: D.trend.channels.map(c => ({ label: c.label, data: c.data, borderColor: c.color, backgroundColor: c.color, tension: .3, pointRadius: 0, borderWidth: 2 })) },
            options: { interaction: { mode: 'index', intersect: false }, scales: { y: { ticks: { callback: v => rp(v) } } }, plugins: { tooltip: { callbacks: { label: c => c.dataset.label + ': ' + rp(c.raw) } } } } });
        mk('chProduk', { type: 'bar', data: { labels: D.produk.labels, datasets: [
                { label: 'Periode ini', data: D.produk.now, backgroundColor: '#b4232f' }].concat(D.produk.prev ? [
                { label: 'Sebelumnya', data: D.produk.prev, backgroundColor: '#d6d3d1' }] : []) },
            options: { indexAxis: 'y', plugins: { legend: { position: 'bottom' } } } });
        if (D.kol) mk('chKol', { type: 'bar', data: { labels: D.kol.labels, datasets: [{ label: 'GMV', data: D.kol.data, backgroundColor: '#ef4444' }] },
            options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => rp(c.raw) } } }, scales: { x: { ticks: { callback: v => rp(v) } } } } });
        if (D.beban) mk('chBeban', { type: 'bar', data: { labels: D.beban.labels, datasets: [{ data: D.beban.data, backgroundColor: '#f59e0b' }] },
            options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => rp(c.raw) } } }, scales: { x: { ticks: { callback: v => rp(v) } } } } });
    }

    // Analisis AI — dimuat terpisah (AJAX) supaya laporan tampil duluan.
    const box = document.getElementById('aiBox'), body = document.getElementById('aiBody'), retry = document.getElementById('aiRetry');
    const esc = s => String(s ?? '').replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
    const list = (title, items, cls) => items && items.length
        ? '<div><p class="text-xs font-bold ' + cls + ' mb-1">' + title + '</p><ul class="list-disc pl-5 space-y-1">' + items.map(i => '<li>' + esc(i) + '</li>').join('') + '</ul></div>' : '';
    const badge = d => ({ tinggi: 'bg-red-600 text-white', sedang: 'bg-amber-500 text-white', rendah: 'bg-stone-300 text-stone-700' }[d] || 'bg-stone-300 text-stone-700');
    function render(a) {
        body.innerHTML = '<p class="font-medium text-stone-800 mb-3">' + esc(a.ringkasan) + '</p>'
            + '<div class="grid md:grid-cols-2 print:grid-cols-2 gap-4 mb-4">' + list('Sorotan positif', a.sorotan, 'text-emerald-700') + list('Perlu diperhatikan', a.perhatian, 'text-rose-700') + '</div>'
            + (a.aksi && a.aksi.length ? '<p class="text-xs font-bold text-stone-800 mb-2">Rekomendasi aksi untuk naikkan omzet</p><ol class="space-y-2">'
                + a.aksi.map((x, i) => '<li class="bg-white/70 rounded-lg border border-red-100 p-3"><div class="flex items-center gap-2"><span class="font-semibold">' + (i + 1) + '. ' + esc(x.judul)
                    + '</span><span class="text-[10px] px-1.5 py-0.5 rounded ' + badge(x.dampak) + '">dampak ' + esc(x.dampak) + '</span></div><p class="text-xs text-stone-600 mt-1">' + esc(x.detail) + '</p></li>').join('') + '</ol>' : '');
    }
    function load() {
        retry.classList.add('hidden');
        body.innerHTML = '<p class="text-stone-500 animate-pulse">AI sedang membaca data laporan… (± 10–30 detik)</p>';
        fetch(box.dataset.url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(@json($aiParams)) })
            .then(r => r.json())
            .then(res => { if (res.ok) render(res.data); else { body.innerHTML = '<p class="text-rose-700">' + esc(res.error) + '</p>'; retry.classList.remove('hidden'); } })
            .catch(() => { body.innerHTML = '<p class="text-rose-700">Gagal menghubungi AI.</p>'; retry.classList.remove('hidden'); });
    }
    retry.addEventListener('click', load);
    load();
})();
</script>
@endif
@endpush
