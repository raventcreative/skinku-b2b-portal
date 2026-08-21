@extends('layouts.app')
@section('title', 'Rekrutan Saya')
@section('heading', 'Rekrutan Saya')

@section('content')
@php $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp
<div class="max-w-4xl">
    <p class="text-sm text-stone-500 mb-4">Mitra yang kamu rekrut + income dari mereka (bonus join saat gabung + RO cashback tiap Grand-mu restock ke HQ). Tarik dana lewat <a href="{{ route('commissions.index') }}" class="text-indigo-600 hover:underline">Saldo Komisi</a>.</p>

    <div class="grid sm:grid-cols-3 gap-3 mb-5">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-[11px] text-stone-500 uppercase tracking-wide">Bonus Join</p>
            <p class="text-xl font-bold text-stone-800 mt-1">{{ $rp($totalJoin) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-[11px] text-stone-500 uppercase tracking-wide">RO Cashback</p>
            <p class="text-xl font-bold text-stone-800 mt-1">{{ $rp($totalRo) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-emerald-200 bg-emerald-50/40 p-4">
            <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Saldo Tersedia</p>
            <p class="text-xl font-bold text-emerald-700 mt-1">{{ $rp($available) }}</p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Daftar Rekrutan ({{ $recruits->count() }})</div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr>
                        <th class="text-left px-4 py-2">No</th>
                        <th class="text-left px-4 py-2">Nama</th>
                        <th class="text-left px-4 py-2">Role</th>
                        <th class="text-left px-4 py-2">Member ID</th>
                        <th class="text-left px-4 py-2">Gabung</th>
                        <th class="text-right px-4 py-2">Income dari dia</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recruits as $i => $r)
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2 text-stone-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-semibold text-stone-800">{{ $r->fullname ?? $r->name }}</td>
                            <td class="px-4 py-2 text-stone-600">{{ \App\Support\PartnerHierarchy::label($r->role) }}</td>
                            <td class="px-4 py-2 font-mono text-stone-500">{{ $r->member_id ?: '—' }}</td>
                            <td class="px-4 py-2 text-stone-500">{{ $r->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-2 text-right font-mono text-emerald-700">{{ $rp($earnByRecruit[$r->id] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-stone-400">Belum ada rekrutan. Ajak mitra baru gabung untuk mulai dapat bonus.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
