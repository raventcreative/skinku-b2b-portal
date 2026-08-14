@extends('layouts.app')
@section('title', 'Omzet Mitra')
@section('heading', 'Omzet Mitra')

@section('content')
<p class="text-xs text-stone-500 mb-4">Total omzet tiap mitra — gabungan jualan ke downline (PO selesai) dan jualan ke customer akhir.</p>

{{-- Satu kendali: PERIODE. Pola sama dengan reports.index. --}}
@php $per = $bulan ? $bulan->translatedFormat('M Y') : 'semua periode'; @endphp
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4 text-sm">
    <span class="text-stone-500">Periode</span>
    <input type="month" name="bulan" value="{{ $bulan?->format('Y-m') }}" onchange="this.form.submit()"
        class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
    @if($bulan)
        <a href="{{ route('reports.omzet-mitra', ['bulan' => \App\Http\Controllers\ReportController::ALL_PERIODS]) }}"
            class="text-xs text-indigo-600 hover:underline">semua periode</a>
    @else
        {{-- Jangan tambahkan input hidden bernama "bulan" di sini: namanya
             bentrok dengan picker di atas (lihat catatan yg sama di reports.index). --}}
        <span class="text-xs text-stone-400">menampilkan semua periode — pilih bulan untuk mempersempit</span>
    @endif
</form>

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 flex flex-wrap items-center gap-3">
        <h3 class="text-sm font-bold text-stone-800">Omzet per Mitra</h3>
        <span class="text-[11px] text-stone-400">{{ $per }}</span>
    </div>

    @if(count($rows))
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr>
                        <th class="text-left px-5 py-2">Mitra</th>
                        <th class="text-left">Tier</th>
                        <th class="text-right">Jual ke Downline</th>
                        <th class="text-right">Jual ke Customer</th>
                        <th class="text-right pr-5">Total Omzet</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr class="border-t border-stone-100 hover:bg-stone-50">
                            <td class="px-5 py-2 font-semibold text-stone-800">{{ $r['nama'] }}</td>
                            <td class="text-stone-500">{{ $r['tier'] }}</td>
                            <td class="text-right text-stone-600">{{ $rp($r['jual_downline']) }}</td>
                            <td class="text-right text-stone-600">{{ $rp($r['jual_customer']) }}</td>
                            <td class="text-right pr-5 font-bold text-stone-800">{{ $rp($r['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-stone-300 bg-stone-50 font-bold text-stone-800">
                        <td class="px-5 py-2">TOTAL</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td class="text-right pr-5">{{ $rp($grandTotal) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        <p class="px-5 py-8 text-center text-xs text-stone-400">
            Belum ada jualan mitra{{ $bulan ? ' pada '.$bulan->translatedFormat('F Y') : '' }}.
        </p>
    @endif
</div>
@endsection
