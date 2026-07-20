@extends('layouts.app')
@section('title', 'KOL · '.$kol->tiktok_username)
@section('heading', 'Detail KOL')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $canFinance = $u->canDo('kol.deal.finance');
@endphp

<a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Database KOL</a>

<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3 mb-5">
    <div class="flex flex-wrap items-start gap-4">
        <div class="flex-1 min-w-[16rem]">
            <h2 class="text-xl font-bold text-stone-900">{{ '@'.$kol->tiktok_username }}
                @if($kol->tiktok_link)<a href="{{ $kol->tiktok_link }}" target="_blank" rel="noopener" class="text-sm text-stone-400 hover:text-stone-700">↗</a>@endif
            </h2>
            <p class="text-xs text-stone-500 mt-1">
                {{ number_format($kol->followers, 0, ',', '.') }} followers · <b>{{ $kol->level }}</b>
                · {{ $kol->kategori ?: 'tanpa kategori' }} · {{ $kol->provinsi ?: '—' }} · status <b>{{ $kol->status }}</b>
            </p>
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
                <input name="tiktok_link" type="url" maxlength="255" placeholder="link profil" value="{{ old('tiktok_link', $kol->tiktok_link) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <input name="followers" type="number" required min="0" value="{{ old('followers', $kol->followers) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
                <select name="kategori" class="px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="">— kategori —</option>
                    @foreach($kategoriList as $kat)<option value="{{ $kat }}" @selected(old('kategori', $kol->kategori) === $kat)>{{ $kat }}</option>@endforeach
                </select>
                <input name="provinsi" maxlength="100" placeholder="provinsi" value="{{ old('provinsi', $kol->provinsi) }}" class="px-3 py-2 border border-stone-300 rounded-lg">
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
                <th rowspan="2" class="text-left px-3 align-bottom" title="basis median views">Verdict (Median)</th>
                <th rowspan="2" class="text-left px-4 align-bottom" title="basis rata-rata views — pembanding, seperti kolom Mean di Excel">Verdict (Mean)</th></tr>
            <tr>
                @for($i = 1; $i <= 7; $i++)<th class="text-right px-2 py-1">{{ $i }}</th>@endfor
            </tr>
        </thead>
        <tbody>
            @forelse($kol->screenings as $s)
                <tr class="border-t border-stone-100 align-top">
                    <td class="px-4 py-2.5 text-stone-600">{{ $s->tanggal_listing->format('d M Y') }}</td>
                    <td class="text-right text-stone-700">{{ $rp($s->ratecard) }}</td>
                    {{-- Satu kolom per video — angka mentahnya, bukan deret bertitik. --}}
                    @foreach($s->views() as $v)
                        <td class="text-right px-2 text-stone-600">{{ number_format($v, 0, ',', '.') }}</td>
                    @endforeach
                    <td class="text-right text-stone-600">{{ number_format($s->total_views, 0, ',', '.') }}</td>
                    <td class="text-right font-semibold text-stone-800">{{ number_format($s->median_views, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-600">{{ number_format($s->rata_views, 1, ',', '.') }}</td>
                    <td class="text-right text-stone-600">{{ $s->ratio !== null ? number_format($s->ratio, 2, ',', '.').'%' : '—' }}</td>
                    <td class="px-3 font-semibold whitespace-nowrap {{ $s->verdict_median === \App\Models\KolScreening::VERDICT_WORTH ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ $s->verdict_median }}
                        <span class="block text-[10px] font-normal text-stone-400">CPM {{ $s->cpm_median !== null ? $rp($s->cpm_median) : '—' }}</span>
                    </td>
                    <td class="px-4 font-semibold whitespace-nowrap {{ $s->verdict_rata === \App\Models\KolScreening::VERDICT_WORTH ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ $s->verdict_rata }}
                        <span class="block text-[10px] font-normal text-stone-400">CPM {{ $s->cpm_rata !== null ? $rp($s->cpm_rata) : '—' }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="15" class="px-4 py-6 text-center text-stone-400">Belum ada screening.</td></tr>
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
