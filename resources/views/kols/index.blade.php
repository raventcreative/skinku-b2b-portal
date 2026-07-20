@extends('layouts.app')
@section('title', 'Database KOL')
@section('heading', 'Database KOL — Kurasi & Kerjasama')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $levelBadge = [
        'Nano' => 'bg-stone-100 text-stone-600', 'Mikro' => 'bg-sky-100 text-sky-700',
        'Middle' => 'bg-indigo-100 text-indigo-700', 'Makro' => 'bg-violet-100 text-violet-700',
        'Mega' => 'bg-amber-100 text-amber-700', 'Super Mega' => 'bg-rose-100 text-rose-700',
    ];
@endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <form method="GET" class="flex flex-wrap items-center gap-2 text-xs">
        <select name="level" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg">
            <option value="">Semua level</option>
            @foreach($levels as $lv)<option value="{{ $lv }}" @selected(($filters['level'] ?? '') === $lv)>{{ $lv }}</option>@endforeach
        </select>
        <select name="kategori" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg">
            <option value="">Semua kategori</option>
            @foreach($kategoriList as $kat)<option value="{{ $kat }}" @selected(($filters['kategori'] ?? '') === $kat)>{{ $kat }}</option>@endforeach
        </select>
        <select name="status" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg">
            <option value="">Semua status</option>
            @foreach(\App\Models\Kol::STATUSES as $st)<option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ $st }}</option>@endforeach
        </select>
        {{-- Filter hasil kurasi: langsung saring yang layak / kemahalan. --}}
        <select name="verdict" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg">
            <option value="">Semua verdict</option>
            <option value="worth" @selected(($filters['verdict'] ?? '') === 'worth')>🟢 Worth It</option>
            <option value="masih" @selected(($filters['verdict'] ?? '') === 'masih')>🟡 Masih Oke</option>
            <option value="mahal" @selected(($filters['verdict'] ?? '') === 'mahal')>🔴 Kemahalan</option>
            <option value="belum" @selected(($filters['verdict'] ?? '') === 'belum')>Belum discreening</option>
        </select>
        {{-- Sort aktif ikut dipertahankan saat ganti filter. --}}
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
        @if(array_filter($filters))
            <a href="{{ route('kols.index') }}" class="text-indigo-600 hover:underline">reset</a>
        @endif
    </form>
    <div class="ml-auto flex gap-2">
        @if($u->canDo('kol.deal.manage'))
            <a href="{{ route('kol-deals.index') }}" class="px-4 py-2 text-sm bg-white border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50">Daftar Deal</a>
        @endif
        @if($u->canDo('kol.screening.manage'))
            <a href="{{ route('kols.listing') }}" class="px-4 py-2 text-sm bg-white border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50">Listing KOL (format Excel)</a>
        <a href="{{ route('kol-screenings.create') }}" class="px-4 py-2 text-sm bg-white border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50">+ Screening</a>
            <button onclick="document.getElementById('addKol').classList.toggle('hidden')"
                class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Tambah KOL</button>
        @endif
    </div>
</div>

