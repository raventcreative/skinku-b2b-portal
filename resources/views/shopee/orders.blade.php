@extends('layouts.app')
@section('title', 'Order Shopee')
@section('heading', 'Order Shopee')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<a href="{{ route('shopee.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

@if($connection?->auto_deduct)
    <div class="mt-3 px-4 py-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-[11px]">
        ⚡ <b>Auto-potong AKTIF</b> — stok dipotong tiap kamu klik <b>"Tarik Order"</b>. Hanya order <b>sudah dikirim</b> &amp; SKU <b>cocok</b> yang dipotong. Selalu bisa dibatalkan (stok balik).
    </div>
@else
    <div class="mt-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
        ℹ️ Stok <b>tidak dipotong otomatis</b>. Klik <b>"Potong Semua"</b> sendiri (mode preview-approve). Hanya order <b>sudah dikirim</b> &amp; SKU <b>cocok</b> yang bisa dipotong. Bisa dibatalkan (stok balik).
    </div>
@endif

{{-- Aksi massal + setelan potong (dipindah dari halaman Integrasi, konsisten TikTok) --}}
<div class="mt-3 flex flex-wrap items-center gap-3">
    <form method="POST" action="{{ route('shopee.deduct-all') }}" onsubmit="return confirm('Potong stok untuk SEMUA order yang sudah dikirim & SKU-nya cocok?')">@csrf
        <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">✂ Potong Semua yang Siap</button>
    </form>
    @if($connection)
        <form method="POST" action="{{ route('shopee.settings') }}" class="flex flex-wrap items-center gap-3">@csrf
            <label class="flex items-center gap-1.5 text-xs text-stone-600 cursor-pointer px-3 py-2 rounded-lg border border-stone-200">
                <input type="hidden" name="auto_deduct" value="0">
                <input type="checkbox" name="auto_deduct" value="1" onchange="this.form.submit()" @checked($connection->auto_deduct)>
                Otomatis potong stok saat tarik order
                @if($connection->auto_deduct)<span class="text-emerald-600 font-semibold">AKTIF</span>@endif
            </label>
            <label class="flex items-center gap-1.5 text-xs text-stone-600 px-3 py-2 rounded-lg border border-stone-200">
                Mulai potong dari
                <input type="date" name="deduct_from" value="{{ $connection->deduct_from?->format('Y-m-d') }}" onchange="this.form.submit()" class="px-2 py-1 border border-stone-300 rounded text-xs">
            </label>
        </form>
    @endif
</div>

@if($cutoff)
    <div class="mt-2 px-4 py-2 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-[11px]">
        🛡️ Order <b>sebelum {{ $cutoff->format('d M Y') }}</b> tidak akan dipotong — barangnya sudah tercakup <b>Stok Opname</b>. Aman menyalakan auto-potong.
    </div>
@else
    <div class="mt-2 px-4 py-2 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-[11px]">
        ⚠️ <b>Batas tanggal belum diset.</b> Kalau kamu potong sekarang, order lama (pra-opname) ikut kepotong → stok dobel berkurang. Isi <b>"Mulai potong dari"</b> dulu.
    </div>
@endif

{{-- Kelola Resep SKU — collapsible (default tertutup), konsisten dengan TikTok. --}}
@if(count($needMap))
    @php
        $skuUnmapped = collect($needMap)->filter(fn ($i) => $i['components']->isEmpty());
        $skuMapped = collect($needMap)->reject(fn ($i) => $i['components']->isEmpty());
        $skuSorted = $skuUnmapped->union($skuMapped); // yang belum dipetakan tampil dulu
    @endphp
    <details class="mt-4 bg-white rounded-2xl border {{ $skuUnmapped->count() ? 'border-rose-200' : 'border-emerald-200' }}">
        <summary class="px-5 py-3 cursor-pointer text-sm font-bold {{ $skuUnmapped->count() ? 'text-stone-800' : 'text-emerald-700' }} select-none">
            @if($skuUnmapped->count())
                ⚙ Kelola Resep SKU — {{ $skuUnmapped->count() }} perlu dipetakan
            @else
                ✓ Kelola Resep SKU — semua {{ $skuMapped->count() }} sudah dipetakan
            @endif
            <span class="text-[11px] font-normal text-stone-400">— klik buka/tutup</span>
        </summary>
        <div class="px-5 pb-5">
            <p class="text-[11px] text-stone-500 mb-3">1 SKU Shopee bisa = beberapa produk SKINKU × qty (mis. paket bundle). Dipetakan sekali, berlaku untuk semua order.</p>
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($skuSorted as $sku => $info)
                    <div class="border {{ $info['components']->isEmpty() ? 'border-rose-200' : 'border-stone-200' }} rounded-xl p-3">
                        <div class="mb-1.5">
                            <span class="font-mono text-stone-800 text-sm">{{ $sku }}</span>
                            @if($info['components']->isEmpty())
                                <span class="ml-1 text-[10px] text-rose-500 font-semibold">belum ada resep</span>
                            @else
                                <span class="ml-1 text-[10px] text-emerald-600 font-semibold">✓ dipetakan</span>
                            @endif
                            <div class="text-[10px] text-stone-400 truncate">{{ $info['name'] }}</div>
                        </div>
                        @foreach($info['components'] as $c)
                            <div class="flex items-center gap-1.5 text-xs py-0.5">
                                <span class="text-emerald-700 truncate">{{ $c->product?->name ?? '(produk terhapus)' }}</span>
                                <span class="text-stone-400 shrink-0">× {{ $c->qty }}</span>
                                <form method="POST" action="{{ route('shopee.sku-map.remove', $c) }}" onsubmit="return confirm('Hapus komponen ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-[10px] text-rose-500 hover:text-rose-700 underline">hapus</button>
                                </form>
                            </div>
                        @endforeach
                        <form method="POST" action="{{ route('shopee.sku-map') }}" class="flex items-center gap-1.5 text-xs mt-2 pt-2 border-t border-stone-100">
                            @csrf
                            <input type="hidden" name="shopee_sku" value="{{ $sku }}">
                            <select name="product_id" required class="px-2 py-1 border border-stone-300 rounded flex-1 min-w-0">
                                <option value="">— produk —</option>
                                @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                            </select>
                            <span class="text-stone-400 shrink-0">×</span>
                            <input type="number" name="qty" value="1" min="1" max="999" class="w-12 px-1.5 py-1 border border-stone-300 rounded text-right shrink-0">
                            <button type="submit" class="px-2.5 py-1 bg-stone-800 text-white rounded hover:bg-stone-900 shrink-0">+</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    </details>
