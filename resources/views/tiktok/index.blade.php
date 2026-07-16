@extends('layouts.app')
@section('title', 'Integrasi TikTok')
@section('heading', 'Integrasi TikTok Shop')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="max-w-3xl space-y-4">

    @unless($configured)
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
            ⚠️ <b>App Key / Secret TikTok belum diisi</b> di file <code>.env</code> server. Tambahkan:
            <pre class="mt-2 text-[11px] bg-white/70 rounded-lg p-2 overflow-x-auto">TIKTOK_APP_KEY=xxxxx
TIKTOK_APP_SECRET=xxxxx
TIKTOK_SERVICE_ID=7659787806251779858</pre>
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

            {{-- Pisahkan dua kemungkinan: cron mati vs tugasnya yang gagal --}}
            <div class="mt-2 pt-2 border-t border-rose-200 text-[11px]">
                Penjadwal (cron) terakhir berdetak:
                <b>{{ $hbAt ? $hbAt->diffForHumans() : 'belum pernah' }}</b>
                @if($cronAlive)
                    <span class="text-rose-900">→ <b>cron HIDUP</b>, berarti tugas sinkronnya yang gagal. Cek
                    <code>storage/logs/laravel-*.log</code> untuk baris <code>[tiktok:sync]</code>.</span>
                @else
                    <span class="text-rose-900">→ <b>cron kemungkinan MATI</b> (atau belum terpasang). Cek Cron Job di hPanel.</span>
                @endif
            </div>

            <div class="mt-1.5 text-[11px] text-rose-700">
                Bisa juga izin TikTok dicabut/kedaluwarsa (perlu Hubungkan ulang).
                Coba <b>Tarik &amp; Simpan Order</b> — kalau manual berhasil tapi ini tetap merah, berarti bukan izinnya.
            </div>
        </div>
    @endif

    {{-- Status koneksi --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Status Koneksi</h3>
        @if($connection)
            <div class="flex items-center gap-2 mb-3">
                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">✓ Terhubung</span>
                <span class="text-sm text-stone-700 font-semibold">{{ $connection->shop_name ?? $connection->seller_name ?? 'Toko TikTok' }}</span>
                @if($connection->region)<span class="text-[11px] text-stone-400">({{ $connection->region }})</span>@endif
            </div>
            <dl class="text-xs text-stone-500 space-y-1">
                <div class="flex gap-2"><dt class="w-28">Shop ID</dt><dd class="font-mono text-stone-700">{{ $connection->shop_id ?: '—' }}</dd></div>
                <div class="flex gap-2"><dt class="w-28">Token berlaku s/d</dt><dd>{{ $connection->access_expires_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div class="flex gap-2"><dt class="w-28">Sinkron terakhir</dt><dd>{{ $connection->last_synced_at?->diffForHumans() ?? 'belum pernah' }}</dd></div>
            </dl>
            <div class="flex gap-2 mt-4 items-center">
                <form method="POST" action="{{ route('tiktok.sync-orders') }}">@csrf
                    <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Tarik &amp; Simpan Order</button>
                </form>
                <a href="{{ route('tiktok.orders') }}" class="px-4 py-2 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800">📦 Pesanan TikTok →</a>
                <a href="{{ route('tiktok.returns') }}" class="px-4 py-2 text-sm bg-amber-600 text-white rounded-lg hover:bg-amber-700">↩ Retur TikTok →</a>
                <a href="{{ route('tiktok.stock') }}" class="px-4 py-2 text-sm bg-teal-700 text-white rounded-lg hover:bg-teal-800">📊 Konversi Stok →</a>
                <a href="{{ route('tiktok.settlements') }}" class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">💰 Dana Cair →</a>
                <form method="POST" action="{{ route('tiktok.disconnect') }}" onsubmit="return confirm('Putuskan koneksi TikTok?')">@csrf @method('DELETE')
                    <button class="px-4 py-2 text-sm text-rose-600 hover:text-rose-800">Putuskan</button>
                </form>
            </div>
        @else
            <p class="text-sm text-stone-500 mb-4">Belum terhubung. Klik tombol di bawah untuk memberi izin toko TikTok kamu ke SKINKU (read-only dulu: tarik order).</p>
            <a href="{{ route('tiktok.connect') }}" class="inline-block px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold {{ $configured ? '' : 'pointer-events-none opacity-50' }}">🔗 Hubungkan TikTok Shop</a>
        @endif
    </div>

    {{-- Hasil tarik order (bukti koneksi) --}}
    @if($orders !== null)
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Order Terbaru — {{ count($orders) }} order</div>
            @if(count($orders))
                <div class="overflow-x-auto">
                    <table class="w-full text-xs whitespace-nowrap">
                        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                            <th class="text-left px-4 py-2">Order ID</th><th class="text-left">Status</th><th class="text-right pr-4">Total</th>
                        </tr></thead>
                        <tbody>
                            @foreach($orders as $o)
                                <tr class="border-t border-stone-100">
                                    <td class="px-4 py-2 font-mono text-stone-700">{{ $o['id'] ?? '—' }}</td>
                                    <td class="text-stone-500">{{ $o['status'] ?? '—' }}</td>
                                    <td class="text-right pr-4 text-stone-700">{{ isset($o['payment']['total_amount']) ? $rp($o['payment']['total_amount']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada order di toko (atau semua sudah tersinkron).</p>
            @endif
        </div>
        <p class="text-[11px] text-stone-400">Ini masih tahap <b>M1</b> — cuma menarik & menampilkan order (read-only). Belum menyentuh stok atau laporan. Kalau ini jalan, lanjut ke M2 (potong stok) &amp; M3 (dana cair → jurnal).</p>
    @endif
</div>
@endsection
