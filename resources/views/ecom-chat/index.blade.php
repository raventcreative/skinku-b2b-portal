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

@if(session('status'))
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-3">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-xl px-4 py-2 mb-3">{{ session('error') }}</div>
@endif

<div class="flex flex-col lg:flex-row gap-4 lg:h-[calc(100vh-8.5rem)]">
    {{-- KIRI: daftar percakapan --}}
    <div class="lg:w-80 shrink-0 flex flex-col bg-white border border-stone-200 rounded-2xl overflow-hidden lg:h-full">
        <div class="p-3 border-b border-stone-200 space-y-2 shrink-0">
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('ecom-chat.sync') }}" class="flex-1" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Menarik…';">
                    @csrf
                    <button class="w-full px-3 py-2 text-xs font-semibold rounded-lg bg-stone-800 text-white hover:bg-stone-900">🔄 Tarik chat dari TikTok</button>
                </form>
                <form method="POST" action="{{ route('ecom-chat.autosend') }}">
                    @csrf
                    <input type="hidden" name="on" value="{{ $autosend ? '0' : '1' }}">
                    <button class="px-3 py-2 text-xs font-semibold rounded-lg whitespace-nowrap {{ $autosend ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-700' }}" title="Auto-send balasan AI">
                        {{ $autosend ? 'Auto: ON' : 'Auto: OFF' }}
                    </button>
                </form>
            </div>
            <div class="flex gap-1">
                <a href="{{ route('ecom-chat.index', ['tab' => 'perlu']) }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg flex items-center gap-1 {{ $tab === 'perlu' ? 'bg-red-600 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }}">
                    Perlu dibalas
                    @if($perluCount > 0)<span class="px-1.5 rounded-full text-[10px] {{ $tab === 'perlu' ? 'bg-white/25' : 'bg-red-100 text-red-700' }}">{{ $perluCount }}</span>@endif
                </a>
                <a href="{{ route('ecom-chat.index', ['tab' => 'semua']) }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg {{ $tab === 'semua' ? 'bg-red-600 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }}">Semua</a>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto divide-y divide-stone-100 min-h-0">
            @forelse($conversations as $c)
                @php([$label, $cls] = $badge[$c->status] ?? ['—', 'bg-stone-100 text-stone-600'])
                @php($nama = $c->buyer_name ?: 'Pembeli TikTok')
                <button type="button" data-conv-id="{{ $c->id }}" onclick="ecomOpen(this)" class="w-full text-left flex items-center gap-3 p-3 hover:bg-stone-50">
                    <span class="shrink-0 w-9 h-9 rounded-full bg-gradient-to-br from-red-500 to-rose-600 text-white flex items-center justify-center text-xs font-bold uppercase">{{ mb_substr($nama, 0, 1) }}</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-stone-800 truncate">{{ $nama }}</p>
                        <p class="text-xs text-stone-500 truncate">{{ $c->last_message_preview ?: '—' }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full {{ $cls }} whitespace-nowrap">{{ $label }}</span>
                        <span class="text-[9px] text-stone-400 whitespace-nowrap">{{ optional($c->last_message_at)->diffForHumans(null, true) }}</span>
                    </div>
                </button>
            @empty
                <p class="p-6 text-sm text-stone-400 text-center">
                    @if($tab === 'perlu')
                        Tak ada chat yang perlu dibalas 🎉 <a href="{{ route('ecom-chat.index', ['tab' => 'semua']) }}" class="text-red-600 underline">Lihat semua</a>
                    @else
                        Belum ada percakapan. Klik <b>🔄 Tarik chat dari TikTok</b>.
                    @endif
                </p>
            @endforelse
        </div>
    </div>

    {{-- KANAN: panel chat (di-load AJAX) --}}
    <div id="ecomChatPane" class="flex-1 min-w-0 bg-white border border-stone-200 rounded-2xl overflow-hidden lg:h-full min-h-[24rem] flex items-center justify-center text-sm text-stone-400 p-6 text-center">
        Pilih percakapan di kiri untuk membuka chat.
    </div>
</div>

<script>
(function () {
    var pane = document.getElementById('ecomChatPane');
    if (!pane) return;

    function scrollThread() {
        var t = document.getElementById('threadMessages');
        if (t) t.scrollTop = t.scrollHeight;
    }

    window.ecomOpen = function (el) {
        var id = el.getAttribute('data-conv-id');
        document.querySelectorAll('[data-conv-id]').forEach(function (x) { x.classList.remove('bg-stone-100'); });
        el.classList.add('bg-stone-100');
        pane.innerHTML = '<div class="w-full text-center text-stone-400 text-sm py-10">Memuat…</div>';
        fetch('{{ url('/ecom-chat') }}/' + id + '/thread', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (html === null) { pane.innerHTML = '<div class="w-full text-center text-rose-500 text-sm py-10">Gagal memuat.</div>'; return; }
                pane.innerHTML = html;
                scrollThread();
            })
            .catch(function () { pane.innerHTML = '<div class="w-full text-center text-rose-500 text-sm py-10">Gagal memuat.</div>'; });
    };

    // Kirim balasan / buat ulang draft TANPA reload — form di-inject ulang tiap buka chat.
    pane.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-send], form[data-redraft]');
        if (!form) return;
        e.preventDefault();
        var btn = form.querySelector('button');
        if (btn) btn.disabled = true;
        fetch(form.getAttribute('action'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (html === null) { if (btn) btn.disabled = false; return; }
                pane.innerHTML = html;
                scrollThread();
            })
            .catch(function () { if (btn) btn.disabled = false; });
    });
})();
</script>
@endsection
