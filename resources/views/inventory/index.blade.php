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
    <div class="px-5 py-3 border-b border-stone-100">
        <span class="text-sm font-bold text-stone-800">{{ $u->isPartner() ? 'Stok Saya' : 'Stok Mitra' }}</span>
    </div>

    @if($u->isPartner())
        {{-- DUA form terpisah, bukan satu dropdown Masuk/Keluar/Penyesuaian.
             Mitra berpikir dua hal: "saya jual barang" dan "stok saya sebenarnya
             sekian" — dropdown yang mencampur keduanya justru membingungkan.
             Di LUAR tabel supaya tetap ada walau stok kosong (baris dibuat
             otomatis saat pertama disentuh). --}}
        <div class="grid md:grid-cols-2 gap-px bg-stone-100 border-b border-stone-100">
            {{-- Form 1: barang keluar (yang paling sering) --}}
            <div class="bg-white p-5">
                <p class="text-sm font-bold text-stone-800 mb-1">📤 Catat Barang Keluar</p>
                <p class="text-[11px] text-stone-400 mb-3">Saat Anda menjual / mengirim barang ke pelanggan.</p>
                <form method="POST" action="{{ route('inventory.partner-adjust') }}" class="space-y-2">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $u->id }}">
                    <input type="hidden" name="type" value="{{ \App\Models\StockMovement::TYPE_OUT }}">
                    <select name="product_id" required class="block w-full px-2 py-1.5 border border-stone-300 rounded text-xs">
                        <option value="">— pilih produk —</option>
                        @foreach($activeProducts as $prod)
                            <option value="{{ $prod->id }}">{{ $prod->name }} ({{ $prod->sku }})</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <input type="number" name="quantity" min="1" value="1" required placeholder="Jumlah keluar"
                            class="w-28 px-2 py-1.5 border border-stone-300 rounded text-xs text-center">
                        <input type="text" name="notes" required maxlength="500" placeholder="Alasan (mis. jual ke customer)"
                            class="flex-1 px-2 py-1.5 border border-stone-300 rounded text-xs">
                    </div>
                    <button class="px-4 py-1.5 bg-red-600 text-white rounded text-xs hover:bg-red-700 w-full">Catat Keluar</button>
                </form>
            </div>

            {{-- Form 2: set stok absolut — saldo awal / koreksi hitung fisik.
                 Tanpa toggle tambah/kurang: cukup isi stok sebenarnya. --}}
            <div class="bg-white p-5">
                <p class="text-sm font-bold text-stone-800 mb-1">📝 Set / Koreksi Stok</p>
                <p class="text-[11px] text-stone-400 mb-3">Isi saldo awal, atau samakan dengan hitungan fisik. Isi jumlah <b>sebenarnya</b>, bukan selisih.</p>
                <form method="POST" action="{{ route('inventory.partner-set') }}" class="space-y-2">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $u->id }}">
                    <select name="product_id" required class="block w-full px-2 py-1.5 border border-stone-300 rounded text-xs">
                        <option value="">— pilih produk —</option>
                        @foreach($activeProducts as $prod)
                            <option value="{{ $prod->id }}">{{ $prod->name }} ({{ $prod->sku }})</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <input type="number" name="target" min="0" required placeholder="Stok sebenarnya"
                            class="w-28 px-2 py-1.5 border border-stone-300 rounded text-xs text-center">
                        <input type="text" name="notes" required maxlength="500" placeholder="Alasan (mis. saldo awal)"
                            class="flex-1 px-2 py-1.5 border border-stone-300 rounded text-xs">
                    </div>
                    <button class="px-4 py-1.5 bg-stone-700 text-white rounded text-xs hover:bg-stone-800 w-full">Set Stok</button>
                </form>
            </div>
        </div>
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
                        @if($u->isPartner())
                            {{-- Aksi cepat per baris: barang keluar saja, tanpa dropdown.
                                 Set/koreksi & saldo awal lewat form di atas. --}}
                            <form method="POST" action="{{ route('inventory.partner-adjust') }}" class="flex gap-1 justify-end items-center">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $line->user_id }}">
                                <input type="hidden" name="product_id" value="{{ $line->product_id }}">
                                <input type="hidden" name="type" value="{{ \App\Models\StockMovement::TYPE_OUT }}">
                                <span class="text-[11px] text-stone-400">Keluar</span>
                                <input type="number" name="quantity" min="1" value="1" class="w-14 px-2 py-1 border border-stone-300 rounded text-center text-[11px]">
                                <input type="text" name="notes" required maxlength="500" placeholder="Alasan"
                                    class="w-36 px-2 py-1 border border-stone-300 rounded text-[11px]">
                                <button class="px-3 py-1 bg-red-600 text-white rounded text-[11px]">−</button>
                            </form>
                        @else
                            {{-- Staf: kontrol penuh atas stok mitra mana pun (Masuk/Keluar/Koreksi). --}}
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
                        @endif
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
