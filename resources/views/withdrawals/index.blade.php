@extends('layouts.app')
@section('title', 'Penarikan Komisi')
@section('heading', 'Penarikan Komisi Mitra')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $statusBadge = [
        'diajukan' => 'bg-amber-100 text-amber-700',
        'disetujui' => 'bg-sky-100 text-sky-700',
        'cair' => 'bg-emerald-100 text-emerald-700',
        'ditolak' => 'bg-rose-100 text-rose-700',
    ];
    $cur = request('status');
@endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <form method="GET" class="flex items-center gap-1">
        <select name="status" onchange="this.form.submit()" class="px-2 py-1.5 text-xs border border-stone-300 rounded-lg">
            <option value="">Semua status</option>
            @foreach(['diajukan', 'disetujui', 'cair', 'ditolak'] as $s)
                <option value="{{ $s }}" @selected($cur === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-2">Tanggal</th>
                <th class="text-left">Mitra</th>
                <th class="text-right">Jumlah</th>
                <th class="text-left px-3">Rekening</th>
                <th class="text-left">Status</th>
                <th class="text-right px-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($withdrawals as $w)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5 text-stone-600">{{ ($w->requested_at ?? $w->created_at)->format('d M Y H:i') }}</td>
                    <td class="text-stone-700 font-semibold">{{ $w->mitra->fullname ?? $w->mitra->company_name ?? ('#'.$w->user_id) }}</td>
                    <td class="text-right text-stone-800 font-semibold">{{ $rp($w->amount) }}</td>
                    <td class="px-3 text-stone-600">
                        {{ $w->bank ?: '—' }} {{ $w->no_rekening }}
                        <div class="text-stone-400">a.n. {{ $w->atas_nama ?: '—' }}</div>
                    </td>
                    <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $statusBadge[$w->status] ?? 'bg-stone-100 text-stone-600' }}">{{ $w->status }}</span></td>
                    <td class="text-right px-4">
                        @if($w->status === 'diajukan')
                            <form method="POST" action="{{ route('withdrawals.process', $w) }}" class="inline"
                                onsubmit="return confirm('Setujui penarikan {{ $rp($w->amount) }} untuk {{ $w->mitra->fullname ?? $w->user_id }}?')">
                                @csrf
                                <input type="hidden" name="status" value="disetujui">
                                <button class="text-sky-600 hover:text-sky-800 font-semibold">Setujui</button>
                            </form>
                        @endif
                        @if($w->status === 'disetujui')
                            <form method="POST" action="{{ route('withdrawals.process', $w) }}" class="inline"
                                onsubmit="return confirm('Tandai CAIR — pastikan dana sudah ditransfer ke {{ $w->mitra->fullname ?? $w->user_id }}?')">
                                @csrf
                                <input type="hidden" name="status" value="cair">
                                <button class="text-emerald-600 hover:text-emerald-800 font-semibold">Cair</button>
                            </form>
                        @endif
                        @if(in_array($w->status, ['diajukan', 'disetujui']))
                            <form method="POST" action="{{ route('withdrawals.process', $w) }}" class="inline ml-2"
                                onsubmit="return confirm('Tolak pengajuan ini? Saldo mitra akan dilepas kembali.')">
                                @csrf
                                <input type="hidden" name="status" value="ditolak">
                                <button class="text-rose-600 hover:text-rose-800 font-semibold">Tolak</button>
                            </form>
                        @endif
                        @if(in_array($w->status, ['cair', 'ditolak']))
                            <span class="text-stone-300">selesai</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-stone-400">Belum ada pengajuan penarikan.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($withdrawals->hasPages())
        <div class="px-4 py-3 border-t border-stone-100">{{ $withdrawals->links() }}</div>
    @endif
</div>
@endsection
