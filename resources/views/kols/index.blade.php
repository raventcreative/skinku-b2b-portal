@extends('layouts.app')
@section('title', 'Database KOL')
@section('heading', 'Database KOL — Kurasi & Kerjasama')

@section('content')
@php
    $u = auth()->user();
    $canDeal = $u->canDo('kol.deal.manage');
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $skorFmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, ',', '.'), '0'), ',');
    $levelBadge = [
        'Nano' => 'bg-stone-100 text-stone-600', 'Mikro' => 'bg-sky-100 text-sky-700',
        'Middle' => 'bg-indigo-100 text-indigo-700', 'Makro' => 'bg-violet-100 text-violet-700',
        'Mega' => 'bg-amber-100 text-amber-700', 'Super Mega' => 'bg-rose-100 text-rose-700',
    ];
    $plainVerdict = fn ($v) => preg_replace('/^[^\\pL\\pN]+/u', '', (string) $v);
    $vColor = fn (string $v) => match (true) {
        str_contains($v, 'Worth It') => 'text-emerald-700',
        str_contains($v, 'Masih Oke') => 'text-amber-600',
        str_contains($v, 'Cukup') => 'text-orange-600',
        str_contains($v, 'Kemahalan') => 'text-rose-700',
        str_contains($v, 'Belum Ada Ratecard') => 'text-stone-400',
        default => 'text-stone-800',
    };
@endphp

{{-- Kotak cari KOL: ketik + pilih dalam 1 field → langsung loncat ke detail KOL. --}}
<div class="mb-4 max-w-xl rounded-2xl border border-stone-200 bg-brand-cream p-4">
    <label class="block">
        <span class="text-xs font-semibold text-stone-700">Cari database KOL</span>
        <span class="mt-0.5 block text-[11px] text-stone-500">Ketik username atau pilih nama untuk langsung membuka profil.</span>
        @include('kols._kol-combo', ['kols' => $allKols, 'name' => 'cari_kol_id', 'id' => 'cariKolCombo', 'atPrefix' => false, 'placeholder' => 'Ketik username atau pilih KOL…'])
    </label>
</div>

