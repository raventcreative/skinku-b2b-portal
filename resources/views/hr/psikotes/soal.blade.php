@extends('layouts.app')
@section('title', 'Bank soal psikotes')
@section('heading', 'Bank soal psikotes')

@section('content')
@php
    use App\Services\PsikotesService;
    use App\Support\Psikotes\BankSoal;
@endphp
<div class="space-y-4 max-w-4xl">
    <a href="{{ route('hr.psikotes.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Psikotes</a>

    <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
        Bank soal disusun sendiri untuk SKINKU — bukan kuesioner resmi berlisensi dan belum divalidasi psikometri. Pakai hasilnya sebagai bahan pendukung wawancara, bukan satu-satunya dasar keputusan.
    </div>

    <details class="bg-white rounded-2xl border border-stone-200" open>
        <summary class="px-5 py-3 cursor-pointer text-sm font-bold text-stone-800">1. {{ PsikotesService::TES['kepribadian'] }} — {{ count(BankSoal::KEPRIBADIAN) }} pernyataan</summary>
        <div class="px-5 pb-5">
            <p class="text-xs text-stone-500 mb-3">Skala 1–5 (sangat tidak setuju → sangat setuju), 10 pernyataan per dimensi. Huruf yang skornya lebih tinggi masuk ke tipe (seri → huruf pertama: E, S, T, J). Label di kurung hanya terlihat di halaman ini.</p>
            <ol class="space-y-1 list-decimal pl-5 text-sm text-stone-700">
                @foreach(BankSoal::KEPRIBADIAN as [$teks, $dim, $kutub])
                    <li>{{ $teks }} <span class="text-[11px] text-stone-400">({{ $dim }} → {{ $kutub }})</span></li>
                @endforeach
            </ol>
        </div>
    </details>

    <details class="bg-white rounded-2xl border border-stone-200">
        <summary class="px-5 py-3 cursor-pointer text-sm font-bold text-stone-800">2. {{ PsikotesService::TES['disc'] }} — {{ count(BankSoal::DISC) }} kelompok kata</summary>
        <div class="px-5 pb-5">
            <p class="text-xs text-stone-500 mb-3">Per kelompok, kandidat memilih 1 kata PALING dan 1 kata PALING TIDAK menggambarkan dirinya. Skor tiap huruf = jumlah "paling" − jumlah "paling tidak". Urutan kata di halaman kandidat diputar per kelompok (huruf tidak terlihat oleh kandidat).</p>
            <div class="grid sm:grid-cols-2 gap-2">
                @foreach(BankSoal::DISC as $g => $kata)
                    <div class="rounded-lg border border-stone-200 px-3 py-2 text-xs text-stone-700">
                        <span class="font-semibold text-stone-400">{{ $g + 1 }}.</span>
                        @foreach(['D', 'I', 'S', 'C'] as $j => $h)
                            <span class="whitespace-nowrap"><b class="text-stone-500">{{ $h }}</b> {{ $kata[$j] }}{{ $j < 3 ? ' ·' : '' }}</span>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <ul class="mt-3 space-y-0.5 text-xs text-stone-500">
                @foreach(BankSoal::GAYA_DISC as $h => $ket)<li><b>{{ $h }}</b> = {{ $ket }}</li>@endforeach
            </ul>
        </div>
    </details>

    <details class="bg-white rounded-2xl border border-stone-200">
        <summary class="px-5 py-3 cursor-pointer text-sm font-bold text-stone-800">3. {{ PsikotesService::TES['logika'] }} — {{ count(BankSoal::LOGIKA) }} soal, {{ BankSoal::MENIT_LOGIKA }} menit</summary>
        <div class="px-5 pb-5">
            <p class="text-xs text-stone-500 mb-3">Jawaban benar ditandai hijau. Skor = persen benar; ≥80 Sangat baik, ≥60 Baik, ≥40 Cukup. Soal kosong dihitung salah.</p>
            <ol class="space-y-3 list-decimal pl-5 text-sm text-stone-700">
                @foreach(BankSoal::LOGIKA as [$soal, $opsi, $kunci])
                    <li>
                        {{ $soal }}
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @foreach($opsi as $o => $t)
                                <span class="rounded px-2 py-0.5 text-xs {{ $o === $kunci ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'bg-stone-100 text-stone-600' }}">{{ $t }}</span>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </details>
</div>
@endsection
