@extends('layouts.app')
@section('title', 'Jurnal Umum')
@section('heading', 'Jurnal Umum')

@section('content')
@include('accounting._nav')

@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp

<div class="flex justify-end gap-2 mb-4 flex-wrap">
    <a href="{{ route('accounting.accounts') }}" class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">⚙ Master COA</a>
    <a href="{{ route('accounting.templates') }}" class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">⚙ Template Transaksi</a>
    <a href="{{ route('accounting.journals.create') }}" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Input Jurnal</a>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Tanggal</th>
                <th class="text-left">Referensi</th>
                <th class="text-left">Deskripsi</th>
                <th class="text-left">Tipe</th>
                <th class="text-right">Total</th>
                <th class="text-left">Status</th>
                <th class="text-right pr-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($journals as $j)
                <tr class="border-t border-stone-100 hover:bg-stone-50 {{ $j->status==='void' ? 'opacity-50' : '' }}">
                    <td class="px-4 py-2.5 text-stone-600">{{ $j->date?->format('d M Y') }}</td>
                    <td class="text-stone-700">{{ $j->reference ?: '—' }}</td>
                    <td class="text-stone-500">{{ $j->description ?: '—' }}</td>
                    <td class="text-stone-400">{{ $j->type }}</td>
                    <td class="text-right font-semibold text-stone-800">{{ $rp($j->total) }}</td>
                    <td>
                        @php $c = ['posted'=>'bg-emerald-100 text-emerald-700','draft'=>'bg-amber-100 text-amber-700','void'=>'bg-stone-200 text-stone-500'][$j->status] ?? 'bg-stone-100'; @endphp
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $c }}">{{ $j->status }}</span>
                    </td>
                    <td class="text-right pr-4">
                        @if($j->status !== 'void')
                            <form method="POST" action="{{ route('accounting.journals.void', $j) }}" onsubmit="return confirm('Void jurnal ini? Tidak lagi dihitung ke saldo.')">
                                @csrf
                                <button class="text-rose-600 hover:text-rose-800 font-semibold">Void</button>
                            </form>
                        @else <span class="text-stone-400">—</span> @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Belum ada jurnal. Klik "+ Input Jurnal" untuk mulai.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $journals->links() }}</div>
@endsection
