@extends('layouts.app')
@section('title', 'Buat Retur')
@section('heading', 'Retur PO '.$po->po_number)

@section('content')
<div class="max-w-2xl">
    @if(session('error'))<div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>@endif

    <p class="text-sm text-stone-500 mb-4">Pilih item &amp; jumlah yang diretur. {{ $isHq ? 'Sebagai HQ, retur langsung berlaku (stok & komisi disesuaikan).' : 'Pengajuan akan menunggu persetujuan HQ.' }}</p>

    <form method="POST" action="{{ route('retur.store') }}">
        @csrf
        <input type="hidden" name="purchase_order_id" value="{{ $po->id }}">

        <div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto mb-4">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr><th class="text-left px-4 py-2">Produk</th><th class="text-right px-4 py-2">Dibeli</th><th class="text-right px-4 py-2">Sudah diretur</th><th class="text-center px-4 py-2 w-32">Qty retur</th></tr>
                </thead>
                <tbody>
                    @foreach($po->items as $i => $item)
                        @php $sudah = (int) ($returnedQty[$item->id] ?? 0); $sisa = (int) $item->qty - $sudah; @endphp
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2 font-semibold text-stone-800">{{ $item->product_name }}<div class="text-[10px] text-stone-400 font-mono">{{ $item->sku }}</div></td>
                            <td class="px-4 py-2 text-right text-stone-600">{{ $item->qty }}</td>
                            <td class="px-4 py-2 text-right text-stone-500">{{ $sudah }}</td>
                            <td class="px-4 py-2 text-center">
                                <input type="hidden" name="items[{{ $i }}][po_item_id]" value="{{ $item->id }}">
                                <input type="number" name="items[{{ $i }}][qty]" min="0" max="{{ $sisa }}" value="0"
                                    class="w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-center" {{ $sisa <= 0 ? 'disabled' : '' }}>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Kondisi barang</label>
                <select name="kondisi" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="normal">Normal — barang masuk stok lagi (bisa dijual)</option>
                    <option value="rusak">Rusak — write-off (tak nambah stok penerima)</option>
                </select>
            </div>
            <label class="flex items-start gap-2 p-3 rounded-lg bg-indigo-50 border border-indigo-100 cursor-pointer">
                <input type="checkbox" name="from_customer" value="1" class="mt-0.5" {{ old('from_customer') ? 'checked' : '' }}>
                <span class="text-xs text-stone-700">
                    <b class="text-indigo-800">Barang dari retur pelanggan</b> — centang kalau mitra sudah tidak memegang barang di stok sistem (sudah terjual, lalu dikembalikan pelanggan). Kalau dicentang, <b>stok mitra tidak dikurangi</b>; penerima (HQ) tetap restock bila kondisi Normal, komisi tetap ditarik. Pakai ini kalau muncul error “stok mitra tidak mencukupi”.
                </span>
            </label>
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Alasan (opsional)</label>
                <textarea name="reason" rows="2" maxlength="500" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('reason') }}</textarea>
            </div>
            <button class="w-full py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">{{ $isHq ? 'Proses Retur' : 'Ajukan Retur' }}</button>
        </div>
        <a href="{{ route('purchase-orders.show', $po) }}" class="block text-center text-xs text-stone-500 hover:text-stone-800 mt-3">← Batal, kembali ke detail PO</a>
    </form>
</div>
@endsection
