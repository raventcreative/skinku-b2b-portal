@extends('layouts.app')
@section('title', 'Ringkasan Hasil Endorse')
@section('heading', 'Ringkasan Hasil Endorse KOL')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $canFinance = $u->canDo('kol.deal.finance');
    $levelBadge = [
        'Nano' => 'bg-stone-100 text-stone-600', 'Mikro' => 'bg-sky-100 text-sky-700',
        'Middle' => 'bg-indigo-100 text-indigo-700', 'Makro' => 'bg-violet-100 text-violet-700',
        'Mega' => 'bg-amber-100 text-amber-700', 'Super Mega' => 'bg-rose-100 text-rose-700',
    ];
@endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <a href="{{ route('kol-deals.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Daftar Deal</a>
    <form method="GET" class="flex items-center gap-2">
        <select name="tujuan" onchange="this.form.submit()" class="px-2 py-1.5 text-xs border border-stone-300 rounded-lg">
            <option value="">Semua tujuan</option>
            <option value="penjualan" @selected($tujuan === 'penjualan')>Penjualan</option>
            <option value="awareness" @selected($tujuan === 'awareness')>Awareness</option>
        </select>
        <select name="status" onchange="this.form.submit()" class="px-2 py-1.5 text-xs border border-stone-300 rounded-lg">
            <option value="">Semua status</option>
            @foreach(\App\Models\KolDeal::STATUSES as $s)<option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>@endforeach
        </select>
    </form>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-2">Kode</th><th class="text-left">KOL</th><th class="text-left">Tujuan</th>
                <th class="text-right">Video (up/FYP)</th><th class="text-right">Total Views</th><th class="text-right px-3">Rata²/video</th>
                @if($canFinance)<th class="text-right">Biaya</th><th class="text-right">Revenue</th><th class="text-right">CPM</th><th class="text-right px-2">ROMI</th>@endif
                <th class="text-left px-3">Verdict</th>
            </tr>
        </thead>
        <tbody>
            @forelse($deals as $d)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5"><a href="{{ route('kol-deals.edit', $d) }}" class="font-semibold text-stone-700 hover:underline">{{ $d->kode }}</a></td>
                    <td>
                        <span class="text-red-700 font-semibold">{{ '@'.($d->kol->tiktok_username ?? '?') }}</span>
                        <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $levelBadge[$d->kol?->level] ?? 'bg-stone-100 text-stone-600' }}">{{ $d->kol?->level ?? '—' }}</span>
                    </td>
                    <td class="text-stone-600 capitalize">{{ $d->hasil_tujuan ?? '—' }}</td>
                    <td class="text-right text-stone-600">{{ $num($d->hasil_video_upload) }} / {{ $num($d->hasil_video_fyp) }}</td>
                    <td class="text-right text-stone-700">{{ $num($d->hasil_views) }}</td>
                    <td class="text-right px-3 text-stone-600">{{ $d->hasil_avg_views !== null ? $num($d->hasil_avg_views) : '—' }}</td>
                    @if($canFinance)
                        <td class="text-right text-stone-600">{{ $rp($d->total_biaya) }}</td>
                        <td class="text-right text-stone-600">{{ $d->hasil_revenue !== null ? $rp($d->hasil_revenue) : '—' }}</td>
                        <td class="text-right text-stone-600">{{ $d->hasil_cpm !== null ? $rp($d->hasil_cpm) : '—' }}</td>
                        <td class="text-right px-2 text-stone-700 font-semibold">{{ $d->hasil_romi !== null ? $d->hasil_romi.'×' : '—' }}</td>
                    @endif
                    <td class="px-3 font-semibold">{{ $d->hasil_verdict }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $canFinance ? 11 : 7 }}" class="px-4 py-8 text-center text-stone-400">Belum ada deal yang laporannya diisi. Isi lewat Edit deal → Laporan Hasil Endorse.</td></tr>
            @endforelse
        </tbody>
        @if($deals->isNotEmpty())
            <tfoot class="bg-stone-50 text-stone-700">
                <tr class="border-t-2 border-stone-200 font-semibold">
                    <td class="px-4 py-2.5" colspan="3">TOTAL — {{ $deals->count() }} deal</td>
                    <td class="text-right">{{ $num($totals['video_upload']) }} / {{ $num($totals['video_fyp']) }}</td>
                    <td class="text-right">{{ $num($totals['views']) }}</td>
                    <td class="text-right px-3">—</td>
                    @if($canFinance)
                        <td class="text-right">{{ $rp($totals['biaya']) }}</td>
                        <td class="text-right">{{ $rp($totals['revenue']) }}</td>
                        <td class="text-right">{{ $totals['cpm'] !== null ? $rp($totals['cpm']) : '—' }}</td>
                        <td class="text-right px-2">{{ $totals['romi'] !== null ? $totals['romi'].'×' : '—' }}</td>
                    @endif
                    <td class="px-3 text-[10px] text-stone-400">gabungan</td>
                </tr>
            </tfoot>
        @endif
    </table>
    </div>
</div>
<p class="mt-3 text-[11px] text-stone-400">Diurut dari verdict terbaik. Verdict ikut tujuan: Penjualan → ROMI (≥2 Bagus, &lt;1 Jelek) · Awareness → CPM (&lt;60rb Bagus, ≥120rb Jelek). Klik kode untuk buka/isi laporan.</p>
@endsection
