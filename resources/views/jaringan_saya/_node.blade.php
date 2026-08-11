@php
    $badge = $node['aktif']
        ? ['Aktif', 'bg-emerald-100 text-emerald-700']
        : ($node['nonaktif'] ? ['Nonaktif', 'bg-stone-200 text-stone-500'] : ['Pasif', 'bg-amber-100 text-amber-700']);
    $arrow = ['naik' => '↑', 'turun' => '↓', 'datar' => '→'][$node['tren_arah']];
    $arrowColor = ['naik' => 'text-emerald-600', 'turun' => 'text-rose-600', 'datar' => 'text-stone-400'][$node['tren_arah']];
@endphp
<div class="p-3" style="padding-left: {{ 0.75 + $depth * 1.5 }}rem">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-[10rem]">
            <div class="flex items-center gap-2">
                @if($depth > 0)<span class="text-stone-300">└</span>@endif
                <span class="font-semibold text-sm text-stone-800">{{ $node['name'] }}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-stone-100 text-stone-600">{{ $node['tier'] }}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded {{ $badge[1] }}">{{ $badge[0] }}</span>
            </div>
            <div class="text-[11px] text-stone-400 mt-0.5">
                {{ $node['member_id'] ?? '—' }}@if($node['region']) · {{ $node['region'] }}@endif · {{ $node['downline_count'] }} downline
            </div>
        </div>
        <div class="flex items-center gap-4 text-right">
            <div>
                <div class="text-[10px] text-stone-400">Omzet bln ini</div>
                <div class="text-sm font-bold text-stone-800">Rp {{ number_format($node['omzet'], 0, ',', '.') }}</div>
                <div class="text-[10px] text-stone-400">{{ $node['trx'] }} transaksi</div>
            </div>
            <div class="hidden sm:block">
                <div class="text-[10px] text-stone-400">Tren 3 bln <span class="{{ $arrowColor }}">{{ $arrow }}</span></div>
                <div class="flex gap-2 text-[11px] text-stone-600">
                    @foreach($node['tren'] as $i => $v)
                        <span>{{ $trenLabels[$i] }}: {{ number_format($v, 0, ',', '.') }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@foreach($node['children'] as $child)
    @include('jaringan_saya._node', ['node' => $child, 'depth' => $depth + 1, 'trenLabels' => $trenLabels])
@endforeach
