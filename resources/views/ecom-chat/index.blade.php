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

<div class="flex flex-col gap-4 lg:h-[calc(100vh-8.5rem)] lg:flex-row">
    {{-- KIRI: daftar percakapan --}}
    <div id="ecomInbox" class="flex shrink-0 flex-col overflow-hidden rounded-2xl border border-stone-200 bg-brand-cream lg:h-full lg:w-[22rem] xl:w-96">
        {{-- Toolbar + tab + daftar SEMUA di dalam #ecomList: sekali swap AJAX (ganti
             tab ATAU channel) semuanya ikut ter-render ulang, tanpa reload halaman. --}}
        <div id="ecomList" class="flex-1 flex flex-col min-h-0 overflow-hidden transition-opacity">
            @include('ecom-chat._list')
        </div>
    </div>

    {{-- KANAN: panel chat (di-load AJAX) --}}
    <div id="ecomChatPane" class="flex min-h-96 flex-1 items-center justify-center overflow-hidden rounded-2xl border border-stone-200 bg-brand-cream p-6 text-center text-sm text-stone-400 lg:h-full lg:min-w-0">
        <div class="max-w-xs"><span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-cream text-brand-maroon"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5A2.5 2.5 0 0 1 6.5 3h7A2.5 2.5 0 0 1 16 5.5v4A2.5 2.5 0 0 1 13.5 12H9l-4.5 3v-3.4A2.5 2.5 0 0 1 4 10V5.5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 7.5h.01M11 7.5h.01m4.5 5H20a1 1 0 0 1 1 1v6h-6v-6a1 1 0 0 1 1-1Zm1-1v-.75a2 2 0 1 1 4 0v.75"/></svg></span><p class="mt-3 font-semibold text-stone-700">Pilih percakapan</p><p class="mt-1 text-xs leading-relaxed text-stone-500">Pilih chat dari daftar untuk melihat pesan dan membalas pembeli.</p></div>
    </div>
</div>

