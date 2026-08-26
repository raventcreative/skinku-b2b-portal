@extends('layouts.app')
@section('title', 'Integrasi Shopee')
@section('heading', 'Integrasi Shopee')

@section('content')

<div class="max-w-3xl space-y-4">

    @unless($configured)
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
            ⚠️ <b>Partner ID / Key Shopee belum diisi</b> di file <code>.env</code> server. Tambahkan:
            <pre class="mt-2 text-[11px] bg-white/70 rounded-lg p-2 overflow-x-auto">SHOPEE_PARTNER_ID=xxxxx
SHOPEE_PARTNER_KEY=xxxxx</pre>
            lalu <code>php artisan optimize:clear</code>.
        </div>
    @endunless

    {{-- Peringatan cron mati: satu-satunya cara user tahu sync berhenti diam-diam --}}
    @if($connection && $connection->syncStale())
        @php
            $hb = cache('scheduler_heartbeat');
            $hbAt = $hb ? \Illuminate\Support\Carbon::parse($hb) : null;
            $cronAlive = $hbAt && $hbAt->gt(now()->subMinutes(15));
        @endphp
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-300 text-rose-800 text-sm">
            🚨 <b>Sinkron otomatis kelihatannya berhenti.</b>
            Terakhir sinkron: <b>{{ $connection->last_synced_at?->diffForHumans() ?? 'belum pernah' }}</b> —
            harusnya tiap 30 menit. Order baru tidak masuk &amp; stok tidak terpotong.

            <div class="mt-2 pt-2 border-t border-rose-200 text-[11px]">
                Penjadwal (cron) terakhir berdetak:
                <b>{{ $hbAt ? $hbAt->diffForHumans() : 'belum pernah' }}</b>
                @if($cronAlive)
                    <span class="text-rose-900">→ <b>cron HIDUP</b>, berarti tugas sinkronnya yang gagal. Cek
                    <code>storage/logs/laravel-*.log</code> untuk baris <code>[shopee:sync]</code>.</span>
                @else
                    <span class="text-rose-900">→ <b>cron kemungkinan MATI</b> (atau belum terpasang). Cek Cron Job di hPanel.</span>
                @endif
            </div>

            <div class="mt-1.5 text-[11px] text-rose-700">
                Bisa juga izin Shopee dicabut/kedaluwarsa (perlu Hubungkan ulang).
                Coba <b>Tarik Order</b> — kalau manual berhasil tapi ini tetap merah, berarti bukan izinnya.
            </div>
        </div>
    @endif

    {{-- Status koneksi --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Status Koneksi</h3>
        @if($connection)
            <div class="flex items-center gap-2 mb-3">
                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">✓ Terhubung</span>
                <span class="text-sm text-stone-700 font-semibold">{{ $connection->shop_name ?? $connection->shop_id ?? 'Toko Shopee' }}</span>
                @if($connection->region)<span class="text-[11px] text-stone-400">({{ $connection->region }})</span>@endif
            </div>
            <dl class="text-xs text-stone-500 space-y-1">
                <div class="flex gap-2"><dt class="w-28">Shop ID</dt><dd class="font-mono text-stone-700">{{ $connection->shop_id ?: '—' }}</dd></div>
                <div class="flex gap-2"><dt class="w-28">Token berlaku s/d</dt><dd>{{ $connection->access_expires_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div class="flex gap-2"><dt class="w-28">Sinkron terakhir</dt><dd>{{ $connection->last_synced_at?->diffForHumans() ?? 'belum pernah' }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-2 mt-4 items-center">
                <form method="POST" action="{{ route('shopee.sync-orders') }}">@csrf
                    <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Tarik Order</button>
                </form>
                <a href="{{ route('shopee.orders') }}" class="px-4 py-2 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">📦 Pesanan Shopee →</a>
                <a href="{{ route('shopee.returns') }}" class="px-4 py-2 text-sm bg-amber-600 text-white rounded-lg hover:bg-amber-700">↩ Retur Shopee →</a>
                <a href="{{ route('shopee.settlements') }}" class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">💰 Pencairan →</a>
                <a href="{{ route('shopee.stock') }}" class="px-4 py-2 text-sm bg-teal-700 text-white rounded-lg hover:bg-teal-800">🔧 SKU &amp; Stok →</a>
            </div>
        @else
            <p class="text-sm text-stone-500 mb-4">Belum terhubung. Klik tombol di bawah untuk memberi izin toko Shopee kamu ke SKINKU (tarik order + potong stok).</p>
            <a href="{{ route('shopee.connect') }}" class="inline-block px-5 py-2.5 text-sm bg-orange-600 text-white rounded-xl hover:bg-orange-700 font-semibold {{ $configured ? '' : 'pointer-events-none opacity-50' }}">🔗 Hubungkan Shopee</a>
        @endif
    </div>

    {{-- Pengaturan potong stok --}}
    @if($connection)
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-3">Pengaturan</h3>
            <form method="POST" action="{{ route('shopee.settings') }}" class="flex flex-wrap items-center gap-4">
                @csrf
                <label class="flex items-center gap-1.5 text-xs text-stone-600 cursor-pointer">
                    <input type="hidden" name="auto_deduct" value="0">
                    <input type="checkbox" name="auto_deduct" value="1" @checked($connection->auto_deduct)>
                    Otomatis potong stok saat tarik order
                    @if($connection->auto_deduct)<span class="text-emerald-600 font-semibold">AKTIF</span>@endif
                </label>
                <label class="flex items-center gap-1.5 text-xs text-stone-600">
                    Mulai potong dari
                    <input type="date" name="deduct_from" value="{{ $connection->deduct_from?->format('Y-m-d') }}" class="px-2 py-1 border border-stone-300 rounded text-xs">
                </label>
                <button type="submit" class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">Simpan</button>
            </form>
            @if($connection->deduct_from)
                <p class="mt-2 text-[11px] text-emerald-700">🛡️ Order sebelum {{ $connection->deduct_from->format('d M Y') }} tidak akan dipotong — sudah tercakup Stok Opname.</p>
            @else
                <p class="mt-2 text-[11px] text-rose-600">⚠️ Batas tanggal belum diset. Kalau kamu potong sekarang, order lama (pra-opname) ikut kepotong → stok dobel berkurang.</p>
            @endif
        </div>
    @endif

    {{-- Pemetaan SKU Shopee — SKU yang tak auto-match Product.sku. Yang sudah punya
         resep = beres; yang kosong = perlu dipetakan. --}}
    @if(count($needMap))
        @php
            $skuUnmapped = collect($needMap)->filter(fn ($i) => $i['components']->isEmpty());
            $skuMapped = collect($needMap)->reject(fn ($i) => $i['components']->isEmpty());
            $needCount = $skuUnmapped->count();
            $skuSorted = $skuUnmapped->union($skuMapped); // yang belum dipetakan tampil dulu
        @endphp
        <div class="bg-white rounded-2xl border {{ $needCount ? 'border-rose-200' : 'border-emerald-200' }} p-5">
            @if($needCount)
                <h3 class="text-sm font-bold text-stone-800 mb-1">⚙ SKU perlu dipetakan ({{ $needCount }})</h3>
                <p class="text-[11px] text-stone-500 mb-3">{{ $skuMapped->count() }} SKU sudah dipetakan. 1 SKU Shopee bisa = beberapa produk SKINKU × qty; dipetakan sekali, berlaku untuk semua order.</p>
            @else
                <h3 class="text-sm font-bold text-emerald-700 mb-1">✓ Semua SKU Shopee sudah dipetakan ({{ $skuMapped->count() }})</h3>
                <p class="text-[11px] text-stone-500 mb-3">Semua SKU dari order sudah punya resep. Bisa edit / tambah komponen di bawah (mis. paket bundle).</p>
            @endif
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
    @endif

</div>
@endsection
