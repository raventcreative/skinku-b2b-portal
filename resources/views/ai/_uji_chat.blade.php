{{-- Uji chat pembeli (tab Chat E-commerce): balasan AI dari isian form di kiri (belum disimpan pun), lewat mesin yang
     sama dgn chat TikTok/Shopee sungguhan. Tidak dikirim ke pembeli & tidak disimpan. --}}
<div id="ujiChat" data-url="{{ route('ai.knowledge.test-chat') }}" class="bg-white rounded-2xl border border-stone-200 p-4 space-y-3 sticky top-4">
    <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-bold text-stone-800">💬 Uji chat pembeli</p>
        <button type="button" data-ulang class="text-xs text-stone-500 hover:text-stone-800">↻ Ulang</button>
    </div>
    <p class="text-[11px] text-stone-500 bg-stone-50 rounded-lg px-3 py-2">Memakai isian di kiri, termasuk yang belum disimpan. Tidak dikirim ke pembeli &amp; tidak disimpan.</p>
    <div class="flex flex-wrap gap-1.5">
        @foreach(['Sudah BPOM?', 'Bisa COD?', 'Kapan dikirim?', 'Batalin pesanan saya'] as $q)
            <button type="button" data-cepat="{{ $q }}" class="px-2.5 py-1 text-[11px] border border-stone-300 rounded-full text-stone-600 hover:bg-stone-50">{{ $q }}</button>
        @endforeach
    </div>
    <div data-pesan class="space-y-2 max-h-[60vh] overflow-y-auto">
        <p data-kosong class="text-xs text-stone-400 text-center py-6">Ketik pertanyaan seperti pembeli — balasan AI muncul di sini.</p>
    </div>
    <form data-kirim class="flex items-center gap-2 border-t border-stone-100 pt-3">
        <input type="text" maxlength="2000" placeholder="Ketik sebagai pembeli…" aria-label="Pesan pembeli" class="flex-1 min-w-0 px-3 py-2 text-sm border border-stone-300 rounded-lg">
        <button class="px-3 py-2 text-sm font-semibold border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50">Kirim</button>
    </form>
    <p data-galat class="hidden text-xs text-rose-600"></p>
</div>

@push('scripts')
<script>
(() => {
    const box = document.getElementById('ujiChat');
    const form = document.querySelector('form[data-pengetahuan]');
    if (!box || !form) return;
    const daftar = box.querySelector('[data-pesan]');
    const kosong = box.querySelector('[data-kosong]');
    const input = box.querySelector('[data-kirim] input');
    const galat = box.querySelector('[data-galat]');
    let pesan = [];   // riwayat uji yang dikirim ke AI: {dari: 'pembeli' | 'ai', teks}
    let sibuk = false;

    const tampilGalat = (teks) => { galat.textContent = teks; galat.classList.toggle('hidden', !teks); };
    // Isian form saat ini (belum disimpan pun): content[chat_faq] → {chat_faq: '...'}
    const isian = () => {
        const out = {};
        form.querySelectorAll('textarea[name^="content["]').forEach(t => { out[t.name.slice(8, -1)] = t.value; });
        return out;
    };
    const el = (tag, cls, teks) => { const e = document.createElement(tag); e.className = cls; if (teks !== undefined) e.textContent = teks; return e; };
    const gelembung = (dari, teks, info = {}) => {
        kosong.classList.add('hidden');
        const baris = el('div', dari === 'pembeli' ? 'flex justify-end' : 'max-w-[90%]');
        if (dari === 'pembeli') {
            baris.appendChild(el('div', 'max-w-[85%] bg-sky-50 text-sky-900 rounded-xl px-3 py-2 text-sm whitespace-pre-line break-words', teks));
        } else {
            const keStaf = info.decision === 'to_staff';
            baris.appendChild(el('div', 'rounded-xl px-3 py-2 text-sm whitespace-pre-line break-words ' + (info.menunggu ? 'bg-stone-50 text-stone-400 animate-pulse' : 'bg-stone-100 ' + (keStaf ? 'text-stone-500' : 'text-stone-800')),
                keStaf && teks ? 'Draf: ' + teks : teks));
            if (info.decision) {
                const ket = el('div', 'mt-1 flex flex-wrap items-center gap-1.5');
                ket.appendChild(el('span', 'px-2 py-0.5 rounded-lg text-[11px] font-semibold ' + (keStaf ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700'),
                    keStaf ? '⚠ Diteruskan ke staf' : '✓ Terkirim otomatis'));
                if (info.reason) ket.appendChild(el('span', 'text-[11px] text-stone-500', 'Alasan: ' + info.reason));
                baris.appendChild(ket);
            }
        }
        daftar.appendChild(baris);
        daftar.scrollTop = daftar.scrollHeight;
        return baris;
    };

    const kirim = async (teks) => {
        teks = teks.trim();
        if (!teks) { tampilGalat('Ketik pesan pembeli dulu.'); return; }
        if (sibuk) return;
        tampilGalat('');
        sibuk = true;
        input.value = '';
        pesan.push({dari: 'pembeli', teks});
        const balonPembeli = gelembung('pembeli', teks);
        const tunggu = gelembung('ai', 'AI sedang menyusun balasan…', {menunggu: true});
        try {
            const res = await fetch(box.dataset.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.CSRF},
                body: JSON.stringify({content: isian(), pesan}),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || res.redirected) {
                throw new Error(data.message || (res.status === 429 ? 'Terlalu sering — tunggu sebentar lalu coba lagi.' : 'Gagal menghubungi AI.'));
            }
            tunggu.remove();
            if (data.reply) pesan.push({dari: 'ai', teks: data.reply});
            gelembung('ai', data.reply || '(AI tidak menulis draf)', {decision: data.decision, reason: data.reason});
        } catch (e) {
            // Gagal → pesan pembeli ditarik dari riwayat & dikembalikan ke kotak ketik supaya bisa dikirim ulang.
            tunggu.remove();
            balonPembeli.remove();
            pesan.pop();
            input.value = teks;
            if (!daftar.querySelector('div')) kosong.classList.remove('hidden');
            tampilGalat(e.message);
        } finally {
            sibuk = false;
            input.focus();
        }
    };

    box.querySelector('[data-kirim]').addEventListener('submit', e => { e.preventDefault(); kirim(input.value); });
    input.addEventListener('input', () => tampilGalat(''));
    box.querySelectorAll('[data-cepat]').forEach(b => b.addEventListener('click', () => kirim(b.dataset.cepat)));
    box.querySelector('[data-ulang]').addEventListener('click', () => {
        pesan = [];
        daftar.querySelectorAll(':scope > div').forEach(d => d.remove());
        kosong.classList.remove('hidden');
        tampilGalat('');
    });
})();
</script>
@endpush
