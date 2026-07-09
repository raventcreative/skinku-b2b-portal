@extends('layouts.app')
@section('title', 'Catat Stok Masuk')
@section('heading', 'Catat Stok Masuk')

@section('content')
<a href="{{ route('stock-receipts.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar</a>

<form method="POST" action="{{ route('stock-receipts.store') }}" class="mt-3 space-y-5" id="receiptForm">
    @csrf

    {{-- Header --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
        <div>
            <label class="block text-xs font-semibold mb-1">Tanggal Terima *</label>
            <input type="date" name="received_at" value="{{ old('received_at', date('Y-m-d')) }}" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Supplier <span class="text-stone-400 font-normal">(opsional)</span></label>
            <input name="supplier_name" value="{{ old('supplier_name') }}" placeholder="Nama supplier" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">No. Referensi <span class="text-stone-400 font-normal">(opsional)</span></label>
            <input name="reference_no" value="{{ old('reference_no') }}" placeholder="No. invoice / DO supplier" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Catatan <span class="text-stone-400 font-normal">(opsional)</span></label>
            <input name="notes" value="{{ old('notes') }}" placeholder="Catatan singkat" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
    </div>

    {{-- Line items --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-stone-800">Produk Masuk</h3>
            <button type="button" onclick="addRow()" class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Baris</button>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Produk</th>
                    <th class="text-right">Stok Skrg</th>
                    <th class="text-right">HPP Skrg</th>
                    <th class="text-right">Qty Masuk</th>
                    <th class="text-right">Harga Beli / unit</th>
                    <th class="text-right">Subtotal</th>
                    <th class="text-right">HPP Baru</th>
                    <th class="pr-4"></th>
                </tr>
            </thead>
            <tbody id="rows"></tbody>
            <tfoot>
                <tr class="border-t border-stone-200 bg-stone-50">
                    <td colspan="5" class="px-4 py-3 text-right font-semibold text-stone-600">Total Biaya</td>
                    <td class="text-right pr-1 font-bold text-stone-900" id="grandTotal">Rp 0</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('stock-receipts.index') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button class="px-6 py-2.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Stok Masuk</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    const PRODUCTS = {{ json_encode($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'stock' => (int) $p->hq_stock, 'cogs' => (float) $p->cogs])) }};
    let idx = 0;

    const rupiah = n => 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID');

    function productOptions(selected) {
        let html = '<option value="">— pilih produk —</option>';
        PRODUCTS.forEach(p => {
            html += `<option value="${p.id}" ${p.id == selected ? 'selected' : ''}>${p.name}${p.sku ? ' (' + p.sku + ')' : ''}</option>`;
        });
        return html;
    }

    function addRow(sel) {
        const i = idx++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100 align-middle';
        tr.dataset.row = i;
        tr.innerHTML = `
            <td class="px-4 py-2">
                <select name="items[${i}][product_id]" onchange="onProduct(${i})" class="w-52 px-2 py-1.5 border border-stone-300 rounded-lg">${productOptions(sel)}</select>
            </td>
            <td class="text-right text-stone-500" data-stock>—</td>
            <td class="text-right text-stone-500" data-cogs>—</td>
            <td class="text-right"><input type="number" min="1" name="items[${i}][quantity]" oninput="recalc(${i})" class="w-20 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right"><input type="number" min="0" step="0.01" name="items[${i}][unit_cost]" oninput="recalc(${i})" class="w-28 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right font-semibold text-stone-700" data-subtotal>Rp 0</td>
            <td class="text-right text-emerald-700 font-semibold" data-newcogs>—</td>
            <td class="pr-4 text-right"><button type="button" onclick="removeRow(${i})" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        document.getElementById('rows').appendChild(tr);
    }

    function rowEl(i) { return document.querySelector(`tr[data-row="${i}"]`); }
    function product(i) {
        const id = rowEl(i).querySelector('select').value;
        return PRODUCTS.find(p => p.id == id);
    }

    function onProduct(i) {
        const tr = rowEl(i), p = product(i);
        tr.querySelector('[data-stock]').textContent = p ? p.stock.toLocaleString('id-ID') : '—';
        tr.querySelector('[data-cogs]').textContent = p ? rupiah(p.cogs) : '—';
        recalc(i);
    }

    function recalc(i) {
        const tr = rowEl(i), p = product(i);
        const qty = parseInt(tr.querySelector('[name$="[quantity]"]').value) || 0;
        const cost = parseFloat(tr.querySelector('[name$="[unit_cost]"]').value) || 0;
        tr.querySelector('[data-subtotal]').textContent = rupiah(qty * cost);

        // Moving average preview.
        let newCogs = '—';
        if (p && qty > 0) {
            const bq = p.stock, bc = p.cogs;
            newCogs = rupiah((bq <= 0 || bc <= 0) ? cost : ((bq * bc) + (qty * cost)) / (bq + qty));
        }
        tr.querySelector('[data-newcogs]').textContent = newCogs;
        grandTotal();
    }

    function grandTotal() {
        let t = 0;
        document.querySelectorAll('#rows tr').forEach(tr => {
            const qty = parseInt(tr.querySelector('[name$="[quantity]"]').value) || 0;
            const cost = parseFloat(tr.querySelector('[name$="[unit_cost]"]').value) || 0;
            t += qty * cost;
        });
        document.getElementById('grandTotal').textContent = rupiah(t);
    }

    function removeRow(i) {
        const tr = rowEl(i);
        if (tr) tr.remove();
        grandTotal();
    }

    // Start with a few blank rows.
    addRow(); addRow(); addRow();
</script>
@endpush
