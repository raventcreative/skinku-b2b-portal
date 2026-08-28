@extends('layouts.app')
@section('title', 'Dashboard KOL')
@section('heading', 'Dashboard KOL')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); $rc = fn ($n) => $n >= 1_000_000 ? round($n / 1_000_000, 1).' jt' : number_format($n, 0, ',', '.'); @endphp

<div class="space-y-4">

    <div class="flex items-center justify-between gap-2 flex-wrap">
        <p class="text-sm text-stone-500">Ringkasan pipeline, views, budget &amp; affiliate.</p>
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('kol-dashboard.index', ['bulan' => $prevMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">←</a>
            <span class="font-semibold text-stone-700">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }}{{ $isCurrent ? '' : ' (arsip)' }}</span>
            <a href="{{ route('kol-dashboard.index', ['bulan' => $nextMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">→</a>
        </div>
    </div>

    @if($isEmpty)
        <div class="bg-white rounded-2xl border border-dashed border-stone-300 p-6">
            <p class="text-sm font-bold text-stone-800 mb-3">Mulai dari sini 🚀</p>
            <ol class="space-y-2 text-sm text-stone-600">
                <li><b>1.</b> Tambahkan KOL di <a href="{{ route('kols.index') }}" class="text-indigo-600 hover:underline">Database KOL</a> lalu isi screening.</li>
                <li><b>2.</b> Scout &amp; nego lewat <a href="{{ route('kol-pipeline.index') }}" class="text-indigo-600 hover:underline">Pipeline</a>, lalu buat <a href="{{ route('kol-deals.index') }}" class="text-indigo-600 hover:underline">Deal</a>.</li>
                <li><b>3.</b> Catat <a href="{{ route('kol-konten.index') }}" class="text-indigo-600 hover:underline">Konten &amp; views</a> dan import <a href="{{ route('kol-affiliate.index') }}" class="text-indigo-600 hover:underline">Affiliate GMV</a> — dashboard terisi otomatis.</li>
            </ol>
        </div>
    @endif

    {{-- Peringatan budget (finance): CPM paid di atas anchor / 1 KOL menyerap terlalu besar.
         Datanya dari KolBudgetService::summary — sebelumnya dihitung tapi tak pernah tampil di sini. --}}
    @if($budget && ($budget['overAnchor'] || $budget['overConcentration']))
        <div class="bg-rose-50 border border-rose-200 rounded-2xl px-4 py-3 space-y-1.5">
            <p class="text-xs font-bold uppercase tracking-wide text-rose-700">⚠ Peringatan budget</p>
            @if($budget['overAnchor'])
                <p class="text-sm text-rose-800">Blended CPM paid <b>{{ $rp($budget['cpm']) }}</b> di atas anchor {{ $rp($budget['anchor']) }} — biaya per 1.000 views kemahalan.</p>
            @endif
            @if($budget['overConcentration'])
                <p class="text-sm text-rose-800">1 KOL menyerap <b>{{ $budget['topSharePct'] }}%</b> budget bulan ini — risiko terlalu bergantung ke satu creator.</p>
            @endif
            <a href="{{ route('kol-deals.index') }}" class="inline-block text-xs font-semibold text-rose-700 hover:underline">Kelola deal & budget →</a>
        </div>
    @endif

    {{-- ROAS + ROI margin-aware (finance) --}}
    @if($budget)
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">ROAS</p>
                <p class="text-xl font-bold text-stone-800">{{ $roas !== null ? number_format($roas, 2, ',', '.').'×' : '—' }}</p>
                <p class="text-[10px] text-stone-400">GMV affiliate ÷ biaya deal</p>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">ROI (margin {{ round($margin * 100) }}%)</p>
                <p class="text-xl font-bold {{ $roi === null ? 'text-stone-400' : ($roi >= 0 ? 'text-emerald-600' : 'text-rose-600') }}">{{ $roi !== null ? round($roi * 100).'%' : '—' }}</p>
                <p class="text-[10px] text-stone-400">(laba kotor − biaya) ÷ biaya · setel margin di Settings</p>
            </div>
        </div>
    @endif

    {{-- Baris stat utama --}}
    <div class="grid sm:grid-cols-2 {{ $budget ? 'lg:grid-cols-5' : 'lg:grid-cols-4' }} gap-3">
        {{-- Views vs target --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <div class="flex items-center justify-between">
                <p class="text-xs text-stone-500">Views bulan ini</p>
                <span class="text-[10px] px-2 py-0.5 rounded-full {{ $viewsAman ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $viewsAman ? 'Aman' : 'Berisiko' }}</span>
            </div>
            <p class="text-2xl font-bold text-stone-800">{{ number_format($totalViews, 0, ',', '.') }}</p>
            <p class="text-[11px] text-stone-400">{{ $target > 0 ? round($totalViews / $target * 100) : 0 }}% dari {{ $rc($target) }} · proyeksi {{ $rc($proj) }}</p>
            <div class="h-1.5 bg-stone-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-red-500" style="width: {{ $target > 0 ? min(100, round($totalViews / $target * 100)) : 0 }}%"></div></div>
        </div>

        {{-- Pipeline --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Pipeline aktif</p>
            <p class="text-2xl font-bold text-stone-800">{{ $pipeline['active'] }}</p>
            <p class="text-[11px] {{ $pipeline['terlambat'] ? 'text-rose-500' : 'text-stone-400' }}">{{ $pipeline['terlambat'] }} terlambat · {{ $pipeline['hariIni'] }} hari ini · {{ $pipeline['tanpaAksi'] }} tanpa aksi</p>
        </div>

        {{-- GMV --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">GMV affiliate</p>
            @if($aff)
                <p class="text-2xl font-bold text-stone-800">{{ $rp($aff['gmv']) }}</p>
                <p class="text-[11px] text-stone-400">{{ number_format($aff['orders'], 0, ',', '.') }} order · {{ $aff['affiliates'] }} affiliate</p>
            @else
                <p class="text-sm text-stone-400 mt-2">🔒 butuh izin Affiliate</p>
            @endif
        </div>

        {{-- Budget / Konten --}}
        @if($budget)
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">Sisa budget</p>
                <p class="text-2xl font-bold {{ $budget['sisa'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $rp($budget['sisa']) }}</p>
                <p class="text-[11px] text-stone-400">spent {{ $rc($budget['spent']) }} · committed {{ $rc($budget['committed']) }}</p>
            </div>
        @else
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">Konten bulan ini</p>
                <p class="text-2xl font-bold text-stone-800">{{ $contentCount }}</p>
                <p class="text-[11px] text-stone-400">paid {{ $rc($paidViews) }} · earned {{ $rc($earnedViews) }} views</p>
            </div>
        @endif

        {{-- CPM paid (blended) vs anchor — finance. Hijau bila ≤ anchor, merah bila di atas. --}}
        @if($budget)
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">CPM paid (blended)</p>
                <p class="text-2xl font-bold {{ $budget['cpm'] === null ? 'text-stone-800' : ($budget['overAnchor'] ? 'text-rose-600' : 'text-emerald-600') }}">{{ $budget['cpm'] !== null ? $rp($budget['cpm']) : '—' }}</p>
                <p class="text-[11px] text-stone-400">{{ $budget['cpm'] !== null ? 'anchor '.$rp($budget['anchor']) : 'belum ada views paid' }}</p>
            </div>
        @endif
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        {{-- Top affiliate + APS --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-semibold text-stone-700">Top affiliate (GMV)</p>
                @if($aff)<a href="{{ route('kol-affiliate.index') }}" class="text-xs text-indigo-600 hover:underline">Semua →</a>@endif
            </div>
            @if($aff && $aff['top']->isNotEmpty())
                <div class="divide-y divide-stone-100">
                    @foreach($aff['top'] as $t)
                        <div class="flex items-center justify-between py-2">
                            <a href="{{ route('kols.show', $t['kol']->id) }}" class="text-sm text-indigo-600 hover:underline">{{ '@'.$t['kol']->tiktok_username }}</a>
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-stone-700">{{ $rp($t['gmv']) }}</span>
                                @if($t['aps']['status'] === 'scored')
                                    @php $tone = ['bina_intensif' => 'bg-emerald-100 text-emerald-700', 'pantau' => 'bg-amber-100 text-amber-700', 'nurture' => 'bg-stone-100 text-stone-500'][$t['aps']['label']]; @endphp
                                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $tone }}" title="APS {{ $apsLabels[$t['aps']['label']] }}{{ $t['aps']['capped'] ? ' · cap 40' : '' }}">APS {{ rtrim(rtrim(number_format($t['aps']['score'], 1, ',', '.'), '0'), ',') }}{{ $t['aps']['capped'] ? ' ⚑' : '' }}</span>
                                @else
                                    <span class="text-[10px] text-stone-300">new</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-stone-400 py-6 text-center">{{ $aff ? 'Belum ada data affiliate — import dulu.' : '🔒 butuh izin Affiliate' }}</p>
            @endif
        </div>

        {{-- Top konten --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-semibold text-stone-700">Top konten (views)</p>
                <a href="{{ route('kol-konten.index') }}" class="text-xs text-indigo-600 hover:underline">Semua →</a>
            </div>
            @if($topContent->isNotEmpty())
                <div class="divide-y divide-stone-100">
                    @foreach($topContent as $c)
                        <div class="flex items-center justify-between py-2 gap-3">
                            <div class="min-w-0">
                                <a href="{{ $c->url }}" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-600 hover:underline truncate block">{{ $c->title ?: $c->url }}</a>
                                <span class="text-[10px] text-stone-400">{{ '@'.$c->kol->tiktok_username }} · {{ $c->label }}</span>
                            </div>
                            <span class="text-sm text-stone-700 shrink-0">{{ $rc((int) ($c->latestSnapshot->views ?? 0)) }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-stone-400 py-6 text-center">Belum ada konten bulan ini.</p>
            @endif
        </div>
    </div>

    {{-- Grafik --}}
    <div class="grid lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-semibold text-stone-700">Views kumulatif (konten tayang)</p>
                {{-- Legend HTML (bukan di canvas) biar teks tajam. --}}
                <div class="flex items-center gap-3 text-[11px] text-stone-500">
                    <span class="flex items-center gap-1"><span class="inline-block w-3 h-1.5 rounded-sm" style="background:#dc2626"></span>Paid</span>
                    <span class="flex items-center gap-1"><span class="inline-block w-3 h-1.5 rounded-sm" style="background:#059669"></span>Earned</span>
                    <span class="flex items-center gap-1"><span class="inline-block w-3 border-t border-dashed border-stone-400"></span>Target</span>
                </div>
            </div>
            <div class="relative h-64"><canvas id="chartViews"></canvas></div>
        </div>
        @if(!empty($chart['gmvWeeks']))
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-sm font-semibold text-stone-700 mb-2">GMV per minggu</p>
                <div class="relative h-64"><canvas id="chartGmv"></canvas></div>
            </div>
        @endif
    </div>

    @if($pipeline['terlambat'] > 0 || $pipeline['hariIni'] > 0)
        <a href="{{ route('kol-reminder.index') }}" class="block bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 text-sm text-amber-800 hover:bg-amber-100">
            ⏰ Ada <b>{{ $pipeline['terlambat'] }}</b> terlambat & <b>{{ $pipeline['hariIni'] }}</b> jatuh tempo hari ini di pipeline — buka Reminder →
        </a>
    @endif
</div>

<script>
    (function () {
        if (!window.Chart) return;
        var c = {!! json_encode($chart) !!};
        var vctx = document.getElementById('chartViews');
        if (vctx) new Chart(vctx, {
            type: 'line',
            data: { labels: c.days, datasets: [
                { label: 'Paid', data: c.cumPaid, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.08)', fill: true, tension: .3, spanGaps: false, pointRadius: 0 },
                { label: 'Earned', data: c.cumEarned, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.08)', fill: true, tension: .3, spanGaps: false, pointRadius: 0 },
                { label: 'Target', data: c.target, borderColor: '#a8a29e', borderDash: [5,4], fill: false, pointRadius: 0 },
            ]},
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 }, maxTicksLimit: 8 } } } }
        });
        var gctx = document.getElementById('chartGmv');
        if (gctx && c.gmvWeeks && c.gmvWeeks.length) new Chart(gctx, {
            type: 'bar',
            data: { labels: c.gmvWeekLabels, datasets: [{ label: 'GMV', data: c.gmvWeeks, backgroundColor: '#dc2626', borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 } } } } }
        });
    })();
</script>
@endsection
