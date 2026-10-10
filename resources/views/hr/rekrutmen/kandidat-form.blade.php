@extends('layouts.app')
@section('title', 'Tambah kandidat')
@section('heading', 'Tambah kandidat')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $input = 'mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg';
    $label = 'block text-xs font-semibold text-stone-600';
    $statusAi = $bacaAi['status'] ?? null;
@endphp
<div class="space-y-4 max-w-2xl">
    <a href="{{ route('hr.rekrutmen.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Rekrutmen</a>

    @unless($cvAi)
        <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-stone-800">Punya file CV kandidat?</p>
                <p class="text-xs text-stone-500">Biar AI yang mengisi form ini dari PDF atau foto CV — nama, kontak, lowongan yang cocok &amp; ringkasan CV berpoin.</p>
            </div>
            @include('hr.rekrutmen._baca-cv')
        </div>
    @endunless

    <form method="POST" action="{{ route('hr.rekrutmen.kandidat.store') }}" class="space-y-4">
        @csrf
        @if($cvAi)
            <div data-baca-cv data-status="{{ $statusAi }}" data-url="{{ route('hr.rekrutmen.baca-cv.status', $cvAi['token'] ?? str_repeat('x', 32)) }}"
                class="rounded-2xl bg-indigo-50 border border-indigo-200 p-4 space-y-2">
                <p class="text-sm text-indigo-800"><b>✨ CV “{{ $cvAi['nama'] }}”</b></p>
                <p data-pesan class="text-sm {{ $statusAi === 'gagal' ? 'text-rose-700' : 'text-indigo-800' }}">
                    @if($statusAi === 'antri')
                        <span class="animate-pulse">⏳</span> AI sedang membaca CV… <span data-detik>0</span> detik (biasanya kurang dari 1–2 menit).
                        Boleh mulai mengisi — kolom yang masih kosong akan diisi otomatis.
                    @elseif($statusAi === 'selesai')
                        Form sudah diisi AI — periksa lagi sebelum disimpan, AI bisa keliru.
                    @else
                        {{ $bacaAi['pesan'] ?? 'AI tidak bisa membaca CV ini.' }} Isi form secara manual; CV tetap bisa dilampirkan.
                    @endif
                </p>
                <label class="flex items-center gap-2 text-sm text-indigo-800">
                    <input type="checkbox" name="lampirkan_cv" value="1" checked class="w-4 h-4 accent-red-700">
                    Lampirkan file CV ini ke kandidat (disimpan privat)
                </label>
            </div>
        @endif

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <div class="grid sm:grid-cols-2 gap-3">
                @include('hr.rekrutmen._field-kandidat')
                <label class="{{ $label }} sm:col-span-2">Catatan HR (opsional)<textarea name="notes" rows="3" maxlength="3000" placeholder="Kesan interview, gaji yang diminta, info dari referensi…" class="{{ $input }}">{{ old('notes', $candidate->notes) }}</textarea></label>
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

@push('scripts')
<script>
// Baca CV dengan AI berjalan di antrean: tunggu hasilnya lalu isi kolom yang masih kosong (isian HR tak ditimpa).
(() => {
    const kotak = document.querySelector('[data-baca-cv]');
    if (!kotak || kotak.dataset.status !== 'antri') return;
    const form = kotak.closest('form');
    const pesan = kotak.querySelector('[data-pesan]');
    const mulai = Date.now();
    const detik = () => Math.round((Date.now() - mulai) / 1000);
    const jam = setInterval(() => { const d = kotak.querySelector('[data-detik]'); if (d) d.textContent = detik(); }, 1000);
    const tulis = (teks, gagal = false) => {
        clearInterval(jam);
        pesan.textContent = teks;
        if (gagal) pesan.classList.replace('text-indigo-800', 'text-rose-700');
    };
    const isi = (nama, nilai) => {
        const el = form.querySelector(`[name="${nama}"]`);
        if (el && nilai !== null && nilai !== undefined && String(el.value).trim() === '') el.value = String(nilai);
    };
    const cek = async () => {
        if (detik() > 360) {
            tulis('AI belum selesai setelah 6 menit — isi form secara manual (CV tetap bisa dilampirkan), atau muat ulang halaman ini nanti.', true);
            return;
        }
        try {
            const res = await fetch(kotak.dataset.url, { headers: { Accept: 'application/json' } });
            const data = res.ok ? await res.json() : { status: 'antri' };
            if (data.status === 'selesai') {
                const h = data.hasil || {};
                ['name', 'phone', 'email', 'job_opening_id', 'cv_summary'].forEach((k) => isi(k, h[k]));
                const ringkas = form.querySelector('[name="cv_summary"]');
                if (ringkas && ringkas.value) ringkas.rows = 14;
                tulis('Form sudah diisi AI — periksa lagi sebelum disimpan, AI bisa keliru.');
                return;
            }
            if (data.status === 'gagal') {
                tulis((data.pesan || 'AI tidak bisa membaca CV ini.') + ' Isi form secara manual; CV tetap bisa dilampirkan.', true);
                return;
            }
        } catch (e) { /* jaringan putus sesaat → coba lagi */ }
        setTimeout(cek, 3000);
    };
    setTimeout(cek, 2000);
})();
</script>
@endpush
