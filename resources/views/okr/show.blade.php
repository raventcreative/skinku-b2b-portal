@extends('layouts.app')
@section('title', $okr->name)
@section('heading', 'OKR — '.$okr->period_label)

@section('content')
@php
    $u = auth()->user();
    $canManage = $u->canDo('okr.manage');
    $allTasks = $okr->objectives->flatMap(fn($objective) => $objective->keyResults)->flatMap(fn($kr) => $kr->tasks);
    $allDone = $allTasks->filter(fn($task) => $task->isCompleted())->count();
    $allTotal = $allTasks->count();
    $allProgress = $allTotal ? (int) round(($allDone / $allTotal) * 100) : 0;
@endphp

<div class="flex flex-wrap items-start justify-between gap-3 mb-5">
    <div>
        <a href="{{ route('okr.index') }}" class="text-xs text-stone-500 hover:text-red-600">← Semua OKR</a>
        <div class="flex flex-wrap items-center gap-2 mt-2">
            <h3 class="text-xl font-bold text-stone-900">{{ $okr->name }}</h3>
            <span class="px-2 py-1 text-[10px] font-bold rounded-full {{ $okr->isDraft() ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-800' }}">
                {{ $okr->isDraft() ? 'DRAF AI' : 'AKTIF' }}
            </span>
        </div>
        <p class="text-xs text-stone-500 mt-1">{{ $okr->period_label }} · {{ $okr->scopeLabel() }} · {{ $okr->start_date->format('d M') }}–{{ $okr->end_date->format('d M Y') }}</p>
    </div>
    @if(!$okr->isDraft())
        <div class="w-52">
            <div class="flex justify-between text-xs mb-1"><span class="text-stone-500">{{ $allDone }}/{{ $allTotal }} tugas</span><b>{{ $allProgress }}%</b></div>
            <div class="h-2.5 bg-stone-200 rounded-full overflow-hidden"><div class="h-full bg-red-500 rounded-full" style="width: {{ $allProgress }}%"></div></div>
        </div>
    @endif
</div>

@if($okr->isDraft())
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5">
        <p class="text-sm font-bold text-amber-900">Pratinjau — belum ada kartu yang dibuat</p>
        <p class="text-xs text-amber-800 mt-1">Periksa target, penerima, tenggat, dan kolom Kanban. Simpan koreksi dulu, lalu setujui untuk membuat seluruh kartu sekaligus.</p>
    </div>
@endif

