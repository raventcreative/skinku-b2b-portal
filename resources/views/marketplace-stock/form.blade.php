@extends('layouts.app')
@section('title', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Master')
@section('heading', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Baru')
@section('content')
<div class="max-w-2xl">
    @if($errors->any())<div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif
    <form method="POST" action="{{ $master->exists ? route('marketplace-stock.update', $master) : route('marketplace-stock.store') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
        @csrf
        @if($master->exists)@method('PUT')@endif
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Nama Produk</label>
            <input type="text" name="name" value="{{ old('name', $master->name) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
        </div>
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Master SKU</label>
            <input type="text" name="master_sku" value="{{ old('master_sku', $master->master_sku) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Harga</label>
                <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $master->base_price) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Stok</label>
                <input type="number" min="0" name="stock" value="{{ old('stock', $master->base_stock) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Tipe</label>
            <select name="is_bundle" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                <option value="0" @selected(! old('is_bundle', $master->is_bundle))>Satuan</option>
                <option value="1" @selected(old('is_bundle', $master->is_bundle))>Bundle</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-stone-700 mb-1">Foto</label>
            @if($master->imageUrl())<img src="{{ $master->imageUrl() }}" alt="" class="w-16 h-16 rounded-lg object-cover border border-stone-200 mb-2">@endif
            <input type="file" name="foto" accept="image/*" class="block text-sm">
            <p class="text-xs text-stone-400 mt-1">JPG/PNG, maks 5MB. Kosongkan bila tak ganti.</p>
        </div>
        <div class="flex gap-2 pt-2">
            <button class="px-5 py-2 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">Simpan</button>
            <a href="{{ route('marketplace-stock.index') }}" class="px-5 py-2 bg-stone-100 text-stone-600 rounded-lg hover:bg-stone-200">Batal</a>
        </div>
    </form>
</div>
@endsection
