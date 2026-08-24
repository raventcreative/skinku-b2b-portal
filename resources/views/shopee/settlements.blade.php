@extends('layouts.app')
@section('title', 'Pencairan Shopee')
@section('heading', 'Pencairan Shopee — Dana Cair (Escrow)')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<a href="{{ route('shopee.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

<div class="mt-3 flex flex-wrap items-center gap-3">
    <form method="POST" action="{{ route('shopee.settlements.sync') }}">@csrf
        <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Tarik Pencairan</button>
    </form>
    <span class="text-[11px] text-stone-500">Dana cair (escrow) per-order dari Shopee — omzet dikurangi komisi, layanan, campaign, biaya transaksi, ongkir &amp; pajak.</span>
</div>

<div class="mt-4 bg-white rounded-2xl border border-stone-200 overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-2.5">Order SN</th>
                <th class="text-left">Tanggal Cair</th>
                <th class="text-right">Omzet (Buyer)</th>
                <th class="text-right">Potongan</th>
                <th class="text-right">Ongkir</th>
                <th class="text-right">Cair (Net)</th>
                <th class="text-left">Status</th>
                <th class="text-left px-4">Rincian</th>
            </tr>
        </thead>
        <tbody>
            @forelse($settlements as $s)
                <tr class="border-t border-stone-100 hover:bg-stone-50/50">
                    <td class="px-4 py-2 font-mono text-stone-700">{{ $s->order_sn }}</td>
                    <td class="text-stone-500">{{ $s->escrow_release_time?->format('d M Y') ?? '—' }}</td>
                    <td class="text-right font-mono text-stone-700">{{ $rp($s->buyer_total_amount) }}</td>
                    <td class="text-right font-mono text-rose-600">{{ $s->feeTotal() ? '−'.$rp($s->feeTotal()) : '·' }}</td>
                    <td class="text-right font-mono text-stone-500">{{ (float) $s->actual_shipping_fee ? $rp($s->actual_shipping_fee) : '·' }}</td>
                    <td class="text-right font-mono font-bold {{ (float) $s->escrow_amount < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $rp($s->escrow_amount) }}</td>
                    <td>
                        @if($s->posting_status === \App\Models\ShopeeSettlement::POST_POSTED)
                            <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">posted</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold">pending</span>
                        @endif
                    </td>
                    <td class="px-4">
                        <a href="{{ route('shopee.settlements.detail', $s) }}" class="text-indigo-700 hover:text-indigo-900 hover:underline text-[11px]">Detail →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-10 text-center text-stone-400">Belum ada data pencairan. Klik "Tarik Pencairan".</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $settlements->links() }}</div>
@endsection
