@extends('layouts.app')
@section('title', 'Chat E-commerce')
@section('heading', 'Chat E-commerce')

@section('content')
@if(session('status'))
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-3">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-xl px-4 py-2 mb-3">{{ session('error') }}</div>
@endif

<div class="flex flex-col lg:flex-row gap-4 lg:h-[calc(100vh-8.5rem)]">
    {{-- KIRI: daftar percakapan --}}
    <div class="lg:w-80 shrink-0 flex flex-col bg-white border border-stone-200 rounded-2xl overflow-hidden lg:h-full">
        <div class="p-3 shrink-0">
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('ecom-chat.sync') }}" class="flex-1" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Menarik…';">
                    @csrf
                    <input type="hidden" name="channel" value="{{ $channel }}">
                    <button class="w-full px-3 py-2 text-xs font-semibold rounded-lg bg-stone-800 text-white hover:bg-stone-900">🔄 Tarik chat {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }}</button>
                </form>
                <form method="POST" action="{{ route('ecom-chat.autosend') }}">
                    @csrf
                    <input type="hidden" name="on" value="{{ $autosend ? '0' : '1' }}">
                    <button class="px-3 py-2 text-xs font-semibold rounded-lg whitespace-nowrap {{ $autosend ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-700' }}" title="Auto-send balasan AI">
                        {{ $autosend ? 'Auto: ON' : 'Auto: OFF' }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Tab + daftar: di-swap AJAX saat ganti filter (tanpa reload halaman). --}}
        <div id="ecomList" class="flex-1 flex flex-col min-h-0 overflow-hidden transition-opacity">
            @include('ecom-chat._list')
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
    var activeConvId = null;
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
    // Di HP (mobile) beri TINGGI pasti (80vh) supaya thread punya rangka tinggi:
    // area pesan bisa scroll sendiri & kotak balas TETAP kelihatan di bawah — tanpa
    // ini, di mobile tak ada tinggi pasti → kotak balas kedorong jauh & tak bisa dibalas.
    function threadMode() {
        pane.className = 'flex-1 min-w-0 bg-white border border-stone-200 rounded-2xl overflow-hidden h-[80vh] min-h-[24rem] lg:h-full';
    }

    window.ecomOpen = function (el) {
        var id = el.getAttribute('data-conv-id');
        activeItem = el;
        activeConvId = id;
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
                // Di HP thread muncul DI BAWAH daftar — bawa ke layar biar langsung
                // kelihatan (termasuk kotak balasnya). Di desktop tak perlu (2 kolom).
                if (window.matchMedia('(max-width: 1023px)').matches) {
                    pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
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

    // Ganti tab filter TANPA reload — ambil daftar terfilter dari server, swap isinya.
    var listWrap = document.getElementById('ecomList');
    if (listWrap) {
        listWrap.addEventListener('click', function (e) {
            var tabLink = e.target.closest('a[data-tab]');
            if (!tabLink) return;
            e.preventDefault();
            var url = tabLink.getAttribute('href');
            listWrap.style.opacity = '0.5';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    listWrap.style.opacity = '';
                    if (html === null) return;
                    listWrap.innerHTML = html;
                    try { currentTab = new URL(url, location.origin).searchParams.get('tab') || 'perlu'; } catch (e2) {}
                    try { history.replaceState(null, '', url); } catch (e3) {}
                    // Sorot ulang percakapan yang sedang dibuka bila masih ada di tab ini.
                    activeItem = activeConvId ? listWrap.querySelector('[data-conv-id="' + activeConvId + '"]') : null;
                    if (activeItem) activeItem.classList.add('bg-stone-100');
                })
                .catch(function () { listWrap.style.opacity = ''; });
        });
    }
})();
</script>
@endsection