<div class="mb-4 rounded-2xl border border-stone-200 bg-brand-cream p-4">
<div class="flex flex-col gap-4">
    <form method="GET" aria-label="Filter database KOL" class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-3 xl:grid-cols-5">
        <select aria-label="Filter level" name="level" onchange="this.form.submit()" class="min-h-10 w-full rounded-lg border border-stone-300 px-2.5 py-2">
            <option value="">Semua level</option>
            @foreach($levels as $lv)<option value="{{ $lv }}" @selected(($filters['level'] ?? '') === $lv)>{{ $lv }}</option>@endforeach
        </select>
        <select aria-label="Filter kategori" name="kategori" onchange="this.form.submit()" class="min-h-10 w-full rounded-lg border border-stone-300 px-2.5 py-2">
            <option value="">Semua kategori</option>
            @foreach($kategoriList as $kat)<option value="{{ $kat }}" @selected(($filters['kategori'] ?? '') === $kat)>{{ $kat }}</option>@endforeach
        </select>
        <select aria-label="Filter status" name="status" onchange="this.form.submit()" class="min-h-10 w-full rounded-lg border border-stone-300 px-2.5 py-2">
            <option value="">Semua status</option>
            @foreach(\App\Models\Kol::STATUSES as $st)<option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ $st }}</option>@endforeach
        </select>
        <select aria-label="Filter platform" name="platform" onchange="this.form.submit()" class="min-h-10 w-full rounded-lg border border-stone-300 px-2.5 py-2">
            <option value="">Semua platform</option>
            @foreach($platforms as $key => $p)<option value="{{ $key }}" @selected(($filters['platform'] ?? '') === $key)>{{ $p['label'] }}</option>@endforeach
        </select>
        <select aria-label="Filter peran" name="role" onchange="this.form.submit()" class="min-h-10 w-full rounded-lg border border-stone-300 px-2.5 py-2">
            <option value="">Semua peran</option>
            @foreach($roleLabels as $val => $lbl)<option value="{{ $val }}" @selected(($filters['role'] ?? '') === $val)>{{ $lbl }}</option>@endforeach
        </select>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" aria-label="Cari nama, manager, atau voucher" placeholder="Nama, manager, voucher…" class="min-h-10 w-full rounded-lg border border-stone-300 px-3 py-2 sm:col-span-2 xl:col-span-1">
        <button class="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-brand-dark px-3 py-2 font-semibold text-white hover:bg-stone-800">
            <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m16 16 4 4"/></svg>Cari
        </button>
        {{-- Filter hasil kurasi: langsung saring yang layak / kemahalan. --}}
        <select aria-label="Filter penilaian" name="verdict" onchange="this.form.submit()" class="min-h-10 w-full rounded-lg border border-stone-300 px-2.5 py-2">
            <option value="">Semua verdict</option>
            <option value="worth" @selected(($filters['verdict'] ?? '') === 'worth')>Worth It</option>
            <option value="masih" @selected(($filters['verdict'] ?? '') === 'masih')>Masih Oke</option>
            <option value="mahal" @selected(($filters['verdict'] ?? '') === 'mahal')>Kemahalan</option>
            <option value="tanpa_harga" @selected(($filters['verdict'] ?? '') === 'tanpa_harga')>Belum ada ratecard</option>
            <option value="belum" @selected(($filters['verdict'] ?? '') === 'belum')>Belum discreening</option>
        </select>
        {{-- Filter Tim Gapok: tampilkan cuma anggota gajian. --}}
        <select aria-label="Filter anggota Gapok" name="gapok" onchange="this.form.submit()" class="min-h-10 w-full rounded-lg border border-stone-300 px-2.5 py-2">
            <option value="">Semua anggota</option>
            <option value="1" @selected(($filters['gapok'] ?? '') === '1')>Gapok saja</option>
        </select>
        {{-- Sort aktif ikut dipertahankan saat ganti filter. --}}
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
        @if(array_filter($filters))
            <a href="{{ route('kols.index') }}" class="inline-flex min-h-10 items-center px-2 font-semibold text-brand-maroon hover:underline">Reset filter</a>
        @endif
    </form>
    <div class="flex flex-wrap gap-2 border-t border-stone-100 pt-3">
        @if($u->canDo('kol.deal.manage'))
            <a href="{{ route('kol-deals.index') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-300 bg-brand-cream px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16M4 12h16M4 19h16"/></svg>Daftar deal</a>
        @endif
        @if($u->canDo('kol.screening.manage'))
            <a href="{{ route('kols.export') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-maroon px-3 py-2 text-xs font-semibold text-white hover:bg-brand-dark" title="Ekspor satu baris untuk setiap KOL"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v3h16v-3"/></svg>Ekspor Excel</a>
            <a href="{{ route('kol-screenings.create') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-300 bg-brand-cream px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>Screening</a>
            <a href="{{ route('kols.import') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-300 bg-brand-cream px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50" title="Impor banyak KOL sekaligus dari file"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4 4 4M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/></svg>Impor</a>
            <button type="button" onclick="document.getElementById('addKol').classList.toggle('hidden')"
                class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-maroon px-3 py-2 text-xs font-semibold text-white hover:bg-brand-dark"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M10 3v14M3 10h14"/></svg>Tambah KOL</button>
        @endif
    </div>
</div>
</div>

