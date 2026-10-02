@extends('layouts.app')
@section('title', 'Views Harian SKINKU')
@section('heading', 'Views Harian Video SKINKU — per Kreator')

@section('content')
@php $fmt = fn ($n) => number_format((int) $n, 0, ',', '.'); @endphp
<div class="space-y-4">
    <div class="bg-white rounded-2xl border border-stone-200 p-4 text-[12px] text-stone-600 leading-relaxed">
        Views <b>per hari</b> dari video yang mempromosikan <b>produk SKINKU</b> (keranjang kuning), per kreator. Dihitung tiap pagi jam 04:00
        dari selisih data TikTok hari ini vs kemarin → kolom tanggal = views <b>pada hari itu</b>, jadi data hari ini baru muncul besok pagi.
        Video non-SKINKU tidak termasuk.
        @if($mulai)<span class="text-stone-400">Riwayat tercatat sejak {{ \Illuminate\Support\Carbon::parse($mulai)->translatedFormat('d M Y') }}.</span>
        @else<span class="text-amber-700">Belum ada data — riwayat mulai terkumpul setelah sync 04:00 berikutnya (butuh 2 pagi untuk angka harian pertama).</span>@endif
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-2 text-xs">
        <label>Dari<input type="date" name="dari" value="{{ $from->toDateString() }}" class="block mt-1 px-2 py-1.5 border border-stone-300 rounded-lg"></label>
        <label>Sampai<input type="date" name="sampai" value="{{ $to->toDateString() }}" class="block mt-1 px-2 py-1.5 border border-stone-300 rounded-lg"></label>
        <label>Kreator<input type="search" name="q" value="{{ $q }}" placeholder="cari username…" class="block mt-1 px-2 py-1.5 border border-stone-300 rounded-lg w-44"></label>
        <button class="px-3 py-1.5 bg-stone-800 text-white rounded-lg">Tampilkan</button>
        <a href="{{ route('kol-views-harian.export', ['dari' => $from->toDateString(), 'sampai' => $to->toDateString()]) }}" class="ml-auto px-3 py-1.5 bg-emerald-700 text-white rounded-lg">Export Excel</a>
    </form>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-auto" style="max-height: calc(100vh - 14rem)">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px] sticky top-0 z-10">
                    <tr>
                        <th class="text-right px-3 py-2">No</th>
                        <th class="text-left px-3 py-2 sticky left-0 bg-stone-50">Kreator</th>
                        <th class="text-right px-2">Video</th>
                        @foreach($dates as $d)<th class="text-right px-2">{{ \Illuminate\Support\Carbon::parse($d)->format('d M') }}</th>@endforeach
                        <th class="text-right px-3 text-stone-700">Total</th>
                        <th class="text-right px-3">GMV</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        @php $max = max(1, max($r['views'])); @endphp
                        <tr class="border-t border-stone-100 hover:bg-stone-50">
                            <td class="text-right px-3 py-2 text-stone-400">{{ $loop->iteration }}</td>
                            <td class="px-3 sticky left-0 bg-white"><a href="{{ route('kols.show', $r['kol']) }}" class="font-semibold text-red-700 hover:underline">{{ '@'.$r['kol']->tiktok_username }}</a></td>
                            <td class="text-right px-2 text-stone-500">{{ $r['videos'] }}</td>
                            @foreach($r['views'] as $v)
                                {{-- Intensitas warna = hari terbaik kreator ini (heatmap ringan). --}}
                                <td class="text-right px-2 {{ $v ? 'text-stone-800' : 'text-stone-300' }}" style="{{ $v ? 'background: rgba(13,148,136,'.round(0.08 + 0.32 * $v / $max, 2).')' : '' }}">{{ $v ? $fmt($v) : '·' }}</td>
                            @endforeach
                            <td class="text-right px-3 font-bold text-stone-800">{{ $fmt($r['total_views']) }}</td>
                            <td class="text-right px-3 text-stone-600">Rp {{ $fmt($r['total_gmv']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($dates) + 5 }}" class="px-4 py-10 text-center text-stone-400">Belum ada views video SKINKU di periode ini.</td></tr>
                    @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot class="bg-stone-50 font-semibold text-stone-700">
                        <tr class="border-t border-stone-200">
                            <td></td><td class="px-3 py-2 sticky left-0 bg-stone-50">Total {{ $rows->count() }} kreator</td><td></td>
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
