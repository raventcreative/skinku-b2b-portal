@extends('layouts.app')
@section('title', 'Pengetahuan AI')
@section('heading', 'Pengetahuan AI')

@section('content')
<div class="max-w-3xl">
    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 mb-5">
        <p class="text-sm font-bold text-indigo-900">Ini "memori" asisten kamu 🧠</p>
        <p class="text-xs text-indigo-700 mt-1">Isi kotak-kotak di bawah biar asisten paham SKINKU dan jawabannya nyambung, bukan generik. Semua ini otomatis "diingat" di <b>setiap</b> obrolan (lewat tombol chat di pojok kanan-bawah). Isi seadanya dulu juga nggak apa — makin lengkap, makin pintar. Boleh dikosongkan sebagian.</p>
    </div>

    <form method="POST" action="{{ route('ai.knowledge.save') }}" class="space-y-4">
        @csrf
        @foreach($sections as $key => $meta)
            @php([$title, $question, $placeholder] = $meta)
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <label class="block">
                    <span class="text-sm font-bold text-stone-800">{{ $title }}</span>
                    <span class="block text-[11px] text-stone-500 mt-0.5">{{ $question }}</span>
                    <textarea name="content[{{ $key }}]" rows="3" maxlength="8000" placeholder="{{ $placeholder }}"
                        class="mt-2 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('content.'.$key, $values[$key] ?? '') }}</textarea>
                </label>
            </div>
        @endforeach

        <div class="flex items-center gap-2 sticky bottom-4">
            <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold shadow">Simpan Pengetahuan</button>
            <span class="text-[11px] text-stone-400">Tersimpan langsung dipakai asisten di obrolan berikutnya.</span>
        </div>
    </form>
</div>
@endsection
