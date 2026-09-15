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

    @if(session('error'))
        <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-xl px-4 py-2 mb-4">{{ session('error') }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-stone-200 rounded-2xl p-4 mb-4">
        <div>
            <p class="text-sm font-bold text-stone-800">Auto-send balasan AI</p>
            <p class="text-[11px] text-stone-500">Saat MATI, AI tetap buat draft — kamu yang kirim. Nyalakan kalau sudah percaya kualitasnya.</p>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('ecom-chat.sync') }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Menarik…';">
                @csrf
                <button class="px-4 py-2 text-sm font-semibold rounded-xl bg-stone-800 text-white hover:bg-stone-900">🔄 Tarik chat dari TikTok</button>
            </form>
            <form method="POST" action="{{ route('ecom-chat.autosend') }}">
                @csrf
                <input type="hidden" name="on" value="{{ $autosend ? '0' : '1' }}">
                <button class="px-4 py-2 text-sm font-semibold rounded-xl {{ $autosend ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-700' }}">
                    {{ $autosend ? 'Auto-send: NYALA' : 'Auto-send: MATI' }}
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white border border-stone-200 rounded-2xl divide-y divide-stone-100">
        @forelse($conversations as $c)
            @php([$label, $cls] = $badge[$c->status] ?? ['—', 'bg-stone-100 text-stone-600'])
            @php($nama = $c->buyer_name ?: 'Pembeli TikTok')
            <a href="{{ route('ecom-chat.show', $c) }}" class="flex items-center gap-3 p-4 hover:bg-stone-50">
                <span class="shrink-0 w-10 h-10 rounded-full bg-gradient-to-br from-red-500 to-rose-600 text-white flex items-center justify-center text-sm font-bold uppercase">{{ mb_substr($nama, 0, 1) }}</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-stone-800 truncate">{{ $nama }}</p>
                    <p class="text-xs text-stone-500 truncate">{{ $c->last_message_preview ?: '—' }}</p>
                </div>
                <span class="text-[10px] font-bold px-2 py-1 rounded-full {{ $cls }} whitespace-nowrap">{{ $label }}</span>
                <span class="text-[10px] text-stone-400 whitespace-nowrap">{{ optional($c->last_message_at)->diffForHumans() }}</span>
            </a>
        @empty
            <p class="p-6 text-sm text-stone-400 text-center">Belum ada percakapan. Klik <b>Tarik chat dari TikTok</b> untuk mengambil percakapan yang sudah ada.</p>
        @endforelse
    </div>
</div>
@endsection