@if($okr->isDraft() && $canManage)
<form method="POST" action="{{ route('okr.update', $okr) }}" class="space-y-5">
    @csrf @method('PUT')
    <div class="bg-white rounded-2xl border border-stone-200 p-5 grid gap-4">
        <label>
            <span class="text-xs font-semibold text-stone-700">Nama rencana</span>
            <input name="name" required maxlength="255" value="{{ old('name', $okr->name) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-semibold">
        </label>
        <label>
            <span class="text-xs font-semibold text-stone-700">Arahan awal</span>
            <textarea name="direction" required rows="3" maxlength="5000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('direction', $okr->direction) }}</textarea>
        </label>
    </div>

    @foreach($okr->objectives as $oi => $objective)
        <section class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="p-5 bg-stone-50 border-b border-stone-200">
                <p class="text-[10px] font-bold tracking-wider text-red-600 uppercase">Objective {{ $oi + 1 }}</p>
                <input name="objectives[{{ $objective->id }}][title]" required maxlength="255"
                    value="{{ old('objectives.'.$objective->id.'.title', $objective->title) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-bold">
                <textarea name="objectives[{{ $objective->id }}][description]" rows="2" maxlength="4000" placeholder="Penjelasan Objective"
                    class="mt-2 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('objectives.'.$objective->id.'.description', $objective->description) }}</textarea>
                <label class="block mt-2">
                    <span class="text-[11px] text-stone-500">Pemilik Objective</span>
                    <select name="objectives[{{ $objective->id }}][owner_user_id]" class="mt-1 px-3 py-2 border border-stone-300 rounded-lg text-xs">
                        <option value="">belum ditentukan</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}" @selected((int) old('objectives.'.$objective->id.'.owner_user_id', $objective->owner_user_id) === $member->id)>{{ $member->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="p-5 space-y-5">
                @foreach($objective->keyResults as $ki => $kr)
                    <div class="border-l-4 border-indigo-300 pl-4">
                        <p class="text-[10px] font-bold text-indigo-700 uppercase">Key Result {{ $oi + 1 }}.{{ $ki + 1 }}</p>
                        <input name="key_results[{{ $kr->id }}][title]" required maxlength="255"
                            value="{{ old('key_results.'.$kr->id.'.title', $kr->title) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-semibold">
                        <div class="grid sm:grid-cols-4 gap-2 mt-2">
                            <input name="key_results[{{ $kr->id }}][metric]" maxlength="255" placeholder="Metrik"
                                value="{{ old('key_results.'.$kr->id.'.metric', $kr->metric) }}" class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
                            <input name="key_results[{{ $kr->id }}][target]" maxlength="255" placeholder="Target"
                                value="{{ old('key_results.'.$kr->id.'.target', $kr->target) }}" class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
                            <select name="key_results[{{ $kr->id }}][owner_user_id]" class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                <option value="">pemilik KR…</option>
                                @foreach($members as $member)
                                    <option value="{{ $member->id }}" @selected((int) old('key_results.'.$kr->id.'.owner_user_id', $kr->owner_user_id) === $member->id)>{{ $member->displayName() }}</option>
                                @endforeach
                            </select>
                            <input type="date" name="key_results[{{ $kr->id }}][due_date]" required
                                min="{{ $okr->start_date->toDateString() }}" max="{{ $okr->end_date->toDateString() }}"
                                value="{{ old('key_results.'.$kr->id.'.due_date', $kr->due_date?->toDateString()) }}" class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
                        </div>

                        <div class="mt-3 space-y-2">
                            @foreach($kr->tasks as $task)
                                <div class="rounded-xl border border-stone-200 p-3 bg-stone-50">
                                    <input name="tasks[{{ $task->id }}][title]" required maxlength="255"
                                        value="{{ old('tasks.'.$task->id.'.title', $task->title) }}" class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs font-semibold">
                                    <textarea name="tasks[{{ $task->id }}][description]" rows="2" maxlength="4000" placeholder="Detail pekerjaan"
                                        class="mt-2 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('tasks.'.$task->id.'.description', $task->description) }}</textarea>
                                    <div class="grid sm:grid-cols-3 gap-2 mt-2">
                                        <select name="tasks[{{ $task->id }}][assignee_user_id]" required class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                            <option value="">penerima…</option>
                                            @foreach($members as $member)
                                                <option value="{{ $member->id }}" @selected((int) old('tasks.'.$task->id.'.assignee_user_id', $task->assignee_user_id) === $member->id)>{{ $member->displayName() }}</option>
                                            @endforeach
                                        </select>
                                        <select name="tasks[{{ $task->id }}][board_column_id]" required class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                            <option value="">kolom Kanban…</option>
                                            @foreach($columns->reject(fn($column) => $column->isDone()) as $column)
                                                <option value="{{ $column->id }}" @selected((int) old('tasks.'.$task->id.'.board_column_id', $task->board_column_id) === $column->id)>{{ $column->board->name }} › {{ $column->name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="date" name="tasks[{{ $task->id }}][due_date]" required
                                            min="{{ $okr->start_date->toDateString() }}" max="{{ $okr->end_date->toDateString() }}"
                                            value="{{ old('tasks.'.$task->id.'.due_date', $task->due_date?->toDateString()) }}" class="px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <button class="w-full px-5 py-3 text-sm bg-stone-800 text-white rounded-xl hover:bg-stone-900 font-bold">Simpan Perubahan Pratinjau</button>
</form>

<div class="mt-5 bg-white rounded-2xl border-2 border-emerald-200 p-5">
    <p class="text-sm font-bold text-emerald-900">Konfirmasi eksekusi</p>
    <p class="text-xs text-emerald-700 mt-1">Tindakan ini membuat {{ $allTotal }} kartu Kanban dan mengaktifkan OKR. Pastikan perubahan di atas sudah disimpan.</p>
    <div class="flex flex-wrap gap-2 mt-4">
        <form method="POST" action="{{ route('okr.approve', $okr) }}" onsubmit="return confirm('Setujui OKR dan buat {{ $allTotal }} kartu Kanban sekarang?')">
            @csrf
            <button class="px-5 py-2.5 text-sm bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 font-bold">Ya, Setujui & Buat Semua Kartu</button>
        </form>
        <form method="POST" action="{{ route('okr.destroy', $okr) }}" onsubmit="return confirm('Hapus draf OKR ini?')">
            @csrf @method('DELETE')
            <button class="px-4 py-2.5 text-sm text-rose-600 hover:bg-rose-50 rounded-xl">Hapus draf</button>
        </form>
    </div>
</div>
@else
    <div class="space-y-5">
        @foreach($okr->objectives as $oi => $objective)
            @php
                $objectiveTasks = $objective->keyResults->flatMap(fn($kr) => $kr->tasks);
                $objectiveDone = $objectiveTasks->filter(fn($task) => $task->isCompleted())->count();
                $objectiveTotal = $objectiveTasks->count();
                $objectiveProgress = $objectiveTotal ? (int) round(($objectiveDone / $objectiveTotal) * 100) : 0;
            @endphp
            <section class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="p-5 bg-stone-50 border-b border-stone-200">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-bold tracking-wider text-red-600 uppercase">Objective {{ $oi + 1 }}</p>
                            <h4 class="font-bold text-stone-900 mt-1">{{ $objective->title }}</h4>
                            @if($objective->description)<p class="text-xs text-stone-600 mt-1">{{ $objective->description }}</p>@endif
                            @if($objective->owner)<p class="text-[11px] text-stone-400 mt-2">Pemilik: {{ $objective->owner->displayName() }}</p>@endif
                        </div>
                        <div class="w-40">
                            <div class="flex justify-between text-[11px] mb-1"><span>{{ $objectiveDone }}/{{ $objectiveTotal }}</span><b>{{ $objectiveProgress }}%</b></div>
                            <div class="h-2 bg-stone-200 rounded-full overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $objectiveProgress }}%"></div></div>
                        </div>
                    </div>
                </div>

                <div class="p-5 space-y-5">
                    @foreach($objective->keyResults as $ki => $kr)
                        @php
                            $krDone = $kr->tasks->filter(fn($task) => $task->isCompleted())->count();
                            $krTotal = $kr->tasks->count();
                            $krProgress = $krTotal ? (int) round(($krDone / $krTotal) * 100) : 0;
                        @endphp
                        <div class="border-l-4 border-indigo-300 pl-4">
                            <div class="flex flex-wrap justify-between gap-2">
                                <div>
                                    <p class="text-[10px] font-bold text-indigo-700 uppercase">Key Result {{ $oi + 1 }}.{{ $ki + 1 }}</p>
                                    <p class="text-sm font-semibold text-stone-900 mt-1">{{ $kr->title }}</p>
                                    <p class="text-xs text-stone-500 mt-1">{{ $kr->metric ?: 'Metrik' }}: <b>{{ $kr->target ?: '—' }}</b> · tenggat {{ $kr->due_date?->format('d M Y') }}</p>
                                </div>
                                <span class="text-xs font-bold {{ $krProgress === 100 ? 'text-emerald-600' : 'text-stone-600' }}">{{ $krProgress }}%</span>
                            </div>
                            <div class="grid md:grid-cols-2 gap-2 mt-3">
                                @foreach($kr->tasks as $task)
                                    <div class="rounded-xl border p-3 {{ $task->isCompleted() ? 'border-emerald-200 bg-emerald-50' : 'border-stone-200 bg-white' }}">
                                        <div class="flex items-start gap-2">
                                            <span class="mt-0.5">{{ $task->isCompleted() ? '✅' : '⬜' }}</span>
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold text-stone-800">{{ $task->title }}</p>
                                                <p class="text-[11px] text-stone-500 mt-1">{{ $task->assignee?->displayName() ?: 'Tanpa penerima' }} · {{ $task->due_date?->format('d M') }}</p>
                                                @if($task->card && $task->card->column?->board)
                                                    <a href="{{ route('kanban.show', $task->card->column->board) }}" class="inline-block mt-2 text-[11px] font-semibold text-indigo-600 hover:underline">{{ $task->card->column->board->name }} › {{ $task->card->column->name }}</a>
                                                @else
                                                    <span class="inline-block mt-2 text-[11px] text-rose-500">Kartu tidak tersedia</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
@endsection
