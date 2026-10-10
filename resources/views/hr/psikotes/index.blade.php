@extends('layouts.app')
@section('title', 'Psikotes')
@section('heading', 'Psikotes')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $perStatus = $sesi->countBy(fn ($s) => $s->statusLabel());
    $kartu = [
        ['Belum dibuka', 'text-stone-800'],
        ['Sedang dikerjakan', 'text-amber-700'],
        ['Selesai', 'text-emerald-700'],
        ['Kedaluwarsa', 'text-rose-600'],
    ];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-stone-500">Semua link psikotes kandidat. Link dibuat dari halaman kandidat (Rekrutmen → pilih kandidat → Buat link psikotes).</p>
        <a href="{{ route('hr.psikotes.soal') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Lihat bank soal</a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach($kartu as [$status, $warna])
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">{{ $status }}</p>
                <p class="text-2xl font-bold {{ ($perStatus[$status] ?? 0) ? $warna : 'text-stone-800' }}">{{ $perStatus[$status] ?? 0 }}</p>
            </div>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">Kandidat</th>
                    <th class="px-4 py-2 text-left font-medium">Status</th>
                    <th class="px-4 py-2 text-left font-medium">Progres</th>
                    <th class="px-4 py-2 text-left font-medium">Hasil</th>
                    <th class="px-4 py-2 text-left font-medium">Dibuat</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse($sesi as $s)
                @php $selesaiTes = collect((array) $s->tests)->filter(fn ($t) => $s->tesSelesai($t))->count(); @endphp
                <tr class="hover:bg-stone-50 align-top">
                    <td class="px-4 py-3">
                        @if($s->candidate)
                            <a href="{{ route('hr.rekrutmen.kandidat.show', $s->candidate) }}" class="font-semibold text-stone-800 hover:text-red-700 hover:underline">{{ $s->candidate->name }}</a>
                            <div class="text-[11px] text-stone-400">{{ $s->candidate->opening?->title ?? 'Tanpa lowongan' }}</div>
                        @else
                            <span class="text-stone-400">Kandidat dihapus</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">@include('hr.psikotes._status', ['sesi' => $s])</td>
                    <td class="px-4 py-3 text-stone-600 whitespace-nowrap">{{ $selesaiTes }}/{{ count((array) $s->tests) }} tes</td>
                    <td class="px-4 py-3 text-stone-700">{{ $s->ringkasan() ?: '—' }}</td>
                    <td class="px-4 py-3 text-stone-600 whitespace-nowrap">
                        {{ $s->created_at->translatedFormat('d M Y') }}
                        <div class="text-[11px] text-stone-400">{{ $s->finished_at ? 'selesai '.$s->finished_at->translatedFormat('d M H:i') : 'berlaku s/d '.$s->expires_at->translatedFormat('d M H:i') }}</div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-stone-400">Belum ada psikotes. Buat link dari halaman kandidat di menu <a href="{{ route('hr.rekrutmen.index') }}" class="text-red-700 hover:underline font-semibold">Rekrutmen</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
    @if($sesi->count() >= 200)
        <p class="text-[11px] text-stone-400">Menampilkan 200 sesi terbaru.</p>
    @endif
</div>
@endsection
