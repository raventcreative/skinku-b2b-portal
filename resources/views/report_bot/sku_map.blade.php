@extends('layouts.app')
@section('title', 'Peta SKU Report Bot')
@section('heading', 'Peta SKU — Parser Report Bot')

@section('content')
<div class="max-w-4xl space-y-5">
    <a href="{{ route('settings.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Pengaturan</a>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm text-stone-600">
            Peta ini dipakai parser <b>TikTok Income</b> untuk mengenali SKU dari file <b>"Semua pesanan"</b>.
            Kunci = <b>SKU ID</b> (kolom "SKU ID" di CSV, angka), dipetakan ke <b>kategori × jumlah pcs</b>.
            Untuk <b>bundle</b>, tambah beberapa komponen dengan SKU ID yang sama.
        </p>
        <p class="text-[11px] text-stone-400 mt-2">
            Menambah SKU di sini <b>tak perlu deploy</b> — langsung dipakai import berikutnya. Total terdaftar:
            <b>{{ $maps->count() }}</b> SKU ID.
        </p>
    </div>

    {{-- Tambah / ubah komponen --}}
    <form method="POST" action="{{ route('report-bot.sku-map.store') }}" class="bg-white rounded-2xl border border-stone-200 p-5">
        @csrf
        <p class="text-sm font-bold text-stone-800 mb-3">Tambah / Ubah Komponen SKU</p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
            <label class="block text-sm sm:col-span-2 lg:col-span-1">
                <span class="text-xs font-semibold text-stone-600">SKU ID (angka)</span>
                <input name="sku_id" value="{{ old('sku_id') }}" required inputmode="numeric" placeholder="mis. 1736331520467240874"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-mono tabular-nums">
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Kategori</span>
                <select name="category" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white text-sm">
                    @foreach($categories as $c)<option value="{{ $c }}" @selected(old('category') === $c)>{{ $c }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Jumlah (pcs)</span>
                <input type="number" name="qty" min="1" max="99" value="{{ old('qty', 1) }}" required
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
            </label>
            <label class="block text-sm sm:col-span-2 lg:col-span-1">
                <span class="text-xs font-semibold text-stone-600">Catatan (nama produk)</span>
                <input name="note" maxlength="255" value="{{ old('note') }}" placeholder="mis. Japanese Pink Exfo 3pcs"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
        </div>
        <div class="mt-4">
            <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan komponen</button>
            <span class="ml-2 text-[11px] text-stone-400">Isi ulang SKU ID + kategori yang sama = memperbarui jumlahnya.</span>
        </div>
    </form>

    {{-- Daftar peta --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-bold text-stone-800 mb-3">SKU Terdaftar</p>
        @forelse($maps as $skuId => $comps)
            <div class="py-3 border-t border-stone-100 first:border-t-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-xs text-stone-700 tabular-nums">{{ $skuId }}</span>
                    @php $note = optional($comps->firstWhere('note', '!=', null))->note; @endphp
                    @if($note)<span class="text-[11px] text-stone-400">— {{ $note }}</span>@endif
                </div>
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach($comps as $m)
                        <span class="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-full bg-stone-100 text-xs text-stone-700">
                            {{ $m->category }} <b class="tabular-nums">×{{ $m->qty }}</b>
                            <form method="POST" action="{{ route('report-bot.sku-map.destroy', $m) }}" onsubmit="return confirm('Hapus komponen {{ $m->category }} dari SKU {{ $skuId }}?')">
                                @csrf @method('DELETE')
                                <button class="w-4 h-4 rounded-full hover:bg-rose-200 text-rose-500 leading-none" title="hapus">&times;</button>
                            </form>
                        </span>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-400 italic">Belum ada peta SKU. Parser sementara memakai daftar bawaan (konstanta).</p>
        @endforelse
    </div>
</div>
@endsection
