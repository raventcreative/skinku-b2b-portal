@extends('psikotes._layout')
@section('title', 'Link psikotes')

@section('body')
<div class="bg-white rounded-2xl border border-stone-200 p-6 text-center space-y-2">
    @if($selesai)
        <h1 class="text-lg font-bold text-stone-800">Psikotes sudah selesai</h1>
        <p class="text-sm text-stone-600">Terima kasih, {{ $namaDepan }}. Jawaban Anda sudah kami terima — link ini tidak bisa dipakai lagi.</p>
    @else
        <h1 class="text-lg font-bold text-stone-800">Link psikotes sudah tidak berlaku</h1>
        <p class="text-sm text-stone-600">Masa berlaku link ini sudah habis. Silakan hubungi tim HR SKINKU untuk meminta link baru.</p>
    @endif
</div>
@endsection
