@extends('layouts.app')
@section('title', 'Buat PO')
@section('heading', 'Ajukan Purchase Order Baru')

@section('content')
@if($isDemo ?? false)
    <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 flex items-start gap-3">
        <svg class="w-5 h-5 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
        <div>
            <p class="text-sm font-bold text-amber-900">Mode lihat saja (demo)</p>
            <p class="text-sm text-amber-800 mt-0.5">Purchase Order hanya bisa dilakukan untuk <b>Distributor</b> &amp; <b>Grand Distributor</b>. Sebagai Reseller, pemesanan stok dilakukan langsung ke distributor Anda.</p>
        </div>
    </div>
@endif
<form method="POST" action="{{ route('purchase-orders.store') }}">
    @csrf
    <div class="grid lg:grid-cols-3 gap-6">
        @include('purchase_orders._catalog')

        <div class="space-y-4">
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <h3 class="text-sm font-bold text-stone-800 mb-3">Ringkasan</h3>
                <div class="flex justify-between text-sm mb-2"><span class="text-stone-500">Total Item</span><span id="totalQty" class="font-semibold">0</span></div>
                <div class="flex justify-between text-lg border-t border-stone-100 pt-3"><span class="text-stone-600 text-sm">Total Bayar</span><span id="totalAmount" class="font-bold text-emerald-700">Rp 0</span></div>
                <p class="text-[10px] text-stone-400 mt-2">Total dihitung ulang otomatis di server berdasarkan harga & role Anda.</p>
                <p class="text-[10px] text-amber-600 mt-1">Ongkir belum termasuk — akan ditetapkan admin setelah PO dibuat, lalu Anda transfer & unggah bukti.</p>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-stone-700 mb-1">Alamat Pengiriman</label>
                    <textarea name="shipping_address" rows="2" class="w-full px-3 py-2 text-sm border border-stone-300 rounded-lg">{{ old('shipping_address', $user->address) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-stone-700 mb-1">Catatan</label>
                    <textarea name="notes" rows="2" class="w-full px-3 py-2 text-sm border border-stone-300 rounded-lg">{{ old('notes') }}</textarea>
                </div>
                <button {{ ($isDemo ?? false) ? 'disabled' : '' }} class="w-full py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-red-600">{{ ($isDemo ?? false) ? 'PO hanya untuk Distributor & Grand' : 'Ajukan PO' }}</button>
            </div>
        </div>
    </div>
</form>
@endsection
