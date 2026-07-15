@extends('layouts.app')
@section('title', 'Dana Cair TikTok')
@section('heading', 'Dana Cair / Pencairan TikTok')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="flex flex-wrap items-center gap-2 mb-4">
    <a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>
    <form method="POST" action="{{ route('tiktok.settlements.sync') }}" class="ml-auto">@csrf
        <button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">↻ Tarik Pencairan</button>
    </form>
</div>

<div class="mb-4 px-4 py-2.5 rounded-xl bg-indigo-50 border border-indigo-200 text-indigo-800 text-[11px] leading-relaxed">
    ℹ️ Tahap <b>M3a</b> — baru menarik & menampilkan data pencairan (read-only). Belum masuk jurnal.
    Setelah data asli terlihat benar, lanjut <b>M3b</b>: preview jurnal (Bank + Beban Fee = Pendapatan) + HPP, lalu posting.
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-2.5">Statement ID</th>
                <th class="text-left">Tanggal</th>
                <th class="text-left">Jenis</th>
                <th class="text-right">Omzet (Bruto)</th>
                <th class="text-right">Fee</th>
                <th class="text-right">Penyesuaian</th>
                <th class="text-right">Cair (Net)</th>
                <th class="text-left px-4">Rincian</th>
            </tr>
        </thead>
        <tbody>
            @forelse($settlements as $s)
                @php
                    $isSale = (float) $s->revenue_amount > 0;
                    $net = (float) $s->settlement_amount;
                @endphp
                <tr class="border-t border-stone-100 hover:bg-stone-50/50">
                    <td class="px-4 py-2 font-mono text-stone-700">{{ $s->tiktok_statement_id }}</td>
                    <td class="text-stone-500">{{ $s->statement_time?->format('d M Y') ?? '—' }}</td>
                    <td>
                        @if($isSale)
                            <span class="px-2 py-0.5 rounded-full text-[10px] bg-emerald-100 text-emerald-700">Penjualan</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-100 text-amber-700">Potongan / non-jualan</span>
                        @endif
                    </td>
                    <td class="text-right font-mono text-stone-700">{{ $isSale ? $rp($s->revenue_amount) : '·' }}</td>
                    <td class="text-right font-mono text-rose-600">{{ (float) $s->fee_amount ? '−'.$rp($s->fee_amount) : '·' }}</td>
                    <td class="text-right font-mono text-stone-500">{{ (float) $s->adjustment_amount ? $rp($s->adjustment_amount) : '·' }}</td>
                    <td class="text-right font-mono font-bold {{ $net < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $rp($net) }}</td>
                    <td class="px-4">
                        <a href="{{ route('tiktok.settlements.detail', $s) }}" class="text-indigo-700 hover:text-indigo-900 hover:underline text-[11px]">Lihat rincian →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-10 text-center text-stone-400">Belum ada data pencairan. Klik <b>Tarik Pencairan</b> untuk menariknya dari TikTok.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $settlements->links() }}</div>
@endsection
