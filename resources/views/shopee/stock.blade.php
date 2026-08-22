@extends('layouts.app')
@section('title', 'SKU & Stok Shopee')
@section('heading', 'Pemetaan SKU Shopee')

@section('content')
<a href="{{ route('shopee.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

<div class="mt-3 px-4 py-2.5 rounded-xl bg-teal-50 border border-teal-200 text-teal-800 text-[11px]">
    ℹ️ SKU yang cocok otomatis dengan SKU produk SKINKU tidak perlu dipetakan manual. Daftar di bawah adalah SKU
    yang <b>belum dikenali</b> dari order Shopee yang sudah ditarik. 1 SKU Shopee bisa = beberapa produk SKINKU × qty
    (mis. paket bundle), dan berlaku untuk semua order begitu dipetakan.
</div>

<div class="mt-4">
    @if(count($needMap))
        @php $products = \App\Models\Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']); @endphp
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
            @foreach($needMap as $sku => $info)
                <div class="bg-white border border-stone-200 rounded-xl p-3">
                    <div class="mb-1.5">
                        <span class="font-mono text-stone-800 text-sm">{{ $sku }}</span>
                        @if($info['components']->isEmpty())<span class="ml-1 text-[10px] text-rose-500">belum ada resep</span>@endif
                        <div class="text-[10px] text-stone-400 truncate">{{ $info['name'] }}</div>
                    </div>
                    @foreach($info['components'] as $c)
                        <div class="flex items-center gap-1.5 text-xs py-0.5">
                            <span class="text-emerald-700 truncate">{{ $c->product?->name ?? '(produk terhapus)' }}</span>
                            <span class="text-stone-400 shrink-0">× {{ $c->qty }}</span>
                            <form method="POST" action="{{ route('shopee.sku-map.remove', $c) }}" onsubmit="return confirm('Hapus komponen ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[10px] text-rose-500 hover:text-rose-700 underline">hapus</button>
                            </form>
                        </div>
                    @endforeach
                    <form method="POST" action="{{ route('shopee.sku-map') }}" class="flex items-center gap-1.5 text-xs mt-2 pt-2 border-t border-stone-100">
                        @csrf
                        <input type="hidden" name="shopee_sku" value="{{ $sku }}">
                        <select name="product_id" required class="px-2 py-1 border border-stone-300 rounded flex-1 min-w-0">
                            <option value="">— produk —</option>
                            @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                        </select>
                        <span class="text-stone-400 shrink-0">×</span>
                        <input type="number" name="qty" value="1" min="1" max="999" class="w-12 px-1.5 py-1 border border-stone-300 rounded text-right shrink-0">
                        <button type="submit" class="px-2.5 py-1 bg-stone-800 text-white rounded hover:bg-stone-900 shrink-0">+</button>
                    </form>
                </div>
            @endforeach
        </div>
    @else
        <div class="bg-white rounded-2xl border border-stone-200 px-4 py-8 text-center text-stone-400 text-sm">
            Semua SKU Shopee yang tersimpan sudah cocok / dipetakan ke produk SKINKU.
        </div>
    @endif
</div>
@endsection
