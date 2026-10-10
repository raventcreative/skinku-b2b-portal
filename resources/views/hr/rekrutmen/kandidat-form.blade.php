@extends('layouts.app')
@section('title', 'Tambah kandidat')
@section('heading', 'Tambah kandidat')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $input = 'mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg';
    $label = 'block text-xs font-semibold text-stone-600';
@endphp
<div class="space-y-4 max-w-2xl">
    <a href="{{ route('hr.rekrutmen.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Rekrutmen</a>

    @unless($cvAi)
        <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-stone-800">Punya file CV kandidat?</p>
                <p class="text-xs text-stone-500">Biar AI yang mengisi form ini dari PDF atau foto CV — nama, kontak, lowongan yang cocok &amp; ringkasan pengalaman.</p>
            </div>
            @include('hr.rekrutmen._baca-cv')
        </div>
    @endunless

    <form method="POST" action="{{ route('hr.rekrutmen.kandidat.store') }}" class="space-y-4">
        @csrf
        @if($cvAi)
            <div class="rounded-2xl bg-indigo-50 border border-indigo-200 p-4 space-y-2">
                <p class="text-sm text-indigo-800"><b>✨ Diisi AI dari CV</b> “{{ $cvAi['nama'] }}” — periksa lagi sebelum disimpan, AI bisa keliru.</p>
                <label class="flex items-center gap-2 text-sm text-indigo-800">
                    <input type="checkbox" name="lampirkan_cv" value="1" checked class="w-4 h-4 accent-red-700">
                    Lampirkan file CV ini ke kandidat (disimpan privat)
                </label>
            </div>
        @endif

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <div class="grid sm:grid-cols-2 gap-3">
                @include('hr.rekrutmen._field-kandidat')
                <label class="{{ $label }} sm:col-span-2">Catatan HR<textarea name="notes" rows="{{ old('notes') ? 9 : 3 }}" maxlength="3000" placeholder="Pengalaman, pendidikan, kesan, gaji yang diharapkan…" class="{{ $input }}">{{ old('notes') }}</textarea></label>
            </div>
            <p class="mt-3 text-[11px] text-stone-400">Kandidat masuk di tahap Lamar. Link psikotes dibuat dari halaman kandidat setelah disimpan.</p>
        </div>

        <button class="px-5 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">Simpan kandidat</button>
    </form>

    @if($cvAi)
        {{-- Di luar form utama (form tak boleh bersarang). --}}
        <div class="flex flex-wrap items-center gap-2 text-xs text-stone-400">
            Salah file / CV lain? @include('hr.rekrutmen._baca-cv', ['teksTombol' => 'Baca CV lain'])
        </div>
    @endif
</div>
@endsection
