@extends('layouts.app')
@section('title', 'Saldo Komisi')
@section('heading', 'Saldo Komisi')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $statusBadge = [
        'diajukan' => 'bg-amber-100 text-amber-700',
        'disetujui' => 'bg-sky-100 text-sky-700',
        'cair' => 'bg-emerald-100 text-emerald-700',
        'ditolak' => 'bg-rose-100 text-rose-700',
    ];
@endphp

<div class="max-w-4xl space-y-5">
    {{-- Saldo --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <div class="text-[11px] text-stone-500">Saldo Tersedia (bisa ditarik)</div>
            <div class="text-2xl font-bold text-emerald-700 mt-1">{{ $rp($available) }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <div class="text-[11px] text-stone-500">Total Saldo Komisi</div>
            <div class="text-2xl font-bold text-stone-800 mt-1">{{ $rp($balance) }}</div>
            @if($available < $balance)
                <div class="text-[11px] text-stone-400 mt-1">{{ $rp($balance - $available) }} sedang dalam proses penarikan</div>
            @endif
        </div>
    </div>

    {{-- Form ajukan penarikan --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-900 mb-1">Ajukan Penarikan</h3>

        @if(!$user->no_rekening)
            <p class="text-xs text-rose-600 mb-3">
                Rekening belum diisi. <a href="{{ route('account.rekening') }}" class="underline font-semibold">Isi rekening dulu</a> sebelum mengajukan penarikan.
            </p>
        @else
            <p class="text-xs text-stone-500 mb-3">
                Dana ditransfer ke <b>{{ $user->bank }} {{ $user->no_rekening }}</b> a.n. <b>{{ $user->atas_nama }}</b>.
                Mau ubah? Buka menu <a href="{{ route('account.rekening') }}" class="text-indigo-600 hover:underline">Rekening</a>.
            </p>
        @endif

        <form method="POST" action="{{ route('commissions.withdraw') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-[11px] font-semibold text-stone-500 mb-1">Jumlah penarikan</label>
                <input type="number" name="amount" min="100000" step="1000" value="{{ old('amount') }}"
                    placeholder="min. Rp 100.000"
                    class="w-48 px-3 py-2 border border-stone-300 rounded-lg text-sm">
                @error('amount')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <button type="submit" {{ $user->no_rekening ? '' : 'disabled' }}
                class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
                onclick="return confirm('Ajukan penarikan saldo komisi?')">
                Ajukan Penarikan
            </button>
        </form>
        <p class="text-[11px] text-stone-400 mt-2">Minimum penarikan Rp 100.000. Saldo langsung terkunci begitu diajukan, sampai HQ memproses.</p>
    </div>

    {{-- Riwayat Penarikan --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-100"><span class="text-sm font-bold text-stone-800">Riwayat Penarikan</span></div>
        @if(count($riwayatPenarikan))
            <div class="overflow-x-auto">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr>
                        <th class="text-left px-4 py-2">Tanggal</th>
                        <th class="text-left">Jumlah</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Catatan</th>
                        <th class="px-4"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($riwayatPenarikan as $w)
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2 text-stone-600">{{ ($w->requested_at ?? $w->created_at)->format('d M Y H:i') }}</td>
                            <td class="font-semibold text-stone-800">{{ $rp($w->amount) }}</td>
                            <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $statusBadge[$w->status] ?? 'bg-stone-100 text-stone-600' }}">{{ $w->status }}</span></td>
                            <td class="text-stone-500">{{ $w->note ?: '—' }}</td>
                            <td class="px-4 text-right">
                                @if($w->status === 'diajukan')
                                    <form method="POST" action="{{ route('commissions.withdraw-cancel', $w) }}"
                                        onsubmit="return confirm('Batalkan pengajuan penarikan ini?')">
                                        @csrf
                                        <button type="submit" class="text-[11px] text-rose-600 hover:underline">Batalkan</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="px-4 py-8 text-center text-xs text-stone-400">Belum ada pengajuan penarikan.</p>
        @endif
    </div>

    {{-- Riwayat Komisi --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-100"><span class="text-sm font-bold text-stone-800">Riwayat Komisi</span></div>
        @if(count($riwayatKomisi))
            <div class="overflow-x-auto">
            <table class="w-full text-xs whitespace-nowrap">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr>
                        <th class="text-left px-4 py-2">Tanggal</th>
                        <th class="text-left">Tipe</th>
                        <th class="text-left">Level</th>
                        <th class="text-left">Rate</th>
                        <th class="text-right px-4">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($riwayatKomisi as $c)
                        <tr class="border-t border-stone-100">
                            <td class="px-4 py-2 text-stone-600">{{ $c->created_at->format('d M Y') }}</td>
                            <td class="text-stone-600">{{ ['join' => 'Join', 'ro_cashback' => 'RO Cashback', 'override' => 'Override'][$c->type] ?? ucfirst(str_replace('_', ' ', $c->type)) }}</td>
                            <td class="text-stone-500">Lv{{ $c->level }}</td>
                            <td class="text-stone-500">{{ rtrim(rtrim(number_format((float) $c->rate, 2, ',', '.'), '0'), ',') }}%</td>
                            <td class="px-4 text-right font-semibold text-stone-800">{{ $rp($c->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="px-4 py-8 text-center text-xs text-stone-400">Belum ada komisi tercatat.</p>
        @endif
    </div>
</div>
@endsection
