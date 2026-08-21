@extends('layouts.app')
@section('title', $package->exists ? 'Edit Paket Join' : 'Tambah Paket Join')
@section('heading', $package->exists ? 'Edit Paket Join' : 'Tambah Paket Join')

@section('content')
<a href="{{ route('join-packages.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar</a>

<form method="POST" action="{{ $package->exists ? route('join-packages.update', $package) : route('join-packages.store') }}" class="mt-3 space-y-5" id="packageForm">
    @csrf
    @if($package->exists)
        @method('PUT')
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
        <div>
            <label class="block text-xs font-semibold mb-1">Nama Paket *</label>
            <input name="name" value="{{ old('name', $package->name) }}" required maxlength="100" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Tier Target *</label>
            <select name="target_role" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                <option value="">— pilih tier —</option>
                <option value="{{ \App\Models\User::ROLE_RESELLER_BRONZE }}" @selected(old('target_role', $package->target_role) === \App\Models\User::ROLE_RESELLER_BRONZE)>Reseller Bronze</option>
                <option value="{{ \App\Models\User::ROLE_RESELLER_GOLD }}" @selected(old('target_role', $package->target_role) === \App\Models\User::ROLE_RESELLER_GOLD)>Reseller Gold</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Harga Paket (Rp) *</label>
            <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $package->price) }}" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div class="flex items-end pb-2">
            <label class="inline-flex items-center gap-2 text-xs font-semibold">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->exists ? $package->is_active : true)) class="accent-red-600">
                Aktif
            </label>
        </div>
    </div>

    {{-- Isi paket --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-stone-800">Isi Paket (Produk)</h3>
            <button type="button" onclick="addRow()" class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Baris</button>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Produk</th>
                    <th class="text-right">Qty</th>
                    <th class="pr-4"></th>
                </tr>
            </thead>
            <tbody id="rows"></tbody>
        </table>
        </div>
        <p class="px-4 py-3 text-[10px] text-stone-400 border-t border-stone-100">Minimal 1 produk. Baris dengan produk kosong akan ditolak saat disimpan.</p>
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('join-packages.index') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button class="px-6 py-2.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Paket</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    const PRODUCTS = {{ \Illuminate\Support\Js::from($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku])) }};
    const EXISTING_ITEMS = {{ \Illuminate\Support\Js::from($package->exists ? $package->items->map(fn ($it) => ['product_id' => $it->product_id, 'qty' => $it->qty])->values() : []) }};
    let idx = 0;

    // Ketik-buat-cari (datalist), bukan gulir 1-1. Pola sama form Deal KOL:
    // teks → product_id (hidden) via peta; server tetap validasi id.
    const label = p => p.name + (p.sku ? ' (' + p.sku + ')' : '');
    const LABEL_TO_ID = {}, ID_TO_LABEL = {};
    PRODUCTS.forEach(p => { const l = label(p); LABEL_TO_ID[l] = p.id; ID_TO_LABEL[p.id] = l; });

    (function () {
        const dl = document.createElement('datalist');
        dl.id = 'productList';
        PRODUCTS.forEach(p => { const o = document.createElement('option'); o.value = label(p); dl.appendChild(o); });
        document.body.appendChild(dl);
    })();

    function addRow(productId, qty) {
        const i = idx++;
        const val = (productId && ID_TO_LABEL[productId]) ? ID_TO_LABEL[productId] : '';
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100 align-top';
        tr.dataset.row = i;
        tr.innerHTML = `
            <td class="px-4 py-2">
                <input type="text" list="productList" autocomplete="off" required data-role="pname"
                    value="${val.replace(/"/g, '&quot;')}" placeholder="Ketik nama / SKU produk…"
                    class="w-72 px-2 py-1.5 border border-stone-300 rounded-lg">
                <input type="hidden" name="items[${i}][product_id]" value="${productId || ''}" data-role="pid">
                <span data-role="miss" class="block mt-1 text-[10px] text-rose-500 hidden">Produk tak ada — pilih dari daftar.</span>
            </td>
            <td class="text-right py-2">
                <input type="number" min="1" name="items[${i}][qty]" value="${qty || 1}" required class="w-20 px-2 py-1.5 border border-stone-300 rounded-lg text-right">
            </td>
            <td class="pr-4 py-2 text-right"><button type="button" onclick="removeRow(${i})" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        document.getElementById('rows').appendChild(tr);
    }

    document.getElementById('rows').addEventListener('input', function (e) {
        const inp = e.target.closest('[data-role="pname"]');
        if (!inp) return;
        const td = inp.closest('td');
        const id = LABEL_TO_ID[inp.value.trim()] || '';
        td.querySelector('[data-role="pid"]').value = id;
        td.querySelector('[data-role="miss"]').classList.toggle('hidden', !!id || inp.value.trim() === '');
    });

    function removeRow(i) {
        const tr = document.querySelector(`tr[data-row="${i}"]`);
        if (tr) tr.remove();
    }

    if (EXISTING_ITEMS.length) {
        EXISTING_ITEMS.forEach(it => addRow(it.product_id, it.qty));
    } else {
        addRow();
    }
</script>
@endpush
