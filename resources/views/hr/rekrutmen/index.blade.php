@extends('layouts.app')
@section('title', 'Rekrutmen')
@section('heading', 'Rekrutmen')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $input = 'mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg';
    $label = 'block text-xs font-semibold text-stone-600';
    $chip = fn (bool $aktif) => $aktif ? 'bg-red-700 text-white' : 'bg-white border border-stone-200 text-stone-600 hover:bg-stone-50';
    $saring = array_filter(['lowongan' => $filters['lowongan'], 'q' => $filters['q']]);
    $adaSaring = $filters['tahap'] || $saring;
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-stone-500">Lowongan, kandidat &amp; tahap seleksi. Link psikotes (tanpa login) dibuat dari halaman kandidat.<br>
            <span class="text-xs text-stone-400">Punya file CV? Klik <b>Baca CV dengan AI</b> — data kandidat terisi otomatis dari PDF / foto CV.</span></p>
        <div class="flex flex-wrap items-center gap-2">
            @include('hr.rekrutmen._baca-cv')
            <a href="{{ route('hr.rekrutmen.kandidat.create', array_filter(['lowongan' => $filters['lowongan']])) }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">+ Tambah kandidat</a>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <a href="{{ route('hr.rekrutmen.index', $saring) }}" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold {{ $chip(! $filters['tahap']) }}">Semua <span class="opacity-70">{{ $perTahap->sum() }}</span></a>
        @foreach(\App\Models\Candidate::STAGES as $key => $nama)
            <a href="{{ route('hr.rekrutmen.index', $saring + ['tahap' => $key]) }}" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold {{ $chip($filters['tahap'] === $key) }}">{{ $nama }} <span class="opacity-70">{{ $perTahap[$key] ?? 0 }}</span></a>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-3 gap-4 items-start">
        <div class="lg:col-span-2 space-y-3">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if($filters['tahap'])<input type="hidden" name="tahap" value="{{ $filters['tahap'] }}">@endif
                <select name="lowongan" class="px-3 py-2 text-sm border border-stone-300 rounded-lg" aria-label="Lowongan">
                    <option value="">Semua lowongan</option>
                    @foreach($openings as $o)<option value="{{ $o->id }}" @selected($filters['lowongan'] === $o->id)>{{ $o->title }}{{ $o->status === 'tutup' ? ' (tutup)' : '' }}</option>@endforeach
                </select>
                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Cari nama kandidat" class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-56">
                <button class="px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Saring</button>
            </form>

            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Kandidat</th>
                            <th class="px-4 py-2 text-left font-medium">Tahap</th>
                            <th class="px-4 py-2 text-left font-medium">Psikotes</th>
                            <th class="px-4 py-2 text-left font-medium">Masuk</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                    @forelse($candidates as $c)
                        @php $sesi = $c->psychotests->first(); @endphp
                        <tr class="hover:bg-stone-50 align-top">
                            <td class="px-4 py-3">
                                <a href="{{ route('hr.rekrutmen.kandidat.show', $c) }}" class="font-semibold text-stone-800 hover:text-red-700 hover:underline">{{ $c->name }}</a>
                                <div class="text-[11px] text-stone-400">{{ $c->opening?->title ?? 'Tanpa lowongan' }}{{ $c->source ? ' · '.$c->source : '' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                @include('hr.rekrutmen._tahap', ['stage' => $c->stage])
                                @if($c->stage === 'interview' && $c->interview_at)
                                    <div class="mt-1 text-[11px] text-stone-500">{{ $c->interview_at->translatedFormat('d M Y H:i') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($sesi)
                                    @include('hr.psikotes._status', ['sesi' => $sesi])
                                    @if($sesi->ringkasan() !== '')<div class="mt-1 text-[11px] text-stone-600">{{ $sesi->ringkasan() }}</div>@endif
                                @else
                                    <span class="text-xs text-stone-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-600 whitespace-nowrap">{{ $c->created_at?->translatedFormat('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-sm text-stone-400">
                            @if($adaSaring)
                                Tidak ada kandidat yang cocok dengan saringan.
                            @else
                                Belum ada kandidat. <a href="{{ route('hr.rekrutmen.kandidat.create') }}" class="text-red-700 hover:underline font-semibold">Tambah kandidat pertama</a>
                            @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-stone-100 flex items-center justify-between gap-2">
                <h3 class="text-sm font-bold text-stone-800">Lowongan</h3>
                <span class="text-xs text-stone-500">{{ $openings->where('status', 'buka')->count() }} buka</span>
            </div>
            <div class="divide-y divide-stone-100">
                @forelse($openings as $o)
                    <details class="px-4 py-3">
                        <summary class="flex items-start justify-between gap-2 cursor-pointer list-none">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-stone-800 truncate">{{ $o->title }}</p>
                                <p class="text-[11px] text-stone-400">{{ $o->department ?: 'Tanpa divisi' }} · {{ $o->candidates_count }} kandidat · ubah</p>
                            </div>
                            <span class="shrink-0 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $o->status === 'buka' ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">{{ \App\Models\JobOpening::STATUSES[$o->status] ?? $o->status }}</span>
                        </summary>
                        <form method="POST" action="{{ route('hr.rekrutmen.lowongan.update', $o) }}" class="mt-3 space-y-2">
                            @csrf @method('PUT')
                            <label class="{{ $label }}">Posisi *<input name="title" value="{{ $o->title }}" required maxlength="120" class="{{ $input }}"></label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="{{ $label }}">Divisi<input name="department" value="{{ $o->department }}" maxlength="60" class="{{ $input }}"></label>
                                <label class="{{ $label }}">Status
                                    <select name="status" class="{{ $input }}">
                                        @foreach(\App\Models\JobOpening::STATUSES as $v => $l)<option value="{{ $v }}" @selected($o->status === $v)>{{ $l }}</option>@endforeach
                                    </select>
                                </label>
                            </div>
                            <label class="{{ $label }}">Deskripsi / syarat<textarea name="description" rows="3" maxlength="3000" class="{{ $input }}">{{ $o->description }}</textarea></label>
                            <div class="flex items-center justify-between gap-2">
                                <button class="px-3 py-1.5 text-xs font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Simpan</button>
                                <a href="{{ route('hr.rekrutmen.index', ['lowongan' => $o->id]) }}" class="text-xs text-red-700 hover:underline">Lihat kandidatnya →</a>
                            </div>
                        </form>
                    </details>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-stone-400">Belum ada lowongan.</p>
                @endforelse
            </div>
            <details class="border-t border-stone-100 px-4 py-3" @if($openings->isEmpty()) open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-red-700">+ Lowongan baru</summary>
                <form method="POST" action="{{ route('hr.rekrutmen.lowongan.store') }}" class="mt-3 space-y-2">
                    @csrf
                    <label class="{{ $label }}">Posisi *<input name="title" value="{{ old('title') }}" required maxlength="120" placeholder="Admin Gudang" class="{{ $input }}"></label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="{{ $label }}">Divisi<input name="department" value="{{ old('department') }}" maxlength="60" placeholder="Gudang" class="{{ $input }}"></label>
                        <label class="{{ $label }}">Status
                            <select name="status" class="{{ $input }}">
                                @foreach(\App\Models\JobOpening::STATUSES as $v => $l)<option value="{{ $v }}" @selected(old('status', 'buka') === $v)>{{ $l }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    <label class="{{ $label }}">Deskripsi / syarat<textarea name="description" rows="3" maxlength="3000" class="{{ $input }}">{{ old('description') }}</textarea></label>
                    <button class="px-4 py-2 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">Buat lowongan</button>
                </form>
            </details>
        </div>
    </div>
</div>
@endsection
