@extends('layouts.app')
@section('title', 'Laporan Stok HQ')
@section('heading', 'Laporan Mutasi Stok HQ')

@section('content')
@php
    $n = fn ($v) => number_format((int) $v, 0, ',', '.');
    $sign = fn ($v) => ((int) $v > 0 ? '+' : '').number_format((int) $v, 0, ',', '.');
    $showMasukLain = ($totals['masuk_lain'] ?? 0) != 0;
    $showKeluarLain = ($totals['keluar_lain'] ?? 0) != 0;
    // jumlah kolom untuk colspan grup
    $keluarCols = 3 + ($showKeluarLain ? 1 : 0);
@endphp

{{-- Kontrol periode --}}
<form method="GET" action="{{ route('hq-stock.report') }}" class="bg-white rounded-2xl border border-stone-200 p-4 mb-4 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-[11px] font-semibold text-stone-500 mb-1">Tampilan</label>
        <div class="flex rounded-lg border border-stone-300 overflow-hidden text-sm">
            <label class="cursor-pointer">
                <input type="radio" name="mode" value="harian" class="peer sr-only" {{ $mode === 'harian' ? 'checked' : '' }} onchange="this.form.submit()">
                <span class="block px-4 py-2 peer-checked:bg-stone-800 peer-checked:text-white text-stone-600">Harian</span>
            </label>
            <label class="cursor-pointer border-l border-stone-300">
                <input type="radio" name="mode" value="bulanan" class="peer sr-only" {{ $mode === 'bulanan' ? 'checked' : '' }} onchange="this.form.submit()">
                <span class="block px-4 py-2 peer-checked:bg-stone-800 peer-checked:text-white text-stone-600">Bulanan</span>
            </label>
        </div>
    </div>
    <div>
        <label class="block text-[11px] font-semibold text-stone-500 mb-1">Periode</label>
        @if($mode === 'bulanan')
            <input type="month" name="date" value="{{ $start->format('Y-m') }}" onchange="this.form.submit()"
                class="px-3 py-2 border border-stone-300 rounded-lg text-sm">
        @else
            <input type="date" name="date" value="{{ $start->format('Y-m-d') }}" onchange="this.form.submit()"
                class="px-3 py-2 border border-stone-300 rounded-lg text-sm">
        @endif
    </div>
    <div class="flex items-center gap-1">
        <a href="{{ route('hq-stock.report', ['mode' => $mode, 'date' => $prev]) }}" class="px-3 py-2 border border-stone-300 rounded-lg text-sm hover:bg-stone-50">←</a>
        <a href="{{ route('hq-stock.report', ['mode' => $mode, 'date' => $next]) }}" class="px-3 py-2 border border-stone-300 rounded-lg text-sm hover:bg-stone-50">→</a>
        <a href="{{ route('hq-stock.export', ['mode' => $mode, 'date' => $anchor]) }}"
            class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⬇ Export Excel</a>
    </div>
    <div class="flex-1 min-w-[140px] text-right">
        <span class="text-lg font-bold text-stone-800">{{ $label }}</span>
    </div>
</form>

@if($baseline)
    <p class="text-[11px] text-stone-400 mb-3">
        📌 Titik nol opname: <b>{{ $baseline->copy()->addSecond()->format('d M Y') }}</b>.
        Data sebelum tanggal ini belum tentu akurat.
    </p>
@else
    <div class="mb-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
        ⚠️ Belum ada <b>Stok Opname</b>. Laporan tetap jalan dari data gerakan, tapi stok awal belum terpatok.
        <a href="{{ route('stok-opname.index') }}" class="underline font-semibold">Isi opname dulu →</a>
    </div>
@endif