<script>
(function () {
    var pane = document.getElementById('ecomChatPane');
    if (!pane) return;
    var inbox = document.getElementById('ecomInbox');
    var activeItem = null;
    var activeConvId = null;
    var currentTab = '{{ $tab }}';
    var badgeMap = {
        needs_staff: ['Perlu staf', 'bg-amber-100 text-amber-800'],
        open: ['Baru', 'bg-sky-100 text-sky-800'],
        replied_ai: ['Dibalas AI', 'bg-violet-100 text-violet-800'],
        replied_staff: ['Dibalas staf', 'bg-emerald-100 text-emerald-800'],
        closed: ['Selesai', 'bg-stone-100 text-stone-600'],
    };

    function marker() { return pane.querySelector('[data-thread-status]'); }
    function syncMobileLayout() {
        if (inbox) inbox.classList.toggle('hidden', Boolean(marker()) && window.matchMedia('(max-width: 1023px)').matches);
    }
    window.addEventListener('resize', syncMobileLayout);

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
            badge.className = 'text-[10px] font-bold px-2 py-1 rounded-full whitespace-nowrap ' + m[1];
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
        pane.className = 'flex-1 min-w-0 bg-brand-cream border border-stone-200 rounded-2xl overflow-hidden h-[80vh] min-h-96 lg:h-full';
    }

    // Kembalikan panel kanan ke keadaan kosong (dipakai saat ganti channel: chat yang
    // sedang kebuka milik channel lama, jadi jangan dibiarkan nyangkut di kanan).
    function emptyMode() {
        pane.className = 'flex flex-1 items-center justify-center overflow-hidden rounded-2xl border border-stone-200 bg-brand-cream p-6 text-center text-sm text-stone-400 lg:h-full lg:min-w-0 min-h-96';
        pane.innerHTML = '<div class="max-w-xs"><span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-cream text-brand-maroon"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5A2.5 2.5 0 0 1 6.5 3h7A2.5 2.5 0 0 1 16 5.5v4A2.5 2.5 0 0 1 13.5 12H9l-4.5 3v-3.4A2.5 2.5 0 0 1 4 10V5.5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 7.5h.01M11 7.5h.01m4.5 5H20a1 1 0 0 1 1 1v6h-6v-6a1 1 0 0 1 1-1Zm1-1v-.75a2 2 0 1 1 4 0v.75"/></svg></span><p class="mt-3 font-semibold text-stone-700">Pilih percakapan</p><p class="mt-1 text-xs leading-relaxed text-stone-500">Pilih chat dari daftar untuk melihat pesan dan membalas pembeli.</p></div>';
    }

    window.ecomOpen = function (el) {
        var id = el.getAttribute('data-conv-id');
        activeItem = el;
        activeConvId = id;
        document.querySelectorAll('[data-conv-id]').forEach(function (x) { x.classList.remove('is-active', 'border-brand-maroon', 'bg-white', 'shadow-sm'); x.classList.add('border-transparent'); x.setAttribute('aria-pressed', 'false'); });
        el.classList.add('is-active');
        el.classList.remove('border-transparent');
        el.classList.add('border-brand-maroon', 'bg-white', 'shadow-sm');
        el.setAttribute('aria-pressed', 'true');
        threadMode();
        pane.innerHTML = '<div class="w-full text-center text-stone-400 text-sm py-10">Memuat…</div>';
        fetch('{{ url('/ecom-chat') }}/' + id + '/thread', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (html === null) { pane.innerHTML = '<div class="w-full text-center text-rose-500 text-sm py-10">Gagal memuat.</div>'; return; }
                pane.innerHTML = html;
                syncMobileLayout();
                scrollThread();
                syncMeta();
                // Di HP thread muncul DI BAWAH daftar — bawa ke layar biar langsung
                // kelihatan (termasuk kotak balasnya). Di desktop tak perlu (2 kolom).
                if (window.matchMedia('(max-width: 1023px)').matches) {
                    var back = pane.querySelector('[data-inbox-back]');
                    if (back) back.focus({ preventScroll: true });
                    pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            })
            .catch(function () { pane.innerHTML = '<div class="w-full text-center text-rose-500 text-sm py-10">Gagal memuat.</div>'; });
    };

    // Kembali ke inbox di ponsel tanpa mengubah status percakapan.
    pane.addEventListener('click', function (e) {
        var back = e.target.closest('[data-inbox-back]');
        if (!back) return;
        e.preventDefault();
        if (inbox) inbox.classList.remove('hidden');
        if (activeItem) {
            activeItem.classList.remove('is-active', 'border-brand-maroon', 'bg-white', 'bg-brand-cream', 'shadow-sm');
            activeItem.classList.add('border-transparent');
            activeItem.setAttribute('aria-pressed', 'false');
        }
        activeItem = null;
        activeConvId = null;
        emptyMode();
        if (window.matchMedia('(max-width: 1023px)').matches && inbox) {
            inbox.scrollIntoView({ behavior: 'smooth', block: 'start' });
            var firstConversation = inbox.querySelector('[data-conv-id]');
            if (firstConversation) firstConversation.focus({ preventScroll: true });
        }
    });

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
                syncMobileLayout();
                scrollThread();
                syncMeta();
                maybeRemoveActive();
            })
            .catch(function (err) {
                if (btn) btn.disabled = false;
                alert(err && err.message ? err.message : 'Gagal, coba lagi.');
            });
    });

    // Ganti tab filter ATAU channel (TikTok/Shopee) TANPA reload — ambil daftar dari
    // server, swap isinya. Toolbar + tab channel ikut di partial jadi ikut ter-update.
    var listWrap = document.getElementById('ecomList');
    if (listWrap) {
        listWrap.addEventListener('click', function (e) {
            var navLink = e.target.closest('a[data-tab], a[data-channel]');
            if (!navLink) return;
            e.preventDefault();
            var isChannelSwitch = navLink.hasAttribute('data-channel');
            var url = navLink.getAttribute('href');
            listWrap.style.opacity = '0.5';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    listWrap.style.opacity = '';
                    if (html === null) return;
                    listWrap.innerHTML = html;
                    try { currentTab = new URL(url, location.origin).searchParams.get('tab') || 'perlu'; } catch (e2) {}
                    try { history.replaceState(null, '', url); } catch (e3) {}
                    if (isChannelSwitch) {
                        // Chat yang kebuka milik channel lama → kosongkan panel kanan.
                        activeItem = null;
                        activeConvId = null;
                        emptyMode();
                        return;
                    }
                    // Sorot ulang percakapan yang sedang dibuka bila masih ada di tab ini.
                    activeItem = activeConvId ? listWrap.querySelector('[data-conv-id="' + activeConvId + '"]') : null;
                    if (activeItem) {
                        activeItem.classList.remove('border-transparent');
                        activeItem.classList.add('is-active', 'border-brand-maroon', 'bg-white', 'shadow-sm');
                        activeItem.setAttribute('aria-pressed', 'true');
                    }
                })
                .catch(function () { listWrap.style.opacity = ''; });
        });
    }
})();
</script>
@endsection
