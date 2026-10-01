{{-- Kolom Harga · Stok · Produk Terkait · Toko Terkait satu master (master tunggal ATAU satu varian). Butuh $m. --}}
@php
    $produkTerkait = $m->listings->count();
    $tokoTerkait = $m->listings->pluck('channel')->unique()->count();
@endphp
    <td class="px-4 py-3">
        <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1">@csrf
            <input type="number" step="0.01" min="0" name="price" value="{{ $m->base_price }}" placeholder="—" class="w-24 px-2 py-1 border border-stone-200 rounded">
            <button aria-label="Simpan harga {{ $m->name }}" title="Simpan harga" class="inline-flex items-center justify-center w-8 h-8 text-indigo-700 border border-indigo-100 rounded-lg hover:bg-indigo-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
        </form>
    </td>
    @if($m->relationLoaded('bundleItems') && $m->bundleItems->isNotEmpty())
        {{-- Bundle ber-resep: stok dihitung dari komponen, tak bisa diisi manual. --}}
        <td class="px-4 py-3 text-sm"><span class="font-semibold text-stone-800">{{ $stokBundle[$m->id] ?? '—' }}</span> <span class="text-[11px] text-emerald-700" title="Dihitung dari stok isi bundling">otomatis</span>@if(($isiKosong[$m->id] ?? '') !== '')<div class="text-[11px] text-amber-700">stok isi belum diisi: {{ $isiKosong[$m->id] }}</div>@endif</td>
    @else
    <td class="px-4 py-3">
        <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1">@csrf
            <input type="number" min="0" name="quantity" value="{{ $m->base_stock }}" placeholder="—" class="w-20 px-2 py-1 border border-stone-200 rounded">
            <button aria-label="Simpan stok {{ $m->name }}" title="Simpan stok" class="inline-flex items-center justify-center w-8 h-8 text-indigo-700 border border-indigo-100 rounded-lg hover:bg-indigo-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
        </form>
    </td>
    @endif
    <td class="px-4 py-3">
        @if($produkTerkait > 0)
            <button type="button" data-master-id="{{ $m->id }}" data-master-sku="{{ $m->master_sku }}" data-master-name="{{ $m->name }}" data-tab="terkait" onclick="mpOpenKaitkan(this)" class="text-indigo-600 hover:underline">{{ $produkTerkait }} Produk</button>
        @else <span class="text-stone-400">—</span> @endif
    </td>
    <td class="px-4 py-3">
        @if($tokoTerkait > 0)
            <button type="button" data-master-id="{{ $m->id }}" data-master-sku="{{ $m->master_sku }}" data-master-name="{{ $m->name }}" data-tab="terkait" onclick="mpOpenKaitkan(this)" class="text-indigo-600 hover:underline">{{ $tokoTerkait }} Toko</button>
        @else <span class="text-stone-400">—</span> @endif
    </td>
