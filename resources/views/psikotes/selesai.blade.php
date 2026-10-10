@extends('psikotes._layout')
@section('title', 'Psikotes selesai')

@section('body')
<div class="bg-white rounded-2xl border border-stone-200 p-6 text-center space-y-2">
    <p class="text-3xl">🎉</p>
    <h1 class="text-lg font-bold text-stone-800">Terima kasih, {{ $namaDepan }}!</h1>
    <p class="text-sm text-stone-600">Semua tes sudah selesai dan jawaban Anda sudah kami terima. Tim HR SKINKU akan menghubungi Anda untuk tahap berikutnya.</p>
    <p class="text-xs text-stone-400">Halaman ini boleh ditutup.</p>
</div>
@endsection
