@extends('layouts.app')
@section('title', 'Kalender Konten')
@section('heading', 'Kalender Konten')

@section('content')
@php
    $weekdayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
    $prevMonth = $month->copy()->subMonth()->format('Y-m');
    $nextMonth = $month->copy()->addMonth()->format('Y-m');
    $monthLabel = $month->locale('id')->translatedFormat('F Y');
@endphp

<div class="space-y-5">
    <div class="flex flex-col justify-between gap-4 border-b border-stone-200 pb-5 sm:flex-row sm:items-end">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[.18em] text-red-700">Studio Konten SKINKU</p>
            <h2 class="mt-1 text-xl font-semibold tracking-tight text-stone-900">{{ $monthLabel }}</h2>
            <p class="mt-1 text-sm text-stone-600">Konten terjadwal dan waktu publikasi per platform.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('content.index') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-200 bg-white px-3.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>Pipeline
            </a>
            <a href="{{ route('content.create') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-red-700 px-4 text-sm font-semibold text-white hover:bg-red-800">
                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20"><path stroke-linecap="round" d="M10 4v12M4 10h12"/></svg>Buat konten
            </a>
        </div>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="inline-flex items-center gap-1 rounded-xl border border-stone-200 bg-white p-1">
            <a aria-label="Bulan sebelumnya" href="{{ route('content.calendar', array_filter(['month' => $prevMonth, 'creator' => $creatorFilter])) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-stone-600 hover:bg-stone-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-700">
                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m12.5 4.5-5 5.5 5 5.5"/></svg>
            </a>
            <span class="min-w-32 px-2 text-center text-sm font-semibold text-stone-800">{{ $monthLabel }}</span>
            <a aria-label="Bulan berikutnya" href="{{ route('content.calendar', array_filter(['month' => $nextMonth, 'creator' => $creatorFilter])) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-stone-600 hover:bg-stone-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-700">
                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 4.5 5 5.5-5 5.5"/></svg>
            </a>
        </div>
        @if($canManage)
            <form method="GET" action="{{ route('content.calendar') }}" class="flex items-center gap-2">
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                <label for="calendarCreator" class="text-xs font-medium text-stone-600">Creator</label>
                <select id="calendarCreator" name="creator" class="min-h-10 min-w-48 px-3 text-sm" onchange="this.form.submit()">
                    <option value="">Semua creator</option>
                    @foreach($creators as $creator)<option value="{{ $creator->id }}" @selected((string) $creatorFilter === (string) $creator->id)>{{ $creator->displayName() }}</option>@endforeach
                </select>
            </form>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
        <div class="grid grid-cols-7 border-b border-stone-200 bg-stone-50/80">
            @foreach($weekdayLabels as $weekday)<div class="px-1 py-2.5 text-center text-[10px] font-bold uppercase tracking-wide text-stone-500 sm:px-3 sm:text-xs">{{ $weekday }}</div>@endforeach
        </div>
        <div class="grid grid-cols-7">
            @for($day = $start->copy(); $day->lte($end); $day->addDay())
                @php
                    $date = $day->toDateString();
                    $dayPosts = $postsByDate->get($date, collect());
                    $inMonth = $day->month === $month->month;
                @endphp
                <section class="group min-h-28 border-b border-r border-stone-100 p-1.5 sm:min-h-36 sm:p-2.5 {{ $inMonth ? 'bg-white' : 'bg-stone-50/60' }}" aria-label="{{ $day->locale('id')->translatedFormat('j F Y') }}">
                    <div class="flex items-center justify-between">
                        <span @class(['flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold tabular-nums', 'bg-red-700 text-white' => $date === now()->toDateString(), 'text-stone-800' => $date !== now()->toDateString() && $inMonth, 'text-stone-300' => ! $inMonth])>{{ $day->day }}</span>
                        @if($day->copy()->setTime(9, 0)->isFuture())
                            <a aria-label="Buat konten untuk {{ $day->locale('id')->translatedFormat('j F') }}" href="{{ route('content.create', ['scheduled_at' => $day->copy()->setTime(9, 0)->format('Y-m-d\TH:i')]) }}" class="flex h-7 w-7 items-center justify-center rounded-md text-stone-300 opacity-0 transition hover:bg-red-50 hover:text-red-700 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-700 group-hover:opacity-100">
                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" d="M10 4v12M4 10h12"/></svg>
                            </a>
                        @endif
                    </div>
                    <div class="mt-1.5 space-y-1">
                        @foreach($dayPosts as $post)
                            <a href="{{ route('content.show', $post) }}" title="{{ $post->title }} · {{ $post->statusLabel() }}" class="block rounded-md border-l-2 px-1.5 py-1 text-[10px] leading-tight sm:px-2 sm:text-[11px] {{ in_array($post->status, ['failed', 'partial'], true) ? 'border-rose-500 bg-rose-50 text-rose-800' : ($post->status === 'done' ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-red-600 bg-red-50 text-red-900') }}">
                                <span class="block font-semibold tabular-nums">{{ $post->scheduled_at->format('H:i') }} <span class="font-normal">·</span> {{ $post->targets->first()?->platformLabel() ?? 'Konten' }}</span>
                                <span class="mt-0.5 hidden truncate sm:block">{{ $post->title }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endfor
        </div>
    </div>
</div>
@endsection
