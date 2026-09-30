@extends('layouts.app')
@section('title', $post->title)
@section('heading', 'Detail Konten')

@section('content')
@php
    $isOwner = $post->user_id === auth()->id();
    $actionLabels = [
        'content.create' => 'Draft dibuat', 'content.update' => 'Konten diperbarui',
        'content.publish' => 'Masuk antrean publikasi', 'content.retry' => 'Publikasi dicoba ulang',
        'content.mark_published' => 'Publikasi manual dicatat',
        'content.submit' => 'Status lama: diajukan untuk persetujuan', 'content.approve' => 'Status lama: disetujui',
        'content.reject' => 'Status lama: ditolak', 'content.withdraw' => 'Status lama: ditarik ke draft',
    ];
@endphp

<div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-col justify-between gap-4 border-b border-stone-200 pb-4 sm:flex-row sm:items-end">
        <div class="min-w-0">
            <a href="{{ route('content.index') }}" class="inline-flex items-center gap-1 text-[11px] font-semibold text-stone-500 hover:text-red-700"><svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m12.5 4.5-5 5.5 5 5.5"/></svg>Pipeline konten</a>
            <h2 class="mt-2 truncate text-xl font-semibold tracking-tight text-stone-900">{{ $post->title }}</h2>
            <p class="mt-1 text-xs text-stone-500">{{ \App\Models\ContentPost::TYPES[$post->type] ?? $post->type }} · {{ $post->user?->displayName() }} · {{ $post->scheduled_at?->format('d M Y, H:i') ?? 'Tanpa jadwal' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @include('content._badge', ['status' => $post->status, 'label' => $post->statusLabel()])
            @if($isOwner && $post->isEditable())
                <a href="{{ route('content.edit', $post) }}" class="inline-flex min-h-9 items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 text-xs font-semibold text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m12.5 3.5 4 4M4 16l1-4 8.5-8.5a1.4 1.4 0 0 1 2 2L7 14l-3 2Z"/></svg>Edit
                </a>
            @endif
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <main class="space-y-5">
            <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5">
                <div class="flex items-center justify-between gap-3 border-b border-stone-100 pb-3"><div><h3 class="text-sm font-bold text-stone-900">Media dan caption</h3><p class="mt-0.5 text-[11px] text-stone-500">Konten yang dikirim ke kanal terpilih.</p></div></div>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @forelse($media as $file)
                        <div class="min-w-0">
                            @if($file->isImage())<a href="{{ $file->url() }}" class="glightbox" data-gallery="content-{{ $post->id }}"><img src="{{ $file->url() }}" alt="Media {{ $loop->iteration }} untuk {{ $post->title }}" class="aspect-square w-full rounded-xl border border-stone-200 object-cover"></a>@else
                                <video src="{{ $file->url() }}" controls class="aspect-square w-full rounded-xl border border-stone-200 bg-black object-cover"></video>
                            @endif
                            <a href="{{ $file->url() }}" download class="mt-1.5 inline-flex min-h-8 items-center gap-1.5 text-[11px] font-semibold text-red-700 hover:underline"><svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M10 2.5v10m0 0 3.5-3.5M10 12.5 6.5 9M3.5 14v2.5h13V14"/></svg>Unduh media</a>
                        </div>
                    @empty
                        <p class="col-span-full rounded-xl bg-stone-50 p-5 text-center text-sm text-stone-500">Belum ada media yang diunggah.</p>
                    @endforelse
                </div>
                @if($post->caption)
                    <div class="mt-4 rounded-xl bg-stone-50 p-4"><div class="mb-2 flex items-center justify-between gap-2"><h4 class="text-[11px] font-bold uppercase tracking-wide text-stone-500">Caption utama</h4><button type="button" data-copy="main-caption" class="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-stone-200 bg-white px-2.5 text-[10px] font-semibold text-stone-600 hover:bg-stone-100"><svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><rect x="6" y="6" width="11" height="11" rx="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 6V4.5A1.5 1.5 0 0 0 11.5 3h-7A1.5 1.5 0 0 0 3 4.5v7A1.5 1.5 0 0 0 4.5 13H6"/></svg><span>Salin caption</span></button></div><p id="main-caption" class="whitespace-pre-line break-words text-sm leading-6 text-stone-700">{{ $post->caption }}</p></div>
                @endif
                @if($post->creator_note)<p class="mt-3 rounded-lg border-l-2 border-stone-300 bg-stone-50 px-3 py-2 text-xs text-stone-600"><span class="font-semibold">Catatan tim:</span> {{ $post->creator_note }}</p>@endif
            </section>

            <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white">
                <header class="border-b border-stone-100 px-4 py-4 sm:px-5"><h3 class="text-sm font-bold text-stone-900">Status publikasi</h3><p class="mt-0.5 text-[11px] text-stone-500">Setiap platform diproses secara terpisah.</p></header>
                <div class="divide-y divide-stone-100">
                    @foreach($post->targets as $target)
                        @php $manualReady = in_array($target->status, ['manual_pending', 'failed'], true) && ! $post->scheduled_at?->isFuture(); @endphp
                        <article class="space-y-3 px-4 py-4 sm:px-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div><h4 class="text-sm font-semibold text-stone-900">{{ $target->platformLabel() }}</h4><p class="mt-0.5 text-[10px] text-stone-500">{{ $target->isManual() ? 'Posting manual' : 'Publikasi melalui API' }}</p></div>
                                <div class="flex items-center gap-2">@if($target->permalink)<a href="{{ $target->permalink }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-stone-200 px-2.5 text-[10px] font-semibold text-stone-700 hover:bg-stone-50">Buka postingan<svg aria-hidden="true" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3h6v6m0-6-8 8"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11v4.5A1.5 1.5 0 0 1 13.5 17h-9A1.5 1.5 0 0 1 3 15.5v-9A1.5 1.5 0 0 1 4.5 5H9"/></svg></a>@endif @include('content._badge', ['status' => $target->status, 'label' => $target->statusLabel()])</div>
                            </div>

                            <div class="relative rounded-lg bg-stone-50 p-3 pr-24">
                                <p id="caption-{{ $target->id }}" class="whitespace-pre-line break-words text-xs leading-5 text-stone-700">{{ $target->caption() }}</p>
                                <button type="button" data-copy="caption-{{ $target->id }}" class="absolute right-2 top-2 inline-flex min-h-8 items-center gap-1 rounded-md border border-stone-200 bg-white px-2 text-[10px] font-semibold text-stone-600 hover:bg-stone-100"><svg aria-hidden="true" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><rect x="6" y="6" width="11" height="11" rx="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 6V4.5A1.5 1.5 0 0 0 11.5 3h-7A1.5 1.5 0 0 0 3 4.5v7A1.5 1.5 0 0 0 4.5 13H6"/></svg>Salin</button>
                            </div>

                            @if($target->attempts || $target->last_error)
                                <p class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-[11px] text-rose-800">Percobaan {{ $target->attempts }}@if($target->last_error) · {{ $target->last_error }}@endif @if($target->next_attempt_at && $target->status === 'queued') · mencoba lagi {{ $target->next_attempt_at->diffForHumans() }}@endif</p>
                            @endif

                            @if($target->status === 'published' && ! $target->isManual())
                                @php $snapshot = $target->snapshots->last(); $engagementRate = $snapshot?->er(); @endphp
                                @if($snapshot)
                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <div class="rounded-lg bg-stone-50 p-2.5"><p class="text-[10px] text-stone-500">Views</p><p class="mt-1 text-sm font-bold tabular-nums text-stone-900">{{ $snapshot->views !== null ? number_format($snapshot->views, 0, ',', '.') : '—' }}</p></div>
                                        <div class="rounded-lg bg-stone-50 p-2.5"><p class="text-[10px] text-stone-500">Engagement</p><p class="mt-1 text-sm font-bold tabular-nums text-stone-900">{{ $engagementRate === null ? '—' : number_format($engagementRate, 2, ',', '.').'%' }}</p></div>
                                        <div class="rounded-lg bg-stone-50 p-2.5"><p class="text-[10px] text-stone-500">Like / komen</p><p class="mt-1 text-sm font-bold tabular-nums text-stone-900">{{ $snapshot->likes ?? '—' }} / {{ $snapshot->comments ?? '—' }}</p></div>
                                        <div class="rounded-lg bg-stone-50 p-2.5"><p class="text-[10px] text-stone-500">Bagikan / simpan</p><p class="mt-1 text-sm font-bold tabular-nums text-stone-900">{{ $snapshot->shares ?? '—' }} / {{ $snapshot->saves ?? '—' }}</p></div>
                                    </div>
                                    <p class="text-[10px] text-stone-400">Insight per {{ $snapshot->captured_on->format('d M Y') }}</p>
                                @else<p class="text-[11px] text-stone-500">Insight tersedia setelah data platform tersinkron.</p>@endif
                            @endif

                            @if($canPublish && $target->status === 'failed' && ! $target->isManual())
                                <form method="POST" action="{{ route('content-targets.retry', $target) }}">@csrf
                                    <button class="inline-flex min-h-9 items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 text-xs font-semibold text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 9a6.5 6.5 0 0 0-11.8-3L3 8m0 0V4m0 4h4m-3.5 3a6.5 6.5 0 0 0 11.8 3L17 12m0 0v4m0-4h-4"/></svg>Coba lagi</button>
                                </form>
                            @endif
                            @if($canPublish && $manualReady)
                                <form method="POST" action="{{ route('content-targets.mark-published', $target) }}" class="flex flex-col gap-2 sm:flex-row">@csrf
                                    <label class="sr-only" for="permalink-{{ $target->id }}">Tautan publikasi {{ $target->platformLabel() }}</label>
                                    <input id="permalink-{{ $target->id }}" name="permalink" type="url" required placeholder="Tempel tautan posting https://…" class="min-h-10 w-full px-3 text-xs sm:max-w-md">
                                    <button class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-red-700 px-3 text-xs font-semibold text-white hover:bg-red-800"><svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m4 10 4 4 8-8"/></svg>Catat sudah terbit</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            @if($insightChart)
                <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5"><h3 class="text-sm font-semibold text-stone-800">Pertumbuhan views per platform</h3><div class="relative mt-3 h-56"><canvas id="chartInsight"></canvas></div></section>
            @endif
        </main>

        <aside class="space-y-5">
            <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5">
                <h3 class="text-sm font-bold text-stone-900">Ringkasan publikasi</h3>
                <dl class="mt-4 divide-y divide-stone-100 text-xs">
                    <div class="flex justify-between gap-3 py-2"><dt class="text-stone-500">Platform</dt><dd class="font-semibold text-stone-800">{{ $post->targets->count() }} kanal</dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-stone-500">Waktu terbit</dt><dd class="text-right font-semibold text-stone-800">{{ $post->scheduled_at?->format('d M Y, H:i') ?? 'Antrean segera' }}</dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-stone-500">Dibuat</dt><dd class="font-semibold text-stone-800">{{ $post->created_at?->format('d M Y') }}</dd></div>
                </dl>
            </section>
            <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5">
                <h3 class="text-sm font-bold text-stone-900">Riwayat</h3>
                <ol class="mt-4 space-y-3 text-xs">
                    @forelse($history as $event)
                        <li class="border-l-2 border-stone-200 pl-3">
                            <p class="font-semibold text-stone-700">{{ $actionLabels[$event->action] ?? $event->action }}</p>
                            <p class="mt-0.5 text-[10px] text-stone-400">{{ $event->created_at?->format('d M Y, H:i') }} · {{ $event->performed_by_email ?? 'sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-stone-500">Belum ada perubahan.</li>
                    @endforelse
                    @foreach($post->targets->whereNotNull('published_at') as $target)
                        <li class="border-l-2 border-emerald-300 pl-3"><p class="font-semibold text-emerald-800">Terbit di {{ $target->platformLabel() }}</p><p class="mt-0.5 text-[10px] text-stone-400">{{ $target->published_at->format('d M Y, H:i') }}</p></li>
                    @endforeach
                </ol>
            </section>
        </aside>
    </div>
</div>

<script>
    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', async function () {
            var content = document.getElementById(button.dataset.copy);
            if (!content) return;
            try {
                await navigator.clipboard.writeText(content.textContent.trim());
                var text = button.querySelector('span');
                if (text) {
                    var original = text.textContent;
                    text.textContent = 'Tersalin';
                    window.setTimeout(function () { text.textContent = original; }, 1800);
                }
            } catch (_) { button.setAttribute('aria-label', 'Tidak dapat menyalin caption'); }
        });
    });
</script>
@if($insightChart)
<script>
    (function () {
        if (!window.Chart) return;
        var chart = {!! json_encode($insightChart) !!};
        var colors = ['#b4232f', '#2563eb', '#16a34a', '#0f172a'];
        new Chart(document.getElementById('chartInsight'), {
            type: 'line',
            data: { labels: chart.labels, datasets: chart.datasets.map(function (dataset, i) { return { label: dataset.label, data: dataset.data, borderColor: colors[i % colors.length], tension: .3, pointRadius: 3, spanGaps: true }; }) },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { font: { size: 10 } } } }, scales: { y: { beginAtZero: true, ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 }, maxTicksLimit: 10 } } } }
        });
    })();
</script>
@endif
@endsection
