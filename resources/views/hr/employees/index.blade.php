@extends('layouts.app')
@section('title', 'Karyawan')
@section('heading', 'Karyawan')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $kartu = [
        ['Karyawan aktif', $ringkas['aktif'], 'text-stone-800'],
        ['Masa percobaan perlu ditinjau', $ringkas['percobaan'], $ringkas['percobaan'] ? 'text-amber-700' : 'text-stone-800'],
        ['Kontrak berakhir ≤ 30 hari', $ringkas['kontrak'], $ringkas['kontrak'] ? 'text-amber-700' : 'text-stone-800'],
        ['Onboarding belum lengkap', $ringkas['onboarding'], $ringkas['onboarding'] ? 'text-rose-600' : 'text-stone-800'],
    ];
    $totalItem = count(\App\Models\Employee::ONBOARDING);
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-stone-500">Database karyawan SKINKU + checklist onboarding. Data identitas &amp; dokumen hanya untuk izin Kelola karyawan.</p>
        @if($bolehKelola)
            <a href="{{ route('hr.employees.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">+ Tambah karyawan</a>
        @endif
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach($kartu as [$label, $nilai, $warna])
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">{{ $label }}</p>
                <p class="text-2xl font-bold {{ $warna }}">{{ $nilai }}</p>
            </div>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-2">
        <select name="status" class="px-3 py-2 text-sm border border-stone-300 rounded-lg" aria-label="Status">
            @foreach(['aktif' => 'Aktif', 'keluar' => 'Keluar', 'semua' => 'Semua status'] as $v => $l)
                <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
            @endforeach
        </select>
        <select name="divisi" class="px-3 py-2 text-sm border border-stone-300 rounded-lg" aria-label="Divisi">
            <option value="">Semua divisi</option>
            @foreach($divisions as $d)<option value="{{ $d }}" @selected($filters['divisi'] === $d)>{{ $d }}</option>@endforeach
        </select>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Cari nama, jabatan, kode" class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-56">
        <button class="px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Saring</button>
    </form>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">Karyawan</th>
                    <th class="px-4 py-2 text-left font-medium">Divisi</th>
                    <th class="px-4 py-2 text-left font-medium">Status kerja</th>
                    <th class="px-4 py-2 text-left font-medium">Mulai</th>
                    <th class="px-4 py-2 text-left font-medium">Onboarding</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse($employees as $e)
                <tr class="hover:bg-stone-50 align-top">
                    <td class="px-4 py-3">
                        <a href="{{ route('hr.employees.show', $e) }}" class="font-semibold text-stone-800 hover:text-red-700 hover:underline">{{ $e->name }}</a>
                        <div class="text-[11px] text-stone-400"><span class="font-mono">{{ $e->kode }}</span>{{ $e->position ? ' · '.$e->position : '' }}</div>
                    </td>
                    <td class="px-4 py-3 text-stone-600">{{ $e->department ?: '—' }}</td>
                    <td class="px-4 py-3">
                        @if($e->aktif())
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-stone-100 text-stone-700">{{ \App\Models\Employee::TYPES[$e->employment_type] ?? $e->employment_type }}</span>
                        @else
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-rose-50 text-rose-700">Keluar {{ $e->resign_date?->translatedFormat('d M Y') }}</span>
                        @endif
                        @if($e->percobaanPerluDitinjau())
                            <div class="mt-1 text-[11px] text-amber-700">Percobaan berakhir {{ $e->probation_end->translatedFormat('d M Y') }}</div>
                        @endif
                        @if($e->kontrakSegeraBerakhir())
                            <div class="mt-1 text-[11px] text-amber-700">Kontrak berakhir {{ $e->contract_end->translatedFormat('d M Y') }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-stone-600 whitespace-nowrap">{{ $e->join_date?->translatedFormat('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @php $n = $e->onboardingSelesai(); @endphp
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-20 rounded-full bg-stone-100 overflow-hidden"><div class="h-full {{ $n === $totalItem ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ (int) round($n / $totalItem * 100) }}%"></div></div>
                            <span class="text-[11px] {{ $n === $totalItem ? 'text-emerald-700' : 'text-stone-500' }}">{{ $n }}/{{ $totalItem }}</span>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-stone-400">
                    {{ $filters['q'] || $filters['divisi'] ? 'Tidak ada karyawan yang cocok dengan saringan.' : 'Belum ada karyawan.' }}
                    @if($bolehKelola && ! $filters['q'] && ! $filters['divisi'])<a href="{{ route('hr.employees.create') }}" class="text-red-700 hover:underline font-semibold">Tambah karyawan pertama</a>@endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
