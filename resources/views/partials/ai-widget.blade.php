{{-- Widget Asisten AI mengambang (pojok kanan-bawah, semua halaman). Chat via
     fetch tanpa reload; aksi tulis tetap lewat konfirmasi. Hanya untuk pemegang
     izin use_ai_assistant. --}}
<div id="aiWidget" class="fixed bottom-4 right-4 z-50 flex flex-col items-end gap-2 print:hidden">

    {{-- Panel chat --}}
    <div id="aiPanel" class="hidden w-[92vw] max-w-sm h-[70vh] max-h-[32rem] bg-white rounded-2xl shadow-2xl border border-stone-200 flex-col overflow-hidden">
        <div class="px-4 py-3 bg-red-700 text-white flex items-center justify-between shrink-0">
            <div class="min-w-0">
                <p class="text-sm font-bold leading-tight">Asisten AI</p>
                <p class="text-[10px] text-red-200">Baca dashboard & bantu tugas Kanban</p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <button id="aiReset" title="Mulai baru" class="p-1.5 hover:bg-red-800 rounded-lg" aria-label="Mulai baru">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M20 9A8 8 0 006.3 5.3M4 15a8 8 0 0013.7 3.7"/></svg>
                </button>
                <button id="aiClose" title="Tutup" class="p-1.5 hover:bg-red-800 rounded-lg" aria-label="Tutup">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div id="aiBody" class="flex-1 overflow-y-auto p-3 space-y-2 bg-stone-50 text-sm"></div>
        <form id="aiForm" class="p-2 border-t border-stone-200 flex items-end gap-2 shrink-0">
            <textarea id="aiInput" rows="1" maxlength="2000" placeholder="Tulis pertanyaan atau perintah…"
                class="flex-1 px-3 py-2 border border-stone-300 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-red-200 max-h-24"></textarea>
            <button type="submit" id="aiSend" class="w-9 h-9 shrink-0 bg-red-600 text-white rounded-xl hover:bg-red-700 flex items-center justify-center disabled:opacity-50" aria-label="Kirim">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 5l7 7-7 7"/></svg>
            </button>
        </form>
    </div>

    {{-- Nudge kecil (sekali, bisa ditutup) --}}
    <div id="aiNudge" class="hidden items-center gap-2 bg-white rounded-full shadow-lg border border-stone-200 pl-3 pr-1.5 py-1.5">
        <span class="text-xs text-stone-600 whitespace-nowrap">Butuh bantuan? Tanya aku 👋</span>
        <button id="aiNudgeX" class="w-5 h-5 flex items-center justify-center text-stone-400 hover:text-stone-700 rounded-full" aria-label="Tutup">✕</button>
    </div>

    {{-- Launcher bulat --}}
    <button id="aiLauncher" class="w-14 h-14 rounded-full bg-red-700 hover:bg-red-800 text-white shadow-xl flex items-center justify-center transition" aria-label="Buka Asisten AI">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12a8 8 0 01-11.6 7.1L3 21l1.9-6.4A8 8 0 1121 12z"/></svg>
    </button>
</div>

