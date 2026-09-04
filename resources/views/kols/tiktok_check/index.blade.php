@extends('layouts.app')
@section('title', 'Cek Performa TikTok')
@section('heading', 'Cek Performa TikTok')

@section('content')
@php
    $short = function ($n) {
        $n = (float) $n;
        if ($n >= 1_000_000_000) return number_format($n / 1_000_000_000, 1, ',', '.').' mlyr';
        if ($n >= 1_000_000) return number_format($n / 1_000_000, 1, ',', '.').' jt';
        if ($n >= 1_000) return number_format($n / 1_000, 0, ',', '.').' rb';
        return number_format($n, 0, ',', '.');
    };
    $rp = fn ($n) => 'Rp '.$short($n);
    $idr = fn ($usd) => $usd === null ? null : $rp($usd * $rate);
    $usdFmt = fn ($usd) => '$'.number_format($usd, 0, '.', ',');
    $genderLabel = ['FEMALE' => 'Perempuan', 'MALE' => 'Laki-laki'];
    $ageLabel = fn ($a) => str_replace(['AGE_RANGE_', '_'], ['', '–'], $a);
    $connected = $conn && $conn->shop_cipher;
@endphp

<div class="space-y-4 max-w-5xl">
    <p class="text-sm text-stone-500 -mt-1">
        Ketik username / nama kreator TikTok → lihat <strong>GMV 30 hari</strong>, follower & views langsung dari TikTok
        (Creator Marketplace), <strong>walau dia belum pernah jadi affiliate kita</strong>. Buat menimbang layak/tidak
        direkrut ke <a href="{{ route('kol-gapok.index') }}" class="text-red-600 hover:underline">Tim Gapok</a>.
    </p>

    @unless($connected)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-3 text-sm">
            App affiliate <strong>belum terhubung</strong>. Hubungkan dulu di
            <a href="{{ route('tiktok-affiliate.index') }}" class="underline font-medium">TikTok Affiliate API</a>,
            baru pencarian kreator bisa jalan.
        </div>
    @endunless

    {{-- Kotak cari --}}
    <form method="GET" action="{{ route('kol-cek-tiktok.index') }}" class="flex gap-2">
        <input type="text" name="q" value="{{ $q }}" autofocus placeholder="username TikTok, mis. dewick02"
            class="flex-1 rounded-xl border border-stone-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500 outline-none">
        <button type="submit" @disabled(! $connected)
            class="rounded-xl bg-red-600 text-white px-5 py-2.5 text-sm font-semibold hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
            🔍 Cari
        </button>
    </form>

    @if($error)
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm">
            Gagal ambil data: {{ $error }}
        </div>
    @endif

    @if($q !== '' && ! $error)
        <p class="text-xs text-stone-500">
            {{ count($creators) }} kreator cocok dengan "<strong>{{ $q }}</strong>" · urut sesuai TikTok.
            Cocokkan @username + avatar + follower biar tak salah orang.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse($creators as $c)
                @php
                    $isExact = $c['username'] !== '' && mb_strtolower($c['username']) === mb_strtolower($q);
                    $k = $known->get(mb_strtolower(trim($c['username'])));
                    $profile = 'https://www.tiktok.com/@'.ltrim($c['username'], '@');

                    // GMV video/LIVE (hanya yang ada) → satu baris.
                    $vl = [];
                    if ($c['video_gmv_usd'] !== null) $vl[] = '🎬 '.$idr($c['video_gmv_usd']);
                    if ($c['live_gmv_usd'] !== null) $vl[] = '🔴 '.$idr($c['live_gmv_usd']);
                    $vlStr = implode(' · ', $vl);

                    // Demografi audiens (region · gender% · umur).
                    $demo = [];
                    if ($c['region']) $demo[] = $c['region'];
                    if ($c['gender']) {
                        $g = $genderLabel[$c['gender']] ?? mb_strtolower($c['gender']);
                        $demo[] = 'mayoritas '.$g.($c['gender_pct'] ? ' '.$c['gender_pct'].'%' : '');
                    }
                    if ($c['age_ranges']) $demo[] = collect($c['age_ranges'])->map($ageLabel)->implode(', ').' th';
                    $demoStr = implode(' · ', $demo);
                @endphp
                <div class="bg-white rounded-2xl border {{ $isExact ? 'border-red-400 ring-2 ring-red-100' : 'border-stone-200' }} p-4 flex flex-col gap-3">
                    {{-- Identitas --}}
                    <div class="flex items-center gap-3">
                        @if($c['avatar'])
                            <img src="{{ $c['avatar'] }}" alt="" referrerpolicy="no-referrer" loading="lazy"
                                class="w-11 h-11 rounded-full object-cover bg-stone-100 shrink-0"
                                onerror="this.style.display='none'">
                        @endif
                        <div class="min-w-0">
                            <p class="font-semibold text-stone-800 text-sm truncate">{{ $c['nickname'] ?: $c['username'] }}</p>
                            <a href="{{ $profile }}" target="_blank" rel="noopener"
                                class="text-xs text-red-600 hover:underline truncate block">{{ '@'.$c['username'] }} <span class="text-[10px]">↗</span></a>
                        </div>
                    </div>

                    @if($k || $isExact)
                        <div class="flex flex-wrap gap-1 -mt-1">
                            @if($isExact)<span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-red-50 text-red-700">cocok persis</span>@endif
                            @if($k)
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-stone-100 text-stone-600">sudah di database</span>
                                @if($k->is_gapok)<span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">💰 Gapok</span>@endif
                            @endif
                        </div>
                    @endif

                    {{-- GMV 30 hari --}}
                    <div class="bg-stone-50 rounded-xl px-3 py-2.5">
                        <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">GMV 30 hari</p>
                        @if($c['gmv_usd'] !== null)
                            <p class="text-lg font-bold text-stone-800 leading-tight">≈ {{ $idr($c['gmv_usd']) }}</p>
                            <p class="text-[11px] text-stone-400">{{ $usdFmt($c['gmv_usd']) }}@if($c['gmv_range']) · <span class="text-stone-500">{{ $c['gmv_range'] }}</span>@endif</p>
                        @elseif($c['gmv_range'])
                            <p class="text-lg font-bold text-stone-800 leading-tight">{{ $c['gmv_range'] }}</p>
                            <p class="text-[11px] text-stone-400">rentang perkiraan TikTok</p>
                        @else
                            <p class="text-sm text-stone-400">data tak tersedia</p>
                        @endif
                        @if($vlStr)
                            <p class="text-[11px] text-stone-500 mt-1">{{ $vlStr }}</p>
                        @endif
                    </div>

                    {{-- Angka kunci --}}
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div>
                            <p class="text-sm font-bold text-stone-800">{{ $short($c['followers']) }}</p>
                            <p class="text-[10px] text-stone-400">follower</p>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-stone-800">{{ $c['avg_video_views'] ? $short($c['avg_video_views']) : '—' }}</p>
                            <p class="text-[10px] text-stone-400">views video</p>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-stone-800">{{ $c['avg_live_uv'] ? $short($c['avg_live_uv']) : '—' }}</p>
                            <p class="text-[10px] text-stone-400">penonton LIVE</p>
                        </div>
                    </div>

                    {{-- Demografi --}}
                    @if($demoStr)
                        <p class="text-[11px] text-stone-500 border-t border-stone-100 pt-2">{{ $demoStr }}</p>
                    @endif

                    {{-- Aksi: rekrut ke gapok + simpan performa ke Database KOL --}}
                    @if($canManage && $c['username'])
                        <div class="flex flex-wrap gap-2 pt-2 border-t border-stone-100 mt-auto">
                            @if($k)
                                <form method="POST" action="{{ route('kol-cek-tiktok.save') }}" class="flex-1 min-w-[8rem]">
                                    @csrf
                                    <input type="hidden" name="username" value="{{ $c['username'] }}">
                                    <input type="hidden" name="open_id" value="{{ $c['open_id'] }}">
                                    <input type="hidden" name="followers" value="{{ $c['followers'] }}">
                                    @if($c['gmv_usd'] !== null)<input type="hidden" name="gmv_usd" value="{{ $c['gmv_usd'] }}">@endif
                                    <button type="submit" class="w-full text-xs font-semibold rounded-lg border border-stone-300 text-stone-700 px-3 py-1.5 hover:bg-stone-50"
                                        title="Simpan follower + GMV asli TikTok ke record KOL ini">💾 Simpan ke Database</button>
                                </form>
                                @unless($k->is_gapok)
                                    <form method="POST" action="{{ route('kol-gapok.add-username') }}">
                                        @csrf
                                        <input type="hidden" name="username" value="{{ $c['username'] }}">
                                        <button type="submit" class="text-xs font-semibold rounded-lg bg-emerald-600 text-white px-3 py-1.5 hover:bg-emerald-700 whitespace-nowrap">+ Gapok</button>
                                    </form>
                                @endunless
                            @else
                                <form method="POST" action="{{ route('kol-gapok.add-username') }}" class="flex-1">
                                    @csrf
                                    <input type="hidden" name="username" value="{{ $c['username'] }}">
                                    <button type="submit" class="w-full text-xs font-semibold rounded-lg bg-emerald-600 text-white px-3 py-1.5 hover:bg-emerald-700"
                                        title="Buat KOL baru (peran affiliate) + tandai Tim Gapok">+ Jadikan Gapok</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="col-span-full bg-white rounded-2xl border border-stone-200 px-4 py-10 text-center text-stone-400 text-sm">
                    Tak ada kreator yang cocok. Coba username lain / lebih spesifik.
                </div>
            @endforelse
        </div>
    @endif

    <p class="text-xs text-stone-400 pt-1">
        Data dari TikTok Creator Marketplace (GMV/views 30 hari terakhir). Angka Rupiah adalah <strong>estimasi</strong>
        dari GMV USD × kurs Rp{{ number_format($rate, 0, ',', '.') }} (atur di <code>TIKTOK_USD_IDR_RATE</code>) — untuk gambaran, bukan nilai pasti.
    </p>
</div>
@endsection