@if($u->canDo('kol.screening.manage'))
<div id="addKol" class="hidden bg-brand-cream rounded-2xl border border-stone-200 p-5 mb-4">
    <p class="text-sm font-bold text-stone-800 mb-3">Tambah KOL</p>
    <form method="POST" action="{{ route('kols.store') }}" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
        @csrf
        <input name="tiktok_username" required maxlength="100" placeholder="username (tanpa @)" value="{{ old('tiktok_username') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="name" maxlength="150" placeholder="nama tampilan (opsional)" value="{{ old('name') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <select name="role" class="px-3 py-2 border border-stone-300 rounded-lg">
            @foreach($roleLabels as $val => $lbl)<option value="{{ $val }}" @selected(old('role', 'kol') === $val)>{{ $lbl }}</option>@endforeach
        </select>
        <select name="platform" class="px-3 py-2 border border-stone-300 rounded-lg">
            @foreach(config('kol.platforms') as $key => $p)<option value="{{ $key }}" @selected(old('platform', 'tiktok') === $key)>{{ $p['label'] }}</option>@endforeach
        </select>
        <input name="tiktok_link" type="url" maxlength="255" placeholder="link profil (opsional, override otomatis)" value="{{ old('tiktok_link') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="followers" type="number" required min="0" placeholder="followers" value="{{ old('followers') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <select name="kategori" class="px-3 py-2 border border-stone-300 rounded-lg">
            <option value="">— kategori —</option>
            @foreach($kategoriList as $kat)<option value="{{ $kat }}" @selected(old('kategori') === $kat)>{{ $kat }}</option>@endforeach
        </select>
        <input name="provinsi" maxlength="100" placeholder="provinsi (opsional)" value="{{ old('provinsi') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="agency" maxlength="150" placeholder="agency (kosongkan bila non-agency)" value="{{ old('agency') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="phone" maxlength="30" placeholder="No. HP (mis. 0812…)" value="{{ old('phone') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <input name="catatan" maxlength="2000" placeholder="catatan (opsional)" value="{{ old('catatan') }}" class="px-3 py-2 border border-stone-300 rounded-lg">
        <div><button class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-maroon px-4 py-2 text-xs font-semibold text-white hover:bg-brand-dark"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>Simpan KOL</button></div>
    </form>
    @if($errors->any())
        <p class="mt-2 text-xs text-rose-600">{{ $errors->first() }}</p>
    @endif
</div>
@endif

<p class="text-[11px] text-stone-500 mb-2 leading-relaxed">
    Ada dua kolom penilaian layak/tidaknya harga KOL:
    <b class="text-brand-maroon">Penilaian Median</b> = dari views tengah, <b>acuan utama</b> (tak mempan diakali 1 video viral) ·
    <b class="text-stone-600">Penilaian Rata-rata</b> = pembanding, bisa terangkat 1 video viral.
    Kalau keduanya beda jauh, artinya ada video yang meledak sendiri — percayai yang <b>Median</b>.
</p>

<style>
    #kolTable .v7col { display: none; }
    #kolTable.show-v7 .v7col { display: table-cell; }
</style>
<div class="flex items-center gap-2 mb-2">
    <button type="button" onclick="toggleV7()" class="inline-flex min-h-9 items-center gap-2 rounded-lg border border-stone-300 bg-brand-cream px-3 py-1.5 text-[11px] font-semibold text-stone-700 hover:bg-brand-cream whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-maroon">
        <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M3 12h18M3 19h18M8 3v4m8 3v4m-5 3v4"/></svg>
        <span id="v7label">Tampilkan 7 kolom views</span>
    </button>
    <span class="text-[11px] text-stone-400">Ringkas: Total · Rata-rata · Median tetap tampil. Klik buat lihat rincian 7 video terakhir.</span>
</div>

<div class="bg-brand-cream rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table id="kolTable" class="w-full min-w-[118rem] text-xs whitespace-nowrap tabular-nums">
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
        <thead class="sticky top-0 z-20 bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th rowspan="2" class="sticky left-0 z-30 bg-stone-50 text-left px-4 py-2 align-bottom">{!! $sortLink('username', 'Username') !!}</th>
                <th rowspan="2" class="text-right align-bottom">{!! $sortLink('followers', 'Followers') !!}</th>
                <th rowspan="2" class="text-left px-3 align-bottom">{!! $sortLink('level', 'Level') !!}</th>
                <th rowspan="2" class="text-left align-bottom">{!! $sortLink('kategori', 'Kategori') !!}</th>
                <th rowspan="2" class="text-left align-bottom">{!! $sortLink('status', 'Status') !!}</th>
                <th rowspan="2" class="text-left align-bottom" title="Agency / Non-Agency">{!! $sortLink('agency', 'Agency') !!}</th>
                <th rowspan="2" class="text-left align-bottom" title="Demografi audiens TikTok (gender mayoritas + umur dominan) — dari Cek Performa TikTok. Kosong = belum disimpan.">Demografi</th>
                {{-- SEMUA kolom angka bisa diurutkan, seperti Excel. Yang belum
                     discreening selalu tenggelam ke bawah apa pun arahnya. --}}
                <th rowspan="2" class="text-right align-bottom" title="Harga kerjasama yang diminta (screening terakhir)">{!! $sortLink('ratecard', 'Ratecard') !!}</th>
                <th colspan="7" class="text-center py-1.5 border-b border-stone-200 v7col">Views 7 Video Terakhir</th>
                <th rowspan="2" class="text-right align-bottom">{!! $sortLink('total', 'Total') !!}</th>
                <th rowspan="2" class="text-right align-bottom" title="Rata-rata views per video">{!! $sortLink('avg', 'Rata-rata') !!}</th>
                <th rowspan="2" class="text-right align-bottom" title="Views tengah (median) — nilai yang paling wajar, tahan dari 1 video viral">{!! $sortLink('median', 'Median') !!}</th>
                <th rowspan="2" class="text-right align-bottom" title="Views tengah ÷ followers">{!! $sortLink('ratio', 'Ratio') !!}</th>
                <th rowspan="2" class="text-right align-bottom px-2" title="Biaya per 1000 views — versi rata-rata">{!! $sortLink('cpm_mean', 'CPM Rata-rata') !!}</th>
                <th rowspan="2" class="text-right align-bottom px-2" title="Biaya per 1000 views — versi median (acuan utama)">{!! $sortLink('cpm', 'CPM Median') !!}</th>
                <th rowspan="2" class="text-right align-bottom px-2" title="Biaya per satu view">{!! $sortLink('cpv', 'CPV') !!}</th>
                <th rowspan="2" class="text-right px-2 align-bottom" title="Peringkat termurah (dari CPM median) di seluruh screening">{!! $sortLink('rank', 'Rank') !!}</th>
                <th rowspan="2" class="text-left px-3 align-bottom" title="Penilaian layak/tidak dari RATA-RATA views — bisa terangkat 1 video viral, jadi pembanding saja">{!! $sortLink('verdict_mean', 'Penilaian Rata-rata') !!}</th>
                <th rowspan="2" class="text-left px-3 align-bottom" title="Penilaian layak/tidak dari views MEDIAN (tengah) — ACUAN UTAMA, tahan dari 1 video viral">{!! $sortLink('verdict', 'Penilaian Median') !!}</th>
                <th rowspan="2" class="text-left align-bottom" title="Estimasi GMV = median views × 1,2% konversi × Rp38rb order rata-rata — hitungan sistem, BUKAN data asli">{!! $sortLink('gmv', 'GMV Estimasi · Viral · Fake') !!}</th>
                <th rowspan="2" class="text-right align-bottom" title="GMV asli dari data KOL — diisi manual saat screening (— bila belum diisi)">GMV Asli</th>
                <th rowspan="2" class="text-right px-2 align-bottom" title="GMV affiliate REAL bulan ini — penjualan SKINKU dari kreator ini (sama sumber dgn Affiliate & GMV). Bukan estimasi.">{!! $sortLink('gmv_real', 'GMV Bln') !!}</th>
                <th rowspan="2" class="text-center px-2 align-bottom" title="Skor APS terakhir (jejak)">APS</th>
                <th rowspan="2" class="text-center px-2 align-bottom" title="Skor KSS terakhir (jejak)">KSS</th>
                <th rowspan="2" class="text-right px-4 align-bottom"></th>
            </tr>
            <tr>
                @for($i = 1; $i <= 7; $i++)<th class="text-right px-2 py-1 v7col">{{ $i }}</th>@endfor
            </tr>
        </thead>
        <tbody>
            @forelse($kols as $kol)
                <tr class="group border-t border-stone-100 hover:bg-brand-cream/60">
                    <td class="sticky left-0 z-10 min-w-56 bg-brand-cream px-4 py-2.5 group-hover:bg-brand-cream">
                        @php $prof = $kol->profileUrl(); @endphp
                        <a href="{{ $prof ?? route('kols.show', $kol) }}" @if($prof) target="_blank" rel="noopener" @endif
                            class="font-bold text-brand-maroon hover:underline" title="Buka profil {{ $kol->platformLabel() }}">{{ '@'.$kol->tiktok_username }}</a>
                        <span class="ml-1 text-[9px] uppercase tracking-wide text-stone-400">{{ $kol->platformLabel() }}</span>
                        @if($kol->role !== 'kol')<span class="ml-1 text-[9px] px-1 py-0.5 rounded-sm bg-sky-100 text-sky-700">{{ $roleLabels[$kol->role] ?? $kol->role }}</span>@endif
                        @if($kol->is_gapok)<span class="ml-1 text-[9px] px-1 py-0.5 rounded-sm bg-amber-100 text-amber-700 font-semibold" title="Anggota Tim Affiliate Gapok">GAPOK</span>@endif
                        @if($kol->isBlacklisted())<span class="ml-1 text-[9px] px-1 py-0.5 rounded-sm bg-rose-100 text-rose-700">BLACKLIST</span>@endif
                        @if($kol->name)<span class="block text-[10px] text-stone-500">{{ $kol->name }}</span>@endif
                        @if($kol->phone)
                            <span class="block text-[10px] text-stone-400"><a href="{{ $kol->whatsappUrl() }}" target="_blank" rel="noopener" class="hover:text-emerald-600">{{ $kol->phone }}</a></span>
                        @endif
                    </td>
                    <td class="text-right text-stone-700">{{ number_format($kol->followers, 0, ',', '.') }}</td>
                    <td class="px-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $levelBadge[$kol->level] ?? 'bg-stone-100 text-stone-600' }}">{{ $kol->level }}</span></td>
                    <td class="text-stone-600">{{ $kol->kategori ?: '—' }}</td>
                    <td class="text-stone-600">{{ $kol->status }}</td>
                    <td class="text-stone-600">{{ $kol->agency ?: '—' }}</td>
                    <td>
                        @php
                            $tpp = $kol->tiktokProfile;
                            $genderLine = null;
                            if ($tpp && $tpp->gender) {
                                $g = $tpp->gender === 'FEMALE' ? '♀ P' : ($tpp->gender === 'MALE' ? '♂ L' : '');
                                $genderLine = $g.($tpp->gender_pct ? ' '.number_format($tpp->gender_pct, 1, ',', '.').'%' : '');
                            }
                        @endphp
                        @if($tpp && ($genderLine || $tpp->age_ranges))
                            @if($genderLine)<span class="text-sm font-bold text-stone-800" title="Gender mayoritas audiens">{{ $genderLine }}</span>@endif
                            @if($tpp->age_ranges)<span class="block text-[11px] font-semibold text-stone-500" title="Umur dominan">{{ $tpp->age_ranges }} th</span>@endif
                        @else
                            <span class="text-stone-300">—</span>
                        @endif
                    </td>
                    @php $ls = $kol->latestScreening; @endphp
                    @if($ls)
                        <td class="text-right text-stone-700">{{ $ls->ratecard !== null ? $rp($ls->ratecard) : '—' }}</td>
                        {{-- Satu kolom per video, rata kanan — rapi seperti Excel. Bisa disembunyikan (v7col). --}}
                        @foreach($ls->views() as $v)
                            <td class="text-right px-2 text-stone-600 v7col">{{ number_format($v, 0, ',', '.') }}</td>
                        @endforeach
                        <td class="text-right text-stone-500">{{ number_format($ls->total_views, 0, ',', '.') }}</td>
                        <td class="text-right text-stone-500">{{ number_format($ls->rata_views, 0, ',', '.') }}</td>
                        <td class="text-right font-semibold text-stone-800">{{ number_format($ls->median_views, 0, ',', '.') }}</td>
                        <td class="text-right text-stone-600">{{ $ls->ratio !== null ? number_format($ls->ratio, 1, ',', '.').'%' : '—' }}</td>
                        {{-- CPM Mean & Median kolom sendiri — angka sejajar rapi ke bawah. --}}
                        <td class="text-right px-2 text-stone-600">{{ $ls->cpm_rata !== null ? number_format($ls->cpm_rata, 0, ',', '.') : '—' }}</td>
                        <td class="text-right px-2 text-stone-700">{{ $ls->cpm_median !== null ? number_format($ls->cpm_median, 0, ',', '.') : '—' }}</td>
                        <td class="text-right px-2 text-stone-600">{{ $ls->cpv_median !== null ? number_format($ls->cpv_median, $ls->cpv_median < 100 ? 1 : 0, ',', '.') : '—' }}</td>
                        <td class="text-right px-2 font-bold text-stone-700">{{ isset($ranks[$ls->id]) ? '#'.$ranks[$ls->id] : '—' }}</td>
                        {{-- Dua indikator seperti Excel: mean (5 tingkat) & median (3 tingkat). --}}
                        <td class="px-3 font-semibold whitespace-nowrap {{ $vColor($ls->verdict_rata) }} @if($canDeal) cursor-pointer hover:underline @endif"
                            @if($canDeal) onclick="openDeal({{ $kol->id }}, @js('@'.$kol->tiktok_username), {{ (int) ($ls->ratecard ?? 0) }})" title="Klik = buat deal cepat" @endif>{{ $plainVerdict($ls->verdict_rata) }}</td>
                        <td class="px-3 font-semibold whitespace-nowrap {{ $vColor($ls->verdict_median) }} @if($canDeal) cursor-pointer hover:underline @endif"
                            @if($canDeal) onclick="openDeal({{ $kol->id }}, @js('@'.$kol->tiktok_username), {{ (int) ($ls->ratecard ?? 0) }})" title="Klik = buat deal cepat" @endif>{{ $plainVerdict($ls->verdict_median) }}</td>
                        <td class="whitespace-nowrap">
                            <span class="font-semibold text-stone-800">{{ $rp($ls->gmv_estimate) }}</span>
                            <span class="block text-[10px] text-stone-500">Viral {{ $ls->viral_label }} · Fake {{ $ls->fake_label ?? '—' }}</span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if($ls->gmv)<span class="font-semibold text-emerald-700">{{ $rp($ls->gmv) }}</span>@else<span class="text-stone-300" title="Belum diisi — isi lewat + Screening / detail">—</span>@endif
                        </td>
                    @else
                        <td colspan="20" class="px-3 text-stone-300">belum discreening</td>
                    @endif
                    @php $gmvB = $gmvMap->get($kol->id)?->gmv; $apsS = $apsMap->get($kol->id); $kssS = $kssMap->get($kol->id); @endphp
                    <td class="text-right px-2 text-stone-600">{{ $canAffiliate && $gmvB ? $rp($gmvB) : '—' }}</td>
                    <td class="text-center px-2 font-semibold text-stone-700">{{ $canAffiliate && $apsS && $apsS->score !== null ? $skorFmt($apsS->score) : '—' }}</td>
                    <td class="text-center px-2 font-semibold text-stone-700">{{ $kssS && $kssS->score !== null ? $skorFmt($kssS->score) : '—' }}</td>
                    <td class="text-right px-4">
                        <a href="{{ route('kols.show', $kol) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2.5 text-[11px] font-semibold text-brand-maroon hover:bg-brand-cream whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-maroon" aria-label="Lihat detail {{ '@'.$kol->tiktok_username }}">Detail<svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 1 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="31" class="px-4 py-8 text-center text-stone-400">Belum ada KOL. Klik <b>+ Tambah KOL</b> untuk mulai.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

@if($canDeal)
{{-- Modal deal cepat: buka dari klik kolom Penilaian. Zero-dep (native <dialog>).
     KOL & ratecard terisi otomatis; PIC/finansial dilengkapi lewat Edit. --}}
<dialog id="dealModal" class="rounded-2xl p-0 w-full max-w-md backdrop:bg-black/40">
    <form method="POST" action="{{ route('kol-deals.store') }}" class="p-5">
        @csrf
        <input type="hidden" name="dari_kol" value="1">
        <input type="hidden" name="kol_id" id="dm_kol_id">
        <input type="hidden" name="status" value="draft">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-stone-900">Deal cepat — <span id="dm_kol_name" class="text-brand-maroon"></span></h3>
            <button type="button" onclick="document.getElementById('dealModal').close()" class="text-stone-400 hover:text-stone-700 text-lg leading-none">&times;</button>
        </div>
        <div class="grid grid-cols-2 gap-3 text-sm">
            <label class="text-[11px] font-semibold text-stone-500">Jenis
                <select name="jenis" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    @foreach(\App\Models\KolDeal::JENIS as $j)<option value="{{ $j }}">{{ strtoupper($j) }}</option>@endforeach
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Jumlah slot (VT)
                <input type="number" name="jumlah_slot" min="1" value="1" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500 col-span-2">Ratecard deal (Rp)
                <input type="number" name="ratecard_deal" id="dm_ratecard" min="0" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Periode mulai
                <input type="date" name="periode_mulai" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Periode selesai
                <input type="date" name="periode_selesai" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
        </div>
        <div class="flex items-center gap-2 mt-4">
            <button class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-maroon px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-7-7 7 7-7 7"/></svg>Buat deal</button>
            <button type="button" onclick="document.getElementById('dealModal').close()" class="px-3 py-2 text-sm text-stone-500 hover:text-stone-800">Batal</button>
            <span class="ml-auto text-[10px] text-stone-400">PIC & finansial via Edit</span>
        </div>
    </form>
</dialog>
<script>
    function openDeal(id, name, ratecard) {
        document.getElementById('dm_kol_id').value = id;
        document.getElementById('dm_kol_name').textContent = name;
        document.getElementById('dm_ratecard').value = ratecard || '';
        document.getElementById('dealModal').showModal();
    }
</script>
@endif

<script>
    // Kotak cari KOL: pilih dari combobox → langsung buka halaman detail KOL.
    (function () {
        var combo = document.getElementById('cariKolCombo');
        if (combo) combo.addEventListener('combo:select', function (e) {
            if (e.detail.value) window.location = '{{ url('/kols') }}/' + e.detail.value;
        });
    })();

    // Sembunyikan/tampilkan 7 kolom "views video terakhir" (default sembunyi,
    // preferensi diingat per-browser). Total/Rata-rata/Median tetap tampil.
    function toggleV7() {
        var t = document.getElementById('kolTable');
        if (!t) return;
        var on = t.classList.toggle('show-v7');
        try { localStorage.setItem('kol_show_v7', on ? '1' : '0'); } catch (e) {}
        var lbl = document.getElementById('v7label');
        if (lbl) lbl.textContent = on ? 'Sembunyikan 7 kolom views' : 'Tampilkan 7 kolom views';
    }
    (function () {
        var show = false;
        try { show = localStorage.getItem('kol_show_v7') === '1'; } catch (e) {}
        if (show) {
            var t = document.getElementById('kolTable');
            if (t) t.classList.add('show-v7');
            var lbl = document.getElementById('v7label');
            if (lbl) lbl.textContent = 'Sembunyikan 7 kolom views';
        }
    })();
</script>
@endsection
