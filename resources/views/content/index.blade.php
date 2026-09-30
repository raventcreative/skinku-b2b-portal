@extends('layouts.app')
@section('title', 'Pipeline Konten')
@section('heading', 'Pipeline Konten')

@section('content')
@php
    $items = [
        ['all', 'Semua', $counts->sum()],
        ['draft', 'Draft', $counts[\App\Models\ContentPost::DRAFT] ?? 0],
        ['scheduled', 'Terjadwal', $counts[\App\Models\ContentPost::SCHEDULED] ?? 0],
        ['publishing', 'Sedang terbit', $counts[\App\Models\ContentPost::PUBLISHING] ?? 0],
        ['attention', 'Perlu tindakan', $attentionCount],
        ['published', 'Selesai', $counts[\App\Models\ContentPost::DONE] ?? 0],
    ];
@endphp

<div class="space-y-5">
    <div class="flex flex-col justify-between gap-4 border-b border-stone-200 pb-5 sm:flex-row sm:items-end">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[.18em] text-red-700">Studio Konten SKINKU</p>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-stone-600">Siapkan konten, pilih jadwal, dan pantau publikasi per platform dari satu tempat.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('content.calendar') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-200 bg-white px-3.5 text-sm font-semibold text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01"/></svg>Kalender
            </a>
            <a href="{{ route('content.create') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-red-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20"><path stroke-linecap="round" d="M10 4v12M4 10h12"/></svg>Buat konten
            </a>
        </div>
    </div>

    <section aria-label="Tahap publikasi" class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
        @foreach($items as [$key, $label, $count])
            <a href="{{ route('content.index', array_merge(request()->except('page'), ['stage' => $key])) }}" @class([
                'group rounded-xl border px-3.5 py-3 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700',
                'border-red-200 bg-red-50/70' => $stage === $key,
                'border-stone-200 bg-white hover:border-stone-300 hover:bg-stone-50/60' => $stage !== $key,
            ])>
                <span class="block text-[10px] font-bold uppercase tracking-[.12em] {{ $stage === $key ? 'text-red-800' : 'text-stone-500' }}">{{ $label }}</span>
                <span class="mt-1 block text-2xl font-semibold tabular-nums tracking-tight {{ $stage === $key ? 'text-red-800' : 'text-stone-900' }}">{{ number_format((int) $count, 0, ',', '.') }}</span>
            </a>
        @endforeach
    </section>

    <form method="GET" action="{{ route('content.index') }}" class="grid gap-2 rounded-xl border border-stone-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-6">
        <input type="hidden" name="stage" value="{{ $stage }}">
        <label class="sr-only" for="contentSearch">Cari judul</label>
        <input id="contentSearch" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari judul konten" class="min-h-10 px-3 text-sm sm:col-span-2 lg:col-span-2">
        @if($canManage)
            <label class="sr-only" for="contentCreator">Creator</label>
            <select id="contentCreator" name="creator" class="min-h-10 px-3 text-sm">
                <option value="">Semua creator</option>
                @foreach($creators as $creator)<option value="{{ $creator->id }}" @selected((string) ($filters['creator'] ?? '') === (string) $creator->id)>{{ $creator->displayName() }}</option>@endforeach
            </select>
        @endif
        <label class="sr-only" for="contentPlatform">Platform</label>
        <select id="contentPlatform" name="platform" class="min-h-10 px-3 text-sm">
            <option value="">Semua platform</option>
            @foreach(config('content.platforms') as $key => $platform)<option value="{{ $key }}" @selected(($filters['platform'] ?? '') === $key)>{{ $platform['label'] }}</option>@endforeach
        </select>
        <label class="sr-only" for="contentFrom">Jadwal dari</label>
        <input id="contentFrom" type="date" name="dari" value="{{ $filters['dari'] ?? '' }}" class="min-h-10 px-3 text-sm">
        <label class="sr-only" for="contentTo">Jadwal sampai</label>
        <input id="contentTo" type="date" name="sampai" value="{{ $filters['sampai'] ?? '' }}" class="min-h-10 px-3 text-sm">
        <button class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-stone-900 px-4 text-sm font-semibold text-white hover:bg-stone-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h14l-5.5 6.2v4.3l-3 1.5v-5.8L3 4Z"/></svg>Terapkan
        </button>
    </form>

    <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" aria-label="Daftar konten">
        <header class="flex items-center justify-between gap-3 border-b border-stone-100 px-4 py-3 sm:px-5">
            <div>
                <h2 class="text-sm font-bold text-stone-900">{{ collect($items)->firstWhere(0, $stage)[1] ?? 'Semua konten' }}</h2>
                <p class="mt-0.5 text-[11px] text-stone-500">{{ number_format($posts->total(), 0, ',', '.') }} konten</p>
            </div>
            <span class="hidden text-[10px] font-semibold uppercase tracking-[.14em] text-stone-400 sm:inline">{{ $canManage ? 'Seluruh creator' : 'Konten saya' }}</span>
        </header>
        @include('content._table', ['posts' => $posts, 'canManage' => $canManage])
    </section>
    <div>{{ $posts->links() }}</div>
</div>
@endsection
