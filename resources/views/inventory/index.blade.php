@extends('layouts.app')
@section('title', 'Pemantauan Stok')
@section('heading', 'Pemantauan Inventori')

@section('content')
@php
    $u = $user;
    // Value tetap IN/OUT/ADJUSTMENT (kontrak backend), tapi label bahasa manusia.
    // Sebelumnya dropdown menampilkan kode Inggris mentah — mitra yang mencari
    // "stok keluar" tak menemukannya di balik kata "OUT".
    $movementTypes = [
        \App\Models\StockMovement::TYPE_OUT => 'Barang Keluar (−)',
        \App\Models\StockMovement::TYPE_IN => 'Barang Masuk (+)',
        \App\Models\StockMovement::TYPE_ADJUSTMENT => 'Koreksi / Penyesuaian',
    ];
@endphp

@if($u->canDo('manage_hq_stock'))
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Stok Pusat (HQ)</div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr><th class="text-left px-4 py-2">Produk</th><th class="text-left">SKU</th><th class="text-right">Stok</th><th class="text-right px-4 w-72">Penyesuaian</th></tr>
            </thead>
            <tbody>
                @forelse($hqProducts as $p)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-3 font-semibold text-stone-800">{{ $p->name }}</td>
                        <td class="text-stone-500">{{ $p->sku }}</td>
                        <td class="text-right font-bold {{ $p->hq_stock <= 0 ? 'text-rose-600' : 'text-stone-800' }}">{{ $p->hq_stock }}</td>
                        <td class="px-4 py-2">
                            {{-- Alasan WAJIB: penyesuaian manual tanpa keterangan jadi
                                 gerakan stok yang tak bisa dijelaskan siapa pun selamanya.
                                 Dulu form ini tak punya kolomnya sama sekali. --}}
                            <form method="POST" action="{{ route('inventory.hq-adjust') }}" class="flex gap-1 justify-end items-center">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $p->id }}">
                                <select name="type" class="px-2 py-1 border border-stone-300 rounded text-[11px]">
                                    @foreach($movementTypes as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                                </select>
                                <input type="number" name="quantity" min="1" value="1" class="w-16 px-2 py-1 border border-stone-300 rounded text-center text-[11px]">
                                <input type="text" name="notes" required maxlength="500" placeholder="Alasan (wajib)"
                                    class="w-40 px-2 py-1 border border-stone-300 rounded text-[11px]">
                                <button class="px-3 py-1 bg-red-600 text-white rounded text-[11px]">Simpan</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Belum ada produk.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
@endif

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 flex flex-wrap items-center gap-2">
        <span class="text-sm font-bold text-stone-800">{{ $u->isPartner() ? 'Stok Saya' : 'Stok Mitra' }}</span>
        @if($u->isPartner())
            <span class="text-[11px] text-stone-500">— catat barang keluar: cari produknya di tabel, pilih <b>Barang Keluar</b>, isi jumlah &amp; alasan, klik OK.</span>
        @endif
    </div>

    @if($u->isPartner())
        {{-- <details> = pemicu murni HTML, tak butuh JS. Sengaja di LUAR tabel
             supaya tetap muncul walau stok masih kosong — produk yang belum ada
             di daftar pun bisa dipilih di sini (barisnya dibuat otomatis saat
             pertama disesuaikan), termasuk untuk mengisi saldo awal. --}}
        <details class="border-b border-stone-100 group">
            <summary class="px-5 py-3 cursor-pointer text-sm font-semibold text-red-700 hover:bg-stone-50 select-none list-none flex items-center gap-2">
                <span class="text-lg leading-none">＋</span> Sesuaikan Stok Sendiri
                <span class="text-[11px] font-normal text-stone-400">(barang keluar, atau isi saldo awal)</span>
            </summary>
            <div class="px-5 pb-4 pt-1 bg-stone-50/60">
                <form method="POST" action="{{ route('inventory.partner-adjust') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $u->id }}">
                    <label class="text-[11px] text-stone-500">Produk
                        <select name="product_id" required class="mt-1 block w-56 px-2 py-1.5 border border-stone-300 rounded text-xs">
                            <option value="">— pilih produk —</option>
                            @foreach($activeProducts as $prod)
                                <option value="{{ $prod->id }}">{{ $prod->name }} ({{ $prod->sku }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-[11px] text-stone-500">Jenis
                        <select name="type" class="mt-1 block w-40 px-2 py-1.5 border border-stone-300 rounded text-xs">
                            @foreach($movementTypes as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-[11px] text-stone-500">Jumlah
                        <input type="number" name="quantity" min="1" value="1" required class="mt-1 block w-20 px-2 py-1.5 border border-stone-300 rounded text-xs text-center">
                    </label>
                    <label class="text-[11px] text-stone-500 flex-1 min-w-[12rem]">Alasan (wajib)
                        <input type="text" name="notes" required maxlength="500" placeholder="mis. jual ke customer / saldo awal" class="mt-1 block w-full px-2 py-1.5 border border-stone-300 rounded text-xs">
                    </label>
                    <button class="px-4 py-1.5 bg-red-600 text-white rounded text-xs hover:bg-red-700">Simpan</button>
                </form>
                <p class="text-[11px] text-stone-400 mt-2">
                    Barang keluar tak boleh melebihi stok tercatat. Untuk produk yang fisiknya sudah Anda pegang tapi belum tercatat, pilih <b>Barang Masuk</b> dengan alasan "saldo awal".
                </p>
            </div>
        </details>
    @endif
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                @if(!$u->isPartner())<th class="text-left px-4 py-2">Mitra</th>@endif
                <th class="text-left px-4 py-2">Produk</th><th class="text-right">Qty</th><th class="text-right">Min</th><th class="text-right px-4 w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($partnerStock as $line)
                <tr class="border-t border-stone-100 {{ $line->isLow() ? 'bg-rose-50/40' : '' }}">
                    @if(!$u->isPartner())<td class="px-4 py-3 text-stone-600">{{ $line->user->company_name ?? ($line->user->fullname ?? '-') }}</td>@endif
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $line->product->name ?? 'Produk' }}</td>
                    <td class="text-right font-bold {{ $line->isLow() ? 'text-rose-600' : 'text-stone-800' }}">{{ $line->quantity }}</td>
                    <td class="text-right text-stone-500">{{ $line->minimum_stock }}</td>
                    <td class="px-4 py-2">
                        <form method="POST" action="{{ route('inventory.partner-adjust') }}" class="flex gap-1 justify-end items-center">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $line->user_id }}">
                            <input type="hidden" name="product_id" value="{{ $line->product_id }}">
                            <select name="type" class="px-2 py-1 border border-stone-300 rounded text-[11px]">
                                @foreach($movementTypes as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                            </select>
                            <input type="number" name="quantity" min="1" value="1" class="w-14 px-2 py-1 border border-stone-300 rounded text-center text-[11px]">
                            <input type="text" name="notes" required maxlength="500" placeholder="Alasan (wajib)"
                                class="w-40 px-2 py-1 border border-stone-300 rounded text-[11px]">
                            <button class="px-3 py-1 bg-red-600 text-white rounded text-[11px]">OK</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-stone-400 text-xs leading-relaxed">
                    Belum ada stok tercatat.
                    @if($u->isPartner())
                        <span class="block mt-1">Stok muncul di sini setelah PO Anda diselesaikan HQ. Barang keluar hanya bisa dicatat dari stok yang tercatat — belum ada stok, belum ada yang bisa dikeluarkan.</span>
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $partnerStock->links() }}</div>
@endsection
