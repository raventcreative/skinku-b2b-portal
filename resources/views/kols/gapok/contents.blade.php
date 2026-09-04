@extends('layouts.app')
@section('title', 'Konten '.$kol->display_name)
@section('heading', 'Konten Kreator')

@section('content')
@php
    $rc = fn ($n) => $n >= 1_000_000 ? 'Rp '.round($n / 1_000_000, 1).' jt' : 'Rp '.number_format($n, 0, ',', '.');
    $profil = $kol->profileUrl() ?: 'https://www.tiktok.com/@'.$kol->handle();
@endphp

<div class="space-y-4">
    <div>
        <a href="{{ route('kol-gapok.index', ['bulan' => $month]) }}" class="text-sm text-stone-500 hover:text-stone-800">← Kembali ke Tim Gapok</a>
        <h2 class="text-lg font-bold text-stone-800 mt-1">
            <a href="{{ $profil }}" target="_blank" rel="noopener" class="hover:text-red-600 hover:underline">{{ '@'.$kol->handle() }} <span class="text-xs">↗</span></a>
        </h2>
        <p class="text-xs text-stone-500">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }} · {{ $videos->count() }} video · {{ $lives->count() }} LIVE</p>
    </div>

    {{-- Video --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-stone-50 flex items-center justify-between">
            <p class="text-sm font-semibold text-stone-700">🎬 Video ({{ $videos->count() }})</p>
            <p class="text-xs text-stone-400">urut GMV terbesar</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                        <th class="px-4 py-2">Judul</th>
                        <th class="px-4 py-2">Diposting</th>
                        <th class="px-4 py-2 text-right">Views</th>
                        <th class="px-4 py-2 text-right">GMV</th>
                        <th class="px-4 py-2 text-right">Order</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($videos as $v)
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-2 text-stone-800"><div class="max-w-md truncate">{{ $v->title ?: '(tanpa judul)' }}</div></td>
                            <td class="px-4 py-2 text-stone-500 text-xs whitespace-nowrap">{{ optional($v->occurred_at)->translatedFormat('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-2 text-right text-stone-700">{{ number_format($v->views, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right font-semibold text-stone-800 whitespace-nowrap">{{ $rc($v->gmv) }}</td>
                            <td class="px-4 py-2 text-right text-stone-700">{{ number_format($v->sku_orders, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right">
                                @if($v->url())<a href="{{ $v->url() }}" target="_blank" rel="noopener" class="text-red-600 hover:underline text-xs whitespace-nowrap">Tonton ↗</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-stone-400 text-sm">Belum ada data video bulan ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- LIVE --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-stone-50">
            <p class="text-sm font-semibold text-stone-700">🔴 Siaran LIVE ({{ $lives->count() }})</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                        <th class="px-4 py-2">Judul</th>
                        <th class="px-4 py-2">Mulai</th>
                        <th class="px-4 py-2 text-right">GMV</th>
                        <th class="px-4 py-2 text-right">Order</th>
                        <th class="px-4 py-2 text-right">Item terjual</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($lives as $l)
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-2 text-stone-800"><div class="max-w-md truncate">{{ $l->title ?: '(tanpa judul)' }}</div></td>
                            <td class="px-4 py-2 text-stone-500 text-xs whitespace-nowrap">{{ optional($l->occurred_at)->translatedFormat('d M Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-2 text-right font-semibold text-stone-800 whitespace-nowrap">{{ $rc($l->gmv) }}</td>
                            <td class="px-4 py-2 text-right text-stone-700">{{ number_format($l->sku_orders, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right text-stone-700">{{ number_format($l->items_sold, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-stone-400 text-sm">Belum ada data LIVE bulan ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-stone-400">Data konten dari TikTok Shop Analytics (akun affiliate), disegarkan tiap hari. Klik "Tonton ↗" buka video di TikTok.</p>
</div>
@endsection