@if($u->canDo('kol.screening.manage'))
<div id="addKol" class="hidden bg-white rounded-2xl border border-stone-200 p-5 mb-4">
    <p class="text-sm font-bold text-stone-800 mb-3">Tambah KOL</p>
    <form method="POST" action="{{ route('kols.store') }}" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
        @csrf
        <input name="tiktok_username" required maxlength="100" placeholder="username TikTok (tanpa @)" value="{{ old('tiktok_username') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="tiktok_link" type="url" maxlength="255" placeholder="link profil (opsional)" value="{{ old('tiktok_link') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="followers" type="number" required min="0" placeholder="followers" value="{{ old('followers') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <select name="kategori" class="px-3 py-2 border border-stone-300 rounded-lg">
            <option value="">— kategori —</option>
            @foreach($kategoriList as $kat)<option value="{{ $kat }}" @selected(old('kategori') === $kat)>{{ $kat }}</option>@endforeach
        </select>
        <input name="provinsi" maxlength="100" placeholder="provinsi (opsional)" value="{{ old('provinsi') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="agency" maxlength="150" placeholder="agency (kosongkan bila non-agency)" value="{{ old('agency') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="catatan" maxlength="2000" placeholder="catatan (opsional)" value="{{ old('catatan') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <div><button class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">Simpan</button></div>
    </form>
    @if($errors->any())
        <p class="mt-2 text-xs text-rose-600">{{ $errors->first() }}</p>
    @endif
</div>
@endif

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        @php
            // Header = tautan sort. Klik pertama asc, klik lagi balik arah;
            // filter aktif ikut terbawa. Panah menandai kolom yang sedang dipakai.
            $sortLink = function (string $col, string $label) use ($sort, $dir, $filters) {
                $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                $arrow = $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
                $url = route('kols.index', array_merge(array_filter($filters), ['sort' => $col, 'dir' => $nextDir]));

                return '<a href="'.$url.'" class="hover:text-stone-800 '.($sort === $col ? 'text-stone-800 font-bold' : '').'">'.$label.$arrow.'</a>';
            };
        @endphp
        {{-- Header dua baris ala Excel: grup "Views 7 Video Terakhir" membawahi
             kolom 1–7, kolom lain rowspan penuh. Angka per video di kolomnya
             sendiri — bukan deret bertitik yang susah dibaca. --}}
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th rowspan="2" class="text-left px-4 py-2 align-bottom">{!! $sortLink('username', 'Username') !!}</th>
                <th rowspan="2" class="text-right align-bottom">{!! $sortLink('followers', 'Followers') !!}</th>
                <th rowspan="2" class="text-left px-3 align-bottom">{!! $sortLink('level', 'Level') !!}</th>
                <th rowspan="2" class="text-left align-bottom">{!! $sortLink('kategori', 'Kategori') !!}</th>
                <th rowspan="2" class="text-left align-bottom">{!! $sortLink('status', 'Status') !!}</th>
                <th rowspan="2" class="text-right align-bottom" title="Harga kerjasama yang diminta (screening terakhir)">Ratecard</th>
                <th colspan="7" class="text-center py-1.5 border-b border-stone-200">Views 7 Video Terakhir</th>
                <th rowspan="2" class="text-right align-bottom">Total</th>
                <th rowspan="2" class="text-right align-bottom">Median</th>
                <th rowspan="2" class="text-right align-bottom" title="Median views ÷ followers">Ratio</th>
                <th rowspan="2" class="text-left px-3 align-bottom" title="Urut berdasarkan CPM median — termurah dulu">{!! $sortLink('verdict', 'Verdict Terakhir') !!}</th>
                <th rowspan="2" class="text-left align-bottom" title="Estimasi GMV + deteksi viral & followers palsu (rumus Excel kolom W)">GMV · Viral · Fake</th>
                <th rowspan="2" class="text-right px-4 align-bottom"></th>
            </tr>
            <tr>
                @for($i = 1; $i <= 7; $i++)<th class="text-right px-2 py-1">{{ $i }}</th>@endfor
            </tr>
        </thead>
        <tbody>
            @forelse($kols as $kol)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5">
                        <a href="{{ route('kols.show', $kol) }}" class="font-bold text-red-700 hover:underline">{{ '@'.$kol->tiktok_username }}</a>
                        @if($kol->tiktok_link)
                            <a href="{{ $kol->tiktok_link }}" target="_blank" rel="noopener" class="ml-1 text-stone-400 hover:text-stone-700" title="Buka TikTok">↗</a>
                        @endif
                    </td>
                    <td class="text-right text-stone-700">{{ number_format($kol->followers, 0, ',', '.') }}</td>
                    <td class="px-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $levelBadge[$kol->level] ?? 'bg-stone-100 text-stone-600' }}">{{ $kol->level }}</span></td>
                    <td class="text-stone-600">{{ $kol->kategori ?: '—' }}</td>
                    <td class="text-stone-600">{{ $kol->status }}</td>
                    @php $ls = $kol->latestScreening; @endphp
                    @if($ls)
                        <td class="text-right text-stone-700">{{ $rp($ls->ratecard) }}</td>
                        {{-- Satu kolom per video, rata kanan — rapi seperti Excel. --}}
                        @foreach($ls->views() as $v)
                            <td class="text-right px-2 text-stone-600">{{ number_format($v, 0, ',', '.') }}</td>
                        @endforeach
                        <td class="text-right text-stone-500">{{ number_format($ls->total_views, 0, ',', '.') }}</td>
                        <td class="text-right font-semibold text-stone-800">{{ number_format($ls->median_views, 0, ',', '.') }}</td>
                        <td class="text-right text-stone-600">{{ $ls->ratio !== null ? number_format($ls->ratio, 1, ',', '.').'%' : '—' }}</td>
                        <td class="px-3 font-semibold {{ str_starts_with($ls->verdict_median, '🟢') ? 'text-emerald-700' : (str_starts_with($ls->verdict_median, '🟡') ? 'text-amber-600' : 'text-rose-700') }}">
                            {{ $ls->verdict_median }}
                            <span class="text-stone-400">· CPM {{ $ls->cpm_median !== null ? $rp($ls->cpm_median) : '—' }}</span>
                        </td>
                        <td class="whitespace-nowrap">
                            <span class="font-semibold text-stone-800">🪙 {{ $rp($ls->gmv_estimate) }}</span>
                            <span class="block text-[10px] text-stone-500">🚀 {{ $ls->viral_label }} · 👤 {{ $ls->fake_label ?? '—' }}</span>
                        </td>
                    @else
                        <td colspan="13" class="px-3 text-stone-300">belum discreening</td>
                    @endif
                    <td class="text-right px-4">
                        <a href="{{ route('kols.show', $kol) }}" class="text-[11px] text-indigo-600 hover:underline whitespace-nowrap">detail →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="19" class="px-4 py-8 text-center text-stone-400">Belum ada KOL. Klik <b>+ Tambah KOL</b> untuk mulai.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
