@extends('layouts.app')
@section('title', 'Detail Konten')
@section('heading', 'Detail Konten')

@section('content')
@php
    $u = auth()->user();
    $canManage = $u->canDo('kol.content.manage');
    $nf = fn ($n) => number_format((int) $n, 0, ',', '.');
    $latestEr = $history->first()['er'] ?? null;
@endphp

<div class="max-w-4xl space-y-4">
    <a href="{{ route('kol-konten.index', ['bulan' => $content->posted_at->format('Y-m')]) }}" class="text-xs text-stone-500 hover:text-stone-800">← Konten &amp; Views</a>

    {{-- Header konten --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ $content->url }}" target="_blank" rel="noopener noreferrer" class="text-lg font-bold text-stone-800 hover:text-indigo-600 break-words">{{ $content->title ?: $content->url }}</a>
                <div class="flex flex-wrap items-center gap-2 mt-1.5 text-sm">
                    <a href="{{ route('kols.show', $content->kol_id) }}" class="text-indigo-600 hover:underline">{{ '@'.$content->kol->tiktok_username }}</a>
                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $content->label === 'paid' ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $content->label }}</span>
                    <span class="text-xs text-stone-400">{{ ucfirst($content->platform) }}</span>
                    <span class="text-xs text-stone-400">· tayang {{ $content->posted_at->format('d M Y') }}</span>
                    @if($content->deal)<a href="{{ route('kol-deals.edit', $content->deal) }}" class="text-xs text-stone-500 hover:underline">· deal {{ $content->deal->kode }}</a>@endif
                </div>
            </div>
            @if($canManage)
                <a href="{{ route('kol-konten.edit', $content) }}" class="text-xs font-semibold text-stone-600 hover:text-stone-900 border border-stone-300 rounded-lg px-3 py-1.5">Edit</a>
            @endif
        </div>
    </div>

    {{-- Stat ringkas --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Views terbaru</p>
            <p class="text-xl font-bold text-stone-800">{{ $latest ? $nf($latest->views) : '—' }}</p>
            <p class="text-[10px] text-stone-400">{{ $latest ? $latest->captured_on->format('d M Y').' · '.$latest->source : 'belum ada snapshot' }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Engagement rate</p>
            <p class="text-xl font-bold {{ $latestEr === null ? 'text-stone-400' : ($latestEr >= 4 ? 'text-emerald-600' : ($latestEr >= 1.5 ? 'text-amber-600' : 'text-stone-800')) }}">{{ $latestEr !== null ? rtrim(rtrim(number_format($latestEr, 2, ',', '.'), '0'), ',').'%' : '—' }}</p>
            <p class="text-[10px] text-stone-400">interaksi ÷ views</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Likes / komen</p>
            <p class="text-xl font-bold text-stone-800">{{ $latest && $latest->likes !== null ? $nf($latest->likes) : '—' }} <span class="text-stone-300 text-base">/</span> {{ $latest && $latest->comments !== null ? $nf($latest->comments) : '—' }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Jumlah snapshot</p>
            <p class="text-xl font-bold text-stone-800">{{ $history->count() }}</p>
            <p class="text-[10px] text-stone-400">riwayat views harian</p>
        </div>
    </div>

    @if($history->isEmpty())
        <div class="bg-white rounded-2xl border border-stone-200 p-10 text-center text-stone-400 text-sm">
            Belum ada snapshot views. Isi lewat <a href="{{ route('kol-konten.grid', ['bulan' => $content->posted_at->format('Y-m')]) }}" class="text-indigo-600 hover:underline">Isi views massal</a>.
        </div>
    @else
        {{-- Grafik pertumbuhan views --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-sm font-semibold text-stone-700 mb-2">Pertumbuhan views</p>
            <div class="relative h-64"><canvas id="chartGrowth"></canvas></div>
        </div>

        {{-- Tabel riwayat snapshot --}}
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-stone-500 text-xs">
                        <tr>
                            <th class="text-left px-4 py-2.5">Tanggal</th>
                            <th class="text-right px-4 py-2.5">Views</th>
                            <th class="text-right px-4 py-2.5">Δ vs sebelumnya</th>
                            <th class="text-right px-4 py-2.5">ER</th>
                            <th class="text-left px-4 py-2.5">Sumber</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($history as $h)
                            <tr>
                                <td class="px-4 py-2.5 text-stone-600">{{ $h['date']->format('d M Y') }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-stone-800">{{ $nf($h['views']) }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    @if($h['delta'] === null)
                                        <span class="text-stone-300">—</span>
                                    @elseif($h['delta'] > 0)
                                        <span class="text-emerald-600">+{{ $nf($h['delta']) }}</span>
                                    @elseif($h['delta'] < 0)
                                        <span class="text-rose-600">{{ $nf($h['delta']) }}</span>
                                    @else
                                        <span class="text-stone-400">0</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right text-stone-600">{{ $h['er'] !== null ? rtrim(rtrim(number_format($h['er'], 2, ',', '.'), '0'), ',').'%' : '—' }}</td>
                                <td class="px-4 py-2.5 text-stone-500">{{ $h['source'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

@if($history->isNotEmpty())
<script>
    (function () {
        if (!window.Chart) return;
        var c = {!! json_encode($chart) !!};
        var ctx = document.getElementById('chartGrowth');
        if (ctx) new Chart(ctx, {
            type: 'line',
            data: { labels: c.labels, datasets: [{ label: 'Views', data: c.views, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.08)', fill: true, tension: .3, pointRadius: 3 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 }, maxTicksLimit: 10 } } } }
        });
    })();
</script>
@endif
@endsection
