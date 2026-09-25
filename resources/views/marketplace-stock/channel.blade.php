@extends('layouts.app')
@section('title','Stok & Harga '.ucfirst($channel))
@section('heading','Stok & Harga '.ucfirst($channel))
@section('content')
@php $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.'); @endphp
<div class="space-y-4">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input yang dimasukkan.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-xs text-stone-500 mb-3">Efektif = override {{ ucfirst($channel) }} kalau ada, kalau tidak ikut Master. "Ikut Master" mengembalikannya.</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Refresh listing</button></form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⇪ Sinkron semua</button></form>
            <a href="{{ route('marketplace-stock.index') }}" class="px-4 py-2 text-sm bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">← Produk Master</a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Master di {{ ucfirst($channel) }} — {{ count($rows) }}</div>
        @if(count($rows))
            <div class="overflow-x-auto"><table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Unit</th><th class="text-left">Stok Efektif</th><th class="text-left">Override Stok</th><th class="text-left">Harga Efektif</th><th class="text-left">Override Harga</th><th class="text-left pr-4">Kirim</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php $m = $row['master']; @endphp
                    <tr class="border-t border-stone-100 align-top">
                        <td class="px-4 py-2.5"><div class="font-semibold text-stone-800">{{ $m->name }}</div><div class="text-[11px] text-stone-400 font-mono">{{ $m->master_sku }}</div></td>
                        <td class="py-2.5 font-semibold">{{ $row['eff_stock'] ?? '—' }}</td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.channel.stok', ['channel'=>$channel,'master'=>$m]) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="quantity" min="0" step="1" value="{{ $row['override_stock'] }}" placeholder="ikut master" class="w-20 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">Set</button>
                            </form>
                            @if($row['override_stock'] !== null)
                                <form method="POST" action="{{ route('marketplace-stock.ikut-master', ['channel'=>$channel,'master'=>$m]) }}" class="mt-1">@csrf<input type="hidden" name="field" value="stock"><button class="px-2 py-1 bg-amber-600 text-white rounded-lg text-[11px]">Ikut Master</button></form>
                            @endif
                        </td>
                        <td class="py-2.5 font-semibold">{{ $rp($row['eff_price']) }}</td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.channel.harga', ['channel'=>$channel,'master'=>$m]) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="price" min="0" step="any" value="{{ $row['override_price'] !== null ? (int) $row['override_price'] : '' }}" placeholder="ikut master" class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button class="px-2 py-1 bg-stone-800 text-white rounded-lg text-[11px]">Set</button>
                            </form>
                            @if($row['override_price'] !== null)
                                <form method="POST" action="{{ route('marketplace-stock.ikut-master', ['channel'=>$channel,'master'=>$m]) }}" class="mt-1">@csrf<input type="hidden" name="field" value="price"><button class="px-2 py-1 bg-amber-600 text-white rounded-lg text-[11px]">Ikut Master</button></form>
                            @endif
                        </td>
                        <td class="py-2.5 pr-4 text-[11px] text-stone-500">{{ $row['listing']->last_status ?? '—' }}/{{ $row['listing']->last_price_status ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada master di channel ini.</p>
        @endif
    </div>
</div>
@endsection
