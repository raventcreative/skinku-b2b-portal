@extends('layouts.app')
@section('title', 'Chat E-commerce')
@section('heading', 'Chat E-commerce')

@section('content')
@php
    // Badge status; untuk "replied" bedakan sumber balasan: AI vs staf.
    $badgeFor = function ($status, $via) {
        if ($status === 'replied') {
            return $via === 'ai'
                ? ['🤖 Dibalas AI', 'bg-violet-100 text-violet-800']
                : ['Dibalas staf', 'bg-emerald-100 text-emerald-800'];
        }

        return [
            'needs_staff' => ['Perlu staf', 'bg-amber-100 text-amber-800'],
            'open' => ['Baru', 'bg-sky-100 text-sky-800'],
            'closed' => ['Selesai', 'bg-stone-100 text-stone-600'],
        ][$status] ?? ['—', 'bg-stone-100 text-stone-600'];
    };
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
            @php($tabs = [
                'perlu' => ['Perlu dibalas', $perluCount],
                'belum_dibaca' => ['Belum dibaca', $unreadCount],
                'ditandai' => ['⭐ Ditandai', $flaggedCount],
                'terbalas' => ['Terbalas', null],
                'ditutup' => ['Ditutup', null],
                'semua' => ['Semua', null],
            ])
            <div class="flex flex-wrap gap-1">
                @foreach($tabs as $key => [$tlabel, $tcount])
                    <a href="{{ route('ecom-chat.index', ['tab' => $key]) }}" class="px-2.5 py-1.5 text-xs font-semibold rounded-lg flex items-center gap-1 {{ $tab === $key ? 'bg-red-600 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }}">
                        {{ $tlabel }}
                        @if($tcount)<span class="px-1.5 rounded-full text-[10px] {{ $tab === $key ? 'bg-white/25' : 'bg-red-100 text-red-700' }}">{{ $tcount }}</span>@endif
                    </a>
                @endforeach
            </div>
        </div>

        <div class="flex-1 overflow-y-auto divide-y divide-stone-100 min-h-0">
            @forelse($conversations as $c)
                @php([$label, $cls] = $badgeFor($c->status, $c->last_reply_via))
                @php($nama = $c->buyer_name ?: 'Pembeli TikTok')
                <button type="button" data-conv-id="{{ $c->id }}" onclick="ecomOpen(this)" class="w-full text-left flex items-center gap-3 p-3 hover:bg-stone-50">
                    <span class="shrink-0 w-9 h-9 rounded-full bg-gradient-to-br from-red-500 to-rose-600 text-white flex items-center justify-center text-xs font-bold uppercase">{{ mb_substr($nama, 0, 1) }}</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-stone-800 truncate">
                            <span data-conv-flag class="text-amber-500 {{ $c->flagged ? '' : 'hidden' }}">★</span>{{ $nama }}
                        </p>
                        <p class="text-xs text-stone-500 truncate">{{ $c->last_message_preview ?: '—' }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                        <span data-conv-badge class="text-[9px] font-bold px-1.5 py-0.5 rounded-full {{ $cls }} whitespace-nowrap">{{ $label }}</span>
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
    var activeItem = null;
    var currentTab = '{{ $tab }}';
    var badgeMap = {
        needs_staff: ['Perlu staf', 'bg-amber-100 text-amber-800'],
        open: ['Baru', 'bg-sky-100 text-sky-800'],
        replied_ai: ['🤖 Dibalas AI', 'bg-violet-100 text-violet-800'],
        replied_staff: ['Dibalas staf', 'bg-emerald-100 text-emerald-800'],
        closed: ['Selesai', 'bg-stone-100 text-stone-600'],
    };

    function marker() { return pane.querySelector('[data-thread-status]'); }

    // Sinkronkan tag + bintang "Ditandai" di daftar kiri dgn kondisi terbaru thread.
    function syncMeta() {
        var st = marker();
        if (!st || !activeItem) return;
        var status = st.getAttribute('data-thread-status');
        var via = st.getAttribute('data-thread-via');
        var key = status === 'replied' ? ('replied_' + (via === 'ai' ? 'ai' : 'staff')) : status;
        var m = badgeMap[key];
        var badge = activeItem.querySelector('[data-conv-badge]');
        if (m && badge) {
            badge.textContent = m[0];
            badge.className = 'text-[9px] font-bold px-1.5 py-0.5 rounded-full whitespace-nowrap ' + m[1];
        }
        var star = activeItem.querySelector('[data-conv-flag]');
        if (star) star.classList.toggle('hidden', st.getAttribute('data-thread-flagged') !== '1');
    }

    // Setelah aksi, buang chat dari daftar bila tak lagi cocok dgn tab aktif
    // (mis. sudah dibalas → keluar dari "Perlu dibalas"/"Belum dibaca").
    function maybeRemoveActive() {
        var st = marker();
        if (!st || !activeItem) return;
        var status = st.getAttribute('data-thread-status');
        var flagged = st.getAttribute('data-thread-flagged') === '1';
        var remove = false;
        if (currentTab === 'perlu' || currentTab === 'belum_dibaca') remove = (status === 'replied' || status === 'closed');
        else if (currentTab === 'ditandai') remove = !flagged;
        else if (currentTab === 'terbalas') remove = (status !== 'replied');
        else if (currentTab === 'ditutup') remove = (status !== 'closed');
        if (remove) { activeItem.remove(); activeItem = null; }
    }

    function scrollThread() {
        var t = document.getElementById('threadMessages');
        if (t) t.scrollTop = t.scrollHeight;
    }

    // Lepas kelas empty-state agar thread MENGISI panel & rata kiri (bukan center).
    function threadMode() {
        pane.className = 'flex-1 min-w-0 bg-white border border-stone-200 rounded-2xl overflow-hidden lg:h-full min-h-[24rem]';
    }

    window.ecomOpen = function (el) {
        var id = el.getAttribute('data-conv-id');
        activeItem = el;
        document.querySelectorAll('[data-conv-id]').forEach(function (x) { x.classList.remove('bg-stone-100'); });
        el.classList.add('bg-stone-100');
        threadMode();
        pane.innerHTML = '<div class="w-full text-center text-stone-400 text-sm py-10">Memuat…</div>';
        fetch('{{ url('/ecom-chat') }}/' + id + '/thread', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (html === null) { pane.innerHTML = '<div class="w-full text-center text-rose-500 text-sm py-10">Gagal memuat.</div>'; return; }
                pane.innerHTML = html;
                scrollThread();
                syncMeta();
            })
            .catch(function () { pane.innerHTML = '<div class="w-full text-center text-rose-500 text-sm py-10">Gagal memuat.</div>'; });
    };

    // Enter = kirim, Shift+Enter = baris baru (khusus textarea balasan).
    pane.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey) return;
        var ta = e.target;
        if (!ta || ta.tagName !== 'TEXTAREA' || ta.getAttribute('name') !== 'text') return;
        var form = ta.closest('form[data-send]');
        if (!form) return;
        e.preventDefault();
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });

    // Kirim/redraft/tutup/tandai TANPA reload; lalu sinkron daftar & buang bila perlu.
    pane.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-send], form[data-redraft], form[data-close], form[data-flag]');
        if (!form) return;
        e.preventDefault();
        var btn = form.querySelector('button');
        if (btn) btn.disabled = true;
        fetch(form.getAttribute('action'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
            .then(function (r) {
                // Jangan gagal senyap — tampilkan alasan bila server menolak (mis. error API TikTok).
                if (!r.ok) { return r.text().then(function (t) { throw new Error(t ? t.slice(0, 400) : ('HTTP ' + r.status)); }); }
                return r.text();
            })
            .then(function (html) {
                pane.innerHTML = html;
                scrollThread();
                syncMeta();
                maybeRemoveActive();
            })
            .catch(function (err) {
                if (btn) btn.disabled = false;
                alert(err && err.message ? err.message : 'Gagal, coba lagi.');
            });
    });
})();
</script>
@endsection
