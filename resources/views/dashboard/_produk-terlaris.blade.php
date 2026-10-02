{{-- Produk terlaris per channel — $produkTerlaris dari ProdukTerlarisService, bulan = ?bulan dashboard. --}}
@php
    $ptChannels = [
        'semua' => ['Semua Channel', '#1c1917'],
        'reseller' => ['Reseller / PO', '#0f4c3a'],
        'tiktok' => ['TikTok', '#ef4444'],
        'shopee' => ['Shopee', '#f97316'],
    ];
    $ptNum = fn ($n) => number_format($n, 0, ',', '.');
@endphp
<div class="bg-white rounded-2xl border border-stone-200 p-5 mb-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
        <h3 class="text-sm font-bold text-stone-800">Produk Terlaris — {{ $bulan->translatedFormat('F Y') }}</h3>
        <p class="text-[11px] text-stone-400">Unit terjual (order berbayar: selesai + berjalan). Bundle dihitung per isi. ▲▼ = dibanding {{ $bulan->copy()->subMonthNoOverflow()->translatedFormat('M Y') }}. Ganti bulan lewat pilihan bulan di atas.</p>
    </div>
    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @foreach($ptChannels as $key => [$label, $color])
            @php $pt = $produkTerlaris[$key]; $max = max(1, collect($pt['rows'])->max('qty') ?? 1); @endphp
            <div class="rounded-xl border border-stone-200 p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-xs font-bold text-stone-700">
                        <span class="w-2.5 h-2.5 rounded-full" style="background: {{ $color }}"></span>{{ $label }}
                    </span>
                    <span class="text-[11px] text-stone-500">{{ $ptNum($pt['total']) }} unit</span>
                </div>
                @forelse($pt['rows'] as $i => $r)
                    @php $d = $r['qty'] - $r['prev']; @endphp
                    <div class="py-1.5 {{ $i ? 'border-t border-stone-100' : '' }}">
                        <div class="flex items-start justify-between gap-2 text-xs">
                            <span class="text-stone-700 min-w-0">
                                <span class="text-stone-400 tabular-nums">{{ $i + 1 }}.</span>
                                {{ $r['label'] }}
                                @if($r['unmapped'])<span class="text-[10px] text-amber-600" title="SKU marketplace belum dipetakan ke produk SKINKU">(blm dipetakan)</span>@endif
                            </span>
                            <span class="shrink-0 text-right tabular-nums">
                                <span class="font-semibold text-stone-800">{{ $ptNum($r['qty']) }}</span>
                                @if($r['prev'] === 0)
                                    <span class="block text-[10px] text-sky-600">baru</span>
                                @elseif($d !== 0)
                                    <span class="block text-[10px] {{ $d > 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $d > 0 ? '▲' : '▼' }} {{ $ptNum(abs($d)) }}</span>
                                @else
                                    <span class="block text-[10px] text-stone-400">= </span>
                                @endif
                            </span>
                        </div>
                        <div class="mt-1 h-1 rounded-full bg-stone-100">
                            <div class="h-1 rounded-full" style="width: {{ round($r['qty'] / $max * 100) }}%; background: {{ $color }}"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-stone-400 py-4 text-center">Belum ada penjualan.</p>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
