@extends('layouts.app')
@section('title', 'Video @'.$kol->handle())
@section('heading', 'Video SKINKU — @'.$kol->handle())

@section('content')
@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
    $handle = $kol->handle();
    $jmlBaru = $rows->where('baru', true)->count();
    $badge = 'ml-1 px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 text-[10px] font-semibold';
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3 text-xs">
        <a href="{{ route('kol-views-harian.index', array_filter(['dari' => $from->toDateString(), 'sampai' => $to->toDateString(), 'q' => $q])) }}" class="px-3 py-1.5 border border-stone-300 rounded-lg text-stone-700 hover:bg-stone-50">← Kembali ke Views Harian</a>
        <a href="{{ route('kols.show', $kol) }}" class="ml-auto text-red-700 hover:underline">Profil KOL →</a>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-4 text-[12px] text-stone-600 leading-relaxed">
        <b>{{ $rows->count() }} video</b> SKINKU <b>{{ '@'.$handle }}</b> yang ditonton atau diunggah di rentang ini.
        Tanda <span class="{{ $badge }}">baru</span> = diunggah di rentang ini (jumlahnya sama dengan kolom Diposting); tanpa tanda = video lama yang masih ditonton.
        Kolom tanggal = views pada hari itu — totalnya sama dengan baris kreator ini di Views Harian. Klik judul untuk membuka videonya di TikTok.
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-2 text-xs">
        <label>Dari<input type="date" name="dari" value="{{ $from->toDateString() }}" class="block mt-1 px-2 py-1.5 border border-stone-300 rounded-lg"></label>
        <label>Sampai<input type="date" name="sampai" value="{{ $to->toDateString() }}" class="block mt-1 px-2 py-1.5 border border-stone-300 rounded-lg"></label>
        @if($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
        <button class="px-3 py-1.5 bg-stone-800 text-white rounded-lg">Tampilkan</button>
    </form>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-auto" style="max-height: calc(100vh - 14rem)">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px] sticky top-0 z-10">
                    <tr>
                        <th class="text-right px-3 py-2">No</th>
                        <th class="text-left px-3 py-2 sticky left-0 bg-stone-50">Video</th>
                        <th class="text-left px-2">Diposting</th>
                        @foreach($dates as $d)<th class="text-right px-2">{{ \Illuminate\Support\Carbon::parse($d)->format('d M') }}</th>@endforeach
                        <th class="text-right px-3 text-stone-700">Total</th>
                        <th class="text-right px-3">GMV</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        @php $max = max(1, max($r['views'])); $judul = $r['title'] ?: '(tanpa judul)'; @endphp
                        <tr class="border-t border-stone-100 hover:bg-stone-50">
                            <td class="text-right px-3 py-2 text-stone-400">{{ $loop->iteration }}</td>
                            <td class="px-3 sticky left-0 bg-white"><a href="{{ 'https://www.tiktok.com/@'.$handle.'/video/'.$r['content_id'] }}" target="_blank" rel="noopener" class="block max-w-xs truncate text-stone-800 hover:text-red-700 hover:underline" title="{{ $judul }}">{{ $judul }}</a></td>
                            <td class="px-2 text-stone-500">{{ $r['posted_at']?->translatedFormat('d M Y') ?? '—' }}@if($r['baru'])<span class="{{ $badge }}">baru</span>@endif</td>
                            @foreach($r['views'] as $v)
                                {{-- Intensitas warna = hari terbaik video ini (heatmap ringan, sama dgn Views Harian). --}}
                                <td class="text-right px-2 {{ $v ? 'text-stone-800' : 'text-stone-300' }}" style="{{ $v ? 'background: rgba(13,148,136,'.round(0.08 + 0.32 * $v / $max, 2).')' : '' }}">{{ $v ? $fmt($v) : '·' }}</td>
                            @endforeach
                            <td class="text-right px-3 font-bold text-stone-800">{{ $fmt($r['total_views']) }}</td>
                            <td class="text-right px-3 text-stone-600">Rp {{ $fmt($r['total_gmv']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($dates) + 5 }}" class="px-4 py-10 text-center text-stone-400">Tidak ada video yang ditonton atau diunggah di rentang ini.</td></tr>
                    @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot class="bg-stone-50 font-semibold text-stone-700">
                        <tr class="border-t border-stone-200">
                            <td></td><td class="px-3 py-2 sticky left-0 bg-stone-50">Total {{ $rows->count() }} video</td><td class="px-2">{{ $jmlBaru }} baru</td>
                            @foreach($totals as $t)<td class="text-right px-2">{{ $fmt($t) }}</td>@endforeach
                            <td class="text-right px-3">{{ $fmt(array_sum($totals)) }}</td><td class="text-right px-3">Rp {{ $fmt($rows->sum('total_gmv')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection
