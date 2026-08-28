@extends('layouts.app')
@section('title', 'Transaksi Affiliate')
@section('heading', 'Transaksi Affiliate')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $rc = fn ($n) => $n >= 1_000_000 ? round($n / 1_000_000, 1).' jt' : number_format($n, 0, ',', '.');
    $isCancelled = fn ($s) => $s !== null && in_array(mb_strtolower($s), \App\Models\KolAffiliateTransaction::CANCELLED, true);
    $platLabel = ['tiktok' => 'TikTok', 'shopee' => 'Shopee'];
@endphp

<div class="space-y-4">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('kol-affiliate.index', ['bulan' => $month]) }}" class="text-xs text-stone-500 hover:text-stone-800">← Ranking</a>
            <span class="text-stone-300">·</span>
            <a href="{{ route('kol-affiliate.transactions', array_merge(request()->query(), ['bulan' => $prevMonth])) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">←</a>
            <span class="font-semibold text-stone-700">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }}</span>
            <a href="{{ route('kol-affiliate.transactions', array_merge(request()->query(), ['bulan' => $nextMonth])) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">→</a>
        </div>
    </div>

    {{-- Filter (GET, auto-submit) --}}
    <form method="GET" class="flex flex-wrap items-end gap-2 text-sm">
        <input type="hidden" name="bulan" value="{{ $month }}">
        <label class="block">
            <span class="text-xs font-semibold text-stone-600">Platform</span>
            <select name="platform" onchange="this.form.submit()" class="mt-1 px-3 py-2 border border-stone-300 rounded-lg bg-white">
                <option value="">Semua platform</option>
                <option value="tiktok" @selected($filters['platform'] === 'tiktok')>TikTok</option>
                <option value="shopee" @selected($filters['platform'] === 'shopee')>Shopee</option>
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-semibold text-stone-600">Creator</span>
            <select name="kol_id" onchange="this.form.submit()" class="mt-1 px-3 py-2 border border-stone-300 rounded-lg bg-white max-w-[200px]">
                <option value="">Semua creator</option>
                @foreach($kols as $k)
                    <option value="{{ $k->id }}" @selected($filters['kol_id'] === $k->id)>{{ $k->tiktok_username }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-semibold text-stone-600">Status</span>
            <select name="status" onchange="this.form.submit()" class="mt-1 px-3 py-2 border border-stone-300 rounded-lg bg-white">
                <option value="all" @selected($filters['status'] === 'all')>Semua status</option>
                <option value="valid" @selected($filters['status'] === 'valid')>Hanya valid (masuk GMV)</option>
                <option value="cancelled" @selected($filters['status'] === 'cancelled')>Hanya batal / retur</option>
            </select>
        </label>
        @if($filters['platform'] || $filters['kol_id'] || $filters['status'] !== 'all')
            <a href="{{ route('kol-affiliate.transactions', ['bulan' => $month]) }}" class="px-3 py-2 text-indigo-600 hover:underline">reset</a>
        @endif
    </form>

    {{-- Stat lingkup filter --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Transaksi</p>
            <p class="text-xl font-bold text-stone-800">{{ number_format($stats['total'], 0, ',', '.') }}</p>
            <p class="text-[10px] text-stone-400">{{ $stats['cancelled'] ? number_format($stats['cancelled'], 0, ',', '.').' batal / retur' : 'tak ada yang batal' }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">GMV valid</p>
            <p class="text-xl font-bold text-stone-800">{{ $rp($stats['gmv']) }}</p>
            <p class="text-[10px] text-stone-400">tanpa order batal / retur</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Komisi valid</p>
            <p class="text-xl font-bold text-stone-800">{{ $rp($stats['commission']) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Belum cocok</p>
            <p class="text-xl font-bold {{ $stats['unmatched'] ? 'text-amber-600' : 'text-stone-800' }}">{{ number_format($stats['unmatched'], 0, ',', '.') }}</p>
            <p class="text-[10px] text-stone-400">GMV tak masuk ranking</p>
        </div>
    </div>

    {{-- Tabel per-order --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr>
                        <th class="text-left px-3 py-2">Tanggal</th>
                        <th class="text-left px-3 py-2">Order ID</th>
                        <th class="text-left px-3 py-2">Platform</th>
                        <th class="text-left px-3 py-2">Creator</th>
                        <th class="text-left px-3 py-2">Produk</th>
                        <th class="text-right px-3 py-2">Qty</th>
                        <th class="text-right px-3 py-2">GMV</th>
                        <th class="text-right px-3 py-2">Komisi</th>
                        <th class="text-left px-3 py-2">Status</th>
                        <th class="text-left px-3 py-2">Tipe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($rows as $t)
                        @php $cancel = $isCancelled($t->status); @endphp
                        <tr class="{{ $cancel ? 'opacity-60' : '' }} hover:bg-stone-50">
                            <td class="px-3 py-2 text-stone-600">{{ $t->order_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-stone-500">{{ $t->order_id }}</td>
                            <td class="px-3 py-2 text-stone-600">{{ $platLabel[$t->platform] ?? ucfirst($t->platform) }}</td>
                            <td class="px-3 py-2">
                                @if($t->kol)
                                    <a href="{{ route('kols.show', $t->kol_id) }}" class="text-indigo-600 hover:underline">{{ '@'.$t->kol->tiktok_username }}</a>
                                @elseif($t->raw_username)
                                    <span class="text-amber-600" title="Belum cocok ke KOL">{{ $t->raw_username }} ⚠</span>
                                @else
                                    <span class="text-stone-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-stone-600 max-w-[220px] truncate" title="{{ $t->product }}">{{ $t->product ?: '—' }}</td>
                            <td class="px-3 py-2 text-right text-stone-600">{{ $t->qty !== null ? number_format($t->qty, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-2 text-right font-medium text-stone-800">{{ $rp($t->gmv) }}</td>
                            <td class="px-3 py-2 text-right text-stone-600">{{ $t->commission !== null ? $rp($t->commission) : '—' }}</td>
                            <td class="px-3 py-2">
                                @if($cancel)
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-rose-100 text-rose-700">{{ $t->status }}</span>
                                @elseif($t->status)
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">{{ $t->status }}</span>
                                @else
                                    <span class="text-stone-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-stone-500">{{ $t->content_type ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-10 text-center text-stone-400 text-sm">Tak ada transaksi untuk filter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rows->hasPages())
            <div class="px-4 py-3 border-t border-stone-100">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
@endsection
