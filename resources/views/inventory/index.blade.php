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
        {{-- Barang keluar = penjualan ke customer, bentuknya nota (1 customer,
             banyak produk + harga). Punya halaman sendiri (tak ada di sidebar),
             jadi tautan ini satu-satunya pintu masuknya — jangan dihapus. --}}
        <div class="px-5 py-4 border-b border-stone-100 bg-stone-50/60 flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[14rem]">
                <p class="text-sm font-bold text-stone-800">📤 Barang Keluar (Penjualan)</p>
                <p class="text-[11px] text-stone-400">Jual ke customer: satu nota bisa banyak produk, lengkap dengan harga &amp; total. Stok terpotong otomatis.</p>
            </div>
            <a href="{{ route('partner-sales.index') }}"
                class="inline-block text-center px-4 py-2 bg-red-600 text-white rounded-lg text-xs font-semibold hover:bg-red-700">
                Catat Penjualan / Barang Keluar →
            </a>
        </div>

        {{-- Penyesuaian stok = satu form dropdown, bukan 10 baris nol. Pilih
             produk (nama + SKU), isi stok SEBENARNYA (bukan selisih), alasan,
             Set. Baris dibuat otomatis kalau produknya belum ada — jadi ini juga
             jalur saldo awal untuk produk yang fisiknya dipegang tapi belum
             tercatat. --}}
        <div class="px-5 py-4 border-b border-stone-100">
            <p class="text-sm font-bold text-stone-800 mb-1">📝 Penyesuaian Stok / Adjustment</p>
            <p class="text-[11px] text-stone-400 mb-3">Samakan stok sistem dengan hitungan fisik, atau isi saldo awal. Ketik jumlah <b>sebenarnya</b>, bukan selisih.</p>
            <form method="POST" action="{{ route('inventory.partner-set') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="user_id" value="{{ $u->id }}">
                <label class="text-[11px] text-stone-500">Produk (SKU)
                    <select name="product_id" required class="mt-1 block w-64 px-2 py-1.5 border border-stone-300 rounded text-xs">
                        <option value="">— pilih produk —</option>
                        @foreach($activeProducts as $prod)
                            <option value="{{ $prod->id }}">{{ $prod->name }} — {{ $prod->sku }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-[11px] text-stone-500">Stok sebenarnya
                    <input type="number" name="target" min="0" required placeholder="0"
                        class="mt-1 block w-28 px-2 py-1.5 border border-stone-300 rounded text-xs text-center">
                </label>
                <label class="text-[11px] text-stone-500 flex-1 min-w-[12rem]">Alasan (wajib)
                    <input type="text" name="notes" required maxlength="500" placeholder="mis. hitung fisik / saldo awal"
                        class="mt-1 block w-full px-2 py-1.5 border border-stone-300 rounded text-xs">
                </label>
                <button class="px-4 py-1.5 bg-stone-700 text-white rounded text-xs hover:bg-stone-800">Set Stok</button>
            </form>
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
                @php $low = $line->minimum_stock > 0 && $line->isLow(); @endphp
                <tr class="border-t border-stone-100 {{ $low ? 'bg-rose-50/40' : '' }}">
                    @if(!$u->isPartner())<td class="px-4 py-3 text-stone-600">{{ $line->user->company_name ?? ($line->user->fullname ?? '-') }}</td>@endif
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $line->product->name ?? 'Produk' }}</td>
                    <td class="text-right font-bold {{ $low ? 'text-rose-600' : 'text-stone-800' }}">{{ $line->quantity }}</td>
                    <td class="text-right text-stone-500">{{ $line->minimum_stock }}</td>
                    <td class="px-4 py-2">
                        @if($u->isPartner())
                            {{-- Read-only: penyesuaian lewat form dropdown di atas
                                 (satu jalur, tampilan bersih), penjualan lewat nota. --}}
                            <span class="text-stone-300 text-[11px]">—</span>
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
                        <span class="block mt-1">Stok bertambah otomatis saat PO Anda diselesaikan HQ. Untuk barang yang fisiknya sudah Anda pegang, isi lewat <b>Penyesuaian Stok</b> di atas.</span>
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@if($partnerStock instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-4">{{ $partnerStock->links() }}</div>
@endif
@if($u->isPartner())
    <p class="text-[11px] text-stone-400 mt-3">
        Tabel di atas hanya menampilkan produk yang stoknya ada. Untuk menyesuaikan atau mengisi saldo awal, pakai <b>Penyesuaian Stok</b>; untuk mencatat penjualan ke customer, pakai <b>Catat Penjualan</b>.
    </p>
@endif
@endsection
