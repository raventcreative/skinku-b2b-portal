@extends('layouts.app')
@section('title', 'OKR')
@section('heading', 'OKR — Target & Eksekusi Tim')

@section('content')
@php $u = auth()->user(); @endphp

<div class="flex flex-wrap items-start justify-between gap-3 mb-5">
    <div>
        <p class="text-sm text-stone-600">AI menyusun Objective, Key Result, dan tugas individu. Progres mengikuti kartu Kanban secara otomatis.</p>
    </div>
    @if($u->canDo('okr.manage'))
        <a href="{{ route('okr.create') }}" class="px-4 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">✨ Susun OKR dengan AI</a>
    @endif
</div>

<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
    @forelse($cycles as $cycle)
        @php
            $tasks = $cycle->objectives->flatMap(fn($objective) => $objective->keyResults)->flatMap(fn($kr) => $kr->tasks);
            $done = $tasks->filter(fn($task) => $task->isCompleted())->count();
            $total = $tasks->count();
            $progress = $total ? (int) round(($done / $total) * 100) : 0;
            $finished = !$cycle->isDraft() && $total > 0 && $done === $total;
        @endphp
        <a href="{{ route('okr.show', $cycle) }}" class="block bg-white rounded-2xl border border-stone-200 p-5 hover:border-red-300 transition">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-bold text-stone-900">{{ $cycle->name }}</p>
                    <p class="text-xs text-stone-500 mt-1">{{ $cycle->period_label }} · {{ $cycle->scopeLabel() }}</p>
                </div>
                @if($cycle->isDraft())
                    <span class="shrink-0 px-2 py-1 text-[10px] font-bold rounded-full bg-amber-100 text-amber-800">DRAF</span>
                @elseif($finished)
                    <span class="shrink-0 px-2 py-1 text-[10px] font-bold rounded-full bg-emerald-100 text-emerald-800">SELESAI</span>
                @else
                    <span class="shrink-0 px-2 py-1 text-[10px] font-bold rounded-full bg-indigo-100 text-indigo-800">AKTIF</span>
                @endif
            </div>

            <div class="mt-5">
                <div class="flex justify-between text-[11px] text-stone-500 mb-1.5">
                    <span>{{ $done }}/{{ $total }} tugas selesai</span>
                    <b class="text-stone-700">{{ $progress }}%</b>
                </div>
                <div class="h-2 rounded-full bg-stone-100 overflow-hidden">
                    <div class="h-full {{ $finished ? 'bg-emerald-500' : 'bg-red-500' }} rounded-full" style="width: {{ $progress }}%"></div>
                </div>
            </div>

            <p class="text-[11px] text-stone-400 mt-4">{{ $cycle->objectives->count() }} Objective · {{ $cycle->start_date->format('d M') }}–{{ $cycle->end_date->format('d M Y') }}</p>
        </a>
    @empty
        <div class="col-span-full py-14 text-center bg-white rounded-2xl border border-dashed border-stone-300">
            <p class="text-stone-500 text-sm">Belum ada OKR.</p>
            @if($u->canDo('okr.manage'))
                <a href="{{ route('okr.create') }}" class="inline-block mt-3 text-sm font-semibold text-red-600 hover:text-red-700">Susun yang pertama dengan AI →</a>
            @endif
        </div>
    @endforelse
</div>
@endsection
