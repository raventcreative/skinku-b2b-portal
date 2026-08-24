@extends('layouts.app')
@section('title', 'Rincian Pencairan Shopee')
@section('heading', 'Rincian Pencairan Shopee')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<a href="{{ route('shopee.settlements') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Pencairan</a>

{{-- Ringkasan pencairan: buyer_total dikurangi komisi/layanan/campaign/txn/ongkir/pajak → net escrow --}}
<div class="mt-3 bg-white rounded-2xl border border-stone-200 p-5 max-w-lg">
    <div class="flex items-center gap-2 mb-3">
        <div class="text-sm font-bold text-stone-800">Order <span class="font-mono">{{ $settlement->order_sn }}</span></div>
        @if($settlement->posting_status === \App\Models\ShopeeSettlement::POST_POSTED)
            <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">posted</span>
        @else
            <span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold">pending</span>
        @endif
    </div>
    <div class="text-[11px] text-stone-400 mb-3">Cair {{ $settlement->escrow_release_time?->format('d M Y H:i') ?? '—' }}</div>

    <dl class="text-xs divide-y divide-stone-100">
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">Omzet (Buyer Total)</dt>
            <dd class="font-mono text-stone-800">{{ $rp($settlement->buyer_total_amount) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">− Komisi</dt>
            <dd class="font-mono text-rose-600">{{ $rp($settlement->commission_fee) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">− Biaya Layanan</dt>
            <dd class="font-mono text-rose-600">{{ $rp($settlement->service_fee) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">− Biaya Campaign</dt>
            <dd class="font-mono text-rose-600">{{ $rp($settlement->campaign_fee) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">− Biaya Transaksi Penjual</dt>
            <dd class="font-mono text-rose-600">{{ $rp($settlement->seller_transaction_fee) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">− Ongkir Aktual</dt>
            <dd class="font-mono text-rose-600">{{ $rp($settlement->actual_shipping_fee) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">− Pajak Escrow</dt>
            <dd class="font-mono text-rose-600">{{ $rp($settlement->escrow_tax) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">− PPh (Withholding Tax)</dt>
            <dd class="font-mono text-rose-600">{{ $rp($settlement->withholding_tax) }}</dd>
        </div>
        <div class="flex justify-between py-1.5">
            <dt class="text-stone-500">Penyesuaian</dt>
            <dd class="font-mono {{ (float) $settlement->total_adjustment_amount < 0 ? 'text-rose-600' : 'text-stone-700' }}">{{ $rp($settlement->total_adjustment_amount) }}</dd>
        </div>
        <div class="flex justify-between py-2 mt-1 border-t-2 border-stone-200">
            <dt class="font-bold text-stone-800">Cair (Net Escrow)</dt>
            <dd class="font-mono font-bold {{ (float) $settlement->escrow_amount < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $rp($settlement->escrow_amount) }}</dd>
        </div>
    </dl>

    <p class="mt-3 text-[11px] text-stone-400">
        Ongkir dibayar buyer: {{ $rp($settlement->buyer_paid_shipping_fee) }} ·
        Rebate ongkir Shopee: {{ $rp($settlement->shopee_shipping_rebate) }}
    </p>
</div>

{{-- Data mentah (raw) — buat memastikan field asli Shopee, untuk audit bentuk field. --}}
<details class="mt-4">
    <summary class="cursor-pointer text-xs text-stone-500 hover:text-stone-800">Data mentah (raw) dari Shopee</summary>
    <pre class="mt-2 text-[10px] bg-stone-50 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap">{{ json_encode($settlement->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</details>
@endsection
