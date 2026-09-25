@extends('layouts.app')
@section('title', $mine ? 'Konten Saya' : 'Review Konten')
@section('heading', $mine ? 'Konten Saya' : 'Review Konten')

@section('content')
@php $statuses = \App\Models\ContentPost::STATUS_LABELS; @endphp
<div class="space-y-4">

    @if($mine)
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex flex-wrap gap-1 text-xs">
                <a href="{{ route('content.index') }}" class="px-3 py-1.5 rounded-lg border {{ request('status') ? 'border-stone-200 text-stone-600' : 'border-red-600 bg-red-600 text-white' }}">Semua</a>
                @foreach($statuses as $key => $label)
                    <a href="{{ route('content.index', ['status' => $key]) }}" class="px-3 py-1.5 rounded-lg border {{ request('status') === $key ? 'border-red-600 bg-red-600 text-white' : 'border-stone-200 text-stone-600 hover:bg-stone-50' }}">{{ $label }}</a>
                @endforeach
            </div>
            <a href="{{ route('content.create') }}" class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold shadow-sm">+ Konten Baru</a>
        </div>
    @else
        @if($manualCount || $failedCount)
            <div class="bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 text-sm text-amber-900">
                @if($manualCount)<b>{{ $manualCount }}</b> postingan menunggu diposting manual (TikTok). @endif
                @if($failedCount)<b>{{ $failedCount }}</b> postingan gagal terbit — buka kontennya untuk retry. @endif
                <a href="{{ route('content-review.index', ['status' => 'all']) }}" class="font-semibold underline">Lihat semua</a>
            </div>
        @endif
        <form method="GET" class="bg-white rounded-2xl border border-stone-200 p-4 flex flex-wrap items-end gap-3 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold text-stone-500 uppercase">Status</span>
                <select name="status" class="px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="all" @selected($status === 'all')>Semua</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold text-stone-500 uppercase">Creator</span>
                <select name="creator" class="px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="">Semua</option>
                    @foreach($creators as $c)
                        <option value="{{ $c->id }}" @selected((string) request('creator') === (string) $c->id)>{{ $c->displayName() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold text-stone-500 uppercase">Platform</span>
                <select name="platform" class="px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="">Semua</option>
                    @foreach(config('content.platforms') as $key => $cfg)
                        <option value="{{ $key }}" @selected(request('platform') === $key)>{{ $cfg['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold text-stone-500 uppercase">Dari</span>
                <input type="date" name="dari" value="{{ request('dari') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold text-stone-500 uppercase">Sampai</span>
                <input type="date" name="sampai" value="{{ request('sampai') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
            </label>
            <button class="px-4 py-2 bg-stone-800 text-white rounded-lg font-semibold">Filter</button>
        </form>
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        @include('content._table', ['posts' => $posts, 'showCreator' => ! $mine])
    </div>
    {{ $posts->links() }}
</div>
@endsection