{{-- Tabel mutasi (geser ke samping di layar kecil; kolom produk menempel) --}}
<div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead>
            <tr class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <th rowspan="2" class="text-left px-3 py-2 sticky left-0 bg-stone-50 z-10">Produk</th>
                <th rowspan="2" class="text-right px-3 py-2">Stok Awal</th>
                <th class="text-center px-3 py-1.5 border-l border-stone-200 bg-emerald-50/50 text-emerald-700" colspan="{{ $showMasukLain ? 2 : 1 }}">Masuk</th>
                <th class="text-center px-3 py-1.5 border-l border-stone-200 bg-rose-50/50 text-rose-700" colspan="{{ $keluarCols }}">Keluar</th>
                <th rowspan="2" class="text-right px-3 py-2 border-l border-stone-200">Penyesuaian</th>
                <th rowspan="2" class="text-right px-3 py-2 border-l border-stone-200 bg-stone-100 text-stone-700">Stok Akhir</th>
            </tr>
            <tr class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <th class="text-right px-3 py-1.5 border-l border-stone-200">Produksi</th>
                @if($showMasukLain)<th class="text-right px-3 py-1.5">Lain</th>@endif
                <th class="text-right px-3 py-1.5 border-l border-stone-200">TikTok</th>
                <th class="text-right px-3 py-1.5">Shopee</th>
                <th class="text-right px-3 py-1.5">Reseller</th>
                @if($showKeluarLain)<th class="text-right px-3 py-1.5">Lain</th>@endif
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                <tr class="border-t border-stone-100 hover:bg-stone-50/50">
                    <td class="px-3 py-2 sticky left-0 bg-white z-10">
                        <a href="{{ route('stock-movements.index', ['product_id' => $r['product']->id, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')]) }}"
                            class="font-semibold text-indigo-700 hover:text-indigo-900 hover:underline" title="Lihat rincian pergerakan produk ini pada periode ini">{{ $r['product']->name }}</a>
                        <div class="text-[10px] text-stone-400 font-mono">{{ $r['product']->sku ?: '—' }}</div>
                    </td>
                    <td class="text-right px-3 py-2 font-mono text-stone-600">{{ $n($r['awal']) }}</td>
                    <td class="text-right px-3 py-2 font-mono border-l border-stone-100 text-emerald-700">{{ $r['produksi'] ? $n($r['produksi']) : '·' }}</td>
                    @if($showMasukLain)<td class="text-right px-3 py-2 font-mono text-emerald-700">{{ $r['masuk_lain'] ? $n($r['masuk_lain']) : '·' }}</td>@endif
                    <td class="text-right px-3 py-2 font-mono border-l border-stone-100 text-rose-600">{{ $r['tiktok'] ? $n($r['tiktok']) : '·' }}</td>
                    <td class="text-right px-3 py-2 font-mono text-stone-300">{{ $r['shopee'] ? $n($r['shopee']) : '·' }}</td>
                    <td class="text-right px-3 py-2 font-mono text-rose-600">{{ $r['reseller'] ? $n($r['reseller']) : '·' }}</td>
                    @if($showKeluarLain)<td class="text-right px-3 py-2 font-mono text-rose-600">{{ $r['keluar_lain'] ? $n($r['keluar_lain']) : '·' }}</td>@endif
                    <td class="text-right px-3 py-2 font-mono border-l border-stone-100 {{ $r['penyesuaian'] > 0 ? 'text-emerald-600' : ($r['penyesuaian'] < 0 ? 'text-rose-600' : 'text-stone-300') }}">{{ $r['penyesuaian'] ? $sign($r['penyesuaian']) : '·' }}</td>
                    <td class="text-right px-3 py-2 font-mono font-bold border-l border-stone-100 bg-stone-50 text-stone-800">{{ $n($r['akhir']) }}</td>
                </tr>
            @empty
                <tr><td colspan="20" class="px-4 py-10 text-center text-stone-400">Tidak ada pergerakan stok pada periode ini.</td></tr>
            @endforelse
        </tbody>
        @if(count($rows))
            <tfoot>
                <tr class="border-t-2 border-stone-300 bg-stone-50 font-bold text-stone-800">
                    <td class="px-3 py-2 sticky left-0 bg-stone-50 z-10">TOTAL</td>
                    <td class="text-right px-3 py-2 font-mono">{{ $n($totals['awal']) }}</td>
                    <td class="text-right px-3 py-2 font-mono border-l border-stone-200">{{ $n($totals['produksi']) }}</td>
                    @if($showMasukLain)<td class="text-right px-3 py-2 font-mono">{{ $n($totals['masuk_lain']) }}</td>@endif
                    <td class="text-right px-3 py-2 font-mono border-l border-stone-200">{{ $n($totals['tiktok']) }}</td>
                    <td class="text-right px-3 py-2 font-mono">{{ $n($totals['shopee']) }}</td>
                    <td class="text-right px-3 py-2 font-mono">{{ $n($totals['reseller']) }}</td>
                    @if($showKeluarLain)<td class="text-right px-3 py-2 font-mono">{{ $n($totals['keluar_lain']) }}</td>@endif
                    <td class="text-right px-3 py-2 font-mono border-l border-stone-200">{{ $sign($totals['penyesuaian']) }}</td>
                    <td class="text-right px-3 py-2 font-mono border-l border-stone-200">{{ $n($totals['akhir']) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

<p class="mt-3 text-[11px] text-stone-400">
    Rumus: <b>Stok Akhir = Stok Awal + Produksi + Penyesuaian − (TikTok + Shopee + Reseller)</b>.
    Kolom TikTok terisi dari order yang sudah kamu <b>Potong Stok</b>. Shopee 0 (belum integrasi). Titik <b>·</b> = nol.
    <br>💡 Klik <b>nama produk</b> untuk lihat rincian tiap pergerakannya (buku besar) pada periode ini.
</p>
@endsection
