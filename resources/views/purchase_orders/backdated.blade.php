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
                <select name="user_id" required class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— pilih —</option>
                    @foreach($partners as $p)
                        <option value="{{ $p->id }}" @selected(old('user_id') == $p->id)>
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

        <label class="block text-[11px] font-semibold text-stone-500 mb-1">Item</label>
        <div id="rows" class="space-y-2 mb-2">
            <div class="flex gap-2" data-row>
                <select name="items[0][product_id]" class="flex-1 px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— produk —</option>
                    @foreach($products as $pr)
                        <option value="{{ $pr->id }}">{{ $pr->name }} @if($pr->sku)({{ $pr->sku }})@endif</option>
                    @endforeach
                </select>
                <input type="number" name="items[0][qty]" min="0" placeholder="qty"
                    class="w-24 px-3 py-2 border border-stone-300 rounded-lg text-sm text-right">
            </div>
        </div>
        <button type="button" onclick="addRow()" class="text-xs text-indigo-600 hover:underline mb-4">+ tambah item</button>

        <div class="mb-4">
            <label class="block text-[11px] font-semibold text-stone-500 mb-1">Catatan (opsional)</label>
            <input name="notes" value="{{ old('notes', 'Backfill dari Excel') }}" maxlength="1000"
                class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </div>

        <p class="text-[11px] text-stone-400 mb-3">
            Harga otomatis mengikuti tier mitra (distributor/reseller) dari Produk Master. PO langsung berstatus <b>selesai</b>.
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
let n = 1;
function addRow() {
    const first = document.querySelector('[data-row]');
    const row = first.cloneNode(true);
    row.querySelectorAll('select, input').forEach(el => {
        el.name = el.name.replace(/items\[\d+\]/, `items[${n}]`);
        el.value = '';
    });
    document.getElementById('rows').appendChild(row);
    n++;
}
</script>
@endsection
