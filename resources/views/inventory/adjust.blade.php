@extends('layouts.app')
@section('title', 'Penyesuaian Stok')
@section('heading', 'Penyesuaian Stok / Adjustment')

@section('content')
<div class="max-w-3xl">
    <a href="{{ route('inventory.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Stok Saya</a>

    <form method="POST" action="{{ route('inventory.adjust.store') }}" class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">@csrf
        <p class="text-sm text-stone-600 mb-4">
            Samakan stok sistem dengan hitungan fisik, atau isi saldo awal. Untuk tiap produk, isi
            <b>stok sebenarnya</b> (bukan selisih). Produk yang tak diisi tidak diubah.
        </p>

        @error('items')
            <p class="mb-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $message }}</p>
        @enderror

        <div class="hidden sm:flex gap-2 text-[10px] uppercase tracking-wide text-stone-400 font-semibold mb-1">
            <span class="flex-1">Produk (ketik untuk cari)</span>
            <span class="w-24 text-right">Stok kini</span>
            <span class="w-28 text-right">Stok sebenarnya</span>
            <span class="w-6"></span>
        </div>
        <div id="rows" class="space-y-2 mb-2"></div>

        <button type="button" onclick="addRow()" class="text-xs text-indigo-600 hover:underline mb-4 inline-block">+ tambah produk</button>

        <div class="mb-4">
            <label class="block text-[11px] font-semibold text-stone-500 mb-1">Alasan (wajib — berlaku untuk semua baris)</label>
            <input name="notes" value="{{ old('notes') }}" required maxlength="500"
                placeholder="mis. hitung fisik 20 Jul / saldo awal"
                class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </div>

        <p class="text-[11px] text-stone-400 mb-3">
            Menyimpan akan <b>menyetel</b> stok tiap produk ke angka yang Anda isi. Baris yang jumlahnya
            sama dengan stok sekarang dilewati. Semua tercatat di riwayat stok &amp; Audit Log.
        </p>

        <button class="px-5 py-2.5 text-sm bg-stone-700 text-white rounded-xl hover:bg-stone-800 font-semibold"
            onclick="return confirm('Setel stok produk-produk ini ke angka yang diisi?')">Simpan Penyesuaian</button>
    </form>
</div>

<script>
const PRODUCTS = @json($products);
let n = 0;

/** Cocok bila SEMUA kata yang diketik ada di nama/SKU — "pink scrub" pun kena. */
function search(q) {
    const words = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return PRODUCTS.slice(0, 8);
    return PRODUCTS.filter(p => {
        const hay = ((p.name || '') + ' ' + (p.sku || '')).toLowerCase();
        return words.every(w => hay.includes(w));
    }).slice(0, 8);
}

function addRow() {
    const i = n++;
    const row = document.createElement('div');
    row.className = 'flex flex-wrap sm:flex-nowrap gap-2 items-start';
    row.setAttribute('data-row', '');
    row.innerHTML = `
        <div class="relative flex-1 min-w-[180px]">
            <input type="text" data-search autocomplete="off" placeholder="ketik nama produk / SKU…"
                class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            <input type="hidden" name="items[${i}][product_id]" data-pid>
            <div data-list class="hidden absolute z-30 left-0 right-0 mt-1 bg-white border border-stone-200 rounded-lg shadow-lg max-h-56 overflow-y-auto"></div>
        </div>
        <span data-now class="w-24 px-2 py-2 text-sm text-right text-stone-400">·</span>
        <input type="number" name="items[${i}][target]" data-target min="0" placeholder="jumlah"
            class="w-28 px-2 py-2 border border-stone-300 rounded-lg text-sm text-right">
        <button type="button" data-del class="w-6 text-stone-300 hover:text-rose-600 text-lg leading-none">×</button>`;
    document.getElementById('rows').appendChild(row);

    const inp = row.querySelector('[data-search]');
    const list = row.querySelector('[data-list]');
    const pid = row.querySelector('[data-pid]');
    const now = row.querySelector('[data-now]');
    const target = row.querySelector('[data-target]');

    const render = () => {
        const hits = search(inp.value);
        list.innerHTML = hits.length
            ? hits.map(p => `<button type="button" class="w-full text-left px-3 py-2 text-xs hover:bg-stone-50" data-id="${p.id}">
                    <span class="font-semibold text-stone-800">${p.name}</span>
                    <span class="text-stone-400">${p.sku ? '· ' + p.sku : ''}</span>
                    <span class="block text-[10px] text-stone-400">stok kini: ${p.current_qty ?? 0}</span>
                </button>`).join('')
            : '<p class="px-3 py-2 text-xs text-stone-400">tidak ada produk cocok</p>';
        list.classList.remove('hidden');
    };

    inp.addEventListener('focus', render);
    inp.addEventListener('input', () => { pid.value = ''; now.textContent = '·'; render(); });
    list.addEventListener('mousedown', e => {
        const btn = e.target.closest('[data-id]');
        if (!btn) return;
        e.preventDefault();
        const p = PRODUCTS.find(x => x.id == btn.dataset.id);
        pid.value = p.id;
        inp.value = p.name + (p.sku ? ' (' + p.sku + ')' : '');
        now.textContent = (p.current_qty ?? 0);
        if (!target.value) target.value = (p.current_qty ?? 0);
        list.classList.add('hidden');
    });
    inp.addEventListener('blur', () => setTimeout(() => list.classList.add('hidden'), 120));

    row.querySelector('[data-del]').addEventListener('click', () => row.remove());
}

addRow();
</script>
@endsection
