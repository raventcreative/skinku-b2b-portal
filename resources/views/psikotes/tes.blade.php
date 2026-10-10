@extends('psikotes._layout')
@section('title', $judul)

@section('body')
@php
    use App\Support\Psikotes\BankSoal;

    $petunjuk = [
        'kepribadian' => 'Pilih seberapa setuju Anda dengan setiap pernyataan. Jawab sesuai diri Anda sehari-hari, bukan yang dianggap ideal.',
        'disc' => 'Di setiap kelompok, pilih 1 kata yang PALING menggambarkan diri Anda dan 1 kata yang PALING TIDAK menggambarkan diri Anda.',
        'logika' => 'Pilih jawaban yang paling tepat. Waktu '.BankSoal::MENIT_LOGIKA.' menit — saat waktu habis, jawaban terkirim otomatis. Soal yang tidak dijawab dihitung salah.',
    ][$tes];
@endphp
<div class="bg-white rounded-2xl border border-stone-200 p-5">
    <p class="text-xs font-semibold text-red-700">Tes {{ $nomorTes }} dari {{ $jumlahTes }}</p>
    <h1 class="text-lg font-bold text-stone-800">{{ $judul }}</h1>
    <p class="text-sm text-stone-600 mt-1">{{ $petunjuk }}</p>
</div>

@if($sisaDetik !== null)
    <div data-timer class="sticky top-0 z-10 bg-white rounded-xl border border-stone-200 px-4 py-2 flex items-center justify-between gap-3 shadow-sm">
        <span class="text-sm text-stone-600">Sisa waktu</span>
        <span id="sisa-waktu" class="font-mono text-lg font-bold text-stone-800">{{ sprintf('%02d:%02d', intdiv($sisaDetik, 60), $sisaDetik % 60) }}</span>
    </div>
    <p id="waktu-habis" hidden class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">Waktu habis — jawaban sedang dikirim…</p>
@endif

@if($errors->any())
    <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</div>
@endif

<form id="form-tes" method="POST" action="{{ route('psikotes.publik.simpan', [$sesi->token, $tes]) }}" class="space-y-4">
    @csrf
    <div class="bg-white rounded-2xl border border-stone-200 divide-y divide-stone-100">
        @if($tes === 'kepribadian')
            @foreach(BankSoal::KEPRIBADIAN as $i => [$teks])
                <fieldset class="px-4 py-4">
                    <legend class="text-sm text-stone-800"><span class="font-semibold text-stone-400">{{ $i + 1 }}.</span> {{ $teks }}</legend>
                    <div class="mt-3 flex gap-1.5">
                        @foreach(BankSoal::SKALA as $v => $label)
                            <label class="pilihan flex-1 min-w-0 flex flex-col items-center gap-1 rounded-lg border border-stone-200 px-1 py-2 cursor-pointer hover:bg-stone-50">
                                <input type="radio" name="jawaban[{{ $i }}]" value="{{ $v }}" required @checked((string) old("jawaban.$i") === (string) $v)>
                                <span class="text-[10px] leading-tight text-center text-stone-500">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        @elseif($tes === 'disc')
            @foreach($disc as $g => $kelompok)
                <fieldset class="px-4 py-4">
                    <legend class="text-xs font-semibold text-stone-500">Kelompok {{ $g + 1 }} dari {{ count($disc) }}</legend>
                    <table class="mt-2 w-full text-sm">
                        <thead>
                            <tr class="text-[11px] text-stone-500">
                                <th class="py-1 text-left font-medium">Kata</th>
                                <th class="py-1 w-20 text-center font-medium">Paling</th>
                                <th class="py-1 w-20 text-center font-medium">Paling tidak</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($kelompok as ['huruf' => $h, 'kata' => $kata])
                                <tr>
                                    <td class="py-2 text-stone-800">{{ $kata }}</td>
                                    <td class="py-2 text-center"><input type="radio" name="paling[{{ $g }}]" value="{{ $h }}" required aria-label="Paling: {{ $kata }}" @checked(old("paling.$g") === $h)></td>
                                    <td class="py-2 text-center"><input type="radio" name="kurang[{{ $g }}]" value="{{ $h }}" required aria-label="Paling tidak: {{ $kata }}" @checked(old("kurang.$g") === $h)></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </fieldset>
            @endforeach
        @else
            @foreach(BankSoal::LOGIKA as $i => [$soal, $opsi])
                <fieldset class="px-4 py-4">
                    <legend class="text-sm text-stone-800"><span class="font-semibold text-stone-400">{{ $i + 1 }}.</span> {{ $soal }}</legend>
                    <div class="mt-3 grid sm:grid-cols-2 gap-1.5">
                        @foreach($opsi as $o => $teks)
                            <label class="pilihan flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-2 text-sm text-stone-700 cursor-pointer hover:bg-stone-50">
                                <input type="radio" name="jawaban[{{ $i }}]" value="{{ $o }}" @checked((string) old("jawaban.$i") === (string) $o)>
                                <span>{{ $teks }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        @endif
    </div>
    <button id="tombol-kirim" class="block w-full px-5 py-3 text-sm font-semibold bg-red-700 text-white rounded-xl hover:bg-red-800">
        {{ $nomorTes < $jumlahTes ? 'Simpan & lanjut ke tes berikutnya' : 'Kirim jawaban' }}
    </button>
</form>
@endsection

@push('scripts')
{{-- Gaya kecil khusus halaman ini (tanpa build Tailwind): sorot pilihan terpilih & perbesar radio di HP. --}}
<style>
    input[type=radio] { width: 1.1rem; height: 1.1rem; accent-color: #b91c1c; }
    .pilihan:has(input:checked) { background: #fef2f2; border-color: #fca5a5; }
    [data-timer].hampir-habis { background: #fff1f2; border-color: #fda4af; }
    [data-timer].hampir-habis #sisa-waktu { color: #be123c; }
</style>
<script>
(() => {
    const form = document.getElementById('form-tes');
    const tombol = document.getElementById('tombol-kirim');
    let terkirim = false;
    form.addEventListener('submit', () => { terkirim = true; tombol.disabled = true; tombol.textContent = 'Mengirim…'; });

    // DISC: kata yang sama tak boleh jadi "paling" sekaligus "paling tidak" — memilih satu menghapus yang lain.
    form.addEventListener('change', (e) => {
        const m = /^(paling|kurang)\[(\d+)\]$/.exec(e.target.name || '');
        if (!m) return;
        const lawan = form.querySelector(`input[name="${m[1] === 'paling' ? 'kurang' : 'paling'}[${m[2]}]"][value="${e.target.value}"]`);
        if (lawan && lawan.checked) lawan.checked = false;
    });

    const sisaEl = document.getElementById('sisa-waktu');
    if (!sisaEl) return;
    // Hitung mundur tes logika; batas sebenarnya dijaga server (dihitung sejak halaman pertama kali dibuka).
    const akhir = Date.now() + @json($sisaDetik) * 1000;
    const tick = () => {
        const sisa = Math.max(0, Math.round((akhir - Date.now()) / 1000));
        sisaEl.textContent = String(Math.floor(sisa / 60)).padStart(2, '0') + ':' + String(sisa % 60).padStart(2, '0');
        if (sisa <= 60) sisaEl.closest('[data-timer]').classList.add('hampir-habis');
        if (sisa === 0 && !terkirim) {
            clearInterval(timer);
            terkirim = true;
            document.getElementById('waktu-habis').hidden = false;
            form.submit();
        }
    };
    const timer = setInterval(tick, 1000);
    tick();
})();
</script>
@endpush
