@extends('layouts.app')
@section('title', 'KOL · '.$kol->tiktok_username)
@section('heading', 'Detail KOL')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $canFinance = $u->canDo('kol.deal.finance');
    $vColor = fn (string $v) => match (true) {
        str_starts_with($v, '🟢') => 'text-emerald-700',
        str_starts_with($v, '🟡') => 'text-amber-600',
        str_starts_with($v, '🟠') => 'text-orange-600',
        str_starts_with($v, '🔴') => 'text-rose-700',
        str_starts_with($v, '⚪') => 'text-stone-400',
        default => 'text-stone-800',
    };
@endphp

<a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Database KOL</a>

@if(session('status'))
    <div class="mt-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
@endif

@if($kol->isBlacklisted())
    <div class="mt-3 px-4 py-3 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
        <b>⛔ KOL ini di-BLACKLIST.</b>{{ $kol->blacklist_reason ? ' Alasan: '.$kol->blacklist_reason : '' }} — jangan dimasukkan ke deal/pipeline baru.
    </div>
@endif

<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3 mb-5">
    <div class="flex flex-wrap items-start gap-4">
        <div class="flex-1 min-w-[16rem]">
            @php $prof = $kol->profileUrl(); @endphp
            <h2 class="text-xl font-bold text-stone-900">
                @if($kol->name)<span>{{ $kol->name }}</span> @endif
                @if($prof)
                    <a href="{{ $prof }}" target="_blank" rel="noopener" class="{{ $kol->name ? 'text-base text-stone-500 font-semibold' : '' }} hover:underline" title="Buka profil {{ $kol->platformLabel() }}">{{ '@'.$kol->tiktok_username }}</a>
                @else
                    {{ '@'.$kol->tiktok_username }}
                @endif
                <span class="text-[11px] uppercase tracking-wide text-stone-400 align-middle ml-1">{{ $kol->platformLabel() }}</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 align-middle">{{ \App\Models\Kol::ROLE_LABELS[$kol->role] ?? $kol->role }}</span>
            </h2>
            <p class="text-xs text-stone-500 mt-1">
                {{ number_format($kol->followers, 0, ',', '.') }} followers · <b>{{ $kol->level }}</b>
                · {{ $kol->kategori ?: 'tanpa kategori' }} · {{ $kol->provinsi ?: '—' }} · {{ $kol->agency ?: 'Non-Agency' }} · status <b>{{ $kol->status }}</b>
            </p>
            @if($kol->phone)
                <p class="text-xs text-stone-500 mt-1">📱 <a href="{{ $kol->whatsappUrl() }}" target="_blank" rel="noopener" class="text-emerald-700 hover:underline font-semibold">{{ $kol->phone }}</a> <span class="text-stone-400">— chat WhatsApp</span></p>
            @endif
            @if($kol->manager_name || $kol->manager_contact)
                <p class="text-xs text-stone-500 mt-1">👔 Manager: <b>{{ $kol->manager_name ?: '—' }}</b>{{ $kol->manager_contact ? ' · '.$kol->manager_contact : '' }}</p>
            @endif
            {{-- Flag komersial + voucher + tracking-link --}}
            @php $flags = collect(['barter_ok' => 'Bersedia barter', 'tiktok_shop_active' => 'TikTok Shop aktif', 'shopee_affiliate_active' => 'Shopee Affiliate aktif'])->filter(fn ($l, $k) => $kol->$k); @endphp
            @if($flags->isNotEmpty() || $kol->voucher_code || $kol->tracking_link)
                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                    @foreach($flags as $lbl)<span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">✓ {{ $lbl }}</span>@endforeach
                    @if($kol->voucher_code)<span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">🎟 {{ $kol->voucher_code }}</span>@endif
                    @if($kol->tracking_link)<a href="{{ $kol->tracking_link }}" target="_blank" rel="noopener" class="text-[10px] px-2 py-0.5 rounded-full bg-stone-100 text-stone-600 hover:bg-stone-200">🔗 tracking link</a>@endif
                </div>
            @endif
            @if($kol->usage_rights)<p class="text-[11px] text-stone-400 mt-1.5">Usage rights: {{ $kol->usage_rights }}</p>@endif
            @if($kol->catatan)<p class="text-xs text-stone-500 mt-2">{{ $kol->catatan }}</p>@endif
        </div>
        <div class="flex gap-2">
            @if($u->canDo('kol.screening.manage'))
                <a href="{{ route('kol-screenings.create', ['kol' => $kol->id]) }}" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-stone-50">+ Screening</a>
            @endif
            @if($u->canDo('kol.deal.manage'))
                <a href="{{ route('kol-deals.create', ['kol' => $kol->id]) }}" class="px-3 py-1.5 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700">+ Deal</a>
            @endif
            @if($u->role === \App\Models\User::ROLE_SUPER_ADMIN)
                <form method="POST" action="{{ route('kols.destroy', $kol) }}" onsubmit="return confirm('Arsipkan KOL ini? (soft-delete — screening/deal tetap tersimpan, bisa dipulihkan)')">
                    @csrf @method('DELETE')
                    <button class="px-3 py-1.5 text-xs bg-white border border-rose-200 text-rose-600 rounded-lg hover:bg-rose-50">Hapus</button>
                </form>
            @endif
        </div>
    </div>

    @if($u->canDo('kol.screening.manage'))
        <details class="mt-4 border-t border-stone-100 pt-3">
            <summary class="text-xs font-semibold text-stone-500 cursor-pointer select-none">Edit profil KOL</summary>
            <form method="POST" action="{{ route('kols.update', $kol) }}" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm mt-3">
                @csrf @method('PUT')
                <select name="platform" class="px-3 py-2 border border-stone-300 rounded-lg">
                    @foreach(config('kol.platforms') as $key => $p)<option value="{{ $key }}" @selected(old('platform', $kol->platform) === $key)>{{ $p['label'] }}</option>@endforeach
                </select>
                <input name="tiktok_link" type="url" maxlength="255" placeholder="link profil (opsional, override otomatis)" value="{{ old('tiktok_link', $kol->tiktok_link) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="followers" type="number" required min="0" value="{{ old('followers', $kol->followers) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <select name="kategori" class="px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="">— kategori —</option>
                    @foreach($kategoriList as $kat)<option value="{{ $kat }}" @selected(old('kategori', $kol->kategori) === $kat)>{{ $kat }}</option>@endforeach
                </select>
                <input name="provinsi" maxlength="100" placeholder="provinsi" value="{{ old('provinsi', $kol->provinsi) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="agency" maxlength="150" placeholder="agency (kosong = non-agency)" value="{{ old('agency', $kol->agency) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="phone" maxlength="30" placeholder="No. HP" value="{{ old('phone', $kol->phone) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <select name="status" class="px-3 py-2 border border-stone-300 rounded-lg">
                    @foreach(\App\Models\Kol::STATUSES as $st)<option value="{{ $st }}" @selected(old('status', $kol->status) === $st)>{{ $st }}</option>@endforeach
                </select>
                <input name="catatan" maxlength="2000" placeholder="catatan" value="{{ old('catatan', $kol->catatan) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                {{-- CRM: nama, peran, manager, flag komersial, voucher, tracking, usage, alasan blacklist --}}
                <input name="name" maxlength="150" placeholder="nama tampilan" value="{{ old('name', $kol->name) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <select name="role" class="px-3 py-2 border border-stone-300 rounded-lg">
                    @foreach(\App\Models\Kol::ROLE_LABELS as $val => $lbl)<option value="{{ $val }}" @selected(old('role', $kol->role) === $val)>{{ $lbl }}</option>@endforeach
                </select>
                <input name="manager_name" maxlength="150" placeholder="nama manager" value="{{ old('manager_name', $kol->manager_name) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="manager_contact" maxlength="150" placeholder="kontak manager" value="{{ old('manager_contact', $kol->manager_contact) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="voucher_code" maxlength="100" placeholder="kode voucher" value="{{ old('voucher_code', $kol->voucher_code) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="tracking_link" type="url" maxlength="255" placeholder="tracking link (URL)" value="{{ old('tracking_link', $kol->tracking_link) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="usage_rights" maxlength="2000" placeholder="usage rights" value="{{ old('usage_rights', $kol->usage_rights) }}" class="px-3 py-2 border border-stone-300 rounded-lg lg:col-span-2">
                <input name="blacklist_reason" maxlength="2000" placeholder="alasan blacklist (jika status blacklist)" value="{{ old('blacklist_reason', $kol->blacklist_reason) }}" class="px-3 py-2 border border-stone-300 rounded-lg lg:col-span-3">
                <div class="lg:col-span-3 flex flex-wrap gap-4 text-xs text-stone-600">
                    <label class="flex items-center gap-1.5"><input type="checkbox" name="barter_ok" value="1" @checked(old('barter_ok', $kol->barter_ok))> Bersedia barter</label>
                    <label class="flex items-center gap-1.5"><input type="checkbox" name="tiktok_shop_active" value="1" @checked(old('tiktok_shop_active', $kol->tiktok_shop_active))> TikTok Shop aktif</label>
                    <label class="flex items-center gap-1.5"><input type="checkbox" name="shopee_affiliate_active" value="1" @checked(old('shopee_affiliate_active', $kol->shopee_affiliate_active))> Shopee Affiliate aktif</label>
                </div>
                <div><button class="px-4 py-2 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800">Simpan Perubahan</button></div>
            </form>
        </details>
    @endif
</div>

{{-- Snapshot performa TikTok (Creator Marketplace) — diisi dari "Cek Performa TikTok". --}}
@if($canAffiliate && $kol->tiktokProfile)
    @php $tp = $kol->tiktokProfile; $genderLabel = ['FEMALE' => 'Perempuan', 'MALE' => 'Laki-laki']; @endphp
    <div class="bg-white rounded-2xl border border-stone-200 p-5 mb-5">
        <div class="flex items-center justify-between gap-2 mb-3">
            <p class="text-sm font-bold text-stone-800">📊 Performa TikTok <span class="text-xs font-normal text-stone-400">(Creator Marketplace)</span></p>
            <a href="{{ route('kol-cek-tiktok.index', ['q' => $kol->tiktok_username]) }}" class="text-[11px] text-red-600 hover:underline whitespace-nowrap">Perbarui →</a>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-stone-50 rounded-xl px-3 py-2.5 sm:col-span-2">
                <p class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">GMV 30 hari</p>
                <p class="text-lg font-bold text-stone-800 leading-tight">{{ $tp->gmv_idr !== null ? '≈ '.$rp($tp->gmv_idr) : ($tp->gmv_range ?: '—') }}</p>
                <p class="text-[11px] text-stone-400">
                    @if($tp->gmv_usd !== null)${{ number_format($tp->gmv_usd, 0, '.', ',') }}@endif
                    @if($tp->gmv_range) · {{ $tp->gmv_range }}@endif
                </p>
                @if($tp->video_gmv_idr !== null || $tp->live_gmv_idr !== null)
                    <p class="text-[11px] text-stone-500 mt-1">
                        @if($tp->video_gmv_idr !== null)🎬 {{ $rp($tp->video_gmv_idr) }}@endif
                        @if($tp->live_gmv_idr !== null) · 🔴 {{ $rp($tp->live_gmv_idr) }}@endif
                    </p>
                @endif
            </div>
            <div class="bg-stone-50 rounded-xl px-3 py-2.5 text-center flex flex-col justify-center">
                <p class="text-base font-bold text-stone-800">{{ number_format($tp->followers, 0, ',', '.') }}</p>
                <p class="text-[10px] text-stone-400">follower</p>
            </div>
            <div class="bg-stone-50 rounded-xl px-3 py-2.5 grid grid-cols-2 gap-2 text-center items-center">
                <div>
                    <p class="text-base font-bold text-stone-800">{{ $tp->avg_video_views ? number_format($tp->avg_video_views, 0, ',', '.') : '—' }}</p>
                    <p class="text-[10px] text-stone-400">views video</p>
                </div>
                <div>
                    <p class="text-base font-bold text-stone-800">{{ $tp->avg_live_uv ? number_format($tp->avg_live_uv, 0, ',', '.') : '—' }}</p>
                    <p class="text-[10px] text-stone-400">penonton LIVE</p>
                </div>
            </div>
        </div>
        @if($tp->region || $tp->gender || $tp->age_ranges)
            <div class="mt-4 border-t border-stone-100 pt-3">
                <p class="text-xs uppercase tracking-wide text-stone-400 font-semibold mb-2">👥 Demografi Audiens</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="bg-indigo-50/60 rounded-xl px-4 py-3">
                        <p class="text-[11px] text-stone-400">Region</p>
                        <p class="text-base font-bold text-stone-800">{{ $tp->region ?: '—' }}</p>
                    </div>
                    <div class="bg-indigo-50/60 rounded-xl px-4 py-3">
                        <p class="text-[11px] text-stone-400">Gender mayoritas</p>
                        <p class="text-base font-bold text-stone-800">
                            {{ $tp->gender ? ($genderLabel[$tp->gender] ?? ucfirst(strtolower($tp->gender))) : '—' }}
                            @if($tp->gender_pct)<span class="text-sm font-semibold text-indigo-600">{{ $tp->gender_pct }}%</span>@endif
                        </p>
                    </div>
                    <div class="bg-indigo-50/60 rounded-xl px-4 py-3">
                        <p class="text-[11px] text-stone-400">Kelompok umur dominan</p>
                        <p class="text-base font-bold text-stone-800">{{ $tp->age_ranges ? $tp->age_ranges.' th' : '—' }}</p>
                    </div>
                </div>
            </div>
        @endif
        <p class="text-[11px] text-stone-400 mt-3">Snapshot Creator Marketplace{{ $tp->synced_at ? ' · disimpan '.$tp->synced_at->translatedFormat('d M Y H:i') : '' }} · Rupiah estimasi (kurs Rp{{ number_format($tp->usd_idr_rate ?: 16000, 0, ',', '.') }}).</p>
    </div>
@endif

{{-- Akun platform (multi-akun additive) + Rate card per tipe konten --}}
<div class="grid lg:grid-cols-2 gap-5 mb-5">
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-bold text-stone-800 mb-3">Akun Platform</p>
        <div class="space-y-1.5 mb-3">
            {{-- Akun utama dari kols --}}
            <div class="flex items-center justify-between gap-2 text-sm">
                <span><span class="text-[9px] uppercase tracking-wide text-stone-400 mr-1">{{ $kol->platformLabel() }}</span> {{ '@'.$kol->tiktok_username }} <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700">utama</span></span>
                <span class="text-xs text-stone-500">{{ number_format($kol->followers, 0, ',', '.') }}</span>
            </div>
            @foreach($kol->accounts as $acc)
                <div class="flex items-center justify-between gap-2 text-sm">
                    <span class="min-w-0">
                        <span class="text-[9px] uppercase tracking-wide text-stone-400 mr-1">{{ $acc->platformLabel() }}</span>
                        @if($acc->profile_link)<a href="{{ $acc->profile_link }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">{{ '@'.$acc->username }}</a>@else{{ '@'.$acc->username }}@endif
                    </span>
                    <span class="flex items-center gap-2 shrink-0">
                        <span class="text-xs text-stone-500">{{ $acc->followers !== null ? number_format($acc->followers, 0, ',', '.') : '—' }}</span>
                        @if($u->canDo('kol.screening.manage'))
                            <form method="POST" action="{{ route('kols.accounts.destroy', $acc) }}" onsubmit="return confirm('Hapus akun ini?')"> @csrf @method('DELETE')<button class="text-[11px] text-rose-400 hover:text-rose-600">×</button></form>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
        @if($u->canDo('kol.screening.manage'))
            <form method="POST" action="{{ route('kols.accounts.store', $kol) }}" class="grid grid-cols-2 gap-2 text-sm border-t border-stone-100 pt-3">
                @csrf
                <select name="platform" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs bg-white">
                    @foreach($platforms as $key => $p)<option value="{{ $key }}">{{ $p['label'] }}</option>@endforeach
                </select>
                <input name="username" required maxlength="150" placeholder="username (tanpa @)" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <input type="number" name="followers" min="0" placeholder="followers" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <input name="profile_link" type="url" maxlength="255" placeholder="link (opsional)" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <div class="col-span-2"><button class="px-3 py-1.5 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800">+ Tambah akun</button></div>
            </form>
        @endif
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-bold text-stone-800 mb-3">Rate Card per Tipe Konten</p>
        <div class="space-y-1.5 mb-3">
            @forelse($kol->rateCards as $rc)
                <div class="flex items-center justify-between gap-2 text-sm">
                    <span class="text-stone-600">{{ $rateTypes[$rc->content_type] ?? $rc->content_type }}{{ $rc->note ? ' · '.$rc->note : '' }} <span class="text-[10px] text-stone-400">{{ $rc->created_at?->format('d M Y') }}</span></span>
                    <span class="flex items-center gap-2 shrink-0">
                        <b class="text-stone-800">{{ $rp($rc->rate) }}</b>
                        @if($u->canDo('kol.screening.manage'))
                            <form method="POST" action="{{ route('kols.rate-cards.destroy', $rc) }}" onsubmit="return confirm('Hapus rate ini?')"> @csrf @method('DELETE')<button class="text-[11px] text-rose-400 hover:text-rose-600">×</button></form>
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-xs text-stone-400">Belum ada rate card.</p>
            @endforelse
        </div>
        @if($u->canDo('kol.screening.manage'))
            <form method="POST" action="{{ route('kols.rate-cards.store', $kol) }}" class="grid grid-cols-2 gap-2 text-sm border-t border-stone-100 pt-3">
                @csrf
                <select name="content_type" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs bg-white">
                    @foreach($rateTypes as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                </select>
                <input type="number" name="rate" min="0" required placeholder="rate (Rp)" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <input name="note" maxlength="255" placeholder="catatan (opsional)" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs col-span-2">
                <div class="col-span-2"><button class="px-3 py-1.5 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800">+ Tambah rate</button></div>
            </form>
        @endif
    </div>
</div>

{{-- Stat bulan ini + pipeline (butuh izin affiliate untuk GMV/APS) --}}
@if($canAffiliate)
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">GMV bulan ini</p>
            <p class="text-xl font-bold text-stone-800">{{ $rp($gmvBulan) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Views bulan ini</p>
            <p class="text-xl font-bold text-stone-800">{{ number_format($viewsBulan, 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Skor APS</p>
            @if($aps && $aps['status'] === 'scored')
                @php $tone = ['bina_intensif' => 'bg-emerald-100 text-emerald-700', 'pantau' => 'bg-amber-100 text-amber-700', 'nurture' => 'bg-stone-100 text-stone-500'][$aps['label']]; @endphp
                <p class="text-xl font-bold text-stone-800">{{ rtrim(rtrim(number_format($aps['score'], 1, ',', '.'), '0'), ',') }} <span class="text-[10px] px-2 py-0.5 rounded-full {{ $tone }} align-middle">{{ $apsLabels[$aps['label']] }}</span></p>
            @else
                <p class="text-xl font-bold text-stone-400">—</p><p class="text-[10px] text-stone-400">belum cukup data (&lt; 4 minggu)</p>
            @endif
        </div>
    </div>

    @if($aps && $aps['status'] === 'scored')
        <details class="bg-white rounded-2xl border border-stone-200 p-4 mb-5">
            <summary class="cursor-pointer text-sm font-semibold text-stone-700">Rincian APS + GMV 4 minggu</summary>
            <div class="mt-3 grid sm:grid-cols-2 gap-x-6 gap-y-2">
                @foreach($aps['components'] as $c)
                    <div>
                        <div class="flex justify-between text-[11px] text-stone-500"><span>{{ $c['label'] }} <span class="text-stone-300">({{ (int) ($c['weight'] * 100) }}%)</span></span><span>{{ $c['raw'] }} · {{ $c['points'] }}</span></div>
                        <div class="h-1.5 bg-stone-100 rounded-full overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $c['points'] }}%"></div></div>
                    </div>
                @endforeach
            </div>
            @if(!empty($weeklyGmv))
                <div class="mt-4">
                    <p class="text-[11px] font-semibold text-stone-500 mb-1">GMV per minggu (4 minggu terakhir)</p>
                    <div class="flex gap-2 text-center">
                        @foreach($weeklyGmv as $i => $g)<div class="flex-1"><p class="text-xs font-semibold text-stone-700 tabular-nums">{{ $g >= 1_000_000 ? round($g / 1_000_000, 1).'jt' : number_format($g, 0, ',', '.') }}</p><p class="text-[10px] text-stone-400">M{{ $i + 1 }}</p></div>@endforeach
                    </div>
                </div>
            @endif
        </details>
    @endif
@endif

@if($kol->pipelineCard)
    <a href="{{ route('kol-pipeline.show', $kol->pipelineCard) }}" class="block bg-white rounded-2xl border border-stone-200 px-4 py-3 mb-5 text-sm hover:bg-stone-50">
        📋 Di pipeline: tahap <b>{{ \App\Models\KolPipelineCard::STAGE_LABELS[$kol->pipelineCard->stage] ?? $kol->pipelineCard->stage }}</b>{{ $kol->pipelineCard->next_action ? ' · next: '.$kol->pipelineCard->next_action : '' }} <span class="text-indigo-600">→ buka kartu</span>
    </a>
@endif

{{-- Tab: Riwayat Screening --}}
<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mb-5">
    <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Riwayat Screening</div>
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        {{-- Header dua baris ala Excel: grup Views membawahi kolom 1–7. --}}
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr><th rowspan="2" class="text-left px-4 py-2 align-bottom">Tanggal</th>
                <th rowspan="2" class="text-right align-bottom">Ratecard</th>
                <th colspan="7" class="text-center py-1.5 border-b border-stone-200">Views 7 Video Terakhir</th>
                <th rowspan="2" class="text-right align-bottom">Total</th>
                <th rowspan="2" class="text-right align-bottom">Median</th><th rowspan="2" class="text-right align-bottom">Rata</th>
                <th rowspan="2" class="text-right align-bottom">Ratio</th>
                <th rowspan="2" class="text-left px-3 align-bottom" title="Penilaian dari views MEDIAN (tengah) — acuan utama, tahan dari 1 video viral">Penilaian Median ⭐</th>
                <th rowspan="2" class="text-left px-4 align-bottom" title="Penilaian dari RATA-RATA views — pembanding, bisa terangkat 1 video viral">Penilaian Rata-rata</th>
                <th rowspan="2" class="text-left px-4 align-bottom" title="Estimasi GMV + deteksi viral & followers palsu">GMV · Viral · Fake</th></tr>
            <tr>
                @for($i = 1; $i <= 7; $i++)<th class="text-right px-2 py-1">{{ $i }}</th>@endfor
            </tr>
        </thead>
        <tbody>
            @forelse($kol->screenings as $s)
                <tr class="border-t border-stone-100 align-top">
                    <td class="px-4 py-2.5 text-stone-600">
                        {{ $s->tanggal_listing->format('d M Y') }}
                        @if($u->canDo('kol.screening.manage'))
                            <a href="{{ route('kol-screenings.edit', $s) }}" class="block text-[10px] text-indigo-600 hover:underline mt-0.5">✎ edit</a>
                        @endif
                    </td>
                    <td class="text-right text-stone-700">
                        @if($s->ratecard !== null)
                            {{ $rp($s->ratecard) }}
                        @elseif($u->canDo('kol.screening.manage'))
                            {{-- Isi harga setelah nego — verdict/CPM/rank langsung hidup. --}}
                            <form method="POST" action="{{ route('kol-screenings.ratecard', $s) }}" class="flex gap-1 justify-end">
                                @csrf @method('PATCH')
                                <input type="number" name="ratecard" min="0" required placeholder="isi harga"
                                    class="w-24 px-2 py-1 border border-stone-300 rounded text-[11px] text-right">
                                <button class="px-2 py-1 bg-stone-700 text-white rounded text-[11px]">Set</button>
                            </form>
                        @else
                            —
                        @endif
                        @if($s->benefit)<span class="block text-[10px] text-stone-500 text-left mt-1 whitespace-normal">🎁 {{ $s->benefit }}</span>@endif
                    </td>
                    {{-- Satu kolom per video — angka mentahnya, bukan deret bertitik. --}}
                    @foreach($s->views() as $v)
                        <td class="text-right px-2 text-stone-600">{{ number_format($v, 0, ',', '.') }}</td>
                    @endforeach
                    <td class="text-right text-stone-600">{{ number_format($s->total_views, 0, ',', '.') }}</td>
                    <td class="text-right font-semibold text-stone-800">{{ number_format($s->median_views, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-600">{{ number_format($s->rata_views, 1, ',', '.') }}</td>
                    <td class="text-right text-stone-600">{{ $s->ratio !== null ? number_format($s->ratio, 2, ',', '.').'%' : '—' }}</td>
                    <td class="px-3 font-semibold whitespace-nowrap {{ $vColor($s->verdict_median) }}">
                        {{ $s->verdict_median }}
                        <span class="block text-[10px] font-normal text-stone-400">CPM {{ $s->cpm_median !== null ? $rp($s->cpm_median) : '—' }} · CPV {{ $s->cpv_median !== null ? 'Rp '.number_format($s->cpv_median, $s->cpv_median < 100 ? 1 : 0, ',', '.') : '—' }}</span>
                    </td>
                    <td class="px-4 font-semibold whitespace-nowrap {{ $vColor($s->verdict_rata) }}">
                        {{ $s->verdict_rata }}
                        <span class="block text-[10px] font-normal text-stone-400">CPM {{ $s->cpm_rata !== null ? $rp($s->cpm_rata) : '—' }} · CPV {{ $s->cpv_rata !== null ? 'Rp '.number_format($s->cpv_rata, $s->cpv_rata < 100 ? 1 : 0, ',', '.') : '—' }}</span>
                    </td>
                    <td class="px-4 whitespace-nowrap">
                        <span class="font-semibold text-stone-800">🪙 {{ $rp($s->gmv_estimate) }}</span>
                        @if($s->gmv)<span class="block text-[10px] text-emerald-700 font-semibold">GMV aktual: {{ $rp($s->gmv) }}</span>@endif
                        <span class="block text-[10px] text-stone-500">🚀 Viral: {{ $s->viral_label }} · 👤 Fake: {{ $s->fake_label ?? '—' }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="16" class="px-4 py-6 text-center text-stone-400">Belum ada screening.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- Tab: Riwayat Deal --}}
<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Riwayat Deal</div>
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr><th class="text-left px-4 py-2">Kode</th><th class="text-left">Jenis</th>
                <th class="text-right">Ratecard Deal</th><th class="text-left px-3">Periode</th>
                <th class="text-left">PIC</th><th class="text-left">Status</th>
                @if($canFinance)<th class="text-right px-4">Total Biaya</th><th class="text-left">Bayar</th>@endif
            </tr>
        </thead>
        <tbody>
            @forelse($kol->deals as $d)
                <tr class="border-t border-stone-100">
                    <td class="px-4 py-2.5 font-semibold text-stone-700">{{ $d->kode }}</td>
                    <td class="uppercase text-stone-600">{{ $d->jenis }}</td>
                    <td class="text-right text-stone-700">{{ $rp($d->ratecard_deal) }}</td>
                    <td class="px-3 text-stone-600">{{ $d->periode_mulai?->format('d M') }} – {{ $d->periode_selesai?->format('d M Y') ?: '—' }}</td>
                    <td class="text-stone-600">{{ $d->pic->fullname ?? '—' }}</td>
                    <td class="text-stone-600">{{ $d->status }}</td>
                    @if($canFinance)
                        <td class="text-right px-4 text-stone-700">{{ $rp($d->total_biaya) }}</td>
                        <td class="text-stone-600">{{ $d->status_bayar }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canFinance ? 8 : 6 }}" class="px-4 py-6 text-center text-stone-400">Belum ada deal.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-5 mt-5">
    {{-- Log Kontak (CRM) --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-bold text-stone-800 mb-3">Log Kontak</p>
        <div class="space-y-2 mb-3">
            @forelse($kol->contactLogs as $log)
                <div class="flex items-start justify-between gap-2 border border-stone-100 rounded-xl p-2.5">
                    <div class="min-w-0">
                        <p class="text-xs"><span class="text-[10px] px-1.5 py-0.5 rounded bg-stone-100 text-stone-500">{{ $channels[$log->channel] ?? $log->channel }}</span> <span class="text-stone-400">{{ $log->contacted_at->format('d M Y') }}{{ $log->creator ? ' · '.$log->creator->fullname : '' }}</span></p>
                        <p class="text-sm text-stone-700 mt-1 whitespace-pre-line">{{ $log->note }}</p>
                    </div>
                    @if($u->canDo('kol.screening.manage'))
                        <form method="POST" action="{{ route('kols.contact-log.destroy', $log) }}" onsubmit="return confirm('Hapus log ini?')" class="shrink-0">
                            @csrf @method('DELETE')
                            <button class="text-[11px] text-rose-400 hover:text-rose-600">hapus</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-xs text-stone-400">Belum ada log kontak.</p>
            @endforelse
        </div>
        @if($u->canDo('kol.screening.manage'))
            <form method="POST" action="{{ route('kols.contact-log.store', $kol) }}" class="grid grid-cols-3 gap-2 text-sm border-t border-stone-100 pt-3">
                @csrf
                <select name="channel" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs bg-white">
                    @foreach($channels as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                </select>
                <input type="date" name="contacted_at" value="{{ now()->toDateString() }}" required class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs col-span-2">
                <input name="note" required maxlength="2000" placeholder="ringkasan kontak…" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs col-span-3">
                <div class="col-span-3"><button class="px-3 py-1.5 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800">+ Catat kontak</button></div>
            </form>
        @endif
    </div>

    {{-- Riwayat Skor + Konten terbaru --}}
    <div class="space-y-5">
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-800 mb-3">Riwayat Skor</p>
            @forelse($kol->scores->take(12) as $sc)
                <div class="flex items-center justify-between text-xs py-1 border-b border-stone-50 last:border-0">
                    <span class="text-stone-400">{{ $sc->captured_on?->format('d M Y') }}</span>
                    <span><span class="uppercase text-[10px] text-stone-400">{{ $sc->type }}</span> <b class="text-stone-800">{{ $sc->score !== null ? rtrim(rtrim(number_format($sc->score, 1, ',', '.'), '0'), ',') : '—' }}</b> <span class="text-stone-500">{{ $sc->label }}</span></span>
                </div>
            @empty
                <p class="text-xs text-stone-400">Belum ada skor tersimpan.</p>
            @endforelse
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-800 mb-3">Konten Terbaru</p>
            @forelse($recentContents as $c)
                <div class="flex items-center justify-between gap-2 text-xs py-1.5 border-b border-stone-50 last:border-0">
                    <a href="{{ route('kol-konten.show', $c) }}" class="text-indigo-600 hover:underline truncate">{{ $c->title ?: $c->url }}</a>
                    <span class="shrink-0 text-stone-500">{{ number_format((int) ($c->latestSnapshot->views ?? 0), 0, ',', '.') }} views</span>
                </div>
            @empty
                <p class="text-xs text-stone-400">Belum ada konten diarsipkan.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
