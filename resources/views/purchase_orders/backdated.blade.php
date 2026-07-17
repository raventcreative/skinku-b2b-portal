@extends('layouts.app')
@section('title', 'Catat Penjualan Distributor')
@section('heading', 'Catat Penjualan Distributor (Back-date)')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="max-w-4xl">

    {{-- Batas potong stok: pengaman utama halaman ini --}}
    <form method="POST" action="{{ route('backdated-sales.cutoff') }}"
        class="rounded-xl border {{ $cutoff ? 'border-emerald-200 bg-emerald-50' : 'border-rose-300 bg-rose-50' }} p-4 mb-5">@csrf
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-[11px] font-semibold {{ $cutoff ? 'text-emerald-700' : 'text-rose-700' }} mb-1">
                    Mulai potong stok dari
                </label>
                <input type="date" name="po_deduct_from" value="{{ $cutoff?->format('Y-m-d') }}"
                    class="px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </div>
            <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Simpan Batas</button>
            <p class="text-[11px] flex-1 min-w-[220px] {{ $cutoff ? 'text-emerald-800' : 'text-rose-800' }}">
                @if($cutoff)
                    🛡️ PO bertanggal <b>sebelum {{ $cutoff->format('d M Y') }}</b> hanya dicatat penjualannya —
                    <b>stok tidak dipotong</b>, karena barangnya sudah keluar sebelum stok opname dan sudah terhitung di sana.
                @else
                    ⚠️ <b>Batas belum diisi.</b> Semua PO yang kamu catat akan <b>memotong stok</b> — untuk order pra-opname
                    itu bikin stok berkurang <b>dua kali</b>. Isi batasnya dulu (biasanya sehari setelah tanggal opname).
                @endif
            </p>
        </div>
    </form>

    <form method="POST" action="{{ route('backdated-sales.store') }}" class="bg-white rounded-2xl border border-stone-200 p-5">@csrf
        <div class="grid sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-[11px] font-semibold text-stone-500 mb-1">Distributor / Reseller</label>
                <select name="user_id" id="partnerSel" required class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— pilih —</option>
                    @foreach($partners as $p)
                        <option value="{{ $p->id }}" data-role="{{ $p->role }}" @selected(old('user_id') == $p->id)>
                            {{ $p->company_name ?: $p->fullname }} ({{ $p->role }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-stone-500 mb-1">Tanggal order (sesuai Excel)</label>
                <input type="date" name="order_date" value="{{ old('order_date') }}" required
                    class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </div>
        </div>

        <div class="hidden sm:flex gap-2 text-[10px] uppercase tracking-wide text-stone-400 font-semibold mb-1">
            <span class="flex-1">Produk (ketik untuk cari)</span>
            <span class="w-20 text-right">Qty</span>
            <span class="w-32 text-right">Harga satuan</span>
            <span class="w-32 text-right">Subtotal</span>
            <span class="w-6"></span>
        </div>
        <div id="rows" class="space-y-2 mb-2"></div>

        <div class="flex items-center gap-2 mb-4">
            <button type="button" onclick="addRow()" class="text-xs text-indigo-600 hover:underline">+ tambah item</button>
            <div class="ml-auto flex items-center gap-3 text-sm">
                <span class="text-stone-500">Total</span>
                <span id="grandTotal" class="text-lg font-bold text-stone-900">Rp 0</span>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-[11px] font-semibold text-stone-500 mb-1">Catatan (opsional)</label>
            <input name="notes" value="{{ old('notes', 'Backfill dari Excel') }}" maxlength="1000"
                class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </div>

        <p class="text-[11px] text-stone-400 mb-3">
            Harga terisi otomatis dari tier mitra, tapi <b>boleh diubah</b> — entri lama sering pakai harga lama.
            Cocokkan <b>Total</b> di atas dengan angka di Excel sebelum menyimpan. PO langsung berstatus <b>selesai</b>.
        </p>

        <button class="px-5 py-2.5 text-sm bg-emerald-700 text-white rounded-xl hover:bg-emerald-800 font-semibold"
            onclick="return confirm('Catat penjualan ini?')">Catat Penjualan</button>
    </form>

    @if(count($recent))
        <div class="bg-white rounded-2xl border border-stone-200 mt-5 overflow-hidden">
            <div class="px-4 py-2.5 border-b border-stone-100 text-sm font-bold text-stone-800">Entri Back-date Terakhir</div>
            <table class="w-full text-xs">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr><th class="text-left px-4 py-2">No. PO</th><th class="text-left">Tgl Order</th>
                        <th class="text-left">Mitra</th><th class="text-right">Total</th><th class="text-left px-4">Stok</th></tr>
                </thead>
                <tbody>
                    @foreach($recent as $po)
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2"><a href="{{ route('purchase-orders.show', $po) }}" class="font-semibold text-indigo-700 hover:underline">{{ $po->po_number }}</a></td>
                            <td class="text-stone-600">{{ $po->orderDate()->format('d M Y') }}</td>
                            <td class="text-stone-600">{{ $po->company_name ?: '—' }}</td>
                            <td class="text-right text-stone-700">{{ $rp($po->total_amount) }}</td>
                            <td class="px-4">
                                @if($po->stock_skipped)
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-stone-100 text-stone-500">🛡️ tidak dipotong</span>
                                @else
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">✂ dipotong</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<script>
const PRODUCTS = @json($products);
const rupiah = v => 'Rp ' + Math.round(v || 0).toLocaleString('id-ID');
let n = 0;

/** Harga tier mengikuti mitra terpilih (distributor vs reseller). */
function tierPrice(p) {
    const opt = document.getElementById('partnerSel').selectedOptions[0];
    const role = opt ? opt.dataset.role : null;
    return parseFloat(role === 'distributor' ? p.price_distributor : p.price_reseller) || 0;
}

/** Cocok bila SEMUA kata yang diketik ada di nama/SKU — "pink scrub" pun kena. */
function search(q) {
    const words = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return PRODUCTS.slice(0, 8);
    return PRODUCTS.filter(p => {
        const hay = ((p.name || '') + ' ' + (p.sku || '')).toLowerCase();
        return words.every(w => hay.includes(w));
    }).slice(0, 8);
}

function recalc() {
    let total = 0;
    document.querySelectorAll('[data-row]').forEach(row => {
        const qty = parseFloat(row.querySelector('[data-qty]').value) || 0;
        const price = parseFloat(row.querySelector('[data-price]').value) || 0;
        const sub = qty * price;
        total += sub;
        row.querySelector('[data-sub]').textContent = sub ? rupiah(sub) : '·';
    });
    document.getElementById('grandTotal').textContent = rupiah(total);
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
        <input type="number" name="items[${i}][qty]" data-qty min="0" placeholder="qty"
            class="w-20 px-2 py-2 border border-stone-300 rounded-lg text-sm text-right">
        <input type="number" name="items[${i}][price]" data-price min="0" step="1" placeholder="harga"
            class="w-32 px-2 py-2 border border-stone-300 rounded-lg text-sm text-right">
        <span data-sub class="w-32 px-2 py-2 text-sm text-right text-stone-700 font-semibold">·</span>
        <button type="button" data-del class="w-6 text-stone-300 hover:text-rose-600 text-lg leading-none">×</button>`;
    document.getElementById('rows').appendChild(row);

    const inp = row.querySelector('[data-search]');
    const list = row.querySelector('[data-list]');
    const pid = row.querySelector('[data-pid]');
    const price = row.querySelector('[data-price]');

    const render = () => {
        const hits = search(inp.value);
        list.innerHTML = hits.length
            ? hits.map(p => `<button type="button" class="w-full text-left px-3 py-2 text-xs hover:bg-stone-50" data-id="${p.id}">
                    <span class="font-semibold text-stone-800">${p.name}</span>
                    <span class="text-stone-400">${p.sku ? '· ' + p.sku : ''}</span>
                    <span class="block text-[10px] text-stone-400">${rupiah(tierPrice(p))}</span>
                </button>`).join('')
            : '<p class="px-3 py-2 text-xs text-stone-400">tidak ada produk cocok</p>';
        list.classList.remove('hidden');
    };

    inp.addEventListener('focus', render);
    inp.addEventListener('input', () => { pid.value = ''; render(); });
    list.addEventListener('mousedown', e => {
        const btn = e.target.closest('[data-id]');
        if (!btn) return;
        e.preventDefault();
        const p = PRODUCTS.find(x => x.id == btn.dataset.id);
        pid.value = p.id;
        inp.value = p.name + (p.sku ? ' (' + p.sku + ')' : '');
        // Harga otomatis dari tier — tetap boleh ditimpa manual.
        if (!price.dataset.touched) price.value = tierPrice(p);
        list.classList.add('hidden');
        recalc();
    });
    inp.addEventListener('blur', () => setTimeout(() => list.classList.add('hidden'), 120));

    price.addEventListener('input', () => { price.dataset.touched = '1'; recalc(); });
    row.querySelector('[data-qty]').addEventListener('input', recalc);
    row.querySelector('[data-del]').addEventListener('click', () => { row.remove(); recalc(); });
}

// Ganti mitra → harga tier ikut berubah, KECUALI yang sudah diketik manual.
document.getElementById('partnerSel').addEventListener('change', () => {
    document.querySelectorAll('[data-row]').forEach(row => {
        const price = row.querySelector('[data-price]');
        const id = row.querySelector('[data-pid]').value;
        if (id && !price.dataset.touched) {
            price.value = tierPrice(PRODUCTS.find(x => x.id == id));
        }
    });
    recalc();
});

addRow();
</script>
@endsection
