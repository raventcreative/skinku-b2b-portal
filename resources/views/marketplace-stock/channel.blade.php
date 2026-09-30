@extends('layouts.app')
@section('title','Stok & Harga '.ucfirst($channel))
@section('heading','Stok & Harga '.ucfirst($channel))
@section('content')
@php $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.'); @endphp
<div class="mx-auto max-w-[1440px] space-y-5 px-1 sm:px-2">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input yang dimasukkan.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5 shadow-sm">
        <div class="mb-4"><h2 class="text-sm font-bold text-stone-800">Stok &amp; Harga {{ ucfirst($channel) }}</h2><p class="text-xs text-stone-500 mt-1">Efektif memakai override {{ ucfirst($channel) }} bila ada. “Ikut Master” mengembalikan nilainya ke master.</p></div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-800 text-white rounded-lg hover:bg-stone-900"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 2.6-6.4L3 8m0-5v5h5m4 1v5l3 2"/></svg>Refresh listing</button></form>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-emerald-700 text-white rounded-lg hover:bg-emerald-800"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v5h-5M4 17v-5h5m-3-3a7 7 0 0 1 12-2l2 2M4 13l2 2a7 7 0 0 0 12-2"/></svg>Sinkron semua</button></form>
            <a href="{{ route('marketplace-stock.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>Produk master</a>
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
                                <button aria-label="Simpan override stok {{ $m->name }}" title="Simpan stok" class="inline-flex items-center justify-center w-8 h-8 bg-stone-800 text-white rounded-lg hover:bg-stone-900"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
                            </form>
                            @if($row['override_stock'] !== null)
                                <form method="POST" action="{{ route('marketplace-stock.ikut-master', ['channel'=>$channel,'master'=>$m]) }}" class="mt-1">@csrf<input type="hidden" name="field" value="stock"><button class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-100 text-amber-800 rounded-lg text-[11px] font-semibold hover:bg-amber-200"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m-6-6 6 6-6 6"/></svg>Ikut Master</button></form>
                            @endif
                        </td>
                        <td class="py-2.5 font-semibold">{{ $rp($row['eff_price']) }}</td>
                        <td class="py-2.5">
                            <form method="POST" action="{{ route('marketplace-stock.channel.harga', ['channel'=>$channel,'master'=>$m]) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="price" min="0" step="any" value="{{ $row['override_price'] !== null ? (int) $row['override_price'] : '' }}" placeholder="ikut master" class="w-24 px-2 py-1 border border-stone-300 rounded-lg text-xs">
                                <button aria-label="Simpan override harga {{ $m->name }}" title="Simpan harga" class="inline-flex items-center justify-center w-8 h-8 bg-stone-800 text-white rounded-lg hover:bg-stone-900"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
                            </form>
                            @if($row['override_price'] !== null)
                                <form method="POST" action="{{ route('marketplace-stock.ikut-master', ['channel'=>$channel,'master'=>$m]) }}" class="mt-1">@csrf<input type="hidden" name="field" value="price"><button class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-100 text-amber-800 rounded-lg text-[11px] font-semibold hover:bg-amber-200"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m-6-6 6 6-6 6"/></svg>Ikut Master</button></form>
                            @endif
                        </td>
                        <td class="py-2.5 pr-4 text-[11px]">
                            @php $lst = $row['listing']; $sFail = $lst?->last_status === 'failed'; $pFail = $lst?->last_price_status === 'failed'; $cFail = $lst?->last_content_status === 'failed'; @endphp
                            <span class="{{ ($sFail || $pFail) ? 'text-rose-600 font-semibold' : 'text-stone-500' }}">{{ $lst?->last_status ?? '—' }}/{{ $lst?->last_price_status ?? '—' }}</span>
                            @if($lst?->last_error)<div class="text-rose-500 mt-0.5 max-w-[240px] break-words" title="{{ $lst->last_error }}">stok: {{ \Illuminate\Support\Str::limit($lst->last_error, 80) }}</div>@endif
                            @if($lst?->last_price_error)<div class="text-rose-500 mt-0.5 max-w-[240px] break-words" title="{{ $lst->last_price_error }}">harga: {{ \Illuminate\Support\Str::limit($lst->last_price_error, 80) }}</div>@endif
                            {{-- Status dorong KONTEN (manual, dari tombol "Dorong Konten") — baris sendiri, tak ikut span stok/harga di atas. Kosong = belum pernah dorong. --}}
                            @if($lst?->last_content_status)<div class="mt-0.5 {{ $cFail ? 'text-rose-600 font-semibold' : 'text-stone-500' }}">Konten: {{ $cFail ? 'gagal' : $lst->last_content_status }}</div>@endif
                            @if($lst?->last_content_error)<div class="text-rose-500 mt-0.5 max-w-[240px] break-words" title="{{ $lst->last_content_error }}">konten: {{ \Illuminate\Support\Str::limit($lst->last_content_error, 80) }}</div>@endif
                        </td>
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
