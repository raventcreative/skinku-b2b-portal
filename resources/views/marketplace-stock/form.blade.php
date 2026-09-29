@extends('layouts.app')
@section('title', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Master')
@section('heading', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Baru')
@section('content')
@php $gallery = $master->exists ? $master->fileGallery(\App\Models\MarketplaceMaster::MASTER_IMAGE) : []; @endphp
<div class="max-w-3xl space-y-4">
    {{-- Flash session('status') sudah ditampilkan layouts.app — jangan diulang di sini (banner dobel habis Hapus/Jadikan Utama). --}}
    @if($errors->any())<div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif

    {{-- Galeri foto existing — WAJIB DI LUAR form utama (HTML tak boleh <form> nested; tiap tombol hapus/utama adalah form sendiri). --}}
    @if($master->exists)
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-3">
            <h3 class="font-semibold text-stone-800">Foto Produk <span class="text-xs font-normal text-stone-400">(maks 9)</span></h3>
            @if($gallery)
                <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                    @foreach($gallery as $i => $g)
                        <div class="relative border border-stone-200 rounded-lg p-1">
                            <img src="{{ $g['url'] }}" alt="" class="w-full h-20 object-cover rounded">
                            @if($i === 0)<span class="absolute top-1 left-1 text-[9px] bg-indigo-600 text-white px-1 rounded">Utama</span>@endif
                            <div class="flex gap-2 mt-1 justify-center">
                                @if($i !== 0)
                                    <form method="POST" action="{{ route('marketplace-stock.master.foto.utama', [$master, $g['id']]) }}">@csrf<button class="text-[10px] text-indigo-600 hover:underline">Jadikan Utama</button></form>
                                @endif
                                <form method="POST" action="{{ route('marketplace-stock.master.foto.hapus', [$master, $g['id']]) }}" onsubmit="return confirm('Hapus foto ini?')">@csrf @method('DELETE')<button class="text-[10px] text-rose-600 hover:underline">Hapus</button></form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-stone-400">Belum ada foto. Tambah lewat form di bawah.</p>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ $master->exists ? route('marketplace-stock.update', $master) : route('marketplace-stock.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @if($master->exists)@method('PUT')@endif

        {{-- Informasi Produk --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Informasi Produk</h3>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Nama Produk</label>
                <input type="text" name="name" value="{{ old('name', $master->name) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Kategori</label>
                <input type="text" name="category" value="{{ old('category', $master->category) }}" placeholder="mis. Perawatan Wajah / BB Cream" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Deskripsi</label>
                <textarea name="description" rows="4" class="w-full px-3 py-2 border border-stone-200 rounded-lg">{{ old('description', $master->description) }}</textarea>
            </div>
        </div>

        {{-- Informasi Penjualan --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Informasi Penjualan</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Harga</label>
                    <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $master->base_price) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Stok</label>
                    <input type="number" min="0" name="stock" value="{{ old('stock', $master->base_stock) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Master SKU</label>
                    <input type="text" name="master_sku" value="{{ old('master_sku', $master->master_sku) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Barcode</label>
                    <input type="text" name="barcode" value="{{ old('barcode', $master->barcode) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tipe</label>
                    <select name="is_bundle" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                        <option value="0" @selected(! old('is_bundle', $master->is_bundle))>Satuan</option>
                        <option value="1" @selected(old('is_bundle', $master->is_bundle))>Bundle</option>
                    </select>
                </div>
            </div>
            <p class="text-[11px] text-stone-400">Harga & stok akan disinkron ke TikTok/Shopee. Field lain disimpan di SKINKU.</p>
        </div>

        {{-- Tambah Foto (upload baru; kelola foto lama ada di galeri ATAS, di luar form ini) --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-3">
            <h3 class="font-semibold text-stone-800">Tambah Foto</h3>
            <input type="file" name="foto[]" accept="image/*" multiple class="block text-sm">
            <p class="text-xs text-stone-400 mt-1">JPG/PNG, tiap file maks 5MB. Bisa pilih beberapa sekaligus. Total maks 9.</p>
        </div>

        {{-- Pengiriman --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Pengiriman</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Berat (g)</label>
                    <input type="number" min="0" name="weight_g" value="{{ old('weight_g', $master->weight_g) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Panjang (cm)</label>
                    <input type="number" min="0" name="length_cm" value="{{ old('length_cm', $master->length_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Lebar (cm)</label>
                    <input type="number" min="0" name="width_cm" value="{{ old('width_cm', $master->width_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tinggi (cm)</label>
                    <input type="number" min="0" name="height_cm" value="{{ old('height_cm', $master->height_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
            </div>
        </div>

        <div class="flex gap-2">
            <button class="px-5 py-2 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">Simpan</button>
            <a href="{{ route('marketplace-stock.index') }}" class="px-5 py-2 bg-stone-100 text-stone-600 rounded-lg hover:bg-stone-200">Batal</a>
        </div>
    </form>
</div>
@endsection
