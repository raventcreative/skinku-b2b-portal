@extends('layouts.app')
@section('title', 'Dashboard Creator')
@section('heading', 'Dashboard Creator')

@section('content')
<div class="space-y-5">

    <div class="flex items-center justify-between gap-3 flex-wrap">
        <p class="text-sm text-stone-500">Setor konten untuk akun resmi SKINKU — admin me-review, lalu portal menerbitkannya ke Facebook, Instagram, Threads &amp; TikTok.</p>
        <a href="{{ route('content.create') }}" class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold shadow-sm">+ Konten Baru</a>
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

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        @foreach($cards as [$label, $value, $bar])
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-[11px] uppercase tracking-wide font-semibold text-stone-500">{{ $label }}</p>
                <p class="text-2xl font-bold text-stone-800 mt-1">{{ number_format($value, 0, ',', '.') }}</p>
                <span class="inline-block mt-2 w-8 h-1 rounded-sm {{ $bar }}"></span>
            </div>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
            <h2 class="text-sm font-bold text-stone-800">Konten terbaru</h2>
            <a href="{{ route('content.index') }}" class="text-xs font-semibold text-red-700 hover:underline">Lihat semua →</a>
        </div>
        @include('content._table', ['posts' => $recent, 'showCreator' => false])
    </div>
</div>
@endsection
