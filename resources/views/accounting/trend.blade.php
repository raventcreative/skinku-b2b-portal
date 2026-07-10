@extends('layouts.app')
@section('title', 'Tren Tahunan')
@section('heading', 'Tren Laba Rugi per Bulan')

@section('content')
@include('accounting._nav')

@php
    $val = fn ($n) => $n < 0 ? '('.number_format(abs($n), 0, ',', '.').')' : number_format($n, 0, ',', '.');
    $cls = fn ($n) => $n < 0 ? 'text-rose-600' : 'text-stone-700';
    $short = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
    $metrics = [
        ['penjualan_bersih', 'Penjualan Bersih', false],
        ['hpp', 'HPP', false],
        ['laba_kotor', 'Laba Kotor', false],
        ['beban_operasional', 'Beban Operasional', false],
        ['operating_income', 'Laba Operasional', false],
        ['net_income', 'Laba Bersih', true],
        ['arus_kas_bersih', 'Arus Kas Bersih', false],
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
                @foreach($metrics as [$key, $label, $bold])
                    @php $total = collect($rows)->sum($key); @endphp
                    <tr class="border-t border-stone-100 {{ $bold ? 'font-bold' : '' }}">
                        <td class="text-left px-4 py-2 sticky left-0 bg-white {{ $bold ? 'text-stone-900' : 'text-stone-700' }}">{{ $label }}</td>
                        @foreach($rows as $r)
                            <td class="text-right px-2 {{ $cls($r[$key]) }}">{{ $r[$key] == 0 ? '·' : $val($r[$key]) }}</td>
                        @endforeach
                        <td class="text-right px-4 bg-stone-50 {{ $cls($total) }}">{{ $val($total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="text-[11px] text-stone-400 mt-3">Angka dibulatkan ke rupiah. Bulan tanpa data ditandai "·". Geser tabel ke kanan untuk lihat semua bulan + Total.</p>
@endsection