@endif

<div class="mt-4 bg-white rounded-2xl border border-stone-200 overflow-hidden">
    @if($orders->count())
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                    <th class="text-left px-4 py-2">Order</th>
                    <th class="text-left px-2 py-2">Tanggal</th>
                    <th class="text-left px-2 py-2">Status</th>
                    <th class="text-right px-2 py-2">Total</th>
                    <th class="text-left px-2 py-2">Pratinjau Potong Stok</th>
                    <th class="text-left px-2 py-2">Stok</th>
                    <th class="text-right px-4 py-2">Aksi</th>
                </tr></thead>
                <tbody>
                    @foreach($orders as $o)
                        @php $pv = $previews[$o->id]; @endphp
                        <tr class="border-t border-stone-100 align-top">
                            <td class="px-4 py-2 font-mono text-stone-700 whitespace-nowrap">{{ $o->order_sn }}</td>
                            <td class="px-2 py-2 text-stone-500 whitespace-nowrap">{{ $o->order_created_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $o->isShipped() ? 'bg-emerald-100 text-emerald-700' : ($o->isCancelled() ? 'bg-rose-100 text-rose-600' : 'bg-stone-100 text-stone-500') }}">{{ $o->status }}</span>
                            </td>
                            <td class="px-2 py-2 text-right text-stone-700 whitespace-nowrap">{{ $rp($o->total_amount) }}</td>
                            <td class="px-2 py-2 min-w-[240px]">
                                @forelse($pv['lines'] as $l)
                                    <div class="py-0.5">
                                        <span class="font-mono text-stone-500">{{ $l['sku'] }}</span>
                                        <span class="text-stone-400">× {{ $l['qty'] }}</span>
                                        @if(count($l['components']))
                                            <span class="text-stone-300">→</span>
                                            @foreach($l['components'] as $c)
                                                <span class="text-emerald-700">{{ $c['product']->name }}</span><span class="text-stone-500 font-semibold"> −{{ $c['deduct'] }}</span>@if(!$loop->last)<span class="text-stone-300"> + </span>@endif
                                            @endforeach
                                        @else
                                            <span class="text-rose-600 font-semibold">❌ SKU belum ada resep</span>
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-stone-400">— tidak ada item —</span>
                                @endforelse
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                @if($o->stock_status === \App\Models\ShopeeOrder::STATUS_DEDUCTED)
                                    <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold">✓ dipotong</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold">belum</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                @if($o->stock_status === \App\Models\ShopeeOrder::STATUS_DEDUCTED)
                                    <span class="text-[10px] text-stone-400">—</span>
                                @elseif($o->isCancelled())
                                    <span class="text-[10px] text-stone-400">dibatalkan</span>
                                @elseif(! $o->isShipped())
                                    <span class="text-[10px] text-stone-400">tunggu dikirim</span>
                                @elseif(! $pv['all_matched'])
                                    <span class="text-[10px] text-rose-500">petakan SKU dulu</span>
                                @else
                                    <form method="POST" action="{{ route('shopee.deduct', $o) }}" onsubmit="return confirm('Potong stok internal SKINKU untuk order ini?')">
                                        @csrf
                                        <button class="px-3 py-1.5 text-[11px] bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">✂ Potong</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="px-5 py-8 text-center text-stone-400 text-sm">Belum ada order tersimpan. Klik "Tarik Order" di halaman Integrasi.</p>
    @endif
</div>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
