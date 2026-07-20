@extends('layouts.app')
@section('title', 'Deal KOL')
@section('heading', 'Kerjasama / Deal KOL')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $canFinance = $u->canDo('kol.deal.finance');
@endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Database KOL</a>
    <a href="{{ route('kol-deals.create') }}" class="ml-auto px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Deal Baru</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr><th class="text-left px-4 py-2">Kode</th><th class="text-left">KOL</th><th class="text-left">Jenis</th>
                <th class="text-right">Ratecard</th><th class="text-left px-3">Periode</th>
                <th class="text-left">PIC</th><th class="text-left">Status</th>
                @if($canFinance)<th class="text-right">Total Biaya</th><th class="text-left px-3">Bayar</th>@endif
                <th class="text-right px-4">Aksi</th></tr>
        </thead>
        <tbody>
            @forelse($deals as $d)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5 font-semibold text-stone-700">{{ $d->kode }}</td>
                    <td><a href="{{ route('kols.show', $d->kol_id) }}" class="text-red-700 hover:underline">{{ '@'.($d->kol->tiktok_username ?? '?') }}</a></td>
                    <td class="uppercase text-stone-600">{{ $d->jenis }}{{ $d->jenis === 'vt' ? ' ×'.$d->jumlah_slot : '' }}</td>
                    <td class="text-right text-stone-700">{{ $rp($d->ratecard_deal) }}</td>
                    <td class="px-3 text-stone-600">{{ $d->periode_mulai?->format('d M') }} – {{ $d->periode_selesai?->format('d M Y') ?: '—' }}</td>
                    <td class="text-stone-600">{{ $d->pic->fullname ?? '—' }}</td>
                    <td class="text-stone-600">{{ $d->status }}</td>
                    @if($canFinance)
                        <td class="text-right text-stone-700">{{ $rp($d->total_biaya) }}</td>
                        <td class="px-3 text-stone-600">{{ $d->status_bayar }}</td>
                    @endif
                    <td class="text-right px-4">
                        <a href="{{ route('kol-deals.edit', $d) }}" class="text-stone-500 hover:text-stone-900 font-semibold">Edit</a>
                        <form method="POST" action="{{ route('kol-deals.destroy', $d) }}" class="inline"
                            onsubmit="return confirm('Hapus deal {{ $d->kode }}? (soft delete, tercatat di Audit Log)')">
                            @csrf @method('DELETE')
                            <button class="ml-2 text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canFinance ? 10 : 8 }}" class="px-4 py-8 text-center text-stone-400">Belum ada deal.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($deals->hasPages())
        <div class="px-4 py-3 border-t border-stone-100">{{ $deals->links() }}</div>
    @endif
</div>
@endsection
