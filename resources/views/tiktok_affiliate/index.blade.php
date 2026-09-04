@extends('layouts.app')
@section('title', 'TikTok Affiliate API')
@section('heading', 'TikTok Affiliate API (Seller Analitik)')

@section('content')
<div class="space-y-4 max-w-3xl">
    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm text-stone-600 mb-4">
            App terpisah <strong>"Seller Analitik"</strong> untuk narik data affiliate per kreator otomatis
            (order/GMV/komisi) → nyuapin <a href="{{ route('kol-gapok.index') }}" class="text-red-600 hover:underline">Tim Gapok</a>.
        </p>

        @if(! $configured)
            <div class="px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                ⚠️ Kredensial belum diisi. Set di <code>.env</code> server lalu <code>php artisan config:clear</code>:
                <pre class="mt-2 text-xs bg-white/60 rounded-lg p-2 overflow-x-auto">TIKTOK_AFFILIATE_APP_KEY=6l6lfioqiql8g
TIKTOK_AFFILIATE_APP_SECRET=(dari Partner Center → Manage app secret)
TIKTOK_AFFILIATE_SERVICE_ID=(ID app "Seller Analitik")</pre>
            </div>
        @elseif($connection && $connection->shop_cipher)
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-sm font-semibold">
                    ● Terhubung: {{ $connection->shop_name ?? $connection->seller_name ?? 'toko' }}
                </span>
                @if($connection->last_synced_at)
                    <span class="text-xs text-stone-400">probe terakhir: {{ $connection->last_synced_at->diffForHumans() }}</span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-4">
                <form method="POST" action="{{ route('tiktok-affiliate.sync') }}">
                    @csrf
                    <button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">⬇ Sync sekarang (7 hari)</button>
                </form>
                <form method="POST" action="{{ route('tiktok-affiliate.probe') }}">
                    @csrf
                    <button class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">🔍 Probe (lihat struktur)</button>
                </form>
                <a href="{{ route('tiktok-affiliate.connect') }}" class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">↻ Re-authorize</a>
                <form method="POST" action="{{ route('tiktok-affiliate.disconnect') }}" onsubmit="return confirm('Putuskan koneksi app affiliate?')">
                    @csrf
                    <button class="px-4 py-2 text-stone-400 hover:text-rose-600 text-sm rounded-xl">Putuskan</button>
                </form>
            </div>
            <p class="text-xs text-stone-400 mt-2">Sync otomatis tiap 6 jam. Setelah sync, data muncul di <a href="{{ route('kol-gapok.index') }}" class="text-red-600 hover:underline">Tim Gapok</a> (kreator yang cocok ke KOL). Yang belum cocok → tautkan di <a href="{{ route('kol-affiliate.index') }}" class="text-red-600 hover:underline">Affiliate &amp; GMV</a>.</p>
        @else
            <a href="{{ route('tiktok-affiliate.connect') }}" class="inline-block px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">🔗 Hubungkan toko (authorize)</a>
            <p class="text-xs text-stone-400 mt-2">Kamu akan diarahkan ke TikTok untuk memberi izin. Setelah itu balik ke sini otomatis.</p>
        @endif
    </div>

    {{-- Hasil probe: struktur respons mentah (buat bikin parser) --}}
    @if($probe)
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-semibold text-stone-700 mb-1">Struktur respons (mentah)</p>
            <p class="text-xs text-stone-400 mb-2">Field teratas: <code>{{ implode(', ', $probe['keys'] ?: ['(kosong)']) }}</code>. Screenshot / copy blok ini, kirim ke Claude buat bikin parser-nya.</p>
            <pre class="text-[11px] bg-stone-900 text-stone-100 rounded-xl p-3 overflow-x-auto max-h-[420px]">{{ $probe['json'] }}</pre>
        </div>
    @endif
</div>
@endsection
