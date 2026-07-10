@php
    if (! function_exists('accPeriodLabel')) {
        function accPeriodLabel($p) {
            [$y, $m] = array_pad(explode('-', $p), 2, '');
            $names = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
            return ($names[(int) $m] ?? $m).' '.$y;
        }
    }
    $tabs = [
        'report' => ['Laporan Keuangan', route('accounting.report')],
        'comparison' => ['Banding', route('accounting.comparison')],
        'trend' => ['Tren', route('accounting.trend')],
        'trial-balance' => ['Neraca Saldo', route('accounting.trial-balance')],
        'journals' => ['Jurnal Umum', route('accounting.journals')],
    ];
    $ownSelector = in_array($tab, ['comparison', 'trend'], true); // punya selektor sendiri
@endphp
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div class="flex gap-1 bg-stone-100 rounded-xl p-1 text-sm overflow-x-auto">
        @foreach($tabs as $key => [$label, $url])
            <a href="{{ $url }}{{ $key === 'comparison' || $key === 'trend' ? '' : '?period='.$period }}" class="px-4 py-1.5 rounded-lg whitespace-nowrap {{ $tab === $key ? 'bg-white shadow-sm font-semibold text-red-700' : 'text-stone-600 hover:text-stone-900' }}">{{ $label }}</a>
        @endforeach
    </div>
    @unless($ownSelector)
        <form method="GET" class="flex items-center gap-2 text-sm">
            <label class="text-stone-500">Periode:</label>
            <select name="period" onchange="this.form.submit()" class="px-3 py-2 border border-stone-300 rounded-lg">
                @foreach($periods as $p)<option value="{{ $p }}" @selected($p === $period)>{{ accPeriodLabel($p) }}</option>@endforeach
            </select>
        </form>
    @endunless
</div>
