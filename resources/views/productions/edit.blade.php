@extends('layouts.app')
@section('title', 'Ubah '.$production->production_number)
@section('heading', 'Ubah Produksi')

@section('content')
<a href="{{ route('productions.show', $production) }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke detail</a>

@if($errors->any())
    <div class="mt-3 px-4 py-2 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('productions.update', $production) }}" class="mt-3 space-y-5">
    @csrf
    @method('PUT')

    <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-wrap items-end gap-5 text-sm">
        <div>
            <p class="text-[10px] uppercase text-stone-400 font-semibold">Produk ({{ $production->production_number }})</p>
            <p class="font-bold text-stone-800">{{ $production->product_name }}</p>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Tanggal Produksi *</label>
            <input type="date" name="produced_at" value="{{ old('produced_at', $production->produced_at?->format('Y-m-d')) }}" required class="px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Qty Jadi (pcs) *</label>
            <input type="number" min="1" name="output_qty" value="{{ old('output_qty', $production->output_qty) }}" oninput="recalc()" required class="w-28 px-3 py-2 border border-stone-300 rounded-lg">
        </div>
    </div>

    {{-- Pemakaian bahan --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="flex justify-between items-center mb-2">
            <h4 class="text-xs font-bold text-stone-700 uppercase tracking-wide">Pemakaian Bahan</h4>
            <button type="button" onclick="addMat()" class="px-3 py-1 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Bahan</button>
        </div>
        <div class="overflow-x-auto border border-stone-100 rounded-xl">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2.5">Bahan</th><th class="text-right">Harga / unit</th><th class="text-right">Qty Pakai</th><th class="text-right">Subtotal</th><th class="pr-4"></th>
                </tr></thead>
                <tbody id="matRows">
                    @foreach($production->materials as $i => $mline)
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2">
                                <select name="materials[{{ $i }}][material_id]" class="w-44 px-2 py-1.5 border border-stone-300 rounded-lg">
                                    @foreach($materials as $m)
                                        <option value="{{ $m->id }}" @selected($m->id == $mline->material_id)>{{ $m->name }} ({{ $m->unit }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="text-right"><input type="number" step="0.01" min="0" name="materials[{{ $i }}][unit_cost]" value="{{ 0 + $mline->unit_cost }}" oninput="recalc()" class="w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
                            <td class="text-right"><input type="number" step="0.001" min="0" name="materials[{{ $i }}][quantity]" value="{{ 0 + $mline->quantity }}" oninput="recalc()" class="w-20 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
                            <td class="text-right font-semibold text-stone-700" data-sub>Rp 0</td>
                            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Biaya lain --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="flex justify-between items-center mb-2">
            <h4 class="text-xs font-bold text-stone-700 uppercase tracking-wide">Biaya Lain <span class="text-stone-400 font-normal normal-case">(ongkir, dll — opsional)</span></h4>
            <button type="button" onclick="addCost()" class="px-3 py-1 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Biaya</button>
        </div>
        <div class="overflow-x-auto border border-stone-100 rounded-xl">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr><th class="text-left px-4 py-2.5">Keterangan</th><th class="text-right">Nominal</th><th class="pr-4"></th></tr></thead>
                <tbody id="costRows">
                    @foreach($production->costs as $i => $cost)
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2"><input name="costs[{{ $i }}][label]" value="{{ $cost->label }}" class="w-52 px-2 py-1.5 border border-stone-300 rounded-lg"></td>
                            <td class="text-right"><input type="number" step="0.01" min="0" name="costs[{{ $i }}][amount]" value="{{ 0 + $cost->amount }}" oninput="recalc()" class="w-28 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
                            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="max-w-xs ml-auto space-y-1.5 text-sm">
        <div class="flex justify-between"><span class="text-stone-500">Total Biaya</span><span class="font-semibold" id="sumTotal">Rp 0</span></div>
        <div class="flex justify-between bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2"><span class="font-bold text-emerald-700">HPP / Pcs</span><span class="font-bold text-emerald-700 text-base" id="sumHpp">Rp 0</span></div>
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('productions.show', $production) }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button class="px-6 py-2.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Perubahan</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    const MATERIALS = {{ \Illuminate\Support\Js::from($materials->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'unit' => $m->unit, 'cost' => (float) $m->avg_cost])) }};
    let mi = {{ $production->materials->count() }};
    let ci = {{ $production->costs->count() }};

    const rupiah = n => 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID');
    function matOptions() {
        let h = '<option value="">— pilih bahan —</option>';
        MATERIALS.forEach(m => h += `<option value="${m.id}">${m.name} (${m.unit})</option>`);
        return h;
    }
    function addMat() {
        const i = mi++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.innerHTML = `
            <td class="px-4 py-2"><select name="materials[${i}][material_id]" onchange="onMat(this)" class="w-44 px-2 py-1.5 border border-stone-300 rounded-lg">${matOptions()}</select></td>
            <td class="text-right"><input type="number" step="0.01" min="0" name="materials[${i}][unit_cost]" oninput="recalc()" class="w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right"><input type="number" step="0.001" min="0" name="materials[${i}][quantity]" oninput="recalc()" class="w-20 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right font-semibold text-stone-700" data-sub>Rp 0</td>
            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        document.getElementById('matRows').appendChild(tr);
    }
    function onMat(sel) {
        const m = MATERIALS.find(x => x.id == sel.value);
        const tr = sel.closest('tr');
        const costInput = tr.querySelector('[name$="[unit_cost]"]');
        if (m && !costInput.value) costInput.value = m.cost;
        recalc();
    }
    function addCost() {
        const i = ci++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.innerHTML = `
            <td class="px-4 py-2"><input name="costs[${i}][label]" placeholder="mis. Ongkos Kirim" class="w-52 px-2 py-1.5 border border-stone-300 rounded-lg"></td>
            <td class="text-right"><input type="number" step="0.01" min="0" name="costs[${i}][amount]" oninput="recalc()" class="w-28 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        document.getElementById('costRows').appendChild(tr);
    }
    function recalc() {
        let mat = 0;
        document.querySelectorAll('#matRows tr').forEach(tr => {
            const qty = parseFloat(tr.querySelector('[name$="[quantity]"]').value) || 0;
            const cost = parseFloat(tr.querySelector('[name$="[unit_cost]"]').value) || 0;
            const sub = qty * cost;
            const sEl = tr.querySelector('[data-sub]');
            if (sEl) sEl.textContent = rupiah(sub);
            mat += sub;
        });
        let other = 0;
        document.querySelectorAll('#costRows tr').forEach(tr => other += parseFloat(tr.querySelector('[name$="[amount]"]').value) || 0);
        const total = mat + other;
        const qtyJadi = parseInt(document.querySelector('[name="output_qty"]').value) || 0;
        document.getElementById('sumTotal').textContent = rupiah(total);
        document.getElementById('sumHpp').textContent = qtyJadi > 0 ? rupiah(total / qtyJadi) : 'Rp 0';
    }
    recalc();
</script>
@endpush
