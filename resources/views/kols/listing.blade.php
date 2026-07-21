@extends('layouts.app')
@section('title', 'Listing KOL')
@section('heading', 'Listing KOL — Replika Format Excel')

@section('content')
@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    // Warna verdict mengikuti emoji tingkatannya.
    $vColor = fn (string $v) => match (true) {
        str_starts_with($v, '🟢') => 'text-emerald-700',
        str_starts_with($v, '🟡') => 'text-amber-600',
        str_starts_with($v, '🟠') => 'text-orange-600',
        str_starts_with($v, '🔴') => 'text-rose-700',
        str_starts_with($v, '⚪') => 'text-stone-400',
        default => 'text-stone-800',
    };
@endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Database KOL</a>
    <p class="text-xs text-stone-400">Satu baris = satu screening/listing, persis sheet <b>Listing KOL</b>. Data baru masuk lewat tombol + Screening di Database KOL.</p>
    <a href="{{ route('kols.listing.export') }}" class="ml-auto px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⬇ Export Excel</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        {{-- Header meniru Excel: kuning untuk grup Views, dua baris. --}}
        <thead class="text-stone-600 text-[10px] uppercase">
            <tr class="bg-stone-100">
                <th rowspan="2" class="text-left px-3 py-2 align-bottom">Bulan Listing</th>
                <th rowspan="2" class="text-left align-bottom">Tanggal Listing</th>
                <th rowspan="2" class="text-left align-bottom">Username Tiktok</th>
                <th rowspan="2" class="text-right align-bottom">Followers</th>
                <th rowspan="2" class="text-right align-bottom px-2" title="Harga kerjasama setiap video">Ratecard</th>
                <th colspan="7" class="text-center py-1.5 bg-yellow-200 text-yellow-900 border-b border-yellow-300">Views 7 Video Terakhir Tiktok</th>
                <th rowspan="2" class="text-right align-bottom px-2">Total Views</th>
                <th rowspan="2" class="text-right align-bottom px-2">Avg Views/Vid</th>
                <th rowspan="2" class="text-right align-bottom px-2">Avg Median/Vid</th>
                <th rowspan="2" class="text-right align-bottom px-2">CPM AVG (Mean)</th>
                <th rowspan="2" class="text-right align-bottom px-2">CPM AVG (Median)</th>
                <th rowspan="2" class="text-left align-bottom px-3">Rata-Rata [Mean] CPM Indicator</th>
                <th rowspan="2" class="text-left align-bottom px-3">Rata-Rata [Median] CPM Indicator</th>
                <th rowspan="2" class="text-left align-bottom px-3">GMV + Viral + Fake Detector</th>
                <th rowspan="2" class="text-left align-bottom px-3">Agency/ Non Agency</th>
                <th rowspan="2" class="text-right align-bottom px-3" title="RANK(CPM Mean; seluruh screening; ascending) — kolom Z Excel">Rank</th>
            </tr>
            <tr class="bg-yellow-50 text-yellow-900">
                @for($i = 1; $i <= 7; $i++)<th class="text-right px-2 py-1">{{ $i }}</th>@endfor
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $s)
                @php $k = $s->kol; @endphp
                <tr class="border-t border-stone-100 hover:bg-stone-50 align-top">
                    <td class="px-3 py-2 text-stone-600">{{ $s->tanggal_listing->translatedFormat('M Y') }}</td>
                    <td class="text-stone-600">{{ $s->tanggal_listing->format('d M y') }}</td>
                    <td>
                        <a href="{{ $k ? route('kols.show', $k) : '#' }}" class="font-bold text-red-700 hover:underline">{{ '@'.($k->tiktok_username ?? '?') }}</a>
                        @if($k?->tiktok_link)<a href="{{ $k->tiktok_link }}" target="_blank" rel="noopener" class="ml-1 text-stone-400 hover:text-stone-700">↗</a>@endif
                    </td>
                    <td class="text-right text-stone-700">{{ number_format((int) ($k->followers ?? 0), 0, ',', '.') }}</td>
                    <td class="text-right px-2 text-stone-700">{{ $s->ratecard !== null ? $rp($s->ratecard) : '—' }}</td>
                    @foreach($s->views() as $v)
                        <td class="text-right px-2 text-stone-600">{{ number_format($v, 0, ',', '.') }}</td>
                    @endforeach
                    <td class="text-right px-2 text-stone-600">{{ number_format($s->total_views, 0, ',', '.') }}</td>
                    <td class="text-right px-2 text-stone-600">{{ number_format($s->rata_views, 0, ',', '.') }}</td>
                    <td class="text-right px-2 font-semibold text-stone-800">{{ number_format($s->median_views, 0, ',', '.') }}</td>
                    <td class="text-right px-2 text-stone-700">{{ $s->cpm_rata !== null ? number_format($s->cpm_rata, 0, ',', '.') : '—' }}</td>
                    <td class="text-right px-2 text-stone-700">{{ $s->cpm_median !== null ? number_format($s->cpm_median, 0, ',', '.') : '—' }}</td>
                    {{-- Indikator Mean: 5 tingkat. Format teks meniru sel Excel. --}}
                    <td class="px-3 whitespace-nowrap">
                        <span class="font-semibold {{ $vColor($s->verdict_rata) }}">{{ $s->verdict_rata }}</span>
                        <span class="block text-[10px] text-stone-500">CPM {{ $s->cpm_rata !== null ? number_format($s->cpm_rata, 0, ',', '.') : '—' }} | CPV {{ $s->cpv_rata !== null ? number_format($s->cpv_rata, $s->cpv_rata < 100 ? 1 : 0, ',', '.') : '—' }} | Ratio {{ $s->ratio_rata !== null ? number_format($s->ratio_rata, 0, ',', '.').'%' : '—' }}</span>
                    </td>
                    {{-- Indikator Median: 3 tingkat (Worth It / Masih Oke / Kemahalan). --}}
                    <td class="px-3 whitespace-nowrap">
                        <span class="font-semibold {{ $vColor($s->verdict_median) }}">{{ $s->verdict_median }}</span>
                        <span class="block text-[10px] text-stone-500">CPM {{ $s->cpm_median !== null ? number_format($s->cpm_median, 0, ',', '.') : '—' }} | CPV {{ $s->cpv_median !== null ? number_format($s->cpv_median, $s->cpv_median < 100 ? 1 : 0, ',', '.') : '—' }} | Ratio {{ $s->ratio !== null ? number_format($s->ratio, 0, ',', '.').'%' : '—' }}</span>
                    </td>
                    <td class="px-3 whitespace-nowrap">
                        <span class="text-stone-800">💰GMV {{ number_format($s->gmv_estimate, 0, ',', '.') }}</span>
                        <span class="block text-[10px] text-stone-500">🚀Viral: {{ $s->viral_label }} | 👤Fake: {{ $s->fake_label ?? '—' }}</span>
                    </td>
                    <td class="px-3 text-stone-600">{{ $k->agency ?? '—' }}</td>
                    <td class="px-3 text-right font-bold text-stone-700">{{ isset($ranks[$s->id]) ? '#'.$ranks[$s->id] : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="22" class="px-4 py-8 text-center text-stone-400">Belum ada screening. Input lewat tombol <b>+ Screening</b> di Database KOL.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($rows->hasPages())
        <div class="px-4 py-3 border-t border-stone-100">{{ $rows->links() }}</div>
    @endif
</div>
@endsection
