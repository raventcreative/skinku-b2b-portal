@extends('layouts.app')
@section('title', 'Insight Konten')
@section('heading', 'Insight Konten')

@php
    $nf = fn ($n) => number_format((int) $n, 0, ',', '.');
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',').'%';
    $erClass = fn ($v) => $v === null ? 'text-stone-400' : ($v >= 4 ? 'text-emerald-600' : ($v >= 1.5 ? 'text-amber-600' : 'text-stone-800'));
@endphp

@section('content')
<div class="space-y-5">

    <div class="flex items-center justify-between gap-3 flex-wrap">
        <p class="text-sm text-stone-500">Performa postingan akun brand yang terbit {{ $days }} hari terakhir. Metrik ditarik otomatis tiap hari pukul 05:00.</p>
        <div class="flex gap-1">
            @foreach(\App\Http\Controllers\ContentInsightController::PERIODS as $p)
                <a href="{{ route('content-insights.index', ['days' => $p]) }}"
                   class="px-3 py-1.5 text-xs rounded-lg font-semibold {{ $p === $days ? 'bg-red-600 text-white' : 'bg-white border border-stone-300 text-stone-600 hover:bg-stone-50' }}">{{ $p }} hari</a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Total views</p>
            <p class="text-2xl font-bold text-stone-800 tabular-nums">{{ $nf($stats['views']) }}</p>
            <p class="text-[10px] text-stone-400">snapshot terbaru tiap postingan</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Engagement rate</p>
            <p class="text-2xl font-bold tabular-nums {{ $erClass($stats['er']) }}">{{ $pct($stats['er']) }}</p>
            <p class="text-[10px] text-stone-400">interaksi ÷ views</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Interaksi</p>
            <p class="text-2xl font-bold text-stone-800 tabular-nums">{{ $nf($stats['interactions']) }}</p>
            <p class="text-[10px] text-stone-400">like + komen + share + save</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Konten terbit</p>
            <p class="text-2xl font-bold text-stone-800 tabular-nums">{{ $nf($stats['posts']) }}</p>
            <p class="text-[10px] text-stone-400">{{ $stats['synced'] }}/{{ $stats['targets'] }} postingan platform punya data</p>
        </div>
    </div>

    @foreach($syncErrors as $error)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <strong>{{ strtoupper($error['platform']) }}:</strong> {{ $error['message'] }}
            <a href="{{ route('social.index') }}" class="ml-1 font-semibold underline">Periksa koneksi akun</a>
        </div>
    @endforeach

    @if($stats['targets'] > 0 && $stats['synced'] === 0)
        <div class="bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 text-sm text-amber-800">
            {{ $stats['targets'] }} postingan terbit belum memiliki data insight. Metrik biasanya tersedia H+1 setelah terbit. Pastikan akun tersambung dan izinnya aktif di
            <a href="{{ route('social.index') }}" class="font-semibold underline">Akun Sosial Media</a> supaya izin insight diberikan.
        </div>
    @elseif($stats['targets'] === 0)
        <div class="rounded-2xl border border-stone-200 bg-white px-5 py-6 text-center">
            <p class="text-sm font-semibold text-stone-800">Belum ada postingan terbit dalam {{ $days }} hari terakhir.</p>
            <p class="mt-1 text-xs text-stone-500">Halaman ini menampilkan konten yang diterbitkan lewat SKINKU dan postingan akun brand yang berhasil diimpor. Impor Instagram dan video TikTok publik berjalan otomatis setiap hari setelah izin akun tersedia.</p>
            <a href="{{ route('content.index') }}" class="mt-3 inline-flex min-h-9 items-center rounded-lg bg-stone-800 px-4 text-xs font-semibold text-white hover:bg-stone-900">Buka Kalender Konten</a>
        </div>
    @endif

    @if(count($chart['labels']))
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-sm font-semibold text-stone-700 mb-2">Pertumbuhan views (kumulatif)</p>
            <div class="relative h-64"><canvas id="chartInsight"></canvas></div>
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <h2 class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Top postingan</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-[11px] uppercase text-stone-500">
                        <tr><th class="px-4 py-2 text-left">Konten</th><th class="px-4 py-2 text-right">Views</th><th class="px-4 py-2 text-right">ER</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($top as $t)
                            @php $s = $t->latestSnapshot; @endphp
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('content.show', $t->post) }}" class="font-semibold text-stone-800 hover:underline">{{ $t->post->title }}</a>
                                    <p class="text-[11px] text-stone-400">{{ $t->platformLabel() }} · {{ $t->published_at?->format('d M Y') }}</p>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $s->views !== null ? $nf($s->views) : '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $erClass($s->er()) }}">{{ $pct($s->er()) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-stone-400">Belum ada data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <h2 class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Performa per creator</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-[11px] uppercase text-stone-500">
                        <tr><th class="px-4 py-2 text-left">Creator</th><th class="px-4 py-2 text-right">Konten</th><th class="px-4 py-2 text-right">Views</th><th class="px-4 py-2 text-right">ER</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($creators as $c)
                            <tr>
                                <td class="px-4 py-2 font-semibold text-stone-800">{{ $c['name'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $c['posts'] }} <span class="text-[11px] text-stone-400">({{ $c['targets'] }} platform)</span></td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $nf($c['views']) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $erClass($c['er']) }}">{{ $pct($c['er']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Belum ada konten terbit.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@if(count($chart['labels']))
<script>
    (function () {
        if (!window.Chart) return;
        var c = {!! json_encode($chart) !!};
        new Chart(document.getElementById('chartInsight'), {
            type: 'line',
            data: { labels: c.labels, datasets: [{ label: 'Views', data: c.views, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.08)', fill: true, tension: .3, pointRadius: 3 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 }, maxTicksLimit: 10 } } } }
        });
    })();
</script>
@endif
@endsection
