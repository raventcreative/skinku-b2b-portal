@extends('layouts.app')
@section('title', 'Buat PO')
@section('heading', 'Ajukan Purchase Order Baru')

@section('content')
<form method="POST" action="{{ route('purchase-orders.store') }}">
    @csrf
    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Katalog Produk · Harga {{ $user->role }}</div>
            <div class="overflow-x-auto">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr><th class="text-left px-4 py-2">Produk</th><th class="text-right">Harga Satuan</th><th class="text-right">Stok Pusat</th><th class="text-center w-32">Qty</th></tr>
                </thead>
                <tbody>
                    @forelse($products as $i => $p)
                        @php $price = $p->{$priceField}; $urls = $p->imageUrls(); @endphp
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @if(count($urls))
                                        <a href="{{ $urls[0] }}" class="glightbox shrink-0" data-gallery="po-prod-{{ $p->id }}" title="Klik untuk lihat foto">
                                            <img src="{{ $urls[0] }}" class="w-11 h-11 rounded-lg object-cover border border-stone-200 hover:opacity-80 transition">
                                        </a>
                                        @foreach(array_slice($urls, 1) as $u)
                                            <a href="{{ $u }}" class="glightbox" data-gallery="po-prod-{{ $p->id }}" style="display:none"></a>
                                        @endforeach
                                    @else
                                        <span class="w-11 h-11 rounded-lg bg-stone-100 flex items-center justify-center shrink-0">{{ $p->image ?: '🧴' }}</span>
                                    @endif
                                    <div>
                                        <p class="font-semibold text-stone-800">{{ $p->name }}</p>
                                        <p class="text-[10px] text-stone-400">{{ $p->sku }}@if(count($urls) > 1) · {{ count($urls) }} foto @endif</p>
                                    </div>
                                </div>
                                <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $p->id }}">
                            </td>
                            <td class="text-right text-stone-700" data-price="{{ $price }}">Rp {{ number_format($price, 0, ',', '.') }}</td>
                            <td class="text-right text-stone-500">{{ $p->hq_stock }}</td>
                            <td class="text-center">
                                <input type="number" min="0" value="0" name="items[{{ $i }}][qty]"
                                       class="qty-input w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-center"
                                       data-price="{{ $price }}" oninput="recalc()">
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Tidak ada produk aktif.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

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
                <button class="w-full py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Ajukan PO</button>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
    function recalc() {
        let qtySum = 0, amount = 0;
        document.querySelectorAll('.qty-input').forEach(inp => {
            const q = parseInt(inp.value) || 0;
            qtySum += q;
            amount += q * parseFloat(inp.dataset.price || 0);
        });
        document.getElementById('totalQty').textContent = qtySum;
        document.getElementById('totalAmount').textContent = 'Rp ' + amount.toLocaleString('id-ID');
    }
</script>
@endpush
