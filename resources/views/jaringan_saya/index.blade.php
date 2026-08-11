@extends('layouts.app')
@section('title', 'Jaringan Saya')
@section('heading', 'Jaringan Saya')

@section('content')
<div class="space-y-4">
    {{-- Ringkasan jaringan --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="bg-white rounded-xl border border-stone-200 p-3">
            <div class="text-[11px] text-stone-500">Total anggota jaringan</div>
            <div class="text-xl font-bold text-stone-800">{{ $totalMembers }}</div>
        </div>
        <div class="bg-white rounded-xl border border-stone-200 p-3">
            <div class="text-[11px] text-stone-500">Aktif (≤30 hari)</div>
            <div class="text-xl font-bold text-emerald-600">{{ $activeCount }}</div>
        </div>
        <div class="bg-white rounded-xl border border-stone-200 p-3">
            <div class="text-[11px] text-stone-500">Omzet jaringan · {{ $periode }}</div>
            <div class="text-xl font-bold text-stone-800">Rp {{ number_format($networkOmzet, 0, ',', '.') }}</div>
        </div>
    </div>

    <p class="text-xs text-stone-500">💡 Ringkasan performa jaringan bawahanmu (read-only). Nama customer downline sengaja tidak ditampilkan demi privasi.</p>

    {{-- Pohon jaringan --}}
    <div class="bg-white rounded-xl border border-stone-200 divide-y divide-stone-100">
        @forelse($tree as $node)
            @include('jaringan_saya._node', ['node' => $node, 'depth' => 0, 'trenLabels' => $trenLabels])
        @empty
            <div class="p-8 text-center text-sm text-stone-400">Kamu belum punya jaringan. Anggota yang ditempatkan sebagai downline-mu akan muncul di sini.</div>
        @endforelse
    </div>
</div>
@endsection
