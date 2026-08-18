@extends('layouts.app')
@section('title', 'Laporan Komisi')
@section('heading', 'Laporan Komisi')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<form method="GET" class="flex flex-wrap items-center gap-3 mb-4 text-sm">
    <span class="text-stone-500">Periode</span>
    <input type="month" name="bulan" value="{{ $bulan?->format('Y-m') }}" onchange="this.form.submit()"
        class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
    @if($bulan)
        <a href="{{ route('reports.komisi', ['bulan' => \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
            class="text-xs text-indigo-600 hover:underline">semua periode</a>
    @else
        <span class="text-xs text-stone-400">semua periode — pilih bulan untuk mempersempit</span>
    @endif
</form>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Komisi {{ $bulan ? $bulan->format('M Y') : '(semua)' }}</div>
        <div class="text-lg font-bold text-stone-800 mt-1">{{ $rp($summary['komisi_periode']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Total Saldo</div>
        <div class="text-lg font-bold text-stone-800 mt-1">{{ $rp($summary['total_saldo']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Tersedia</div>
        <div class="text-lg font-bold text-emerald-700 mt-1">{{ $rp($summary['total_tersedia']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Sedang Diproses</div>
        <div class="text-lg font-bold text-amber-700 mt-1">{{ $rp($summary['total_tertahan']) }}</div>
    </div>
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="text-[11px] text-stone-500">Sudah Cair</div>
        <div class="text-lg font-bold text-stone-800 mt-1">{{ $rp($summary['total_cair']) }}</div>
    </div>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">
        Komisi per Mitra <span class="text-stone-400 font-normal">({{ $summary['jumlah_mitra'] }} mitra)</span>
    </div>
    @if(count($rows))
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-2">Mitra</th>
                    <th class="text-left">Tier</th>
                    <th class="text-right">Komisi (periode)</th>
                    <th class="text-right">Transaksi</th>
                    <th class="text-right">Saldo</th>
                    <th class="text-right">Diproses</th>
                    <th class="text-right px-4">Tersedia</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2">
                            {{-- reports.komisi-detail baru ada di Task 3. route() dievaluasi SAAT
                                 render (bukan cuma saat diklik), jadi tanpa guard ini halaman 500
                                 begitu ada 1 baris mitra. Route::has() bikin baris ini teks biasa
                                 dulu — otomatis jadi tautan begitu Task 3 mendaftarkan route-nya. --}}
                            @if(\Illuminate\Support\Facades\Route::has('reports.komisi-detail'))
                                <a href="{{ route('reports.komisi-detail', ['mitra' => $r['user']->id, 'bulan' => $bulan ? $bulan->format('Y-m') : \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
                                    class="text-indigo-600 hover:underline font-semibold">{{ $r['user']->name }}</a>
                            @else
                                <span class="font-semibold text-stone-800">{{ $r['user']->name }}</span>
                            @endif
                        </td>
                        <td class="text-stone-500">{{ $r['tier'] }}</td>
                        <td class="text-right text-stone-700">{{ $rp($r['komisi']) }}</td>
                        <td class="text-right text-stone-500">{{ $r['transaksi'] }}</td>
                        <td class="text-right text-stone-700">{{ $rp($r['saldo']) }}</td>
                        <td class="text-right text-amber-700">{{ $rp($r['tertahan']) }}</td>
                        <td class="text-right px-4 font-semibold text-emerald-700">{{ $rp($r['tersedia']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @else
        <p class="px-4 py-8 text-center text-xs text-stone-400">Belum ada komisi tercatat.</p>
    @endif
</div>
@endsection
