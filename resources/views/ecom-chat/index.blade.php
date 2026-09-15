@extends('layouts.app')
@section('title', 'Chat E-commerce')
@section('heading', 'Chat E-commerce')

@section('content')
@php
    $badge = [
        'needs_staff' => ['Perlu staf', 'bg-amber-100 text-amber-800'],
        'replied' => ['Terbalas', 'bg-emerald-100 text-emerald-800'],
        'open' => ['Baru', 'bg-sky-100 text-sky-800'],
        'closed' => ['Selesai', 'bg-stone-100 text-stone-600'],
    ];
@endphp
<div class="max-w-3xl">
    @if(session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-4">{{ session('status') }}</div>
    @endif

    <div class="flex items-center justify-between bg-white border border-stone-200 rounded-2xl p-4 mb-4">
        <div>
            <p class="text-sm font-bold text-stone-800">Auto-send balasan AI</p>
            <p class="text-[11px] text-stone-500">Saat MATI, AI tetap buat draft — kamu yang kirim. Nyalakan kalau sudah percaya kualitasnya.</p>
        </div>
        <form method="POST" action="{{ route('ecom-chat.autosend') }}">
            @csrf
            <input type="hidden" name="on" value="{{ $autosend ? '0' : '1' }}">
            <button class="px-4 py-2 text-sm font-semibold rounded-xl {{ $autosend ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-700' }}">
                {{ $autosend ? 'Auto-send: NYALA' : 'Auto-send: MATI' }}
            </button>
        </form>
    </div>

    <div class="bg-white border border-stone-200 rounded-2xl divide-y divide-stone-100">
        @forelse($conversations as $c)
            @php([$label, $cls] = $badge[$c->status] ?? ['—', 'bg-stone-100 text-stone-600'])
            <a href="{{ route('ecom-chat.show', $c) }}" class="flex items-center gap-3 p-4 hover:bg-stone-50">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-stone-800 truncate">{{ $c->buyer_name ?? 'Pembeli TikTok' }}</p>
                    <p class="text-xs text-stone-500 truncate">{{ $c->last_message_preview }}</p>
                </div>
                <span class="text-[10px] font-bold px-2 py-1 rounded-full {{ $cls }}">{{ $label }}</span>
                <span class="text-[10px] text-stone-400 whitespace-nowrap">{{ optional($c->last_message_at)->diffForHumans() }}</span>
            </a>
        @empty
            <p class="p-6 text-sm text-stone-400 text-center">Belum ada percakapan masuk.</p>
        @endforelse
    </div>
</div>
@endsection
