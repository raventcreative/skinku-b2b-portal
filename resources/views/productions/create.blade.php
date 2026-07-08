@extends('layouts.app')
@section('title', 'Catat Produksi')
@section('heading', 'Catat Produksi')

@section('content')
<a href="{{ route('productions.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar</a>

@if($products->isEmpty() || $materials->isEmpty())
    <div class="mt-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
        @if($products->isEmpty())Belum ada produk jadi — buat produk dulu di menu Manajemen Produk. @endif
        @if($materials->isEmpty())Belum ada bahan baku — tambahkan di menu Bahan Baku dan beli stoknya dulu.@endif
    </div>
@endif

<form method="POST" action="{{ route('productions.store') }}" class="mt-3 space-y-5" id="prodForm">
    @csrf

    {{-- Header --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5 grid sm:grid-cols-3 gap-4 text-sm">
        <div>
            <label class="block text-xs font-semibold mb-1">Tanggal Produksi *</label>
            <input type="date" name="produced_at" value="{{ old('produced_at', date('Y-m-d')) }}" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Produk Jadi *</label>
            <select name="product_id" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                <option value="">— pilih produk —</option>
                @foreach($products as $p)<option value="{{ $p->id }}" @selected(old('product_id')==$p->id)>{{ $p->name }}{{ $p->sku ? ' ('.$p->sku.')' : '' }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Qty Jadi (pcs) *</label>
            <input type="number" min="1" name="output_qty" id="outputQty" value="{{ old('output_qty') }}" oninput="recalc()" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
    </div>

    {{-- Materials used --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-stone-800">Pemakaian Bahan</h3>
            <button type="button" onclick="addMat()" class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Bahan</button>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Bahan</th>
                    <th class="text-right">Stok</th>
                    <th class="text-right">HPP / unit</th>
                    <th class="text-right">Qty Pakai</th>
                    <th class="text-right">Subtotal</th>
                    <th class="pr-4"></th>
                </tr>
            </thead>
            <tbody id="matRows"></tbody>
        </table>
        </div>
    </div>

    {{-- Other costs --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-stone-800">Biaya Lain <span class="text-stone-400 font-normal text-xs">(ongkir, tenaga kerja — opsional)</span></h3>
            <button type="button" onclick="addCost()" class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Biaya</button>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Keterangan</th>
                    <th class="text-right">Nominal</th>
                    <th class="pr-4"></th>
                </tr>
            </thead>
            <tbody id="costRows"></tbody>
        </table>
        </div>
    </div>

    {{-- Summary --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="max-w-xs ml-auto space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-stone-500">Total Biaya Bahan</span><span class="font-semibold" id="sumMat">Rp 0</span></div>
            <div class="flex justify-between"><span class="text-stone-500">Total Biaya Lain</span><span class="font-semibold" id="sumCost">Rp 0</span></div>
            <div class="flex justify-between border-t border-stone-200 pt-2"><span class="font-semibold text-stone-700">Sub Total</span><span class="font-bold text-stone-900" id="sumTotal">Rp 0</span></div>
            <div class="flex justify-between bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2"><span class="font-bold text-emerald-700">HPP / Pcs</span><span class="font-bold text-emerald-700 text-base" id="sumHpp">Rp 0</span></div>
        </div>
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('productions.index') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button class="px-6 py-2.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Produksi</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    const MATERIALS = @json($materials->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'unit' => $m->unit, 'stock' => (float) $m->stock, 'cost' => (float) $m->avg_cost]));
    let mi = 0, ci = 0;

    const rupiah = n => 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID');
    const fmt = n => (Math.round(n * 1000) / 1000).toLocaleString('id-ID');

    function matOptions() {
        let h = '<option value="">— pilih bahan —</option>';
        MATERIALS.forEach(m => h += `<option value="${m.id}">${m.name} (${m.unit})</option>`);
        return h;
    }

    function addMat() {
        const i = mi++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.dataset.mat = i;
        tr.innerHTML = `
            <td class="px-4 py-2"><select name="materials[${i}][material_id]" onchange="onMat(${i})" class="w-52 px-2 py-1.5 border border-stone-300 rounded-lg">${matOptions()}</select></td>
            <td class="text-right text-stone-500" data-stock>—</td>
            <td class="text-right text-stone-500" data-cost>—</td>
            <td class="text-right"><input type="number" step="0.001" min="0" name="materials[${i}][quantity]" oninput="recalc()" class="w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right font-semibold text-stone-700" data-sub>Rp 0</td>
            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        document.getElementById('matRows').appendChild(tr);
    }

    function material(i) {
        const id = document.querySelector(`tr[data-mat="${i}"] select`).value;
        return MATERIALS.find(m => m.id == id);
    }
    function onMat(i) {
        const tr = document.querySelector(`tr[data-mat="${i}"]`), m = material(i);
        tr.querySelector('[data-stock]').textContent = m ? fmt(m.stock) + ' ' + m.unit : '—';
        tr.querySelector('[data-cost]').textContent = m ? rupiah(m.cost) : '—';
        recalc();
    }

    function addCost(label) {
        const i = ci++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.innerHTML = `
            <td class="px-4 py-2"><input name="costs[${i}][label]" value="${label || ''}" placeholder="mis. Ongkos Kirim" class="w-56 px-2 py-1.5 border border-stone-300 rounded-lg"></td>
            <td class="text-right"><input type="number" step="0.01" min="0" name="costs[${i}][amount]" oninput="recalc()" class="w-32 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        document.getElementById('costRows').appendChild(tr);
    }

    function recalc() {
        let mat = 0;
        document.querySelectorAll('#matRows tr').forEach(tr => {
            const i = tr.dataset.mat, m = material(i);
            const qty = parseFloat(tr.querySelector('[name$="[quantity]"]').value) || 0;
            const sub = m ? qty * m.cost : 0;
            tr.querySelector('[data-sub]').textContent = rupiah(sub);
            mat += sub;
        });
        let cost = 0;
        document.querySelectorAll('#costRows tr').forEach(tr => {
            cost += parseFloat(tr.querySelector('[name$="[amount]"]').value) || 0;
        });
        const total = mat + cost;
        const qtyJadi = parseInt(document.getElementById('outputQty').value) || 0;
        document.getElementById('sumMat').textContent = rupiah(mat);
        document.getElementById('sumCost').textContent = rupiah(cost);
        document.getElementById('sumTotal').textContent = rupiah(total);
        document.getElementById('sumHpp').textContent = qtyJadi > 0 ? rupiah(total / qtyJadi) : 'Rp 0';
    }

    // Start with a few blank rows.
    addMat(); addMat(); addMat();
    addCost('Ongkos Kirim');
</script>
@endpush
