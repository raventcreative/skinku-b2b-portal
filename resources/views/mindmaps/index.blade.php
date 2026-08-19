@extends('layouts.app')
@section('title', 'Mindmaps')
@section('heading', 'Mindmaps')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <p class="text-sm text-stone-500">Kanvas ide, diagram, dan papan campaign — dibagikan ke tim.</p>
        <form method="POST" action="{{ route('mindmaps.store') }}" class="flex items-center gap-2">
            @csrf
            <input name="title" required maxlength="255" placeholder="Judul papan baru…"
                class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-56">
            <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">+ Papan Baru</button>
        </form>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($maps as $map)
            <div class="bg-white rounded-2xl border border-stone-200 p-5 hover:border-stone-400 hover:shadow-sm transition">
                <a href="{{ route('mindmaps.show', $map) }}" class="block">
                    <p class="text-sm font-bold text-stone-900 truncate">{{ $map->title }}</p>
                    <p class="text-[11px] text-stone-400 mt-2">oleh {{ $map->creator?->fullname ?? $map->creator?->name ?? '—' }} · diperbarui {{ $map->updated_at->diffForHumans() }}</p>
                </a>
                @if($map->isOwner(auth()->user()))
                    <form method="POST" action="{{ route('mindmaps.destroy', $map) }}" class="mt-3 text-right"
                        onsubmit="return confirm('Hapus papan {{ $map->title }} beserta seluruh isinya?')">
                        @csrf @method('DELETE')
                        <button class="text-[11px] text-rose-500 hover:text-rose-700">hapus papan</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-sm text-stone-400 col-span-full py-8 text-center">Belum ada papan. Buat papan baru untuk mulai.</p>
        @endforelse
    </div>
</div>
@endsection
