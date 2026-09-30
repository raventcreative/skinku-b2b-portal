@extends('layouts.app')
@section('title', 'Katalog Paket Join')
@section('heading', 'Katalog Paket Join')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4 rounded-2xl border border-stone-200 bg-white p-5">
    <div>
        <p class="text-[10px] font-bold uppercase tracking-[.14em] text-red-700">Onboarding mitra</p>
        <p class="mt-1 max-w-xl text-sm text-stone-600">Atur produk dan harga paket untuk Reseller Bronze atau Gold.</p>
        <p class="mt-2 text-xs font-medium text-stone-400">{{ $packages->count() }} paket tersedia <span class="mx-1">·</span> {{ $packages->where('is_active', true)->count() }} aktif</p>
    </div>
    <a href="{{ route('join-packages.create') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Tambah paket</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="ui-table min-w-[760px] w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th scope="col" class="text-left px-4 py-3">Nama Paket</th>
                <th scope="col" class="text-left">Tier Target</th>
                <th scope="col" class="text-right">Harga</th>
                <th scope="col" class="text-right">Produk</th>
                <th scope="col" class="text-left">Status</th>
                <th scope="col" class="text-right px-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($packages as $pkg)
                <tr>
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $pkg->name }}</td>
                    <td class="text-stone-600"><span class="inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-semibold text-indigo-700">{{ str_replace('_', ' ', $pkg->target_role) }}</span></td>
                    <td class="text-right font-semibold tabular-nums text-stone-800">Rp {{ number_format($pkg->price, 0, ',', '.') }}</td>
                    <td class="text-right tabular-nums text-stone-600">{{ $pkg->items_count }}</td>
                    <td>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold {{ $pkg->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-600' }}">
                            {{ $pkg->is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <div class="flex justify-end gap-2">
                        <a href="{{ route('join-packages.edit', $pkg) }}" class="inline-flex min-h-8 items-center rounded-lg border border-stone-200 px-3 font-semibold text-stone-600 hover:bg-stone-50">Edit</a>
                        <form method="POST" action="{{ route('join-packages.destroy', $pkg) }}" onsubmit="return confirm('Hapus paket join ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="inline-flex min-h-8 items-center rounded-lg border border-rose-200 px-3 font-semibold text-rose-700 hover:bg-rose-50">Hapus</button>
                        </form>
                        </div>
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
