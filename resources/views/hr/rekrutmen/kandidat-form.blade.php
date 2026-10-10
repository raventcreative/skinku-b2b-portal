@extends('layouts.app')
@section('title', 'Tambah kandidat')
@section('heading', 'Tambah kandidat')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $input = 'mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg';
    $label = 'block text-xs font-semibold text-stone-600';
@endphp
<form method="POST" action="{{ route('hr.rekrutmen.kandidat.store') }}" class="space-y-4 max-w-2xl">
    @csrf
    <a href="{{ route('hr.rekrutmen.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Rekrutmen</a>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="grid sm:grid-cols-2 gap-3">
            @include('hr.rekrutmen._field-kandidat')
        </div>
        <p class="mt-3 text-[11px] text-stone-400">Kandidat masuk di tahap Lamar. CV diunggah dan link psikotes dibuat dari halaman kandidat setelah disimpan.</p>
    </div>

    <button class="px-5 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">Simpan kandidat</button>
</form>
@endsection
