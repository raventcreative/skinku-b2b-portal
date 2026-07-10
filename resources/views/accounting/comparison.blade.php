@extends('layouts.app')
@section('title', 'Banding Periode')
@section('heading', 'Perbandingan Laporan')

@section('content')
@include('accounting._nav')

@php
    $rp = fn ($n) => number_format($n, 0, ',', '.');
    $specLabel = fn ($s) => strlen($s) === 4 ? 'Tahun '.$s : accPeriodLabel($s);
    // baris perbandingan: label, nilai A, nilai B, (bold?)
    $row = function ($label, $va, $vb, $bold = false) use ($rp) {
        $d = $va - $vb;
        $pct = abs($vb) > 0.005 ? ($d / abs($vb)) * 100 : null;
        $pctTxt = $pct === null ? '—' : sprintf('%+.1f%%', $pct);
        $cls = $d > 0 ? 'text-emerald-700' : ($d < 0 ? 'text-rose-600' : 'text-stone-400');
        $b = $bold ? 'font-bold text-stone-900' : 'text-stone-700';
        return '<tr class="border-t border-stone-100">'
            .'<td class="px-4 py-2 '.$b.'">'.$label.'</td>'
            .'<td class="text-right '.$b.'">'.$rp($va).'</td>'
            .'<td class="text-right '.$b.'">'.$rp($vb).'</td>'
            .'<td class="text-right '.$cls.'">'.($d < 0 ? '('.$rp(abs($d)).')' : $rp($d)).'</td>'
            .'<td class="text-right text-xs '.$cls.'">'.$pctTxt.'</td>'
            .'</tr>';
    };
@endphp

{{-- Pemilih 2 periode --}}
<form method="GET" class="bg-white rounded-2xl border border-stone-200 p-4 mb-4 flex flex-wrap items-end gap-4 text-sm">
    @foreach(['a' => 'Periode A', 'b' => 'Periode B'] as $name => $lbl)
        <div>
            <label class="block text-xs font-semibold mb-1">{{ $lbl }}</label>
            <select name="{{ $name }}" class="px-3 py-2 border border-stone-300 rounded-lg min-w-44">
                <optgroup label="Per Tahun">
                    @foreach($years as $y)<option value="{{ $y }}" @selected(($name==='a'?$specA:$specB) === $y)>Tahun {{ $y }}</option>@endforeach
                </optgroup>
                <optgroup label="Per Bulan">
                    @foreach($months as $m)<option value="{{ $m }}" @selected(($name==='a'?$specA:$specB) === $m)>{{ accPeriodLabel($m) }}</option>@endforeach
                </optgroup>
            </select>
        </div>
    @endforeach
    <button class="px-4 py-2 bg-stone-800 text-white rounded-lg hover:bg-stone-900">Bandingkan →</button>
</form>

@php
    $blocks = [
        'Laba Rugi' => [
            ['Penjualan Bersih', $A['is']['penjualan_bersih'], $B['is']['penjualan_bersih'], false],
            ['Harga Pokok Penjualan', $A['is']['hpp'], $B['is']['hpp'], false],
            ['Laba Kotor', $A['is']['laba_kotor'], $B['is']['laba_kotor'], false],
            ['Beban Operasional', $A['is']['beban_operasional'], $B['is']['beban_operasional'], false],
            ['Laba Operasional', $A['is']['operating_income'], $B['is']['operating_income'], false],
            ['Pendapatan Lain-lain', $A['is']['pendapatan_lain'], $B['is']['pendapatan_lain'], false],
            ['Beban Non-operasional', $A['is']['beban_non_operasional'], $B['is']['beban_non_operasional'], false],
            ['LABA BERSIH', $A['is']['net_income'], $B['is']['net_income'], true],
        ],
        'Neraca' => [
            ['Total Aktiva', $A['bs']['total_aktiva'], $B['bs']['total_aktiva'], true],
            ['Total Liabilitas', $A['bs']['total_liabilitas'], $B['bs']['total_liabilitas'], false],
            ['Total Ekuitas', $A['bs']['total_ekuitas'], $B['bs']['total_ekuitas'], false],
            ['— Laba (Rugi) Berjalan', $A['bs']['laba_berjalan'], $B['bs']['laba_berjalan'], false],
        ],
        'Arus Kas' => [
            ['Arus Operasi', $A['cf']['operating'], $B['cf']['operating'], false],
            ['Arus Investasi', $A['cf']['investing'], $B['cf']['investing'], false],
            ['Arus Pendanaan', $A['cf']['financing'], $B['cf']['financing'], false],
            ['Kenaikan (Penurunan) Kas', $A['cf']['net'], $B['cf']['net'], false],
            ['Kas Akhir Periode', $A['cf']['kas_akhir'], $B['cf']['kas_akhir'], true],
        ],
    ];
@endphp

<div class="space-y-5 max-w-4xl mx-auto">
    @foreach($blocks as $title => $rows)
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 font-bold text-stone-800 text-sm">{{ $title }}</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm whitespace-nowrap">
                    <thead class="bg-stone-50 text-stone-500 text-[10px] uppercase">
                        <tr>
                            <th class="text-left px-4 py-2">Akun</th>
                            <th class="text-right">{{ $specLabel($specA) }}</th>
                            <th class="text-right">{{ $specLabel($specB) }}</th>
                            <th class="text-right">Selisih</th>
                            <th class="text-right pr-4">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                            {!! $row($r[0], $r[1], $r[2], $r[3]) !!}
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
    <p class="text-[11px] text-stone-400 text-center">Angka dibulatkan ke rupiah untuk keterbacaan. Selisih & % dihitung dari A − B. Neraca diambil per akhir periode.</p>
</div>
@endsection
