@extends('layouts.app')
@section('title', 'Affiliate & GMV')
@section('heading', 'Affiliate & GMV')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="space-y-4">

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('kol-affiliate.index', ['bulan' => $prevMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">←</a>
            <span class="font-semibold text-stone-700">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }}</span>
            <a href="{{ route('kol-affiliate.index', ['bulan' => $nextMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">→</a>
        </div>
        @if($canManage && \Illuminate\Support\Facades\Route::has('kol-affiliate.import'))
            <a href="{{ route('kol-affiliate.import') }}" class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">⬆ Import data affiliate</a>
        @endif
    </div>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">GMV bulan ini</p><p class="text-xl font-bold text-stone-800">{{ $rp($summary['gmv']) }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Komisi</p><p class="text-xl font-bold text-stone-800">{{ $rp($summary['commission']) }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Order</p><p class="text-xl font-bold text-stone-800">{{ number_format($summary['orders'], 0, ',', '.') }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Affiliate aktif</p><p class="text-xl font-bold text-stone-800">{{ $summary['affiliates'] }}</p></div>
    </div>

    {{-- Belum cocok --}}
    @if($unmatched->isNotEmpty())
        <div class="bg-amber-50 rounded-2xl border border-amber-200 p-4">
            <p class="text-sm font-semibold text-amber-800 mb-2">⚠ {{ $unmatched->count() }} username belum cocok — GMV tak masuk ranking. Yang GMV-nya besar = calon affiliate belum terdata.</p>
            <div class="space-y-1">
                @foreach($unmatched as $row)
                    <div class="flex flex-wrap items-center justify-between gap-2 bg-white rounded-lg px-3 py-2 text-sm">
                        <span class="text-stone-700 font-medium">{{ $row->raw_username }}</span>
                        <span class="text-stone-500 text-xs">{{ $rp($row->gmv) }} · {{ $row->orders }} order</span>
                        @if($canManage)
                            <form method="POST" action="{{ route('kol-affiliate.match') }}" class="flex items-center gap-1">
                                @csrf
                                <input type="hidden" name="raw_username" value="{{ $row->raw_username }}">
                                <input type="text" data-select-search="matchsel{{ $loop->index }}" placeholder="cari KOL…" class="w-28 px-2 py-1 border border-stone-300 rounded text-xs">
                                <select name="kol_id" id="matchsel{{ $loop->index }}" required class="px-2 py-1 border border-stone-300 rounded text-xs bg-white">
                                    <option value="">tautkan ke…</option>
                                    @foreach($kols as $k)
                                        <option value="{{ $k->id }}">{{ '@'.$k->tiktok_username }}</option>
                                    @endforeach
                                </select>
                                <button class="px-2 py-1 bg-stone-700 hover:bg-stone-800 text-white text-xs rounded">Tautkan</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Ranking --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-stone-500 text-xs">
                    <tr>
                        <th class="text-left px-4 py-2.5">#</th>
                        <th class="text-left px-4 py-2.5">Creator</th>
                        <th class="text-right px-4 py-2.5">GMV</th>
                        <th class="text-right px-4 py-2.5">Order</th>
                        <th class="text-right px-4 py-2.5">Komisi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($ranking as $i => $r)
                        <tr>
                            <td class="px-4 py-2.5 text-stone-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2.5"><a href="{{ route('kols.show', $r->kol_id) }}" class="text-indigo-600 hover:underline">{{ '@'.$r->kol->tiktok_username }}</a></td>
                            <td class="px-4 py-2.5 text-right font-medium text-stone-800">{{ $rp($r->gmv) }}</td>
                            <td class="px-4 py-2.5 text-right text-stone-600">{{ number_format((int) $r->orders, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right text-stone-600">{{ $rp($r->commission) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-stone-400 text-sm">Belum ada data affiliate bulan ini. Import dulu dari Affiliate Center.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
