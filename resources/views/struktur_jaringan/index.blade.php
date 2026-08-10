@extends('layouts.app')
@section('title', 'Struktur Jaringan')
@section('heading', 'Struktur Jaringan Mitra')

@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        @forelse($roots as $root)
            <ul class="list-none">@include('struktur_jaringan._node', ['node' => $root])</ul>
        @empty
            <p class="text-sm text-stone-500">Belum ada Grand Distributor yang ditempatkan. Mulai tempatkan mitra lewat Kelola Anggota atau dari panel di bawah.</p>
        @endforelse
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <h2 class="text-sm font-semibold text-stone-600 mb-2">Belum ditempatkan ({{ $unplaced->count() }})</h2>
        @if($unplaced->isEmpty())
            <p class="text-xs text-stone-400">Semua mitra sudah ditempatkan di pohon.</p>
        @else
            <ul class="flex flex-wrap gap-2">
                @foreach($unplaced as $u)
                    <li class="rounded border border-dashed border-stone-300 px-2 py-1 text-sm">
                        {{ $u->fullname }} <span class="text-xs text-stone-400">{{ \App\Support\PartnerHierarchy::label($u->role) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