<script>
(function () {
    var R = {
        state: {!! \Illuminate\Support\Js::from(route('ai.state')) !!},
        send: {!! \Illuminate\Support\Js::from(route('ai.send')) !!},
        confirm: {!! \Illuminate\Support\Js::from(route('ai.confirm')) !!},
        reset: {!! \Illuminate\Support\Js::from(route('ai.reset')) !!},
    };
    var panel = document.getElementById('aiPanel'),
        launcher = document.getElementById('aiLauncher'),
        nudge = document.getElementById('aiNudge'),
        body = document.getElementById('aiBody'),
        form = document.getElementById('aiForm'),
        input = document.getElementById('aiInput'),
        sendBtn = document.getElementById('aiSend');
    var loaded = false, busy = false;

    function csrf() { return window.CSRF || (document.querySelector('meta[name=csrf-token]') || {}).content; }

    function req(url, method, data) {
        var opt = { method: method, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (method === 'POST') {
            opt.headers['X-CSRF-TOKEN'] = csrf();
            opt.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            opt.body = new URLSearchParams(data || {}).toString();
        }
        return fetch(url, opt).then(function (r) { return r.json(); });
    }

    function bubble(role, text) {
        var wrap = document.createElement('div');
        wrap.className = role === 'user' ? 'flex justify-end' : 'flex justify-start';
        var b = document.createElement('div');
        b.className = (role === 'user'
            ? 'bg-red-600 text-white rounded-br-sm'
            : 'bg-white border border-stone-200 text-stone-800 rounded-bl-sm')
            + ' max-w-[85%] px-3 py-2 rounded-2xl text-sm whitespace-pre-wrap break-words';
        b.textContent = text;            // textContent = aman dari XSS
        wrap.appendChild(b);
        return wrap;
    }

    function render(state) {
        state = state || { thread: [], pending: null };
        body.innerHTML = '';
        if (!state.thread || !state.thread.length) {
            var e = document.createElement('div');
            e.className = 'text-center text-stone-400 text-xs py-8 px-2';
            e.textContent = 'Halo! 👋 Coba: “ringkas penjualan bulan ini”, atau “buatkan kartu Kanban … di papan … kolom …”.';
            body.appendChild(e);
        } else {
            state.thread.forEach(function (m) { body.appendChild(bubble(m.role, m.content)); });
        }
        if (state.pending) {
            var c = document.createElement('div');
            c.className = 'rounded-xl bg-amber-50 border border-amber-200 p-3';
            var p = document.createElement('p'); p.className = 'text-[11px] font-semibold text-amber-800 mb-1'; p.textContent = 'Konfirmasi aksi';
            var t = document.createElement('p'); t.className = 'text-sm text-stone-700 whitespace-pre-wrap break-words'; t.textContent = state.pending.preview;
            var row = document.createElement('div'); row.className = 'flex gap-2 mt-2';
            var ya = document.createElement('button'); ya.className = 'px-3 py-1.5 text-xs bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700'; ya.textContent = 'Ya, jalankan';
            var no = document.createElement('button'); no.className = 'px-3 py-1.5 text-xs text-stone-500 hover:text-stone-800'; no.textContent = 'Batal';
            ya.onclick = function () { decide('ya'); }; no.onclick = function () { decide('batal'); };
            row.appendChild(ya); row.appendChild(no); c.appendChild(p); c.appendChild(t); c.appendChild(row);
            body.appendChild(c);
            input.disabled = true; input.placeholder = 'Selesaikan konfirmasi dulu…';
        } else {
            input.disabled = false; input.placeholder = 'Tulis pertanyaan atau perintah…';
        }
        body.scrollTop = body.scrollHeight;
    }

    function typing(on) {
        busy = on; sendBtn.disabled = on;
        var t = document.getElementById('aiTyping');
        if (on && !t) {
            t = document.createElement('div'); t.id = 'aiTyping'; t.className = 'flex justify-start';
            t.innerHTML = '<div class="bg-white border border-stone-200 text-stone-400 px-3 py-2 rounded-2xl rounded-bl-sm text-sm">mengetik…</div>';
            body.appendChild(t); body.scrollTop = body.scrollHeight;
        } else if (!on && t) { t.remove(); }
    }

    function decide(v) {
        if (busy) return; typing(true);
        req(R.confirm, 'POST', { setuju: v }).then(render).catch(function () {}).finally(function () { typing(false); });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = input.value.trim();
        if (!msg || busy) return;
        input.value = ''; input.style.height = 'auto';
        body.appendChild(bubble('user', msg));    // optimis
        typing(true);
        req(R.send, 'POST', { message: msg }).then(render).catch(function () {
            body.appendChild(bubble('assistant', '⚠️ Gagal menghubungi server. Coba lagi.'));
        }).finally(function () { typing(false); });
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });
    input.addEventListener('input', function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 96) + 'px'; });

    document.getElementById('aiReset').addEventListener('click', function () {
        if (busy) return;
        if (!confirm('Mulai percakapan baru? Riwayat sekarang dihapus.')) return;
        req(R.reset, 'POST').then(render);
    });

    function openPanel() {
        panel.classList.remove('hidden'); panel.classList.add('flex');
        launcher.classList.add('hidden'); hideNudge();
        if (!loaded) { loaded = true; req(R.state, 'GET').then(render).catch(function () { render(); }); }
        setTimeout(function () { input.focus(); }, 50);
    }
    function closePanel() {
        panel.classList.add('hidden'); panel.classList.remove('flex');
        launcher.classList.remove('hidden');
    }
    function hideNudge() { nudge.classList.add('hidden'); nudge.classList.remove('flex'); }

    launcher.addEventListener('click', openPanel);
    document.getElementById('aiClose').addEventListener('click', closePanel);
    document.getElementById('aiNudgeX').addEventListener('click', function () {
        hideNudge(); try { localStorage.setItem('ai_nudge_off', '1'); } catch (e) {}
    });

    // Nudge muncul sekali kalau belum pernah ditutup.
    try {
        if (localStorage.getItem('ai_nudge_off') !== '1') {
            nudge.classList.remove('hidden'); nudge.classList.add('flex');
        }
    } catch (e) {}
})();
</script>
