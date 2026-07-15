@extends('layouts.app')
@section('title', 'Pesanan TikTok')
@section('heading', 'Pesanan TikTok — Pratinjau Stok')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

<div class="mt-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
    ℹ️ Stok <b>tidak dipotong otomatis</b> (default). Kamu klik <b>"Potong Stok"</b>/<b>"Potong Semua"</b> sendiri (mode preview-approve). Hanya order <b>sudah dikirim</b> &amp; SKU <b>cocok</b> yang bisa dipotong. Bisa dibatalkan (stok balik).
</div>

{{-- Aksi massal + saklar auto (default MATI) --}}
<div class="mt-3 flex flex-wrap items-center gap-3">
    <form method="POST" action="{{ route('tiktok.deduct-all') }}" onsubmit="return confirm('Potong stok untuk SEMUA order yang sudah dikirim & SKU-nya cocok?')">@csrf
        <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">✂ Potong Semua yang Siap</button>
    </form>
    @if($connection)
        <form method="POST" action="{{ route('tiktok.toggle-auto') }}">@csrf
            <input type="hidden" name="auto_deduct" value="0">
            <label class="flex items-center gap-1.5 text-xs text-stone-600 cursor-pointer px-3 py-2 rounded-lg border border-stone-200">
                <input type="checkbox" name="auto_deduct" value="1" onchange="this.form.submit()" @checked($connection->auto_deduct)>
                Otomatis potong stok saat tarik order
                @if($connection->auto_deduct)<span class="text-emerald-600 font-semibold">AKTIF</span>@endif
            </label>
        </form>

        {{-- Batas mulai potong: pengaman supaya order pra-opname tidak kepotong dobel --}}
        <form method="POST" action="{{ route('tiktok.deduct-from') }}" class="flex items-center gap-1.5 px-3 py-2 rounded-lg border border-stone-200">@csrf
            <label class="text-xs text-stone-600">Mulai potong dari</label>
            <input type="date" name="deduct_from" value="{{ $connection->deduct_from?->format('Y-m-d') }}"
                onchange="this.form.submit()" class="px-2 py-1 border border-stone-300 rounded text-xs">
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

@if(count($skusNeedingMap))
    <details class="mt-4 bg-white rounded-2xl border border-rose-200">
        <summary class="px-5 py-3 cursor-pointer text-sm font-bold text-stone-800 select-none">⚙ Kelola Resep SKU ({{ count($skusNeedingMap) }}) <span class="text-[11px] font-normal text-stone-400">— klik buka/tutup</span></summary>
        <div class="px-5 pb-5">
        <p class="text-[11px] text-stone-500 mb-3">1 SKU TikTok bisa = beberapa produk SKINKU × qty. Contoh: <b>Soap-3</b> → Body Soap ×3; <b>bundle</b> → Sabun ×1 + Lotion ×1 + Scrub ×1. Diingat untuk semua order.</p>
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
            @foreach($skusNeedingMap as $sku => $info)
                <div class="border border-stone-200 rounded-xl p-3 flex flex-col">
                    <div class="mb-1.5">
                        <span class="font-mono text-stone-800 text-sm">{{ $sku }}</span>
                        @if($info['components']->isEmpty())<span class="ml-1 text-[10px] text-rose-500">belum ada resep</span>@endif
                        <div class="text-[10px] text-stone-400 truncate">{{ $info['name'] }}</div>
                    </div>
                    {{-- komponen yang sudah ada --}}
                    @foreach($info['components'] as $c)
                        <div class="flex items-center gap-1.5 text-xs py-0.5">
                            <span class="text-emerald-700 truncate">{{ $c->product?->name ?? '(produk terhapus)' }}</span>
                            <span class="text-stone-400 shrink-0">× {{ $c->qty }}</span>
                            <form method="POST" action="{{ route('tiktok.sku-map.remove', $c) }}" class="inline shrink-0">@csrf @method('DELETE')
                                <button class="text-[10px] text-rose-500 hover:text-rose-700 underline">hapus</button>
                            </form>
                        </div>
                    @endforeach
                    {{-- tambah komponen --}}
                    <form method="POST" action="{{ route('tiktok.sku-map') }}" class="flex items-center gap-1.5 text-xs mt-auto pt-2">@csrf
                        <input type="hidden" name="tiktok_sku" value="{{ $sku }}">
                        <select name="product_id" required class="px-2 py-1 border border-stone-300 rounded flex-1 min-w-0">
                            <option value="">— produk —</option>
                            @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                        </select>
                        <span class="text-stone-400 shrink-0">×</span>
                        <input type="number" name="qty" value="1" min="1" max="999" class="w-12 px-1.5 py-1 border border-stone-300 rounded text-right shrink-0">
                        <button class="px-2.5 py-1 bg-stone-800 text-white rounded hover:bg-stone-900 shrink-0">+</button>
                    </form>
                </div>
            @endforeach
        </div>
        </div>
    </details>
