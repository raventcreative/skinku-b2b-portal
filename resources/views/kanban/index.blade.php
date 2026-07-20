@extends('layouts.app')
@section('title', 'Kanban')
@section('heading', 'Kanban — Papan Tugas Tim')

@section('content')
@php $u = auth()->user(); @endphp

<form method="POST" action="{{ route('kanban.store') }}" class="flex flex-wrap items-center gap-2 mb-5">@csrf
    <input name="name" required maxlength="150" placeholder="nama papan baru… (mis. Marketing Juli)"
        class="flex-1 min-w-[16rem] max-w-md px-3 py-2 border border-stone-300 rounded-lg text-sm">
    <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">+ Buat Papan</button>
</form>

@if($errors->any())
    <p class="mb-4 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
@endif

<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
    @forelse($boards as $board)
        <div class="bg-white rounded-2xl border border-stone-200 p-5 hover:border-stone-300 transition">
            <a href="{{ route('kanban.show', $board) }}" class="block">
                <p class="font-bold text-stone-900">{{ $board->name }}</p>
                <p class="text-xs text-stone-500 mt-1">
                    {{ $board->columns_count }} kolom · {{ $cardCounts[$board->id] ?? 0 }} kartu
                </p>
                <p class="text-[11px] text-stone-400 mt-2">dibuat {{ $board->creator->fullname ?? '—' }} · {{ $board->created_at->format('d M Y') }}</p>
            </a>
            @if($u->isSuperAdmin() || $board->created_by === $u->id)
                <form method="POST" action="{{ route('kanban.destroy', $board) }}" class="mt-3 text-right"
                    onsubmit="return confirm('Hapus papan {{ $board->name }} beserta seluruh kolom & kartunya?')">
                    @csrf @method('DELETE')
                    <button class="text-[11px] text-rose-500 hover:text-rose-700">hapus papan</button>
                </form>
            @endif
        </div>
    @empty
        <p class="col-span-full py-10 text-center text-stone-400 text-sm">Belum ada papan. Buat papan pertama di atas.</p>
    @endforelse
</div>
@endsection
