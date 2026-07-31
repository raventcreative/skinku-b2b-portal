@extends('layouts.app')
@section('title', $map->title)
@section('heading', 'Mindmap — '.$map->title)

@section('content')
<div class="max-w-5xl mx-auto">
    <a href="{{ route('mindmaps.index') }}" class="text-xs text-stone-500 hover:text-red-600">← Semua papan</a>
    <h3 class="text-xl font-bold text-stone-900 mt-2">{{ $map->title }}</h3>
    <p class="text-xs text-stone-500 mt-1">{{ $canEdit ? 'Bisa edit' : 'Lihat saja' }}. Kanvas menyusul.</p>
</div>
@endsection
