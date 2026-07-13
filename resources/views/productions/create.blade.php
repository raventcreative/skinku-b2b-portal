@extends('layouts.app')
@section('title', 'Catat Produksi')
@section('heading', 'Catat Produksi')

@section('content')
<a href="{{ route('productions.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar</a>

@if($products->isEmpty() || $materials->isEmpty())
    <div class="mt-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
        @if($products->isEmpty())Belum ada produk jadi — buat produk dulu di menu Manajemen Produk. @endif
        @if($materials->isEmpty())Belum ada bahan baku — tambahkan di menu Bahan Baku dulu.@endif
    </div>
@endif

<form method="POST" action="{{ route('productions.store') }}" class="mt-3 space-y-5" id="prodForm">
    @csrf

    {{-- Shared header --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-wrap items-end gap-4 text-sm">
        <div>
            <label class="block text-xs font-semibold mb-1">Tanggal Produksi *</label>
            <input type="date" name="produced_at" value="{{ old('produced_at', date('Y-m-d')) }}" required class="px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <p class="text-xs text-stone-500">Tanggal berlaku untuk semua produk di bawah. Tambahkan beberapa produk sekaligus dengan tombol <b>+ Tambah Produk</b>.</p>
    </div>

    {{-- Product blocks --}}
    <div id="blocks" class="space-y-5"></div>

    <button type="button" onclick="addBlock()" class="w-full py-3 text-sm border-2 border-dashed border-stone-300 rounded-2xl text-stone-500 hover:border-red-400 hover:text-red-600 font-semibold">+ Tambah Produk</button>

    <div class="flex justify-end gap-2">
        <a href="{{ route('productions.index') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button class="px-6 py-2.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Produksi</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    const MATERIALS = {{ \Illuminate\Support\Js::from($materials->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'unit' => $m->unit, 'stock' => (float) $m->stock, 'cost' => (float) $m->avg_cost])) }};
    const PRODUCTS = {{ \Illuminate\Support\Js::from($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku])) }};
    let bi = 0;

    const rupiah = n => 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID');
    const fmt = n => (Math.round(n * 1000) / 1000).toLocaleString('id-ID');

    function matOptions() {
        let h = '<option value="">— pilih bahan —</option>';
        MATERIALS.forEach(m => h += `<option value="${m.id}">${m.name} (${m.unit})</option>`);
        h += '<option value="__new__">➕ Tambah bahan baru…</option>';
        return h;
    }
    function productOptions() {
        let h = '<option value="">— pilih produk —</option>';
        PRODUCTS.forEach(p => h += `<option value="${p.id}">${p.name}${p.sku ? ' (' + p.sku + ')' : ''}</option>`);
        return h;
    }

    function addBlock() {
        const b = bi++;
        const div = document.createElement('div');
        div.className = 'bg-white rounded-2xl border border-stone-200 overflow-hidden';
        div.dataset.b = b;
        div.dataset.mi = 0;
        div.dataset.ci = 0;
        div.innerHTML = `
            <div class="px-5 py-3 border-b border-stone-100 flex justify-between items-end gap-3 flex-wrap bg-stone-50/50">
                <div class="flex gap-3 items-end flex-wrap">
                    <div>
                        <label class="block text-xs font-semibold mb-1">Produk Jadi *</label>
                        <select name="blocks[${b}][product_id]" required class="w-56 px-3 py-2 border border-stone-300 rounded-lg text-sm">${productOptions()}</select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">Qty Jadi (pcs) *</label>
                        <input type="number" min="1" name="blocks[${b}][output_qty]" oninput="recalc()" required class="w-28 px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </div>
                </div>
                <button type="button" onclick="removeBlock(${b})" class="text-xs text-rose-600 hover:text-rose-800 font-semibold">✕ Hapus Produk</button>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <div class="flex justify-between items-center mb-2"><h4 class="text-xs font-bold text-stone-700 uppercase tracking-wide">Pemakaian Bahan</h4><button type="button" onclick="addMat(${b})" class="px-3 py-1 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Bahan</button></div>
                    <div class="overflow-x-auto border border-stone-100 rounded-xl">
                        <table class="w-full text-xs whitespace-nowrap">
                            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                                <th class="text-left px-4 py-2.5">Bahan</th><th class="text-right">Stok</th><th class="text-right">Harga / unit</th><th class="text-right">Qty Pakai</th><th class="text-right">Subtotal</th><th class="pr-4"></th>
                            </tr></thead>
                            <tbody data-mat-rows></tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between items-center mb-2"><h4 class="text-xs font-bold text-stone-700 uppercase tracking-wide">Biaya Lain <span class="text-stone-400 font-normal normal-case">(ongkir, dll — opsional)</span></h4><button type="button" onclick="addCost(${b})" class="px-3 py-1 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Biaya</button></div>
                    <div class="overflow-x-auto border border-stone-100 rounded-xl">
                        <table class="w-full text-xs whitespace-nowrap">
                            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr><th class="text-left px-4 py-2.5">Keterangan</th><th class="text-right">Nominal</th><th class="pr-4"></th></tr></thead>
                            <tbody data-cost-rows></tbody>
                        </table>
                    </div>
                </div>
                <div class="border-t border-stone-100 pt-3 max-w-xs ml-auto space-y-1.5 text-sm">
                    <div class="flex justify-between"><span class="text-stone-500">Total Biaya Bahan</span><span class="font-semibold" data-sum-mat>Rp 0</span></div>
                    <div class="flex justify-between"><span class="text-stone-500">Total Biaya Lain</span><span class="font-semibold" data-sum-cost>Rp 0</span></div>
                    <div class="flex justify-between border-t border-stone-200 pt-1.5"><span class="font-semibold text-stone-700">Sub Total</span><span class="font-bold text-stone-900" data-sum-total>Rp 0</span></div>
                    <div class="flex justify-between bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2"><span class="font-bold text-emerald-700">HPP / Pcs</span><span class="font-bold text-emerald-700 text-base" data-sum-hpp>Rp 0</span></div>
                </div>
            </div>`;
        document.getElementById('blocks').appendChild(div);
        addMat(b); addMat(b); addMat(b);
        addCost(b, 'Ongkos Kirim');
    }

    function blockEl(b) { return document.querySelector(`#blocks [data-b="${b}"]`); }

    function addMat(b) {
        const blk = blockEl(b);
        const m = parseInt(blk.dataset.mi); blk.dataset.mi = m + 1;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.innerHTML = `
            <td class="px-4 py-2"><select name="blocks[${b}][materials][${m}][material_id]" onchange="onMat(this)" class="w-44 px-2 py-1.5 border border-stone-300 rounded-lg">${matOptions()}</select></td>
            <td class="text-right text-stone-500" data-stock>—</td>
            <td class="text-right"><input type="number" step="0.01" min="0" name="blocks[${b}][materials][${m}][unit_cost]" oninput="recalc()" placeholder="0" class="w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right"><input type="number" step="0.001" min="0" name="blocks[${b}][materials][${m}][quantity]" oninput="recalc()" class="w-20 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right font-semibold text-stone-700" data-sub>Rp 0</td>
            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        blk.querySelector('[data-mat-rows]').appendChild(tr);
    }

    async function onMat(sel) {
        if (sel.value === '__new__') { await addNewMaterial(sel); }
        const m = MATERIALS.find(x => x.id == sel.value);
        const tr = sel.closest('tr');
        tr.querySelector('[data-stock]').textContent = m ? fmt(m.stock) + ' ' + m.unit : '—';
        const costInput = tr.querySelector('[name$="[unit_cost]"]');
        if (m) costInput.value = m.cost; // default to saved HPP, still editable
        recalc();
    }

    // Tambah bahan baru langsung dari form → tersimpan ke Master Bahan Baku (dedup by nama).
    async function addNewMaterial(sel) {
        const name = (prompt('Nama bahan baru:') || '').trim();
        if (!name) { sel.value = ''; return; }
        const unit = (prompt('Satuan (mis. pcs, kg, ml):', 'pcs') || 'pcs').trim() || 'pcs';
        try {
            const res = await fetch('{{ route('materials.quick') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ name, unit }),
            });
            const d = await res.json();
            if (!res.ok) { alert(d.message || 'Gagal menambah bahan.'); sel.value = ''; return; }
            if (!MATERIALS.find(x => x.id == d.id)) MATERIALS.push({ id: d.id, name: d.name, unit: d.unit, stock: 0, cost: 0 });
            // sisipkan option ke SEMUA dropdown bahan (sebelum opsi "➕ Tambah…")
            document.querySelectorAll('select[name$="[material_id]"]').forEach(s => {
                if (![...s.options].some(o => o.value == d.id)) {
                    s.add(new Option(`${d.name} (${d.unit})`, d.id), s.options[s.options.length - 1]);
                }
            });
            sel.value = d.id;
            if (!d.created) alert(`Bahan "${d.name}" sudah ada di master — dipakai yang itu.`);
        } catch (e) { alert('Error: ' + e.message); sel.value = ''; }
    }

    function addCost(b, label) {
        const blk = blockEl(b);
        const c = parseInt(blk.dataset.ci); blk.dataset.ci = c + 1;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.innerHTML = `
            <td class="px-4 py-2"><input name="blocks[${b}][costs][${c}][label]" value="${label || ''}" placeholder="mis. Ongkos Kirim" class="w-52 px-2 py-1.5 border border-stone-300 rounded-lg"></td>
            <td class="text-right"><input type="number" step="0.01" min="0" name="blocks[${b}][costs][${c}][amount]" oninput="recalc()" class="w-28 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        blk.querySelector('[data-cost-rows]').appendChild(tr);
    }

    function removeBlock(b) {
        const blk = blockEl(b);
        if (blk) blk.remove();
        if (!document.querySelector('#blocks [data-b]')) addBlock(); // keep at least one
        recalc();
    }

    function recalc() {
        document.querySelectorAll('#blocks [data-b]').forEach(blk => {
            let mat = 0;
            blk.querySelectorAll('[data-mat-rows] tr').forEach(tr => {
                const qty = parseFloat(tr.querySelector('[name$="[quantity]"]').value) || 0;
                const cost = parseFloat(tr.querySelector('[name$="[unit_cost]"]').value) || 0;
                const sub = qty * cost;
                tr.querySelector('[data-sub]').textContent = rupiah(sub);
                mat += sub;
            });
            let cost = 0;
            blk.querySelectorAll('[data-cost-rows] tr').forEach(tr => {
                cost += parseFloat(tr.querySelector('[name$="[amount]"]').value) || 0;
            });
            const total = mat + cost;
            const qtyJadi = parseInt(blk.querySelector('[name$="[output_qty]"]').value) || 0;
            blk.querySelector('[data-sum-mat]').textContent = rupiah(mat);
            blk.querySelector('[data-sum-cost]').textContent = rupiah(cost);
            blk.querySelector('[data-sum-total]').textContent = rupiah(total);
            blk.querySelector('[data-sum-hpp]').textContent = qtyJadi > 0 ? rupiah(total / qtyJadi) : 'Rp 0';
        });
    }

    // Start with one product block.
    addBlock();
</script>
@endpush
