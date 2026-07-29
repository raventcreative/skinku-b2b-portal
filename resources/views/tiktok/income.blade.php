@extends('layouts.app')
@section('title', 'Laporan Income TikTok')
@section('heading', 'Laporan Income TikTok')

@section('content')
<div class="max-w-6xl">
    <a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Integrasi TikTok</a>

    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 my-4">
        <p class="text-sm font-bold text-indigo-900">Gabung pesanan + income → tahu item per order 🧾</p>
        <p class="text-xs text-indigo-700 mt-1">Upload 2 file dari TikTok Seller: <b>"Semua pesanan" (.csv)</b> yang ada SKU-nya, dan <b>"income" (.xlsx)</b> yang ada settlement-nya. SKINKU gabung by <b>Order ID</b> lalu kelompokkan qty per <b>item-besar</b> (kategori produk, bundle "1 SKU = N pcs" ikut kehitung). <span class="text-indigo-500">Ini laporan saja — stok tidak dipotong dari sini.</span></p>
    </div>

    {{-- Form upload --}}
    <form method="POST" action="{{ route('tiktok.income.process') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-stone-200 p-5 grid sm:grid-cols-[1fr_1fr_auto] gap-3 items-end">
        @csrf
        <label class="text-[11px] font-semibold text-stone-500">1) Semua Pesanan (.csv)
            <input type="file" name="orders" accept=".csv" required class="mt-1 block w-full text-xs">
        </label>
        <label class="text-[11px] font-semibold text-stone-500">2) Income TikTok (.xlsx)
            <input type="file" name="income" accept=".xlsx" required class="mt-1 block w-full text-xs">
        </label>
        <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Proses</button>
    </form>

    @if($report)
        @php($s = $report['summary'])
        {{-- Ringkasan validasi --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mt-5">
            @foreach([
                ['Baris CSV', $s['csv_read']], ['Order unik', $s['unique_orders']], ['Order income', $s['income_orders']],
                ['Cocok', $s['matched']], ['Tak cocok', $s['unmatched']], ['SKU tak dikenal', $s['unmapped_count']],
            ] as [$label, $val])
                <div class="bg-white rounded-xl border border-stone-200 p-3">
                    <p class="text-[10px] uppercase tracking-wide text-stone-400">{{ $label }}</p>
                    <p class="text-xl font-bold text-stone-800">{{ number_format($val, 0, ',', '.') }}</p>
                </div>
            @endforeach
        </div>

        @if(count($report['unmapped']))
            <div class="mt-4 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
                ⚠️ <b>{{ count($report['unmapped']) }} SKU belum dipetakan</b> ke produk (qty-nya belum masuk kolom item):
                <span class="font-mono">{{ implode(', ', array_slice($report['unmapped'], 0, 25)) }}{{ count($report['unmapped']) > 25 ? ' …' : '' }}</span>.
                <a href="{{ route('tiktok.orders') }}" class="underline font-semibold">Lengkapi di Peta SKU →</a>
            </div>
        @endif

        <div class="flex items-center gap-2 mt-4">
            <a href="{{ route('tiktok.income.download') }}" class="px-4 py-2 text-sm bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-semibold">⬇ Unduh Excel</a>
            <form method="POST" action="{{ route('tiktok.income.reset') }}" onsubmit="return confirm('Bersihkan laporan ini?')">
                @csrf
                <button class="px-3 py-2 text-sm text-stone-500 hover:text-rose-600">Mulai baru</button>
            </form>
            <span class="text-[11px] text-stone-400 ml-auto">Item-besar = kategori produk (kosong = kategori tak ada di order itu).</span>
        </div>

        {{-- Tabel per order --}}
        <div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto mt-3">
            <table class="text-sm whitespace-nowrap w-full">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr>
                        <th class="text-left px-3 py-2">Order ID</th>
                        <th class="text-left px-3">Waktu</th>
                        <th class="text-right px-3">Pendapatan</th>
                        <th class="text-right px-3">Biaya</th>
                        <th class="text-right px-3">Settlement</th>
                        @foreach($report['columns'] as $col)
                            <th class="text-right px-3">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['rows'] as $r)
                        <tr class="border-t border-stone-100 {{ $r['matched'] ? '' : 'bg-rose-50/40' }}">
                            <td class="px-3 py-1.5 font-mono text-xs text-stone-700">{{ $r['order_id'] }}
                                @unless($r['matched'])<span class="text-[9px] text-rose-500">(tak ketemu)</span>@endunless
                            </td>
                            <td class="px-3 text-stone-500 text-xs">{{ $r['time'] }}</td>
                            <td class="px-3 text-right text-stone-600">{{ number_format($r['revenue'], 0, ',', '.') }}</td>
                            <td class="px-3 text-right text-stone-500">{{ number_format($r['fee'], 0, ',', '.') }}</td>
                            <td class="px-3 text-right font-semibold text-emerald-700">{{ number_format($r['settlement'], 0, ',', '.') }}</td>
                            @foreach($report['columns'] as $col)
                                <td class="px-3 text-right {{ ($r['cat_qty'][$col] ?? 0) ? 'font-semibold text-stone-800' : 'text-stone-300' }}">{{ ($r['cat_qty'][$col] ?? 0) ?: '·' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-stone-400">Tidak ada baris.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
