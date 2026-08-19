{{-- Kartu katalog produk + input qty. Dipakai bersama Buat PO & Edit PO.
     Param:
       $products      koleksi produk aktif (urutan sama di create & edit → index qty konsisten)
       $priceRole     tier harga yang dipakai (default $user->role). Saat Edit oleh admin,
                      HARUS $po->user_role — bukan role editor — agar cocok dgn server.
       $qtyByProduct  map [product_id => qty] untuk prefill (default 0 = kosong). --}}
@php
    $priceRole = $priceRole ?? $user->role;
    $qtyByProduct = $qtyByProduct ?? collect();
@endphp
<div class="lg:col-span-2 bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Katalog Produk · Harga {{ $priceRole }}</div>
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr><th class="text-left px-4 py-2">Produk</th><th class="text-right">Harga Satuan</th><th class="text-right">Stok Pusat</th><th class="text-center w-32">Qty</th></tr>
        </thead>
        <tbody>
            @forelse($products as $i => $p)
                @php $price = $p->priceForRole($priceRole); $urls = $p->imageUrls(); $qty = (int) ($qtyByProduct[$p->id] ?? 0); @endphp
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
                        <input type="number" min="0" value="{{ $qty }}" name="items[{{ $i }}][qty]"
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
    document.addEventListener('DOMContentLoaded', recalc);
</script>
@endpush
