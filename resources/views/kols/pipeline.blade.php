@extends('layouts.app')
@section('title', 'Pipeline KOL')
@section('heading', 'Pipeline KOL')

@section('content')
@php $u = auth()->user(); @endphp

<div class="space-y-4">

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Kartu aktif</p>
            <p class="text-2xl font-bold text-stone-800">{{ $statAktif }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Terlambat</p>
            <p class="text-2xl font-bold {{ $statTerlambat ? 'text-rose-600' : 'text-stone-800' }}">{{ $statTerlambat }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Hari ini / besok</p>
            <p class="text-2xl font-bold {{ $statDekat ? 'text-amber-600' : 'text-stone-800' }}">{{ $statDekat }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Tanpa next action</p>
            <p class="text-2xl font-bold {{ $statTanpaAksi ? 'text-amber-600' : 'text-stone-800' }}">{{ $statTanpaAksi }}</p>
        </div>
    </div>

    {{-- Tambah kartu --}}
    @if($u->canDo('kol.pipeline.manage'))
        <details class="bg-white rounded-2xl border border-stone-200 p-4">
            <summary class="cursor-pointer text-sm font-semibold text-stone-700">+ Tambah kartu ke pipeline</summary>
            <form method="POST" action="{{ route('kol-pipeline.store') }}" class="mt-3 grid sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                @csrf
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">KOL</span>
                    @include('kols._kol-combo', ['kols' => $kolsTanpaKartu, 'name' => 'kol_id', 'id' => 'pipelineKolCombo'])
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Stage awal</span>
                    <select name="stage" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        @foreach($labels as $val => $lbl)
                            <option value="{{ $val }}" @selected($val === 'kandidat')>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Next action</span>
                    <input name="next_action" maxlength="255" placeholder="mis. DM perkenalan" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Tanggal</span>
                    <input type="date" name="next_action_at" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <div class="lg:col-span-4">
                    <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Tambah kartu</button>
                    <span class="ml-3 text-[11px] text-stone-400">Follow-up maks 3× — setelah itu parkir ke <b>Drop</b>.</span>
                </div>
            </form>
        </details>
    @endif

    {{-- Papan kanban --}}
    <div class="flex gap-3 overflow-x-auto pb-4">
        @foreach($stages as $stage)
            @php $cards = $byStage[$stage] ?? collect(); @endphp
            <div class="min-w-[248px] w-[248px] shrink-0">
                <div class="flex items-center justify-between px-1 mb-2">
                    <span class="text-xs font-bold uppercase tracking-wide text-stone-500">{{ $labels[$stage] }}</span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-stone-100 text-stone-500">{{ $cards->count() }}</span>
                </div>
                <div class="space-y-2">
                    @foreach($cards as $c)
                        @php
                            $late = $c->isActive() && $c->next_action_at && $c->next_action_at->lt($today);
                            $soon = $c->isActive() && $c->next_action_at && $c->next_action_at->between($today, $today->copy()->addDay()->endOfDay());
                        @endphp
                        <div id="card-{{ $c->id }}" class="bg-white rounded-xl border border-stone-200 p-3 space-y-1.5">
                            <div class="flex items-start justify-between gap-2">
                                <a href="{{ route('kols.show', $c->kol_id) }}" class="text-sm font-semibold text-indigo-600 hover:underline">{{ '@'.$c->kol->tiktok_username }}</a>
                                @if($c->followup_count > 0)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-stone-100 text-stone-500 shrink-0">FU {{ $c->followup_count }}×</span>
                                @endif
                            </div>
                            @if($c->next_action)
                                <p class="text-xs {{ $late ? 'text-rose-600 font-medium' : ($soon ? 'text-amber-600' : 'text-stone-500') }}">
                                    {{ $c->next_action }}
                                    <span class="block text-[10px] {{ $late ? 'text-rose-500' : 'text-stone-400' }}">{{ $c->next_action_at?->format('d M Y') }}{{ $late ? ' · terlambat' : '' }}</span>
                                </p>
                            @elseif($c->isActive())
                                <p class="text-[11px] text-amber-600">⚠ belum ada next action</p>
                            @endif

                            @if($u->canDo('kol.pipeline.manage'))
                                <details class="pt-1">
                                    <summary class="cursor-pointer text-[11px] text-stone-400 hover:text-stone-600">Pindah / aksi</summary>
                                    <div class="mt-2 space-y-2">
                                        <form method="POST" action="{{ route('kol-pipeline.stage', $c) }}" class="flex gap-1">
                                            @csrf @method('PATCH')
                                            <select name="stage" class="flex-1 px-2 py-1 border border-stone-300 rounded text-xs bg-white">
                                                @foreach($labels as $val => $lbl)
                                                    <option value="{{ $val }}" @selected($val === $c->stage)>{{ $lbl }}</option>
                                                @endforeach
                                            </select>
                                            <button class="px-2 py-1 bg-stone-700 hover:bg-stone-800 text-white text-xs rounded">Pindah</button>
                                        </form>
                                        <form method="POST" action="{{ route('kol-pipeline.next-action', $c) }}" class="space-y-1">
                                            @csrf @method('PATCH')
                                            <input name="next_action" required maxlength="255" placeholder="next action" class="w-full px-2 py-1 border border-stone-300 rounded text-xs">
                                            <div class="flex items-center gap-2">
                                                <input type="date" name="next_action_at" required value="{{ now()->toDateString() }}" class="flex-1 px-2 py-1 border border-stone-300 rounded text-xs">
                                                <label class="flex items-center gap-1 text-[10px] text-stone-500"><input type="checkbox" name="is_followup" value="1"> FU</label>
                                                <button class="px-2 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded">Set</button>
                                            </div>
                                        </form>
                                        @if($u->role === \App\Models\User::ROLE_SUPER_ADMIN)
                                            <form method="POST" action="{{ route('kol-pipeline.destroy', $c) }}" onsubmit="return confirm('Hapus kartu ini permanen?')">
                                                @csrf @method('DELETE')
                                                <button class="text-[10px] text-rose-500 hover:underline">Hapus kartu</button>
                                            </form>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                    @if($cards->isEmpty())
                        <p class="text-[11px] text-stone-300 px-1">—</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
