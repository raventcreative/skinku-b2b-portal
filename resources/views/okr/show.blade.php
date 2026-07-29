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
    $today = now()->startOfDay();
    $minDue = $today->betweenIncluded($okr->start_date, $okr->end_date)
        ? $today->toDateString()
        : $okr->start_date->toDateString();
@endphp

<div class="max-w-6xl mx-auto">
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
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4">
            <p class="text-sm font-bold text-amber-900">Pratinjau OKR — belum ada kartu yang dibuat</p>
            <p class="text-xs text-amber-800 mt-1">AI sudah memilih penanggung jawab, PIC, tenggat, dan kolom Kanban. Cukup periksa ringkasannya, lalu setujui.</p>
        </div>
    @endif

    @if($okr->isDraft() && $canManage)
        <div class="bg-white rounded-xl border border-stone-200 p-4 mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-bold text-stone-900">{{ $allTotal }} pekerjaan siap dibuat menjadi kartu Kanban</p>
                    <p class="text-xs text-stone-500 mt-1">Tidak perlu mengatur ulang jika pembagian di bawah sudah sesuai.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick="toggleOkrEditor()" class="px-3 py-2 text-xs font-semibold text-stone-600 border border-stone-300 rounded-lg hover:bg-stone-50">
                        Edit manual
                    </button>
                    <form method="POST" action="{{ route('okr.approve', $okr) }}" onsubmit="return confirm('Setujui OKR dan buat {{ $allTotal }} kartu Kanban sekarang?')">
                        @csrf
                        <button class="px-4 py-2 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-bold">Setujui & Buat {{ $allTotal }} Kartu</button>
                    </form>
                </div>
            </div>
            <details class="mt-3 pt-3 border-t border-stone-100">
                <summary class="text-xs font-semibold text-stone-500 cursor-pointer">Lihat arahan awal</summary>
                <p class="mt-2 text-xs text-stone-600 whitespace-pre-line">{{ $okr->direction }}</p>
            </details>
        </div>
    @endif

    <div class="space-y-4">
        @foreach($okr->objectives as $oi => $objective)
            @php
                $objectiveTasks = $objective->keyResults->flatMap(fn($kr) => $kr->tasks);
                $objectiveDone = $objectiveTasks->filter(fn($task) => $task->isCompleted())->count();
                $objectiveTotal = $objectiveTasks->count();
                $objectiveProgress = $objectiveTotal ? (int) round(($objectiveDone / $objectiveTotal) * 100) : 0;
            @endphp
            <section class="bg-white rounded-xl border border-stone-200 overflow-hidden">
                <div class="px-4 py-3 bg-stone-50 border-b border-stone-200">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[10px] font-bold tracking-wider text-red-600 uppercase">Objective {{ $oi + 1 }}</p>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-indigo-100 text-indigo-700">{{ $objective->specialistLabel() }} AI</span>
                                <span class="text-[11px] text-stone-500">Penanggung jawab: <b class="{{ $objective->owner ? 'text-stone-700' : 'text-rose-600' }}">{{ $objective->owner?->displayName() ?: 'belum terisi' }}</b></span>
                            </div>
                            <h4 class="font-bold text-stone-900 mt-1">{{ $objective->title }}</h4>
                            @if($objective->description)<p class="text-xs text-stone-600 mt-1 max-w-3xl">{{ $objective->description }}</p>@endif
                        </div>
                        @if(!$okr->isDraft())
                            <div class="w-36">
                                <div class="flex justify-between text-[11px] mb-1"><span>{{ $objectiveDone }}/{{ $objectiveTotal }}</span><b>{{ $objectiveProgress }}%</b></div>
                                <div class="h-2 bg-stone-200 rounded-full overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $objectiveProgress }}%"></div></div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="p-4 space-y-4">
                    @foreach($objective->keyResults as $ki => $kr)
                        @php
                            $krDone = $kr->tasks->filter(fn($task) => $task->isCompleted())->count();
                            $krTotal = $kr->tasks->count();
                            $krProgress = $krTotal ? (int) round(($krDone / $krTotal) * 100) : 0;
                        @endphp
                        <div class="border-l-4 border-indigo-300 pl-3">
                            <div class="flex flex-wrap justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold text-indigo-700 uppercase">Key Result {{ $oi + 1 }}.{{ $ki + 1 }}</p>
                                    <p class="text-sm font-semibold text-stone-900 mt-1">{{ $kr->title }}</p>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-[11px] text-stone-500">
                                        <span>Metrik: <b class="text-stone-700">{{ $kr->metric ?: 'belum terisi' }}</b></span>
                                        <span>Target: <b class="text-stone-700">{{ $kr->target ?: 'belum terisi' }}</b></span>
                                        <span>Penanggung jawab: <b class="{{ $kr->owner ? 'text-stone-700' : 'text-rose-600' }}">{{ $kr->owner?->displayName() ?: 'belum terisi' }}</b></span>
                                        <span>Tenggat: <b class="text-stone-700">{{ $kr->due_date?->format('d M Y') ?: 'belum terisi' }}</b></span>
                                    </div>
                                </div>
                                @if(!$okr->isDraft())
                                    <span class="text-xs font-bold {{ $krProgress === 100 ? 'text-emerald-600' : 'text-stone-600' }}">{{ $krProgress }}%</span>
                                @endif
                            </div>

                            <div class="grid md:grid-cols-2 gap-2 mt-3">
                                @foreach($kr->tasks as $task)
                                    <article class="rounded-lg border p-3 {{ $task->isCompleted() ? 'border-emerald-200 bg-emerald-50' : 'border-stone-200 bg-stone-50/60' }}">
                                        <div class="flex items-start gap-2">
                                            <span class="mt-0.5 text-xs">{{ $task->isCompleted() ? '✓' : '○' }}</span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-xs font-bold text-stone-800">{{ $task->title }}</p>
                                                <p class="text-[11px] leading-relaxed text-stone-600 mt-1">{{ $task->description ?: 'Detail pekerjaan belum terisi.' }}</p>
                                                <div class="flex flex-wrap gap-x-3 gap-y-1 mt-2 text-[10px] text-stone-500">
                                                    <span>PIC: <b class="{{ $task->assignee ? 'text-stone-700' : 'text-rose-600' }}">{{ $task->assignee?->displayName() ?: 'belum terisi' }}</b></span>
                                                    <span>Tenggat: <b class="text-stone-700">{{ $task->due_date?->format('d M Y') ?: 'belum terisi' }}</b></span>
                                                </div>
                                                @if($okr->isDraft())
                                                    <p class="mt-1.5 text-[10px] {{ $task->column?->board ? 'text-indigo-600' : 'text-rose-600' }}">
                                                        Kanban: {{ $task->column?->board?->name ? $task->column->board->name.' › '.$task->column->name : 'kolom belum terisi' }}
                                                    </p>
                                                @elseif($task->card && $task->card->column?->board)
                                                    <a href="{{ route('kanban.show', $task->card->column->board) }}" class="inline-block mt-1.5 text-[10px] font-semibold text-indigo-600 hover:underline">{{ $task->card->column->board->name }} › {{ $task->card->column->name }}</a>
                                                @else
                                                    <span class="inline-block mt-1.5 text-[10px] text-rose-500">Kartu tidak tersedia</span>
                                                @endif
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    @if($okr->isDraft() && $canManage)
        <div id="okrEditPanel" class="{{ $errors->any() ? '' : 'hidden' }} mt-5">
            <div class="flex items-center justify-between gap-3 mb-3">
                <div>
                    <h4 class="text-sm font-bold text-stone-900">Koreksi manual</h4>
                    <p class="text-xs text-stone-500 mt-0.5">Gunakan hanya jika hasil otomatis perlu diubah.</p>
                </div>
                <button type="button" onclick="toggleOkrEditor()" class="text-xs text-stone-500 hover:text-stone-800">Tutup</button>
            </div>

            <form method="POST" action="{{ route('okr.update', $okr) }}" class="space-y-4">
                @csrf @method('PUT')
                <div class="bg-white rounded-xl border border-stone-200 p-4 grid md:grid-cols-2 gap-3">
                    <label>
                        <span class="text-xs font-semibold text-stone-700">Nama OKR</span>
                        <input name="name" required maxlength="255" value="{{ old('name', $okr->name) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-semibold">
                    </label>
                    <label>
                        <span class="text-xs font-semibold text-stone-700">Arahan awal</span>
                        <textarea name="direction" required rows="2" maxlength="5000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('direction', $okr->direction) }}</textarea>
                    </label>
                </div>

                @foreach($okr->objectives as $oi => $objective)
                    <section class="bg-white rounded-xl border border-stone-200 p-4 space-y-4">
                        <div class="grid md:grid-cols-[7rem_1fr_13rem] gap-2">
                            <label>
                                <span class="text-[10px] font-bold text-red-600 uppercase">Divisi AI</span>
                                <select name="objectives[{{ $objective->id }}][specialist]" class="mt-1 block w-full px-2 py-2 border border-stone-300 rounded-lg text-xs">
                                    @foreach(\App\Models\OkrObjective::SPECIALISTS as $key => $label)
                                        <option value="{{ $key }}" @selected(old('objectives.'.$objective->id.'.specialist', $objective->specialist) === $key)>{{ $label }} AI</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span class="text-[10px] font-bold text-stone-600 uppercase">Objective {{ $oi + 1 }}</span>
                                <input name="objectives[{{ $objective->id }}][title]" required maxlength="255" value="{{ old('objectives.'.$objective->id.'.title', $objective->title) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs font-bold">
                            </label>
                            <label>
                                <span class="text-[10px] font-bold text-stone-600 uppercase">Penanggung jawab</span>
                                <select name="objectives[{{ $objective->id }}][owner_user_id]" class="mt-1 block w-full px-2 py-2 border border-stone-300 rounded-lg text-xs">
                                    <option value="">Belum ditentukan</option>
                                    @foreach($members as $member)
                                        <option value="{{ $member->id }}" @selected((int) old('objectives.'.$objective->id.'.owner_user_id', $objective->owner_user_id) === $member->id)>{{ $member->displayName() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="md:col-span-3">
                                <span class="text-[10px] font-bold text-stone-600 uppercase">Penjelasan Objective</span>
                                <textarea name="objectives[{{ $objective->id }}][description]" rows="2" maxlength="4000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('objectives.'.$objective->id.'.description', $objective->description) }}</textarea>
                            </label>
                        </div>

                        @foreach($objective->keyResults as $ki => $kr)
                            <div class="border-l-4 border-indigo-300 pl-3 space-y-2">
                                <label class="block">
                                    <span class="text-[10px] font-bold text-indigo-700 uppercase">Key Result {{ $oi + 1 }}.{{ $ki + 1 }}</span>
                                    <input name="key_results[{{ $kr->id }}][title]" required maxlength="255" value="{{ old('key_results.'.$kr->id.'.title', $kr->title) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs font-semibold">
                                </label>
                                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                                    <label><span class="text-[10px] text-stone-500">Metrik pengukuran</span><input name="key_results[{{ $kr->id }}][metric]" maxlength="255" value="{{ old('key_results.'.$kr->id.'.metric', $kr->metric) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                    <label><span class="text-[10px] text-stone-500">Target angka</span><input name="key_results[{{ $kr->id }}][target]" maxlength="255" value="{{ old('key_results.'.$kr->id.'.target', $kr->target) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                    <label>
                                        <span class="text-[10px] text-stone-500">Penanggung jawab KR</span>
                                        <select name="key_results[{{ $kr->id }}][owner_user_id]" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                            <option value="">Belum ditentukan</option>
                                            @foreach($members as $member)
                                                <option value="{{ $member->id }}" @selected((int) old('key_results.'.$kr->id.'.owner_user_id', $kr->owner_user_id) === $member->id)>{{ $member->displayName() }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label><span class="text-[10px] text-stone-500">Tenggat KR</span><input type="date" name="key_results[{{ $kr->id }}][due_date]" required min="{{ $minDue }}" max="{{ $okr->end_date->toDateString() }}" value="{{ old('key_results.'.$kr->id.'.due_date', $kr->due_date?->toDateString()) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                </div>

                                <div class="grid lg:grid-cols-2 gap-2">
                                    @foreach($kr->tasks as $task)
                                        <div class="rounded-lg border border-stone-200 p-3 bg-stone-50">
                                            <label class="block"><span class="text-[10px] text-stone-500">Nama pekerjaan</span><input name="tasks[{{ $task->id }}][title]" required maxlength="255" value="{{ old('tasks.'.$task->id.'.title', $task->title) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs font-semibold"></label>
                                            <label class="block mt-2"><span class="text-[10px] text-stone-500">Detail pekerjaan & hasil yang harus diserahkan</span><textarea name="tasks[{{ $task->id }}][description]" rows="3" maxlength="4000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('tasks.'.$task->id.'.description', $task->description) }}</textarea></label>
                                            <div class="grid sm:grid-cols-2 gap-2 mt-2">
                                                <label>
                                                    <span class="text-[10px] text-stone-500">PIC pekerjaan</span>
                                                    <select name="tasks[{{ $task->id }}][assignee_user_id]" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                                        <option value="">Pilih PIC</option>
                                                        @foreach($members as $member)
                                                            <option value="{{ $member->id }}" @selected((int) old('tasks.'.$task->id.'.assignee_user_id', $task->assignee_user_id) === $member->id)>{{ $member->displayName() }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label><span class="text-[10px] text-stone-500">Tenggat pekerjaan</span><input type="date" name="tasks[{{ $task->id }}][due_date]" required min="{{ $minDue }}" max="{{ $okr->end_date->toDateString() }}" value="{{ old('tasks.'.$task->id.'.due_date', $task->due_date?->toDateString()) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                                <label class="sm:col-span-2">
                                                    <span class="text-[10px] text-stone-500">Kolom Kanban tujuan</span>
                                                    <select name="tasks[{{ $task->id }}][board_column_id]" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                                        <option value="">Pilih kolom</option>
                                                        @foreach($columns->reject(fn($column) => $column->isDone()) as $column)
                                                            <option value="{{ $column->id }}" @selected((int) old('tasks.'.$task->id.'.board_column_id', $task->board_column_id) === $column->id)>{{ $column->board->name }} › {{ $column->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach

                <button class="w-full px-5 py-3 text-sm bg-stone-800 text-white rounded-xl hover:bg-stone-900 font-bold">Simpan Koreksi</button>
            </form>
        </div>

        <div class="flex justify-end mt-4">
            <form method="POST" action="{{ route('okr.destroy', $okr) }}" onsubmit="return confirm('Hapus draf OKR ini?')">
                @csrf @method('DELETE')
                <button class="px-3 py-2 text-xs text-rose-600 hover:bg-rose-50 rounded-lg">Hapus draf</button>
            </form>
        </div>

        <script>
            function toggleOkrEditor() {
                const panel = document.getElementById('okrEditPanel');
                panel.classList.toggle('hidden');
                if (!panel.classList.contains('hidden')) {
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        </script>
    @endif
</div>
@endsection
