@extends('layouts.app')
@section('title', 'Pengetahuan AI')
@section('heading', 'Pengetahuan AI')

@section('content')
@php($activeTab = request('tab', 'sistem'))
@php($activeTab = (is_string($activeTab) && array_key_exists($activeTab, $groups)) ? $activeTab : 'sistem')
<div class="max-w-3xl">
    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 mb-5">
        <p class="text-sm font-bold text-indigo-900">Ini "memori" asisten kamu 🧠</p>
        <p class="text-xs text-indigo-700 mt-1"><b>Sistem</b> = konteks buat Asisten AI internal & OKR. <b>Chat E-commerce</b> = pengetahuan buat balas chat pembeli (TikTok, nanti Shopee). Isi seadanya dulu juga nggak apa — makin lengkap, makin pintar.</p>
    </div>

    <div class="flex gap-1 mb-4 border-b border-stone-200">
        @foreach($groups as $gkey => $glabel)
            <a href="{{ route('ai.knowledge', ['tab' => $gkey]) }}"
               class="px-4 py-2 text-sm font-semibold rounded-t-lg -mb-px border-b-2 {{ $activeTab === $gkey ? 'border-red-600 text-red-700 bg-white' : 'border-transparent text-stone-500 hover:text-stone-800' }}">
                {{ $glabel }}
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('ai.knowledge.save') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="group" value="{{ $activeTab }}">
        @foreach($sectionsByGroup[$activeTab] as $key => $meta)
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
            <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold shadow">Simpan {{ $groups[$activeTab] }}</button>
            <span class="text-[11px] text-stone-400">Tersimpan langsung dipakai di obrolan/chat berikutnya.</span>
        </div>
    </form>
</div>
@endsection
