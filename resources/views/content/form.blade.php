@extends('layouts.app')
@section('title', $post->exists ? 'Edit Konten' : 'Konten Baru')
@section('heading', $post->exists ? 'Edit Konten' : 'Konten Baru')

@section('content')
@php
    $platforms = config('content.platforms');
    $media = $post->exists ? $post->filesIn(\App\Models\ContentPost::MEDIA)->get() : collect();
    $selected = old('platforms', $selected);
@endphp

@if($post->exists && $post->status === 'rejected' && $post->review_note)
    <div class="mb-4 bg-rose-50 border border-rose-200 rounded-2xl px-4 py-3 text-sm text-rose-800">
        <b>Ditolak reviewer:</b> {{ $post->review_note }} — perbaiki lalu ajukan ulang.
    </div>
@endif

<form method="POST" enctype="multipart/form-data"
      action="{{ $post->exists ? route('content.update', $post) : route('content.store') }}"
      class="grid lg:grid-cols-3 gap-5">
    @csrf
    @if($post->exists) @method('PUT') @endif

    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-4">
            <label class="block">
                <span class="text-xs font-semibold text-stone-600">Judul internal *</span>
                <input name="title" required maxlength="150" value="{{ old('title', $post->title) }}" placeholder="mis. Reels Body Serum — before/after"
                       class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>

            <fieldset>
                <legend class="text-xs font-semibold text-stone-600">Tipe konten *</legend>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach(\App\Models\ContentPost::TYPES as $key => $label)
                        <label class="flex items-center gap-2 px-3 py-2 border border-stone-300 rounded-lg text-sm cursor-pointer has-[:checked]:border-red-600 has-[:checked]:bg-red-50">
                            <input type="radio" name="type" value="{{ $key }}" @checked(old('type', $post->type) === $key)> {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-[11px] text-stone-500">Foto = 1 gambar · Video = 1 video MP4/MOV · Carousel = {{ config('content.carousel_min') }}–{{ config('content.carousel_max') }} gambar.</p>
            </fieldset>

            <div>
                <span class="text-xs font-semibold text-stone-600">Media {{ $post->exists ? '(upload baru = mengganti semua media lama)' : '*' }}</span>
                <input type="file" name="media[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"
                       class="mt-1 block w-full text-sm file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-stone-800 file:text-white">
                <p class="mt-1 text-[11px] text-stone-500">Gambar JPG/PNG/WEBP maks {{ intdiv(config('content.image_max_kb'), 1024) }} MB · video maks {{ intdiv(config('content.video_max_kb'), 1024) }} MB.</p>
                @if($media->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($media as $f)
                            @if($f->isImage())
                                <img src="{{ $f->url() }}" alt="" class="w-20 h-20 object-cover rounded-lg border border-stone-200">
                            @else
                                <video src="{{ $f->url() }}" class="w-20 h-20 object-cover rounded-lg border border-stone-200" muted></video>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <label class="block">
                <span class="text-xs font-semibold text-stone-600">Caption utama</span>
                <textarea name="caption" rows="6" id="mainCaption" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm"
                          placeholder="Caption yang dipakai semua platform (bisa di-override per platform di kanan).">{{ old('caption', $post->caption) }}</textarea>
                <span class="text-[11px] text-stone-500"><span id="captionCount">0</span> karakter · batas: Threads 500, Instagram &amp; TikTok 2.200.</span>
            </label>
        </div>
    </div>

    <div class="space-y-5">
        <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
            <p class="text-xs font-semibold text-stone-600">Terbit ke akun SKINKU *</p>
            @foreach($platforms as $key => $cfg)
                <div class="border border-stone-200 rounded-xl p-3">
                    <label class="flex items-center gap-2 text-sm font-semibold text-stone-800">
                        <input type="checkbox" name="platforms[]" value="{{ $key }}" @checked(in_array($key, $selected, true))>
                        {{ $cfg['label'] }}
                        @if($cfg['mode'] === 'manual')<span class="text-[10px] font-normal px-1.5 py-0.5 rounded-sm bg-amber-100 text-amber-800">diposting manual oleh admin</span>
                        @elseif($cfg['mode'] === 'auto')<span class="text-[10px] font-normal px-1.5 py-0.5 rounded-sm bg-amber-100 text-amber-800">otomatis bila akun terhubung, selain itu manual</span>@endif
                    </label>
                    <details class="mt-2" @if(old("captions.$key", $captions[$key] ?? null)) open @endif>
                        <summary class="text-[11px] text-stone-500 cursor-pointer">Caption khusus {{ $cfg['label'] }} (maks {{ number_format($cfg['caption_max'], 0, ',', '.') }})</summary>
                        <textarea name="captions[{{ $key }}]" rows="3" maxlength="{{ $cfg['caption_max'] }}" class="mt-1 block w-full px-2 py-1.5 border border-stone-300 rounded-lg text-xs"
                                  placeholder="Kosong = pakai caption utama">{{ old("captions.$key", $captions[$key] ?? '') }}</textarea>
                    </details>
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
            <label class="block">
                <span class="text-xs font-semibold text-stone-600">Usulan jadwal terbit</span>
                <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i')) }}"
                       class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                <span class="text-[11px] text-stone-500">Kosong = secepatnya setelah disetujui.</span>
            </label>
            <label class="block">
                <span class="text-xs font-semibold text-stone-600">Catatan untuk reviewer</span>
                <textarea name="creator_note" rows="3" maxlength="2000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('creator_note', $post->creator_note) }}</textarea>
            </label>
        </div>

        <div class="flex flex-col gap-2">
            <button name="submit" value="1" class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold shadow-sm">Simpan &amp; Ajukan Review</button>
            <button name="submit" value="0" class="px-5 py-2.5 text-sm bg-white border border-stone-300 text-stone-700 rounded-xl hover:bg-stone-50 font-semibold">Simpan Draft</button>
        </div>
    </div>
</form>

@if($post->exists)
    <form method="POST" action="{{ route('content.destroy', $post) }}" class="mt-4" onsubmit="return confirm('Hapus konten ini beserta medianya?')">
        @csrf @method('DELETE')
        <button class="text-xs text-rose-700 hover:underline">Hapus konten</button>
    </form>
@endif

<script>
    (function () {
        var c = document.getElementById('mainCaption'), n = document.getElementById('captionCount');
        var update = function () { n.textContent = c.value.length; };
        c.addEventListener('input', update); update();
    })();
</script>
@endsection
