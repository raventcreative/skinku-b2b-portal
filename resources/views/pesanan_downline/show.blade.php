@extends('layouts.app')
@section('title', 'Detail Pesanan Downline')
@section('heading', 'Detail Pesanan Downline')

@section('content')
@php
    $payBadge = [
        'unpaid'                => ['Belum Dibayar', 'bg-stone-100 text-stone-600'],
        'awaiting_verification' => ['Menunggu Verifikasi', 'bg-amber-100 text-amber-700'],
        'paid'                  => ['Lunas', 'bg-emerald-100 text-emerald-700'],
        'rejected'              => ['Bukti Ditolak', 'bg-rose-100 text-rose-700'],
    ][$po->payment_status] ?? ['-', 'bg-stone-100 text-stone-600'];
@endphp
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-2xl border border-stone-200 p-6">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="text-lg font-bold text-stone-900">{{ $po->po_number }}</h3>
                    <p class="text-xs text-stone-500 mt-1">{{ $po->created_at?->format('d M Y H:i') }}</p>
                </div>
                <div class="flex flex-col items-end gap-1.5">
                    <span class="px-3 py-1 rounded-full text-xs font-bold {{ $po->statusColor() }}">{{ $po->status }}</span>
                    <span class="px-3 py-1 rounded-full text-[10px] font-bold {{ $payBadge[1] }}">{{ $payBadge[0] }}</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mt-5 text-xs">
                <div><p class="text-stone-400 uppercase">Downline</p><p class="font-semibold text-stone-800">{{ $po->company_name ?? ($po->user->fullname ?? '-') }}</p></div>
                <div><p class="text-stone-400 uppercase">Role</p><p class="font-semibold text-stone-800">{{ $po->user_role }}</p></div>
                <div class="col-span-2"><p class="text-stone-400 uppercase">Alamat Pengiriman</p><p class="text-stone-700">{{ $po->shipping_address ?? '-' }}</p></div>
                @if($po->notes)<div class="col-span-2"><p class="text-stone-400 uppercase">Catatan</p><p class="text-stone-700">{{ $po->notes }}</p></div>@endif
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Item Pesanan</div>
            <div class="overflow-x-auto">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr><th class="text-left px-4 py-2">Produk</th><th class="text-left">SKU</th><th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right px-4">Subtotal</th></tr>
                </thead>
                <tbody>
                    @foreach($po->items as $item)
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2 font-semibold text-stone-800">{{ $item->product_name }}</td>
                            <td class="text-stone-500">{{ $item->sku }}</td>
                            <td class="text-right">{{ $item->qty }}</td>
                            <td class="text-right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                            <td class="text-right px-4">Rp {{ number_format($item->total_price, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-stone-100">
                        <td colspan="4" class="px-4 py-1.5 text-right text-stone-500">Subtotal Barang</td>
                        <td class="px-4 py-1.5 text-right text-stone-700">Rp {{ number_format($po->subtotal, 0, ',', '.') }}</td>
                    </tr>
                    @if($po->discount > 0)
                    <tr>
                        <td colspan="4" class="px-4 py-1.5 text-right text-stone-500">Diskon</td>
                        <td class="px-4 py-1.5 text-right text-rose-600">- Rp {{ number_format($po->discount, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td colspan="4" class="px-4 py-1.5 text-right text-stone-500">Ongkir</td>
                        <td class="px-4 py-1.5 text-right text-stone-700">Rp {{ number_format($po->shipping_cost, 0, ',', '.') }}</td>
                    </tr>
                    <tr class="border-t-2 border-stone-200 font-bold">
                        <td colspan="4" class="px-4 py-3 text-right">Total Bayar</td>
                        <td class="px-4 py-3 text-right text-emerald-700">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        {{-- Pre-cek stok upline. Kalau ada produk kurang, aksi kirim/selesai
             diblok dulu (disabled) sampai stok mencukupi. --}}
        @if(count($stokKurang) > 0)
            <div class="bg-rose-50 border border-rose-200 rounded-2xl p-5">
                <h3 class="text-sm font-bold text-rose-800 mb-2">⚠ Stok Anda Tidak Cukup</h3>
                <p class="text-[11px] text-rose-700 mb-3">Lengkapi stok produk berikut sebelum memproses pesanan ini:</p>
                <table class="w-full text-[11px]">
                    <thead><tr class="text-rose-400 uppercase text-[9px]">
                        <th class="text-left">Produk</th><th class="text-right">Tersedia</th><th class="text-right">Dibutuhkan</th></tr></thead>
                    <tbody>
                        @foreach($stokKurang as $k)
                            <tr class="border-t border-rose-100">
                                <td class="py-1 font-semibold text-rose-800">{{ $k['nama'] }}</td>
                                <td class="text-right text-rose-700">{{ $k['tersedia'] }}</td>
                                <td class="text-right text-rose-700">{{ $k['butuh'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Pembayaran --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-3">Pembayaran</h3>

            <div class="flex justify-between items-center mb-3">
                <span class="text-xs text-stone-500">Status</span>
                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold {{ $payBadge[1] }}">{{ $payBadge[0] }}</span>
            </div>

            @if($po->paymentProofUrl())
                <a href="{{ $po->paymentProofUrl() }}" target="_blank" class="block mb-3">
                    <img src="{{ $po->paymentProofUrl() }}" class="w-full rounded-lg border border-stone-200" alt="Bukti transfer">
                    <span class="text-[10px] text-stone-400">Klik untuk perbesar</span>
                </a>
            @else
                <p class="text-[11px] text-stone-400 mb-3">Belum ada bukti transfer diunggah.</p>
            @endif

            @if($po->payment_note)
                <p class="text-[11px] text-stone-500 mb-3">Catatan: {{ $po->payment_note }}</p>
            @endif

            @if($po->isPaid())
                <p class="text-[11px] text-emerald-600">Lunas pada {{ $po->paid_at?->format('d M Y H:i') }}.</p>
            @endif
        </div>

        {{-- Aksi: placeholder — form aktif ditambahkan di Task 3. --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-3">Aksi</h3>
            <button type="button" disabled
                class="w-full py-2.5 bg-stone-200 text-stone-400 text-sm font-semibold rounded-xl cursor-not-allowed">
                Kirim / Selesaikan Pesanan
            </button>
            @if(count($stokKurang) > 0)
                <p class="text-[10px] text-rose-600 mt-2">Terkunci — lengkapi stok yang kurang di atas terlebih dahulu.</p>
            @else
                <p class="text-[10px] text-stone-400 mt-2">Tombol ini akan aktif pada pembaruan berikutnya.</p>
            @endif
        </div>

        <a href="{{ route('pesanan-downline.index') }}" class="block text-center text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar Pesanan Downline</a>
    </div>
</div>
@endsection
