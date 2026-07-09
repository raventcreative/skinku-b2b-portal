@extends('layouts.app')
@section('title', 'Riwayat HPP · '.$product->name)
@section('heading', 'Riwayat HPP Produk')

@section('content')
<a href="{{ url()->previous() }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali</a>

<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3 flex flex-wrap justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-stone-900">{{ $product->name }}</h2>
        <p class="text-xs text-stone-500 mt-1">{{ $product->sku }} · Riwayat perubahan HPP (harga pokok) dari waktu ke waktu.</p>
    </div>
    <div class="text-right">
        <p class="text-[11px] uppercase tracking-wide text-stone-400 font-semibold">HPP Sekarang</p>
        <p class="text-2xl font-bold text-emerald-700">Rp {{ number_format($product->cogs, 0, ',', '.') }}</p>
    </div>
</div>

@if($entries->isEmpty())
    <div class="bg-white rounded-2xl border border-stone-200 p-10 text-center text-stone-400 text-sm mt-5">
        Belum ada riwayat. HPP berubah saat ada Produksi atau Stok Masuk untuk produk ini.
    </div>
@else
    <div class="bg-white rounded-2xl border border-stone-200 p-5 mt-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Grafik HPP</h3>
        <canvas id="hppChart" height="90"></canvas>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mt-5">
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Tanggal</th>
                    <th class="text-left">Sumber</th>
                    <th class="text-left">Ref</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">HPP batch / unit</th>
                    <th class="text-right">HPP produk</th>
                    <th class="text-right pr-4">Perubahan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entries as $e)
                    @php $delta = $e['after'] - $e['before']; @endphp
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2.5 text-stone-600">{{ $e['date']?->format('d M Y') }}</td>
                        <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $e['source']==='Produksi' ? 'bg-red-100 text-red-700' : 'bg-stone-200 text-stone-600' }}">{{ $e['source'] }}</span></td>
                        <td class="text-stone-500">{{ $e['ref'] }}</td>
                        <td class="text-right text-stone-500">{{ number_format($e['qty'], 0, ',', '.') }}</td>
                        <td class="text-right text-stone-600">Rp {{ number_format($e['batch'], 0, ',', '.') }}</td>
                        <td class="text-right text-stone-400">Rp {{ number_format($e['before'], 0, ',', '.') }} → <span class="font-semibold text-stone-800">Rp {{ number_format($e['after'], 0, ',', '.') }}</span></td>
                        <td class="text-right pr-4 font-semibold {{ $delta > 0 ? 'text-rose-600' : ($delta < 0 ? 'text-emerald-600' : 'text-stone-400') }}">
                            @if($delta > 0) ▲ Rp {{ number_format($delta, 0, ',', '.') }}
                            @elseif($delta < 0) ▼ Rp {{ number_format(abs($delta), 0, ',', '.') }}
                            @else — @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    <p class="text-[11px] text-stone-400 mt-3">HPP produk = rata-rata bergerak. "HPP batch" = harga pada transaksi itu. ▲ merah = HPP naik, ▼ hijau = HPP turun.</p>
@endif
@endsection

@push('scripts')
@if($entries->isNotEmpty())
<script>
    const HPP = {{ \Illuminate\Support\Js::from($chart) }};
    new Chart(document.getElementById('hppChart'), {
        type: 'line',
        data: {
            labels: HPP.map(r => r.label),
            datasets: [{ label: 'HPP / pcs', data: HPP.map(r => r.hpp), borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.1)', fill: true, tension: .3 }]
        },
        options: { plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: v => 'Rp ' + v.toLocaleString('id-ID') } } } }
    });
</script>
@endif
@endpush
