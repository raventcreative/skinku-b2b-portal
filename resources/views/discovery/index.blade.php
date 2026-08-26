@extends('layouts.app')
@section('title', 'Rekomendasi AI')
@section('heading', 'Rekomendasi AI')

@section('content')
@php $u = auth()->user(); @endphp

<div class="max-w-5xl space-y-4">

    <p class="text-sm text-stone-500">
        AI mencari di web (real-time) lalu merangkum: kandidat <b>KOL/influencer</b> baru
        atau <b>tren produk</b> skincare/beauty. Tiap pencarian memakai kuota API.
    </p>

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif

    @unless($configured)
        <div class="px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm">
            ⚠️ <b>Rekomendasi AI belum aktif.</b> Set <code>TAVILY_API_KEY</code> (mesin pencari) dan
            <code>OPENAI_API_KEY</code> (perangkum) di <code>.env</code> server, lalu jalankan
            <code>optimize:clear</code>. Pencarian akan gagal sampai keduanya terisi.
        </div>
    @endunless

    {{-- Tab switch --}}
    <div class="flex gap-1 border-b border-stone-200">
        <button type="button" data-tab="kol" onclick="showDiscoveryTab('kol')"
            class="disc-tab px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition {{ $tab === 'kol' ? 'border-red-600 text-red-700' : 'border-transparent text-stone-400 hover:text-stone-600' }}">
            🔎 Cari KOL
        </button>
        <button type="button" data-tab="produk" onclick="showDiscoveryTab('produk')"
            class="disc-tab px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition {{ $tab === 'produk' ? 'border-red-600 text-red-700' : 'border-transparent text-stone-400 hover:text-stone-600' }}">
            📈 Tren Produk
        </button>
    </div>

    {{-- ============================ TAB: KOL ============================ --}}
    <section data-panel="kol" class="{{ $tab === 'kol' ? '' : 'hidden' }} space-y-4">
        <form method="POST" action="{{ route('discovery.kol') }}" class="bg-white rounded-2xl border border-stone-200 p-5" onsubmit="discoveryLoading(this)">
            @csrf
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Kategori / niche</span>
                    <input name="kategori" maxlength="100" value="{{ old('kategori', $kolBrief['kategori'] ?? '') }}"
                        placeholder="mis. jerawat, brightening, anti-aging"
                        class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Platform</span>
                    <select name="platform" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        @foreach(['tiktok' => 'TikTok', 'instagram' => 'Instagram', 'youtube' => 'YouTube'] as $val => $lbl)
                            <option value="{{ $val }}" @selected(($kolBrief['platform'] ?? 'tiktok') === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Region</span>
                    <input name="region" maxlength="100" value="{{ old('region', $kolBrief['region'] ?? '') }}"
                        placeholder="Indonesia" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Follower min</span>
                    <input type="number" name="follower_min" min="0" value="{{ old('follower_min', $kolBrief['follower_min'] ?? '') }}"
                        placeholder="mis. 50000" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-right">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Follower max</span>
                    <input type="number" name="follower_max" min="0" value="{{ old('follower_max', $kolBrief['follower_max'] ?? '') }}"
                        placeholder="mis. 200000" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-right">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Keyword tambahan</span>
                    <input name="keyword" maxlength="150" value="{{ old('keyword', $kolBrief['keyword'] ?? '') }}"
                        placeholder="opsional" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
            </div>
            <div class="flex items-center gap-4 pt-4">
                <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl disabled:opacity-60">
                    🔎 Cari KOL
                </button>
                <span class="text-[11px] text-stone-400">Follower = estimasi AI — <b>verifikasi manual</b> sebelum deal.</span>
            </div>
        </form>

        @isset($kolError)
            <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $kolError }}</div>
        @endisset

        @isset($kolResult)
            @if(empty($kolResult['candidates']))
                <div class="px-4 py-6 rounded-2xl border border-dashed border-stone-300 text-center text-sm text-stone-500">
                    Tidak ada kandidat ditemukan. Coba longgarkan brief atau ganti keyword.
                </div>
            @else
                <p class="text-xs text-stone-400">Pencarian: <span class="text-stone-500">{{ $kolResult['query'] }}</span> · {{ count($kolResult['candidates']) }} kandidat</p>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach($kolResult['candidates'] as $c)
                        <div class="bg-white rounded-2xl border border-stone-200 p-4 flex flex-col gap-2">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <a href="{{ $c['profile_url'] }}" target="_blank" rel="noopener noreferrer"
                                        class="font-semibold text-indigo-600 hover:underline">{{ '@'.$c['username'] }}</a>
                                    <span class="ml-1 text-[10px] uppercase tracking-wide text-stone-400">{{ $c['platform'] }}</span>
                                </div>
                                <span class="text-xs text-stone-500 shrink-0">
                                    {{ $c['followers_est'] ? number_format($c['followers_est'], 0, ',', '.').' flw' : 'follower ?' }}
                                </span>
                            </div>
                            @if($c['kategori'])
                                <span class="inline-block w-fit text-[10px] px-2 py-0.5 rounded-full bg-stone-100 text-stone-600">{{ $c['kategori'] }}</span>
                            @endif
                            @if($c['alasan'])
                                <p class="text-xs text-stone-500 leading-relaxed">{{ $c['alasan'] }}</p>
                            @endif
                            <a href="{{ $c['source_url'] }}" target="_blank" rel="noopener noreferrer"
                                class="text-[10px] text-stone-400 hover:text-indigo-600 hover:underline break-all">↗ sumber temuan</a>
                            @if($u->canDo('kol.screening.manage'))
                                <form method="POST" action="{{ route('discovery.kol.add') }}" class="mt-1" onsubmit="discoveryLoading(this)">
                                    @csrf
                                    <input type="hidden" name="username" value="{{ $c['username'] }}">
                                    <input type="hidden" name="platform" value="{{ $c['platform'] }}">
                                    <input type="hidden" name="url" value="{{ $c['profile_url'] }}">
                                    <input type="hidden" name="followers" value="{{ $c['followers_est'] }}">
                                    <input type="hidden" name="kategori" value="{{ $c['kategori'] }}">
                                    <button class="w-full px-3 py-1.5 text-xs font-semibold rounded-lg border border-emerald-300 text-emerald-700 hover:bg-emerald-50 disabled:opacity-60">
                                        + Tambah ke Database KOL
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endisset
    </section>

    {{-- ========================== TAB: PRODUK ========================== --}}
    <section data-panel="produk" class="{{ $tab === 'produk' ? '' : 'hidden' }} space-y-4">
        <form method="POST" action="{{ route('discovery.produk') }}" class="bg-white rounded-2xl border border-stone-200 p-5" onsubmit="discoveryLoading(this)">
            @csrf
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Topik / tren yang ingin dicari</span>
                <input name="topik" maxlength="200" required value="{{ old('topik', $produkTopik ?? '') }}"
                    placeholder="mis. tren serum barrier-repair, ingredient yang lagi naik, produk kompetitor"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
            </label>
            <div class="flex items-center gap-4 pt-4">
                <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl disabled:opacity-60">
                    📈 Cari Tren
                </button>
                <span class="text-[11px] text-stone-400">Read-only — intel pasar buat inspirasi pengembangan produk.</span>
            </div>
        </form>

        @isset($produkError)
            <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $produkError }}</div>
        @endisset

        @isset($produkResult)
            @if(empty($produkResult['poin']))
                <div class="px-4 py-6 rounded-2xl border border-dashed border-stone-300 text-center text-sm text-stone-500">
                    Tidak ada tren ditemukan. Coba topik yang lebih spesifik.
                </div>
            @else
                <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-4">
                    @if($produkResult['ringkasan'])
                        <p class="text-sm text-stone-700 leading-relaxed">{{ $produkResult['ringkasan'] }}</p>
                    @endif
                    <ol class="space-y-3">
                        @foreach($produkResult['poin'] as $p)
                            <li class="border-l-2 border-red-200 pl-3">
                                <p class="text-sm font-semibold text-stone-800">{{ $p['judul'] }}</p>
                                @if($p['detail'])
                                    <p class="text-xs text-stone-500 leading-relaxed mt-0.5">{{ $p['detail'] }}</p>
                                @endif
                                @if(!empty($p['sumber']))
                                    <div class="flex flex-wrap gap-x-3 gap-y-0.5 mt-1">
                                        @foreach($p['sumber'] as $s)
                                            <a href="{{ $s }}" target="_blank" rel="noopener noreferrer"
                                                class="text-[10px] text-indigo-600 hover:underline break-all">↗ sumber</a>
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
        @endisset
    </section>

</div>

<script>
    function showDiscoveryTab(tab) {
        document.querySelectorAll('[data-panel]').forEach(function (el) {
            el.classList.toggle('hidden', el.getAttribute('data-panel') !== tab);
        });
        document.querySelectorAll('.disc-tab').forEach(function (el) {
            var on = el.getAttribute('data-tab') === tab;
            el.classList.toggle('border-red-600', on);
            el.classList.toggle('text-red-700', on);
            el.classList.toggle('border-transparent', !on);
            el.classList.toggle('text-stone-400', !on);
        });
    }
    // Tombol jadi "Mencari…" + disabled saat submit (Tavily + AI butuh beberapa detik).
    function discoveryLoading(form) {
        var btn = form.querySelector('button[type=submit], button:not([type])');
        if (btn) { btn.disabled = true; btn.dataset.label = btn.innerHTML; btn.innerHTML = 'Mencari…'; }
    }
</script>
@endsection
