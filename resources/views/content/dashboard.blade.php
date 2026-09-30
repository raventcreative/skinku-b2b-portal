@extends('layouts.app')
@section('title', 'Dashboard Creator')
@section('heading', 'Dashboard Creator')

@section('content')
<div class="space-y-7">

    <div class="flex items-end justify-between gap-4 flex-wrap border-b border-stone-200 pb-5">
        <div class="max-w-2xl">
            <p class="text-[10px] uppercase tracking-[0.16em] font-bold text-red-700 mb-2">Workspace Content Creator</p>
            <p class="text-sm leading-6 text-stone-600">{{ $showCreator ? 'Ringkasan konten seluruh creator untuk akun resmi SKINKU.' : 'Kelola konten untuk akun resmi SKINKU.' }} Admin menyetujui sebelum portal menerbitkannya ke Facebook, Instagram, Threads, dan TikTok.</p>
        </div>
        <a href="{{ route('content.create') }}" class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold focus-visible:outline-red-700">Buat konten</a>
    </div>

    @if($attention->isNotEmpty())
        <div class="bg-rose-50 border border-rose-200 rounded-2xl px-4 py-3 space-y-2">
            <p class="text-xs font-bold uppercase tracking-wide text-rose-700">Perlu perhatian</p>
            @foreach($attention as $p)
                <div class="text-sm text-rose-800">
                    <a href="{{ route('content.show', $p) }}" class="font-semibold hover:underline">{{ $p->title }}</a>
                    — {{ $p->statusLabel() }}@if($p->status === 'rejected' && $p->review_note): <span class="italic">“{{ $p->review_note }}”</span>@endif
                </div>
            @endforeach
        </div>
    @endif

    @php
        $reviewLabel = $cards[1][0];
        $reviewCount = $cards[1][1];
    @endphp
    <section aria-label="Ringkasan performa konten" class="grid xl:grid-cols-12 gap-6 xl:gap-8 items-stretch">
        <div class="xl:col-span-4 bg-red-950 text-white rounded-2xl p-6 sm:p-7 flex flex-col justify-between min-h-48">
            <div>
                <p class="text-xs uppercase tracking-[0.14em] font-semibold text-red-200">{{ $reviewLabel }}</p>
                <p class="mt-3 text-5xl font-semibold tracking-tight tabular-nums">{{ number_format($reviewCount, 0, ',', '.') }}</p>
            </div>
            <p class="mt-6 text-sm text-red-100">Konten dalam antrean persetujuan admin</p>
        </div>

        <div class="xl:col-span-8 grid grid-cols-2 sm:grid-cols-3 gap-x-6">
            @foreach($cards as $i => [$label, $value])
                @continue($i === 1)
                <div class="py-4 border-b border-stone-200 min-h-24">
                    <p class="text-[10px] uppercase tracking-[0.12em] font-semibold text-stone-500">{{ $label }}</p>
                    <p class="text-2xl font-semibold tracking-tight text-stone-900 mt-2 tabular-nums">{{ number_format($value, 0, ',', '.') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-stone-100 flex items-center justify-between">
            <h2 class="text-sm font-bold text-stone-800">Konten terbaru</h2>
            <a href="{{ route('content.index') }}" class="text-xs font-semibold text-red-700 hover:underline">Lihat semua →</a>
        </div>
        @include('content._table', ['posts' => $recent, 'showCreator' => $showCreator])
    </div>
</div>
@endsection
