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

<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3 mb-5">
    <div class="flex flex-wrap items-start gap-4">
        <div class="flex-1 min-w-[16rem]">
            @php $prof = $kol->profileUrl(); @endphp
            <h2 class="text-xl font-bold text-stone-900">
                @if($prof)
                    <a href="{{ $prof }}" target="_blank" rel="noopener" class="hover:underline" title="Buka profil {{ $kol->platformLabel() }}">{{ '@'.$kol->tiktok_username }}</a>
                @else
                    {{ '@'.$kol->tiktok_username }}
                @endif
                <span class="text-[11px] uppercase tracking-wide text-stone-400 align-middle ml-1">{{ $kol->platformLabel() }}</span>
            </h2>
            <p class="text-xs text-stone-500 mt-1">
                {{ number_format($kol->followers, 0, ',', '.') }} followers · <b>{{ $kol->level }}</b>
                · {{ $kol->kategori ?: 'tanpa kategori' }} · {{ $kol->provinsi ?: '—' }} · {{ $kol->agency ?: 'Non-Agency' }} · status <b>{{ $kol->status }}</b>
            </p>
            @if($kol->phone)
                <p class="text-xs text-stone-500 mt-1">📱 <a href="{{ $kol->whatsappUrl() }}" target="_blank" rel="noopener" class="text-emerald-700 hover:underline font-semibold">{{ $kol->phone }}</a> <span class="text-stone-400">— chat WhatsApp</span></p>
            @endif
            @if($kol->catatan)<p class="text-xs text-stone-500 mt-2">{{ $kol->catatan }}</p>@endif
        </div>
        <div class="flex gap-2">
            @if($u->canDo('kol.screening.manage'))
                <a href="{{ route('kol-screenings.create', ['kol' => $kol->id]) }}" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-stone-50">+ Screening</a>
            @endif
            @if($u->canDo('kol.deal.manage'))
                <a href="{{ route('kol-deals.create', ['kol' => $kol->id]) }}" class="px-3 py-1.5 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700">+ Deal</a>
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
                <div><button class="px-4 py-2 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800">Simpan Perubahan</button></div>
            </form>
        </details>
    @endif
</div>

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
                    <td class="px-4 py-2.5 text-stone-600">{{ $s->tanggal_listing->format('d M Y') }}</td>
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
@endsection
