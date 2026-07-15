@extends('layouts.app')
@section('title', 'Rincian Pencairan')
@section('heading', 'Rincian Pencairan TikTok')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $ket = fn ($type) => \App\Services\TikTokSettlementService::translateType($type);
    $pick = fn ($a, $keys) => collect($keys)->map(fn ($k) => $a[$k] ?? null)->first(fn ($v) => $v !== null && $v !== '');
@endphp

<a href="{{ route('tiktok.settlements') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Dana Cair</a>

{{-- Ringkasan pencairan --}}
<div class="mt-3 bg-white rounded-2xl border border-stone-200 p-5">
    <div class="text-sm font-bold text-stone-800 mb-2">Statement <span class="font-mono">{{ $settlement->tiktok_statement_id }}</span></div>
    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
        <div><dt class="text-stone-400">Tanggal</dt><dd class="font-semibold text-stone-700">{{ $settlement->statement_time?->format('d M Y') ?? '—' }}</dd></div>
        <div><dt class="text-stone-400">Omzet (bruto)</dt><dd class="font-mono text-stone-700">{{ $rp($settlement->revenue_amount) }}</dd></div>
        <div><dt class="text-stone-400">Fee</dt><dd class="font-mono text-rose-600">−{{ $rp($settlement->fee_amount) }}</dd></div>
        <div><dt class="text-stone-400">Cair (net)</dt><dd class="font-mono font-bold {{ (float) $settlement->settlement_amount < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $rp($settlement->settlement_amount) }}</dd></div>
    </dl>
</div>

@if($error)
    <div class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
        Gagal menarik rincian: {{ $error }}
    </div>
@elseif(is_array($transactions) && count($transactions))
    <div class="mt-4 bg-white rounded-2xl border border-stone-200 overflow-x-auto">
        <div class="px-4 py-2.5 text-[11px] text-stone-500 border-b border-stone-100">
            {{ count($transactions) }} transaksi dalam pencairan ini.
            <span class="text-stone-400">(Struktur respons: [{{ implode(', ', $rawKeys) }}])</span>
        </div>
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-2">Keterangan</th>
                    <th class="text-left">Jenis (asli)</th>
                    <th class="text-left">Order Terkait</th>
                    <th class="text-right pr-4">Nilai</th>
                    <th class="text-left px-4">Data mentah</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transactions as $t)
                    @php
                        $type = $pick($t, ['type', 'transaction_type', 'adjustment_type', 'sub_type']);
                        $order = $pick($t, ['order_id', 'associated_order_id', 'adjustment_order_id']);
                        $amount = $pick($t, ['settlement_amount', 'amount', 'total_settlement_amount', 'adjustment_amount']);
                    @endphp
                    <tr class="border-t border-stone-100 align-top">
                        <td class="px-4 py-2 font-semibold text-stone-800">{{ $ket($type) }}</td>
                        <td class="text-stone-500 font-mono">{{ $type ?? '—' }}</td>
                        <td class="text-stone-500 font-mono">{{ $order ?? '—' }}</td>
                        <td class="text-right pr-4 font-mono {{ (float) $amount < 0 ? 'text-rose-600' : 'text-stone-700' }}">{{ $amount !== null ? $rp($amount) : '—' }}</td>
                        <td class="px-4">
                            <details><summary class="cursor-pointer text-indigo-600 text-[11px]">lihat</summary>
                                <pre class="mt-1 text-[10px] bg-stone-50 rounded p-2 max-w-md overflow-x-auto whitespace-pre-wrap">{{ json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <div class="mt-4 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm">
        Tidak ada rincian transaksi dari TikTok untuk pencairan ini.
        <span class="text-amber-600">(Struktur respons: [{{ implode(', ', $rawKeys) }}])</span>
    </div>
@endif

{{-- Data mentah statement (dari sync) — buat memastikan field asli --}}
<details class="mt-4">
    <summary class="cursor-pointer text-xs text-stone-500 hover:text-stone-800">Data mentah statement (dari penyimpanan)</summary>
    <pre class="mt-2 text-[10px] bg-stone-50 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap">{{ json_encode($settlement->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</details>
@endsection