@endif

<div class="mt-4 space-y-2">
    @forelse($orders as $o)
        @php $pv = $previews[$o->id]; @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-4 flex flex-col sm:flex-row sm:items-start gap-3">
            {{-- Order + status --}}
            <div class="sm:w-52 sm:shrink-0">
                <div class="font-mono text-xs text-stone-700 break-all">{{ $o->tiktok_order_id }}</div>
                <div class="text-[10px] text-stone-400">{{ $o->order_created_at?->format('d M Y') ?? '—' }} · {{ $rp($o->total_amount) }}</div>
                @if($o->isShipped())
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">{{ $o->status }}</span>
                    <span class="text-[10px] text-stone-400">· barang keluar</span>
                @else
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold">{{ $o->status }}</span>
                    <span class="text-[10px] text-stone-400">· belum dikirim</span>
                @endif
            </div>
            {{-- Item → produk --}}
            <div class="flex-1 text-xs min-w-0">
                @forelse($pv['lines'] as $l)
                    <div class="py-0.5">
                        <span class="font-mono text-stone-500">{{ $l['sku'] }}</span>
                        <span class="text-stone-400">× {{ $l['qty'] }}</span>
                        <span class="text-stone-300">→</span>
                        @if(count($l['components']))
                            @foreach($l['components'] as $c)
                                <span class="text-emerald-700">{{ $c['product']->name }}</span><span class="text-stone-500 font-semibold"> −{{ $c['deduct'] }}</span><span class="text-[10px] text-stone-400"> (stok {{ (int) $c['product']->hq_stock }})</span>@if(!$loop->last)<span class="text-stone-300"> + </span>@endif
                            @endforeach
                        @else
                            <span class="text-rose-600 font-semibold">❌ SKU belum ada resep</span>
                        @endif
                    </div>
                @empty
                    <span class="text-stone-400">— tidak ada item —</span>
                @endforelse
            </div>
            {{-- Aksi --}}
            <div class="sm:w-36 sm:shrink-0 sm:text-right">
                @if($o->stock_status === \App\Models\TiktokOrder::STATUS_DEDUCTED)
                    <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold">✓ sudah dipotong</span>
                    <form method="POST" action="{{ route('tiktok.reverse', $o) }}" class="inline" onsubmit="return confirm('Batalkan pemotongan stok? Stok akan dikembalikan.')">@csrf
                        <button class="ml-1 text-[10px] text-stone-500 hover:text-rose-600 underline">batalkan</button>
                    </form>
                @elseif($beforeCutoff[$o->id] ?? false)
                    <span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold" title="Barang sudah keluar sebelum stok opname — tidak dipotong lagi">🛡️ pra-opname</span>
                @elseif(! $o->isShipped())
                    <span class="text-[10px] text-stone-400">tunggu dikirim</span>
                @elseif(! $pv['all_matched'])
                    <span class="text-[10px] text-rose-500">petakan SKU dulu</span>
                @else
                    <form method="POST" action="{{ route('tiktok.deduct', $o) }}" onsubmit="return confirm('Potong stok internal SKINKU untuk order ini?')">@csrf
                        <button class="w-full sm:w-auto px-3 py-1.5 text-[11px] bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">✂ Potong Stok</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-stone-200 px-4 py-8 text-center text-stone-400 text-sm">Belum ada order tersimpan. Klik "Tarik &amp; Simpan Order" di halaman Integrasi.</div>
    @endforelse
</div>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
