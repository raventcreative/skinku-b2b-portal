@extends('layouts.app')
@section('title', $lesson->title)
@section('heading', 'Pembelajaran')

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('learning.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar materi</a>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mt-3">
        <div class="aspect-video bg-black">
            @if($lesson->embedUrl())
                <iframe class="w-full h-full" src="{{ $lesson->embedUrl() }}" title="{{ $lesson->title }}"
                        frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen></iframe>
            @else
                <div class="w-full h-full flex items-center justify-center text-white text-sm">
                    Link video tidak valid. <a href="{{ $lesson->video_url }}" target="_blank" class="underline ml-1">Buka di YouTube</a>
                </div>
            @endif
        </div>
        <div class="p-6">
            @if($lesson->category)<span class="text-[11px] uppercase tracking-wide text-red-600 font-bold">{{ $lesson->category }}</span>@endif
            <h2 class="text-xl font-bold text-stone-900 mt-1">{{ $lesson->title }}</h2>
            @if($lesson->description)
                <p class="text-sm text-stone-600 mt-3 whitespace-pre-line leading-relaxed">{{ $lesson->description }}</p>
            @endif
            @if(! $lesson->embedUrl())
                <a href="{{ $lesson->video_url }}" target="_blank" class="inline-block mt-4 px-4 py-2 bg-red-600 text-white text-sm rounded-lg">Tonton di YouTube</a>
            @endif
        </div>
    </div>
</div>
@endsection
