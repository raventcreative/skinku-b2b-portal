@extends('layouts.app')
@section('title', 'OKR')
@section('heading', 'OKR — Target & Eksekusi Tim')

@section('content')
@php $u = auth()->user(); @endphp

<div class="space-y-5">
    <header class="flex flex-col gap-4 rounded-2xl border border-stone-200 bg-brand-cream p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div class="flex items-start gap-4">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-cream text-brand-maroon">
                <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m0 14h17M8 15l4-4 3 3 6-7"/><path stroke-linecap="round" d="M17 7h4v4"/></svg>
            </span>
            <div><h2 class="text-base font-bold text-stone-900">Sasaran tim</h2><p class="mt-1 max-w-2xl text-xs leading-relaxed text-stone-600">Pantau Objective, Key Result, dan tugas tim. Progres tersinkron otomatis dari kartu Kanban.</p></div>
        </div>
        @if($u->canDo('okr.manage'))
            <a href="{{ route('okr.create') }}" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-maroon px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-maroon focus-visible:ring-offset-2">
                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M10 3v14M3 10h14"/></svg>
                Susun OKR dengan AI
            </a>
        @endif
    </header>

    @if($cycles->isNotEmpty())
        <section aria-label="Daftar periode OKR" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($cycles as $cycle)
                @php
                    $tasks = $cycle->objectives->flatMap(fn ($objective) => $objective->keyResults)->flatMap(fn ($kr) => $kr->tasks);
                    $done = $tasks->filter(fn ($task) => $task->isCompleted())->count();
                    $total = $tasks->count();
                    $progress = $total ? (int) round(($done / $total) * 100) : 0;
                    $finished = ! $cycle->isDraft() && $total > 0 && $done === $total;
                    $status = $cycle->isDraft()
                        ? ['Draf', 'bg-amber-50 text-amber-800 border-amber-200']
                        : ($finished ? ['Selesai', 'bg-emerald-50 text-emerald-800 border-emerald-200'] : ['Aktif', 'bg-brand-cream text-brand-maroon border-brand-maroon/20']);
                @endphp
                <article class="group flex min-w-0 flex-col overflow-hidden rounded-2xl border border-stone-200 bg-brand-cream transition hover:-translate-y-0.5 hover:border-brand-maroon/40 hover:shadow-sm">
                    <a href="{{ route('okr.show', $cycle) }}" class="flex flex-1 flex-col p-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-maroon">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0"><h3 class="break-words text-sm font-bold leading-snug text-stone-900 group-hover:text-brand-maroon">{{ $cycle->name }}</h3><p class="mt-1.5 text-xs text-stone-500">{{ $cycle->period_label }} <span aria-hidden="true">·</span> {{ $cycle->scopeLabel() }}</p></div>
                            <span class="shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $status[1] }}">{{ $status[0] }}</span>
                        </div>
                        <div class="mt-5">
                            <div class="flex items-center justify-between gap-2 text-[11px]"><span class="font-medium text-stone-600">Tugas selesai</span><span class="tabular-nums text-stone-500">{{ $done }} / {{ $total }} <strong class="ml-1 text-stone-800">{{ $progress }}%</strong></span></div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-label="Progres {{ $cycle->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}"><div class="h-full rounded-full transition-all {{ $finished ? 'bg-emerald-600' : 'bg-brand-maroon' }}" style="width: {{ $progress }}%"></div></div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-stone-500"><span>{{ $cycle->objectives->count() }} Objective</span><span aria-hidden="true">·</span><span>{{ $cycle->start_date->format('d M') }}–{{ $cycle->end_date->format('d M Y') }}</span></div>
                        @if($cycle->objectives->pluck('specialist')->filter()->unique()->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach($cycle->objectives->pluck('specialist')->filter()->unique() as $specialist)
                                    <span class="rounded-md bg-stone-100 px-2 py-1 text-[9px] font-bold tracking-wide text-stone-600">{{ strtoupper($specialist) }}</span>
                                @endforeach
                            </div>
                        @endif
                        <span class="mt-auto flex items-center gap-1 pt-5 text-xs font-semibold text-brand-maroon">Buka detail <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 1 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg></span>
                    </a>
                    @if($u->canDo('okr.manage'))
                        <div class="flex justify-end border-t border-stone-100 px-4 py-2">
                            <form method="POST" action="{{ route('okr.destroy', $cycle) }}" onsubmit="return confirm('{{ $cycle->isDraft() ? 'Hapus draf OKR ini?' : 'Hapus OKR ini beserta SEMUA kartu Kanban dari tugasnya? Tidak bisa dibatalkan.' }}')">
                                @csrf @method('DELETE')
                                <button type="submit" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-stone-500 hover:bg-rose-50 hover:text-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" aria-label="Hapus OKR {{ $cycle->name }}">
                                    <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>Hapus OKR
                                </button>
                            </form>
                        </div>
                    @endif
                </article>
            @endforeach
        </section>
    @else
        <section class="rounded-2xl border border-dashed border-stone-300 bg-brand-cream px-5 py-14 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-cream text-brand-maroon"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m0 14h17M8 15l4-4 3 3 6-7"/></svg></span>
            <h2 class="mt-4 text-sm font-bold text-stone-900">Belum ada periode OKR</h2>
            <p class="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-stone-500">Susun sasaran pertama untuk mulai membagi target dan memantau pelaksanaan tim.</p>
            @if($u->canDo('okr.manage'))<a href="{{ route('okr.create') }}" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-maroon px-4 py-2 text-xs font-semibold text-white hover:bg-brand-dark">Susun OKR pertama<svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 1 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg></a>@endif
        </section>
    @endif
</div>
@endsection
