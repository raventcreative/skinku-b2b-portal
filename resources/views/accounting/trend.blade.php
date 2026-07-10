@extends('layouts.app')
@section('title', 'Tren Tahunan')
@section('heading', 'Tren Keuangan per Bulan')

@section('content')
@include('accounting._nav')

@php
    $val = fn ($n) => $n < 0 ? '('.number_format(abs($n), 0, ',', '.').')' : number_format($n, 0, ',', '.');
    $cls = fn ($n) => $n < 0 ? 'text-rose-600' : 'text-stone-700';
    $short = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
    // index bulan terakhir yg ADA TRANSAKSI — deteksi dari ARUS/flow (bukan saldo neraca,
    // karena neraca carry-forward jadi selalu keisi walau bulan kosong).
    $lastIdx = 0;
    foreach ($rows as $i => $r) {
        if (abs($r['penjualan_bersih']) > 0.5 || abs($r['beban_operasional']) > 0.5
            || abs($r['net_income']) > 0.5 || abs($r['arus_kas_bersih']) > 0.5) {
            $lastIdx = $i;
        }
    }
    // [key, label, type(flow=dijumlah / balance=saldo akhir), bold]
    $sections = [
        'LABA RUGI' => [
            ['penjualan_bersih', 'Penjualan Bersih', 'flow', false],
            ['hpp', 'HPP', 'flow', false],
            ['laba_kotor', 'Laba Kotor', 'flow', false],
            ['beban_operasional', 'Beban Operasional', 'flow', false],
            ['operating_income', 'Laba Operasional', 'flow', false],
            ['net_income', 'Laba Bersih', 'flow', true],
        ],
        'NERACA (saldo akhir bulan)' => [
            ['total_aktiva', 'Total Aktiva', 'balance', true],
            ['total_liabilitas', 'Total Liabilitas', 'balance', false],
            ['total_ekuitas', 'Total Ekuitas', 'balance', false],
        ],
        'ARUS KAS' => [
            ['arus_operasi', 'Arus Operasi', 'flow', false],
            ['arus_investasi', 'Arus Investasi', 'flow', false],
            ['arus_pendanaan', 'Arus Pendanaan', 'flow', false],
            ['arus_kas_bersih', 'Kenaikan (Penurunan) Kas', 'flow', false],
            ['kas_akhir', 'Kas Akhir', 'balance', true],
        ],
    ];
@endphp

<form method="GET" class="bg-white rounded-2xl border border-stone-200 p-4 mb-4 flex items-end gap-3 text-sm">
    <div>
        <label class="block text-xs font-semibold mb-1">Tahun</label>
        <select name="year" onchange="this.form.submit()" class="px-3 py-2 border border-stone-300 rounded-lg">
            @foreach($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach
        </select>
    </div>
    <p class="text-[11px] text-stone-400">Semua bulan {{ $year }} berdampingan. Kolom Total = jumlah setahun.</p>
</form>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 text-[10px] uppercase">
                <tr>
                    <th class="text-left px-4 py-2 sticky left-0 bg-stone-50">Akun</th>
                    @foreach($short as $m => $lbl)<th class="text-right px-2">{{ $lbl }}</th>@endforeach
                    <th class="text-right px-4 bg-stone-100">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sections as $sectionTitle => $metrics)
                    <tr class="bg-stone-100/70"><td colspan="14" class="px-4 py-1.5 text-[10px] font-bold uppercase tracking-wide text-stone-500 sticky left-0 bg-stone-100">{{ $sectionTitle }}</td></tr>
                    @foreach($metrics as [$key, $label, $type, $bold])
                        @php $total = $type === 'flow' ? collect($rows)->sum($key) : $rows[$lastIdx][$key]; @endphp
                        <tr class="border-t border-stone-100 {{ $bold ? 'font-bold' : '' }}">
                            <td class="text-left px-4 py-2 sticky left-0 bg-white {{ $bold ? 'text-stone-900' : 'text-stone-700' }}">{{ $label }}</td>
                            @foreach($rows as $i => $r)
                                {{-- bulan setelah bulan terakhir berdata = kosong ("·"), termasuk baris saldo/neraca --}}
                                <td class="text-right px-2 {{ $cls($r[$key]) }}">{{ $i > $lastIdx || abs($r[$key]) < 0.5 ? '·' : $val($r[$key]) }}</td>
                            @endforeach
                            <td class="text-right px-4 bg-stone-50 {{ $cls($total) }}">{{ $val($total) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="text-[11px] text-stone-400 mt-3">Angka dibulatkan ke rupiah. Bulan tanpa data ditandai "·". Geser tabel ke kanan untuk semua bulan + Total. <b>Kolom Total</b>: baris Laba Rugi & Arus Kas = jumlah setahun; baris <b>Neraca &amp; Kas Akhir = saldo akhir tahun</b> (kumulatif, bukan dijumlah).</p>
@endsection
