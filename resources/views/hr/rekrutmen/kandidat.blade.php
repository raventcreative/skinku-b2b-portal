@extends('layouts.app')
@section('title', $candidate->name.' · Rekrutmen')
@section('heading', 'Rekrutmen')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    use App\Models\Candidate;
    use App\Models\PsychotestSession;
    use App\Services\PsikotesService;
    use Illuminate\Support\Carbon;

    $input = 'mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg';
    $label = 'block text-xs font-semibold text-stone-600';
    $hasil = (array) ($sesi?->results ?? []);
    $aktif = $sesi && ! $sesi->selesai() && ! $sesi->kedaluwarsa();
    $namaDepan = strtok($candidate->name, ' ');
@endphp
<div class="space-y-4 max-w-5xl">
    <a href="{{ route('hr.rekrutmen.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Rekrutmen</a>

    <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold text-stone-800">{{ $candidate->name }}</h2>
                <p class="text-sm text-stone-500">{{ $candidate->opening?->title ?? 'Tanpa lowongan' }}{{ $candidate->source ? ' · '.$candidate->source : '' }} · masuk {{ $candidate->created_at?->translatedFormat('d M Y') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @include('hr.rekrutmen._tahap', ['stage' => $candidate->stage])
                    @if($candidate->interview_at)<span class="text-xs text-stone-500">Interview {{ $candidate->interview_at->translatedFormat('d M Y H:i') }}</span>@endif
                </div>
            </div>
            @if($candidate->employee)
                <a href="{{ route('hr.employees.show', $candidate->employee) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-emerald-50 text-emerald-700 rounded-lg">Karyawan {{ $candidate->employee->kode }} →</a>
            @elseif($bolehJadikanKaryawan && $candidate->stage !== 'ditolak')
                <form method="POST" action="{{ route('hr.rekrutmen.kandidat.karyawan', $candidate) }}" onsubmit="return confirm(@js('Jadikan '.$candidate->name.' karyawan? Data karyawan baru dibuat dari data kandidat ini.'))">@csrf
                    <button class="px-4 py-2 text-sm font-semibold bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">Jadikan karyawan</button>
                </form>
            @endif
        </div>
        <form method="POST" action="{{ route('hr.rekrutmen.kandidat.tahap', $candidate) }}" class="flex flex-wrap items-center gap-1.5 border-t border-stone-100 pt-3">@csrf
            <span class="text-xs text-stone-500 mr-1">Pindah tahap:</span>
            @foreach(Candidate::STAGES as $key => $nama)
                @continue($key === $candidate->stage)
                <button name="stage" value="{{ $key }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-stone-300 text-stone-700 hover:bg-stone-50">{{ $nama }}</button>
            @endforeach
        </form>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 items-start">
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between gap-2">
                <h3 class="text-sm font-bold text-stone-800">Psikotes</h3>
                @if($sesi) @include('hr.psikotes._status', ['sesi' => $sesi]) @endif
            </div>
            <div class="p-5 space-y-4">
                @if(! $sesi)
                    <p class="text-sm text-stone-500">Belum ada link psikotes. Link berlaku {{ PsychotestSession::BERLAKU_HARI }} hari, sekali pakai, tanpa login — kandidat cukup membukanya di HP (±30 menit, 3 tes).</p>
                @else
                    <p class="text-xs text-stone-500">Dibuat {{ $sesi->created_at->translatedFormat('d M Y H:i') }} · berlaku sampai {{ $sesi->expires_at->translatedFormat('d M Y H:i') }}{{ $sesi->finished_at ? ' · selesai '.$sesi->finished_at->translatedFormat('d M Y H:i') : '' }}</p>
                    @if($aktif)
                        @php
                            $pesanWa = "Halo {$namaDepan}, terima kasih sudah melamar di SKINKU. Silakan kerjakan psikotes online (±30 menit) lewat link berikut:\n{$sesi->link()}\nLink berlaku sampai ".$sesi->expires_at->translatedFormat('d F Y H:i').' dan hanya bisa dipakai sekali.';
                        @endphp
                        <div class="flex items-center gap-2">
                            <input id="link-psikotes" value="{{ $sesi->link() }}" readonly aria-label="Link psikotes" class="flex-1 min-w-0 px-3 py-2 text-xs font-mono border border-stone-300 rounded-lg bg-stone-50">
                            <button type="button" data-salin="link-psikotes" class="px-3 py-2 text-xs font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Salin</button>
                        </div>
                        <a href="{{ $candidate->whatsappUrl($pesanWa) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-green-600 text-white rounded-lg hover:bg-green-700">Kirim lewat WhatsApp</a>
                    @endif
                    <ol class="flex flex-wrap gap-2 text-xs">
                        @foreach((array) $sesi->tests as $tes)
                            <li class="rounded-full px-2.5 py-1 {{ $sesi->tesSelesai($tes) ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">{{ $sesi->tesSelesai($tes) ? '✓' : '○' }} {{ PsikotesService::TES[$tes] }}</li>
                        @endforeach
                    </ol>

                    @isset($hasil['kepribadian'])
                        @php $k = $hasil['kepribadian']; @endphp
                        <div class="border-t border-stone-100 pt-4">
                            <p class="text-xs font-semibold text-stone-500">Kepribadian 16 tipe</p>
                            <p class="text-2xl font-bold tracking-widest text-stone-800">{{ $k['tipe'] }}</p>
                            <p class="text-sm text-stone-600">{{ $k['deskripsi'] }}</p>
                            <div class="mt-2 space-y-1.5">
                                @foreach($k['dimensi'] as $pct)
                                    @php [$a, $b] = array_keys($pct); @endphp
                                    <div class="flex items-center gap-2 text-[11px]">
                                        <span class="w-12 text-right {{ $pct[$a] >= $pct[$b] ? 'font-bold text-stone-800' : 'text-stone-400' }}">{{ $a }} {{ $pct[$a] }}%</span>
                                        <div class="flex-1 h-2 rounded-full bg-stone-100 overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $pct[$a] }}%"></div></div>
                                        <span class="w-12 {{ $pct[$b] > $pct[$a] ? 'font-bold text-stone-800' : 'text-stone-400' }}">{{ $pct[$b] }}% {{ $b }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <p class="mt-2 text-[11px] text-stone-400">E/I = sumber energi (ekstrovert/introvert) · S/N = fakta/ide · T/F = logika/perasaan · J/P = terencana/fleksibel</p>
                        </div>
                    @endisset

                    @isset($hasil['disc'])
                        @php $d = $hasil['disc']; @endphp
                        <div class="border-t border-stone-100 pt-4">
                            <p class="text-xs font-semibold text-stone-500">Gaya kerja (DISC)</p>
                            <p class="text-2xl font-bold text-stone-800">{{ $d['utama'] }}<span class="text-base text-stone-400">/{{ $d['kedua'] }}</span></p>
                            <p class="text-sm text-stone-600">{{ $d['deskripsi'] }}</p>
                            <div class="mt-2 grid grid-cols-4 gap-2">
                                @foreach($d['skor'] as $h => $n)
                                    <div class="rounded-lg border px-2 py-1.5 text-center {{ $h === $d['utama'] ? 'border-red-200 bg-red-50' : 'border-stone-200' }}">
                                        <p class="text-xs font-bold text-stone-700">{{ $h }}</p>
                                        <p class="text-sm tabular-nums {{ $n > 0 ? 'text-emerald-700' : ($n < 0 ? 'text-rose-600' : 'text-stone-500') }}">{{ $n > 0 ? '+'.$n : $n }}</p>
                                    </div>
                                @endforeach
                            </div>
                            <p class="mt-2 text-[11px] text-stone-400">Skor = jumlah dipilih "paling" − "paling tidak" (−24 s/d +24). Huruf kedua = gaya pendukung.</p>
                        </div>
                    @endisset

                    @isset($hasil['logika'])
                        @php
                            $l = $hasil['logika'];
                            $p = (array) ($sesi->progress['logika'] ?? []);
                            $menit = isset($p['mulai'], $p['selesai']) ? max(1, (int) round(abs(Carbon::parse($p['mulai'])->diffInSeconds(Carbon::parse($p['selesai']))) / 60)) : null;
                        @endphp
                        <div class="border-t border-stone-100 pt-4">
                            <p class="text-xs font-semibold text-stone-500">Logika &amp; hitung</p>
                            <p class="text-2xl font-bold text-stone-800">{{ $l['skor'] }}<span class="text-base text-stone-400">/100</span></p>
                            <p class="text-sm text-stone-600">{{ $l['benar'] }} dari {{ $l['total'] }} soal benar · {{ $l['kategori'] }}{{ $menit ? ' · dikerjakan '.$menit.' menit' : '' }}</p>
                            @if(! empty($p['lewat_waktu']))<p class="mt-1 text-[11px] font-semibold text-amber-700">⚠ Jawaban dikirim melewati batas waktu.</p>@endif
                        </div>
                    @endisset

                    @if($hasil)
                        <p class="text-[11px] text-stone-400">Hasil psikotes = bahan pendukung wawancara, bukan satu-satunya dasar keputusan. <a href="{{ route('hr.psikotes.soal') }}" class="text-red-700 hover:underline">Lihat bank soal</a></p>
                    @endif
                @endif

                @unless($aktif)
                    <form method="POST" action="{{ route('hr.rekrutmen.kandidat.psikotes', $candidate) }}" @if($sesi?->selesai()) onsubmit="return confirm('Kandidat sudah menyelesaikan psikotes. Buat link tes ulang?')" @endif>@csrf
                        <button class="px-4 py-2 text-sm font-semibold rounded-lg {{ $sesi ? 'bg-stone-100 text-stone-700 hover:bg-stone-200' : 'bg-red-700 text-white hover:bg-red-800' }}">{{ $sesi ? 'Buat link baru' : 'Buat link psikotes' }}</button>
                    </form>
                @endunless
            </div>
        </div>

        <div class="space-y-4">
            @if($candidate->cv_summary)
                <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                    <div class="px-5 py-3 border-b border-stone-100"><h3 class="text-sm font-bold text-stone-800">Ringkasan CV</h3></div>
                    <div class="px-5 py-4 text-sm leading-relaxed text-stone-700 whitespace-pre-wrap">{{ $candidate->cv_summary }}</div>
                </div>
            @endif
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-stone-100"><h3 class="text-sm font-bold text-stone-800">CV &amp; berkas lamaran</h3></div>
                <div class="p-5 space-y-2">
                    @forelse($cv as $f)
                        <div class="flex items-center gap-2 text-xs">
                            <a href="{{ route('hr.rekrutmen.kandidat.cv.show', [$candidate, $f]) }}" target="_blank" rel="noopener" class="min-w-0 text-red-700 hover:underline break-words">📄 {{ $f->original_name }}</a>
                            <span class="shrink-0 text-stone-400">{{ $f->created_at?->translatedFormat('d M Y') }}</span>
                            <form method="POST" action="{{ route('hr.rekrutmen.kandidat.cv.destroy', [$candidate, $f]) }}" onsubmit="return confirm('Hapus berkas ini?')" class="ml-auto">@csrf @method('DELETE')
                                <button class="text-[11px] text-rose-600 hover:underline">Hapus</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm text-stone-400">Belum ada CV.</p>
                    @endforelse
                    <form method="POST" action="{{ route('hr.rekrutmen.kandidat.cv.store', $candidate) }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2 border-t border-stone-100 pt-3">@csrf
                        <input type="file" name="cv" accept=".pdf,.doc,.docx,image/*" required aria-label="File CV" class="text-xs text-stone-600 max-w-[200px]">
                        <button class="px-3 py-1.5 text-xs font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Unggah</button>
                    </form>
                    <p class="text-[11px] text-stone-400">Disimpan privat &amp; ikut backup dokumen. Maks 5 MB: PDF, Word, atau foto.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('hr.rekrutmen.kandidat.update', $candidate) }}" class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
                @csrf @method('PUT')
                <h3 class="text-sm font-bold text-stone-800">Data kandidat</h3>
                <div class="grid sm:grid-cols-2 gap-3">
                    @include('hr.rekrutmen._field-kandidat')
                    <label class="{{ $label }}">Jadwal interview<input type="datetime-local" name="interview_at" value="{{ old('interview_at', $candidate->interview_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}"></label>
                    <label class="{{ $label }} sm:col-span-2">Catatan HR<textarea name="notes" rows="4" maxlength="3000" placeholder="Hasil interview, gaji yang diharapkan, kesan…" class="{{ $input }}">{{ old('notes', $candidate->notes) }}</textarea></label>
                </div>
                <button class="px-4 py-2 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">Simpan</button>
            </form>

            {{-- Kandidat yang sudah jadi karyawan tak bisa dihapus (riwayat rekrutmennya disimpan). --}}
            @unless($candidate->employee_id)
                <form method="POST" action="{{ route('hr.rekrutmen.kandidat.destroy', $candidate) }}" class="text-right"
                      onsubmit="return confirm(@js('Hapus permanen kandidat '.$candidate->name.' beserta CV & hasil psikotesnya? Tidak bisa dibatalkan.'))">
                    @csrf @method('DELETE')
                    <button class="px-3 py-2 text-sm text-rose-600 hover:underline">Hapus kandidat</button>
                </form>
            @endunless
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-salin]').forEach((b) => b.addEventListener('click', () => {
    const el = document.getElementById(b.dataset.salin);
    const done = () => { b.textContent = 'Tersalin ✓'; setTimeout(() => { b.textContent = 'Salin'; }, 1500); };
    const cadangan = () => { el.select(); document.execCommand('copy'); done(); };
    navigator.clipboard ? navigator.clipboard.writeText(el.value).then(done).catch(cadangan) : cadangan();
}));
</script>
@endpush
