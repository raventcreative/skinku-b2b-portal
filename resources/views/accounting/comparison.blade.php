@extends('layouts.app')
@section('title', 'Banding Periode')
@section('heading', 'Perbandingan Laporan')

@section('content')
@include('accounting._nav')

@php
    $rp = fn ($n) => number_format($n, 0, ',', '.');
    $specLabel = fn ($s) => strlen($s) === 4 ? 'Tahun '.$s : accPeriodLabel($s);
    // baris perbandingan: label, nilai A, nilai B, bold?, biaya? (biaya naik = merah), id grup rincian
    $row = function ($label, $va, $vb, $bold = false, $cost = false, $group = null, $child = false) use ($rp) {
        $d = $va - $vb;
        $pct = abs($vb) > 0.005 ? ($d / abs($vb)) * 100 : null;
        $pctTxt = $pct === null ? '—' : sprintf('%+.1f%%', $pct);
        $good = $cost ? $d < 0 : $d > 0;
        $cls = abs($d) < 0.5 ? 'text-stone-400' : ($good ? 'text-emerald-700' : 'text-rose-600');
        $b = $bold ? 'font-bold text-stone-900' : ($child ? 'text-stone-500 text-xs' : 'text-stone-700');
        $trAttr = $child
            ? ' class="hidden bg-stone-50/60" data-rincian="'.$group.'"'
            : ($group ? ' class="border-t border-stone-100 cursor-pointer hover:bg-stone-50" onclick="toggleRincian(\''.$group.'\', this)"' : ' class="border-t border-stone-100"');
        $lbl = $child
            ? '<span class="pl-6">'.e($label).'</span>'
            : ($group ? '<span class="inline-block w-4 text-stone-400 rincian-caret">▸</span>'.e($label) : e($label));
        return '<tr'.$trAttr.'>'
            .'<td class="px-4 py-2 '.$b.'">'.$lbl.'</td>'
            .'<td class="text-right '.$b.'">'.$rp($va).'</td>'
            .'<td class="text-right '.$b.'">'.$rp($vb).'</td>'
            .'<td class="text-right '.$cls.($child ? ' text-xs' : '').'">'.($d < 0 ? '('.$rp(abs($d)).')' : $rp($d)).'</td>'
            .'<td class="text-right text-xs pr-4 '.$cls.'">'.$pctTxt.'</td>'
            .'</tr>';
    };
    // Rincian per akun: gabung kode A ∪ B, urut selisih terbesar → pos penyebab naik/turun langsung di atas.
    $kv = fn (array $items, int $sign = 1) => collect($items)->mapWithKeys(fn ($x) => [$x['code'] => ['name' => $x['name'], 'amount' => $sign * $x['amount']]])->all();
    $detail = function (array $la, array $lb) {
        $codes = array_unique(array_merge(array_keys($la), array_keys($lb)));
        return collect($codes)->map(fn ($c) => [
            'label' => $c.' · '.($la[$c]['name'] ?? $lb[$c]['name']),
            'a' => $la[$c]['amount'] ?? 0.0, 'b' => $lb[$c]['amount'] ?? 0.0,
        ])->sortByDesc(fn ($x) => abs($x['a'] - $x['b']))->values()->all();
    };
    $isLines = fn ($S, string $pos, int $sign = 1) => $kv(collect($S['lines'][$pos] ?? [])->map(fn ($v, $c) => ['code' => $c] + $v)->values()->all(), $sign);
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
    // [label, A, B, bold, biaya?, rincian (null = tanpa rincian)]
    $blocks = [
        'Laba Rugi' => [
            ['Penjualan Bersih', $A['is']['penjualan_bersih'], $B['is']['penjualan_bersih'], false, false,
                $detail($isLines($A, 'penjualan') + $isLines($A, 'retur_potongan', -1), $isLines($B, 'penjualan') + $isLines($B, 'retur_potongan', -1))],
            ['Harga Pokok Penjualan', $A['is']['hpp'], $B['is']['hpp'], false, true, $detail($isLines($A, 'hpp'), $isLines($B, 'hpp'))],
            ['Laba Kotor', $A['is']['laba_kotor'], $B['is']['laba_kotor'], false, false, null],
            ['Beban Operasional', $A['is']['beban_operasional'], $B['is']['beban_operasional'], false, true,
                $detail($isLines($A, 'beban_operasional'), $isLines($B, 'beban_operasional'))],
            ['Laba Operasional', $A['is']['operating_income'], $B['is']['operating_income'], false, false, null],
            ['Pendapatan Lain-lain', $A['is']['pendapatan_lain'], $B['is']['pendapatan_lain'], false, false,
                $detail($isLines($A, 'pendapatan_lain'), $isLines($B, 'pendapatan_lain'))],
            ['Beban Non-operasional', $A['is']['beban_non_operasional'], $B['is']['beban_non_operasional'], false, true,
                $detail($isLines($A, 'beban_non_operasional'), $isLines($B, 'beban_non_operasional'))],
            ['LABA BERSIH', $A['is']['net_income'], $B['is']['net_income'], true, false, null],
        ],
        'Neraca' => [
            ['Total Aktiva', $A['bs']['total_aktiva'], $B['bs']['total_aktiva'], true, false, $detail($kv($A['bs']['aktiva']), $kv($B['bs']['aktiva']))],
            ['Total Liabilitas', $A['bs']['total_liabilitas'], $B['bs']['total_liabilitas'], false, true, $detail($kv($A['bs']['liabilitas']), $kv($B['bs']['liabilitas']))],
            ['Total Ekuitas', $A['bs']['total_ekuitas'], $B['bs']['total_ekuitas'], false, false, $detail($kv($A['bs']['ekuitas']), $kv($B['bs']['ekuitas']))],
            ['— Laba (Rugi) Berjalan', $A['bs']['laba_berjalan'], $B['bs']['laba_berjalan'], false, false, null],
        ],
        'Arus Kas' => [
            ['Arus Operasi', $A['cf']['operating'], $B['cf']['operating'], false, false, null],
            ['Arus Investasi', $A['cf']['investing'], $B['cf']['investing'], false, false, null],
            ['Arus Pendanaan', $A['cf']['financing'], $B['cf']['financing'], false, false, null],
            ['Kenaikan (Penurunan) Kas', $A['cf']['net'], $B['cf']['net'], false, false, null],
            ['Kas Akhir Periode', $A['cf']['kas_akhir'], $B['cf']['kas_akhir'], true, false, null],
        ],
    ];
@endphp

<div class="w-full space-y-5">
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
                        @foreach($rows as $i => $r)
                            @php $gid = $r[5] ? \Illuminate\Support\Str::slug($title).'-'.$i : null; @endphp
                            {!! $row($r[0], $r[1], $r[2], $r[3], $r[4], $gid) !!}
                            @foreach($r[5] ?? [] as $c)
                                {!! $row($c['label'], $c['a'], $c['b'], false, $r[4], $gid, true) !!}
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
    <p class="text-[11px] text-stone-400 text-center">Angka dibulatkan ke rupiah untuk keterbacaan. Selisih & % dihitung dari A − B. Neraca diambil per akhir periode.
        Klik baris bertanda ▸ untuk rincian per akun (urut selisih terbesar). Untuk pos biaya, naik = merah.</p>
</div>

<script>
function toggleRincian(id, tr) {
    const rows = document.querySelectorAll('[data-rincian="' + id + '"]');
    const buka = rows.length && rows[0].classList.contains('hidden');
    rows.forEach(r => r.classList.toggle('hidden', ! buka));
    const c = tr.querySelector('.rincian-caret');
    if (c) c.textContent = buka ? '▾' : '▸';
}
</script>
@endsection
