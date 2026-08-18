@extends('layouts.app')
@section('title', 'Katalog Paket Join')
@section('heading', 'Katalog Paket Join')

@section('content')
<div class="flex justify-between items-center mb-4">
    <p class="text-sm text-stone-500">Paket produk untuk onboarding mitra baru (join sebagai Reseller Bronze/Gold).</p>
    <a href="{{ route('join-packages.create') }}" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Tambah Paket</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Nama Paket</th>
                <th class="text-left">Tier Target</th>
                <th class="text-right">Harga</th>
                <th class="text-right">#Item</th>
                <th class="text-left">Status</th>
                <th class="text-right px-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($packages as $pkg)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $pkg->name }}</td>
                    <td class="text-stone-600">{{ str_replace('_', ' ', $pkg->target_role) }}</td>
                    <td class="text-right text-stone-700">Rp {{ number_format($pkg->price, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-500">{{ $pkg->items_count }}</td>
                    <td>
                        <span class="px-2 py-0.5 rounded-full text-[10px] {{ $pkg->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-200 text-stone-600' }}">
                            {{ $pkg->is_active ? 'aktif' : 'nonaktif' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('join-packages.edit', $pkg) }}" class="text-stone-500 hover:text-stone-900 font-semibold">Edit</a>
                        <form method="POST" action="{{ route('join-packages.destroy', $pkg) }}" class="inline" onsubmit="return confirm('Hapus paket join ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="ml-2 text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-stone-400">Belum ada paket join.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
