@extends('layouts.app')
@section('title', 'Edit PO')
@section('heading', 'Edit Purchase Order')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-stone-500">Mengubah isi <span class="font-bold text-stone-800">{{ $po->po_number }}</span> — tambah/kurangi produk &amp; qty. Alamat &amp; ongkir tidak diubah di sini.</p>
    <a href="{{ route('purchase-orders.show', $po) }}" class="text-xs text-stone-500 hover:text-stone-800">← Batal, kembali ke detail</a>
</div>

@if($errors->any())
    <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('purchase-orders.update', $po) }}">
    @csrf
    @method('PUT')
    <div class="grid lg:grid-cols-3 gap-6">
        @include('purchase_orders._catalog', ['priceRole' => $po->user_role])

        <div class="space-y-4">
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <h3 class="text-sm font-bold text-stone-800 mb-3">Ringkasan</h3>
                <div class="flex justify-between text-sm mb-2"><span class="text-stone-500">Total Item</span><span id="totalQty" class="font-semibold">0</span></div>
                <div class="flex justify-between text-lg border-t border-stone-100 pt-3"><span class="text-stone-600 text-sm">Total Barang</span><span id="totalAmount" class="font-bold text-emerald-700">Rp 0</span></div>
                <p class="text-[10px] text-stone-400 mt-2">Total dihitung ulang di server saat disimpan. Diskon &amp; ongkir yang sudah ada tidak berubah.</p>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <button class="w-full py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan Perubahan</button>
            </div>
        </div>
    </div>
</form>
@endsection
