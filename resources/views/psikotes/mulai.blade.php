@extends('psikotes._layout')
@section('title', 'Mulai psikotes')

@section('body')
@php
    $info = [
        'kepribadian' => ['40 pernyataan', '± 8 menit', 'Pilih seberapa setuju Anda dengan tiap pernyataan.'],
        'disc' => ['24 kelompok kata', '± 8 menit', 'Pilih kata yang paling dan paling tidak menggambarkan diri Anda.'],
        'logika' => ['20 soal', \App\Support\Psikotes\BankSoal::MENIT_LOGIKA.' menit (dibatasi)', 'Soal hitungan dan logika sederhana. Waktu mulai berjalan saat halaman tes dibuka.'],
    ];
@endphp
<div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
    <div>
        <h1 class="text-lg font-bold text-stone-800">Halo, {{ $namaDepan }} 👋</h1>
        <p class="text-sm text-stone-600 mt-1">Terima kasih sudah melamar di SKINKU. Sebelum lanjut, silakan kerjakan {{ count((array) $sesi->tests) }} tes singkat berikut. Tidak ada jawaban benar atau salah untuk tes kepribadian dan gaya kerja — jawablah apa adanya.</p>
    </div>
    <ol class="space-y-2">
        @foreach((array) $sesi->tests as $i => $tes)
            @php [$jumlah, $waktu, $ket] = $info[$tes]; @endphp
            <li class="flex items-start gap-3 rounded-xl border border-stone-200 px-4 py-3 {{ $sesi->tesSelesai($tes) ? 'bg-emerald-50' : '' }}">
                <span class="mt-0.5 text-sm font-bold {{ $sesi->tesSelesai($tes) ? 'text-emerald-700' : 'text-stone-400' }}">{{ $sesi->tesSelesai($tes) ? '✓' : $i + 1 }}</span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-stone-800">{{ \App\Services\PsikotesService::TES[$tes] }}</p>
                    <p class="text-xs text-stone-500">{{ $jumlah }} · {{ $waktu }}</p>
                    <p class="text-xs text-stone-500 mt-0.5">{{ $ket }}</p>
                </div>
            </li>
        @endforeach
    </ol>
    <ul class="text-xs text-stone-500 space-y-1 list-disc pl-5">
        <li>Kerjakan dalam satu waktu di tempat yang tenang, sebaiknya lewat HP atau laptop dengan internet stabil.</li>
        <li>Link ini hanya bisa dipakai sekali dan berlaku sampai {{ $sesi->expires_at->translatedFormat('d F Y H:i') }}.</li>
    </ul>
    <a href="{{ route('psikotes.publik.tes', [$sesi->token, $berikut]) }}" class="block w-full text-center px-5 py-3 text-sm font-semibold bg-red-700 text-white rounded-xl hover:bg-red-800">{{ $sesi->started_at ? 'Lanjutkan tes' : 'Mulai tes' }}</a>
</div>
@endsection
