@extends('layouts.app')
@section('title', 'Barang Keluar / Penjualan')
@section('heading', 'Barang Keluar — Penjualan ke Customer')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="max-w-4xl">
    <a href="{{ route('inventory.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Stok Saya</a>

    <form method="POST" action="{{ route('partner-sales.store') }}" class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">@csrf
        <div class="grid sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-[11px] font-semibold text-stone-500 mb-1">
                    Customer <span class="font-normal text-stone-400">(opsional — nama pembeli)</span>
                </label>
                <input name="customer_name" value="{{ old('customer_name') }}" maxlength="150"
                    placeholder="mis. Toko Budi"
                    class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-stone-500 mb-1">Tanggal jual</label>
                <input type="date" name="sold_at" value="{{ old('sold_at', now()->format('Y-m-d')) }}" required
                    class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </div>
        </div>

        @error('items')
            <p class="mb-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $message }}</p>
        @enderror

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
            <input name="notes" value="{{ old('notes') }}" maxlength="1000"
                class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </div>

        <p class="text-[11px] text-stone-400 mb-3">
            Harga otomatis dari harga jual (retail), <b>boleh diubah</b>. Menyimpan akan <b>memotong stok</b> tiap produk.
            Kalau salah satu produk stoknya kurang, seluruh nota dibatalkan — tak ada yang terpotong separuh.
        </p>

        <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold"
            onclick="return confirm('Catat penjualan & potong stok?')">Catat &amp; Potong Stok</button>
    </form>

    {{-- Riwayat penjualan --}}
    <div class="bg-white rounded-2xl border border-stone-200 mt-5 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-100 flex flex-wrap items-center gap-3">
            <span class="text-sm font-bold text-stone-800">Riwayat Penjualan</span>
            <form method="GET" class="flex items-center gap-2 ml-auto">
                <input type="month" name="bulan" value="{{ $bulan?->format('Y-m') }}" onchange="this.form.submit()"
                    class="px-2 py-1 border border-stone-300 rounded-lg text-xs">
                @if($bulan)
                    <a href="{{ route('partner-sales.index') }}" class="text-[11px] text-indigo-600 hover:underline">semua</a>
                @endif
            </form>
            <a href="{{ route('partner-sales.export', array_filter(['bulan' => $bulan?->format('Y-m')])) }}"
                class="px-3 py-1.5 text-xs bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⬇ Excel</a>
        </div>

        <div class="px-4 py-2.5 bg-stone-50 border-b border-stone-100 flex flex-wrap items-baseline gap-2 text-xs">
            <span class="text-stone-500">
                {{ $bulan ? $bulan->translatedFormat('F Y') : 'Semua periode' }}
            </span>
            <span class="ml-auto text-stone-500">Total terjual
                <b class="text-stone-900 text-sm">{{ $rp($total) }}</b>
            </span>
        </div>

        @if(count($recent))
            <div class="overflow-x-auto">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr><th class="text-left px-4 py-2">No.</th><th class="text-left">Tanggal</th>
                        <th class="text-left">Customer</th><th class="text-left">Item</th><th class="text-right px-4">Total</th></tr>
                </thead>
                <tbody>
                    @foreach($recent as $sale)
                        <tr class="border-t border-stone-100 align-top">
                            <td class="px-4 py-2 font-semibold text-stone-700">{{ $sale->sale_number }}</td>
                            <td class="text-stone-600">{{ $sale->sold_at->format('d M Y') }}</td>
                            <td class="text-stone-600">{{ $sale->customer_name ?: '—' }}</td>
                            <td class="text-stone-500">
                                @foreach($sale->items as $it)
                                    <span class="block">{{ $it->product_name }} × {{ $it->qty }}</span>
                                @endforeach
                            </td>
                            <td class="px-4 text-right font-semibold text-stone-800">{{ $rp($sale->total_amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @if($recent->hasPages())
                <div class="px-4 py-3 border-t border-stone-100">{{ $recent->links() }}</div>
            @endif
        @else
            <p class="px-4 py-8 text-center text-xs text-stone-400">
                {{ $bulan ? 'Tidak ada penjualan pada bulan ini.' : 'Belum ada penjualan tercatat.' }}
            </p>
        @endif
    </div>
</div>

<script>
const PRODUCTS = @json($products);
const rupiah = v => 'Rp ' + Math.round(v || 0).toLocaleString('id-ID');
let n = 0;

/** Harga default = retail (mitra menjual ke customer akhir). */
function retailPrice(p) {
    return parseFloat(p.price_retail) || 0;
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
                    <span class="block text-[10px] text-stone-400">${rupiah(retailPrice(p))}</span>
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
        if (!price.dataset.touched) price.value = retailPrice(p);
        list.classList.add('hidden');
        recalc();
    });
    inp.addEventListener('blur', () => setTimeout(() => list.classList.add('hidden'), 120));

    price.addEventListener('input', () => { price.dataset.touched = '1'; recalc(); });
    row.querySelector('[data-qty]').addEventListener('input', recalc);
    row.querySelector('[data-del]').addEventListener('click', () => { row.remove(); recalc(); });
}

addRow();
</script>
@endsection
