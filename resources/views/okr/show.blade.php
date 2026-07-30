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
    $showEditors = $errors->any();
    $formatEvidence = function ($value) {
        if (is_bool($value)) return $value ? 'Ya' : 'Tidak';
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, is_float($value) && floor($value) !== $value ? 2 : 0, ',', '.');
        }
        return (string) $value;
    };
    $legacyDraft = $okr->isDraft() && (
        blank($okr->analysis_summary)
        || count($okr->analysis_evidence ?? []) < 3
        || $okr->objectives->contains(fn($objective) => blank($objective->ownerLabel()))
        || $okr->objectives->contains(fn($objective) => blank($objective->rationale))
        || $okr->objectives->flatMap(fn($objective) => $objective->keyResults)->contains(fn($kr) => blank($kr->ownerLabel()))
        || $okr->objectives->flatMap(fn($objective) => $objective->keyResults)->contains(fn($kr) => blank($kr->baseline) || blank($kr->target_gap))
        || $allTasks->contains(fn($task) => blank($task->description))
    );
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
        <div class="{{ $legacyDraft ? 'bg-rose-50 border-rose-200' : 'bg-amber-50 border-amber-200' }} border rounded-xl p-4 mb-4">
            @if($legacyDraft)
                <p class="text-sm font-bold text-rose-900">Draf lama terdeteksi</p>
                <p class="text-xs text-rose-700 mt-1">Draf ini tersimpan sebelum pengisian otomatis diperbaiki. Susun ulang draf agar owner, detail pekerjaan, PIC, tenggat, dan kolom Kanban dihitung ulang.</p>
            @else
                <p class="text-sm font-bold text-amber-900">Pratinjau OKR — belum ada kartu yang dibuat</p>
                <p class="text-xs text-amber-800 mt-1">AI sudah memilih penanggung jawab, PIC, tenggat, dan kolom Kanban. Cukup periksa ringkasannya, lalu setujui.</p>
            @endif
        </div>
    @endif

    @if($okr->isDraft() && $delegationWarnings !== [])
        <div class="bg-sky-50 border border-sky-200 rounded-xl p-4 mb-4">
            <p class="text-xs font-bold text-sky-900">Catatan pembagian tugas</p>
            <ul class="mt-1.5 space-y-1 text-xs text-sky-800 list-disc pl-4">
                @foreach($delegationWarnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($okr->isDraft())
        <section class="bg-white border border-stone-200 rounded-xl p-4 mb-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-bold text-stone-900">Checklist kelayakan pratinjau</p>
                    <p class="text-[11px] text-stone-500 mt-0.5">Approval ditahan sampai seluruh pemeriksaan faktual di bawah lulus.</p>
                </div>
                @php $acceptancePassed = collect($acceptanceChecklist)->every(fn($row) => $row['status'] === 'pass'); @endphp
                <span class="px-2 py-1 rounded-full text-[10px] font-bold {{ $acceptancePassed ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ $acceptancePassed ? 'Siap ditinjau' : 'Perlu koreksi' }}</span>
            </div>
            <div class="grid md:grid-cols-2 gap-2 mt-3">
                @foreach($acceptanceChecklist as $row)
                    <article class="rounded-lg border p-3 {{ $row['status'] === 'pass' ? 'border-emerald-100 bg-emerald-50/50' : 'border-rose-100 bg-rose-50/50' }}">
                        <p class="text-xs font-semibold {{ $row['status'] === 'pass' ? 'text-emerald-800' : 'text-rose-800' }}">{{ $row['status'] === 'pass' ? '✓' : '✕' }} {{ $row['label'] }}</p>
                        <p class="text-[10px] mt-1 {{ $row['status'] === 'pass' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $row['detail'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if($okr->analysis_summary)
        <section class="bg-white border border-stone-200 rounded-xl p-4 mb-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="text-sm font-bold text-stone-900">Dasar analisis AI</p>
                    <p class="text-[11px] text-stone-500 mt-0.5">Angka di bawah diambil ulang dari query sistem, bukan dipercaya dari jawaban model.</p>
                </div>
                <span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold">{{ count($okr->analysis_evidence ?? []) }} bukti terverifikasi</span>
            </div>
            <p class="mt-3 text-xs leading-5 text-stone-700">{{ $okr->analysis_summary }}</p>

            <div class="grid md:grid-cols-2 gap-2 mt-3">
                @foreach($okr->analysis_evidence ?? [] as $evidence)
                    <article class="rounded-lg border border-emerald-100 bg-emerald-50/50 p-3">
                        <div class="flex flex-wrap justify-between gap-2">
                            <p class="text-[10px] font-bold text-emerald-800">{{ $evidence['specialist'] ?? 'DATA' }} · {{ $evidence['label'] ?? $evidence['source_path'] }}</p>
                            <p class="text-xs font-bold text-stone-900">{{ $formatEvidence($evidence['value'] ?? null) }}</p>
                        </div>
                        <p class="text-[11px] leading-4 text-stone-600 mt-1">{{ $evidence['interpretation'] ?? '' }}</p>
                        <p class="text-[9px] text-stone-400 mt-1">Sumber: {{ $evidence['source_path'] ?? '—' }}{{ filled($evidence['period'] ?? null) ? ' · Periode '.$evidence['period'] : '' }}</p>
                    </article>
                @endforeach
            </div>

            <div class="grid md:grid-cols-2 gap-3 mt-3">
                <div class="rounded-lg bg-amber-50 border border-amber-100 p-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-amber-800">Asumsi / data yang belum tersedia</p>
                    @if(($okr->analysis_assumptions ?? []) === [])
                        <p class="text-[11px] text-amber-700 mt-1">AI tidak menandai asumsi tambahan.</p>
                    @else
                        <ul class="mt-1.5 space-y-1 text-[11px] text-amber-800 list-disc pl-4">
                            @foreach($okr->analysis_assumptions as $assumption)<li>{{ $assumption }}</li>@endforeach
                        </ul>
                    @endif
                </div>
                <div class="rounded-lg bg-rose-50 border border-rose-100 p-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-rose-800">Konflik dan keputusan BOD</p>
                    <div class="mt-1.5 space-y-2">
                        @forelse($okr->analysis_conflicts ?? [] as $conflict)
                            <div class="text-[11px] text-rose-800">
                                <p class="font-semibold">{{ $conflict['issue'] }}</p>
                                <p>Dampak: {{ $conflict['impact'] }}</p>
                                <p>Keputusan: {{ $conflict['decision_required'] }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-rose-700">Tidak ada konflik yang ditandai.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <details class="mt-3 pt-3 border-t border-stone-100">
                <summary class="text-[11px] font-semibold text-stone-500 cursor-pointer">Lihat cakupan data yang benar-benar dibaca</summary>
                <div class="grid sm:grid-cols-3 gap-2 mt-2">
                    @foreach($okr->data_coverage ?? [] as $coverage)
                        <div class="rounded-lg border border-stone-200 p-2.5 text-[10px]">
                            <p class="font-bold text-stone-800">{{ $coverage['specialist'] }}</p>
                            <p class="text-stone-600 mt-1">Dibaca: {{ collect($coverage['sources'] ?? [])->map(fn($source) => str_replace('_', ' ', $source))->implode(', ') ?: 'tidak ada' }}</p>
                            @if(($coverage['closed'] ?? []) !== [])
                                <p class="text-rose-600 mt-1">Ditutup izin: {{ collect($coverage['closed'])->map(fn($source) => str_replace('_', ' ', $source))->implode(', ') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="text-[10px] text-stone-400 mt-2">AI membaca ringkasan analitis read-only, bukan menyalin seluruh baris transaksi mentah. Pendekatan ini menjaga prompt tetap fokus dan membuat angka sumber dapat diverifikasi.</p>
            </details>
        </section>
    @endif

    @if($okr->isDraft() && $canManage)
        <div class="bg-white rounded-xl border border-stone-200 p-4 mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-bold text-stone-900">{{ $allTotal }} pekerjaan dalam pratinjau</p>
                    <p class="text-xs text-stone-500 mt-1">Klik <b>Edit</b> pada bagian yang ingin dikoreksi. Perubahan dilakukan langsung di kartu tersebut.</p>
                </div>
                <form method="POST" action="{{ route('okr.approve', $okr) }}" onsubmit="return confirm('Setujui OKR dan buat {{ $allTotal }} kartu Kanban sekarang?')">
                    @csrf
                    <button @disabled($legacyDraft) class="px-4 py-2 text-xs text-white rounded-lg font-bold {{ $legacyDraft ? 'bg-stone-300 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700' }}">Setujui & Buat {{ $allTotal }} Kartu</button>
                </form>
            </div>
            <details class="mt-3 pt-3 border-t border-stone-100">
                <summary class="text-xs font-semibold text-stone-500 cursor-pointer">Lihat arahan awal</summary>
                <p class="mt-2 text-xs text-stone-600 whitespace-pre-line">{{ $okr->direction }}</p>
            </details>
        </div>
    @endif

    @if($okr->isDraft() && $canManage)
        <form id="okrInlineForm" method="POST" action="{{ route('okr.update', $okr) }}">
            @csrf @method('PUT')
            <input type="hidden" name="name" value="{{ old('name', $okr->name) }}">
            <textarea name="direction" class="hidden">{{ old('direction', $okr->direction) }}</textarea>
    @endif

    <div class="space-y-4">
        @foreach($okr->objectives as $oi => $objective)
            @php
                $objectiveTasks = $objective->keyResults->flatMap(fn($kr) => $kr->tasks);
                $objectiveDone = $objectiveTasks->filter(fn($task) => $task->isCompleted())->count();
                $objectiveTotal = $objectiveTasks->count();
                $objectiveProgress = $objectiveTotal ? (int) round(($objectiveDone / $objectiveTotal) * 100) : 0;
                $objectiveEditor = 'objective-editor-'.$objective->id;
            @endphp
            <section class="bg-white rounded-xl border border-stone-200 overflow-hidden">
                <div class="px-4 py-3 bg-stone-50 border-b border-stone-200">
                    <div id="{{ $objectiveEditor }}-view" class="{{ $showEditors ? 'hidden' : '' }} flex flex-wrap justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[10px] font-bold tracking-wider text-red-600 uppercase">Objective {{ $oi + 1 }}</p>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-indigo-100 text-indigo-700">{{ $objective->specialistLabel() }} AI</span>
                                <span class="text-[11px] text-stone-500">Penanggung jawab: <b class="{{ $objective->ownerLabel() ? 'text-stone-700' : 'text-rose-600' }}">{{ $objective->ownerLabel() ?: 'belum terisi' }}</b></span>
                            </div>
                            <h4 class="font-bold text-stone-900 mt-1">{{ $objective->title }}</h4>
                            @if($objective->description)<p class="text-xs text-stone-600 mt-1 max-w-3xl">{{ $objective->description }}</p>@endif
                            @if($objective->rationale)<p class="text-[11px] text-indigo-700 mt-1.5 max-w-3xl"><b>Alasan dipilih:</b> {{ $objective->rationale }}</p>@endif
                        </div>
                        @if($okr->isDraft() && $canManage)
                            <button type="button" onclick="toggleInlineEditor('{{ $objectiveEditor }}')" class="self-start px-2.5 py-1 text-[10px] font-semibold text-stone-600 border border-stone-300 rounded-lg hover:bg-white">Edit Objective</button>
                        @elseif(!$okr->isDraft())
                            <div class="w-36">
                                <div class="flex justify-between text-[11px] mb-1"><span>{{ $objectiveDone }}/{{ $objectiveTotal }}</span><b>{{ $objectiveProgress }}%</b></div>
                                <div class="h-2 bg-stone-200 rounded-full overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $objectiveProgress }}%"></div></div>
                            </div>
                        @endif
                    </div>

                    @if($okr->isDraft() && $canManage)
                        <div id="{{ $objectiveEditor }}-edit" class="{{ $showEditors ? '' : 'hidden' }} grid md:grid-cols-[7rem_1fr_13rem] gap-2">
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
                                <span class="text-[10px] font-bold text-stone-600 uppercase">Nama penanggung jawab</span>
                                <input name="objectives[{{ $objective->id }}][owner_name]" required maxlength="255" value="{{ old('objectives.'.$objective->id.'.owner_name', $objective->ownerLabel()) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">
                            </label>
                            <label class="md:col-span-3">
                                <span class="text-[10px] font-bold text-stone-600 uppercase">Penjelasan Objective</span>
                                <textarea name="objectives[{{ $objective->id }}][description]" rows="2" maxlength="4000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('objectives.'.$objective->id.'.description', $objective->description) }}</textarea>
                            </label>
                            <label class="md:col-span-3">
                                <span class="text-[10px] font-bold text-stone-600 uppercase">Alasan strategis dipilih</span>
                                <textarea name="objectives[{{ $objective->id }}][rationale]" required minlength="20" rows="2" maxlength="4000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('objectives.'.$objective->id.'.rationale', $objective->rationale) }}</textarea>
                            </label>
                            <button type="button" onclick="toggleInlineEditor('{{ $objectiveEditor }}')" class="md:col-span-3 justify-self-end text-[10px] text-stone-500 hover:text-stone-800">Selesai mengedit</button>
                        </div>
                    @endif
                </div>

                <div class="p-4 space-y-4">
                    @foreach($objective->keyResults as $ki => $kr)
                        @php
                            $krDone = $kr->tasks->filter(fn($task) => $task->isCompleted())->count();
                            $krTotal = $kr->tasks->count();
                            $krProgress = $krTotal ? (int) round(($krDone / $krTotal) * 100) : 0;
                            $krEditor = 'kr-editor-'.$kr->id;
                        @endphp
                        <div class="border-l-4 border-indigo-300 pl-3">
                            <div id="{{ $krEditor }}-view" class="{{ $showEditors ? 'hidden' : '' }} flex flex-wrap justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold text-indigo-700 uppercase">Key Result {{ $oi + 1 }}.{{ $ki + 1 }}</p>
                                    <p class="text-sm font-semibold text-stone-900 mt-1">{{ $kr->title }}</p>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-[11px] text-stone-500">
                                        <span>Metrik: <b class="text-stone-700">{{ $kr->metric ?: 'belum terisi' }}</b></span>
                                        <span>Target: <b class="text-stone-700">{{ $kr->target ?: 'belum terisi' }}</b></span>
                                        <span>Penanggung jawab: <b class="{{ $kr->ownerLabel() ? 'text-stone-700' : 'text-rose-600' }}">{{ $kr->ownerLabel() ?: 'belum terisi' }}</b></span>
                                        <span>Tenggat: <b class="text-stone-700">{{ $kr->due_date?->format('d M Y') ?: 'belum terisi' }}</b></span>
                                    </div>
                                    @if($kr->baseline)
                                        <div class="mt-1.5 text-[11px] leading-4">
                                            <p class="{{ $kr->baseline_status === 'actual' ? 'text-emerald-700' : 'text-amber-700' }}">
                                                <b>{{ $kr->baseline_status === 'actual' ? 'Baseline aktual' : ($kr->baseline_status === 'assumption' ? 'Asumsi baseline' : 'Perlu validasi') }}:</b>
                                                {{ $kr->baseline }}
                                                @if($kr->baseline_source)<span class="text-stone-400">({{ $kr->baseline_source }})</span>@endif
                                            </p>
                                            <p class="text-stone-600"><b>Gap ke target:</b> {{ $kr->target_gap }}</p>
                                        </div>
                                    @endif
                                </div>
                                @if($okr->isDraft() && $canManage)
                                    <button type="button" onclick="toggleInlineEditor('{{ $krEditor }}')" class="self-start px-2 py-1 text-[10px] font-semibold text-stone-500 hover:text-indigo-700">Edit KR</button>
                                @else
                                    <span class="text-xs font-bold {{ $krProgress === 100 ? 'text-emerald-600' : 'text-stone-600' }}">{{ $krProgress }}%</span>
                                @endif
                            </div>

                            @if($okr->isDraft() && $canManage)
                                <div id="{{ $krEditor }}-edit" class="{{ $showEditors ? '' : 'hidden' }} rounded-lg bg-indigo-50/60 p-3">
                                    <label class="block">
                                        <span class="text-[10px] font-bold text-indigo-700 uppercase">Key Result {{ $oi + 1 }}.{{ $ki + 1 }}</span>
                                        <input name="key_results[{{ $kr->id }}][title]" required maxlength="255" value="{{ old('key_results.'.$kr->id.'.title', $kr->title) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs font-semibold">
                                    </label>
                                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-2 mt-2">
                                        <label><span class="text-[10px] text-stone-500">Metrik pengukuran</span><input name="key_results[{{ $kr->id }}][metric]" maxlength="255" value="{{ old('key_results.'.$kr->id.'.metric', $kr->metric) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                        <label><span class="text-[10px] text-stone-500">Target angka</span><input name="key_results[{{ $kr->id }}][target]" maxlength="255" value="{{ old('key_results.'.$kr->id.'.target', $kr->target) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                        <label>
                                            <span class="text-[10px] text-stone-500">Penanggung jawab KR</span>
                                            <input name="key_results[{{ $kr->id }}][owner_name]" required maxlength="255" value="{{ old('key_results.'.$kr->id.'.owner_name', $kr->ownerLabel()) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                        </label>
                                        <label><span class="text-[10px] text-stone-500">Tenggat KR</span><input type="date" name="key_results[{{ $kr->id }}][due_date]" required min="{{ $minDue }}" max="{{ $okr->end_date->toDateString() }}" value="{{ old('key_results.'.$kr->id.'.due_date', $kr->due_date?->toDateString()) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                    </div>
                                    <input type="hidden" name="key_results[{{ $kr->id }}][baseline_status]" value="{{ old('key_results.'.$kr->id.'.baseline_status', $kr->baseline_status) }}">
                                    <input type="hidden" name="key_results[{{ $kr->id }}][baseline_source]" value="{{ old('key_results.'.$kr->id.'.baseline_source', $kr->baseline_source) }}">
                                    <div class="grid md:grid-cols-2 gap-2 mt-2">
                                        <label><span class="text-[10px] text-stone-500">Baseline / kebutuhan validasi</span><input name="key_results[{{ $kr->id }}][baseline]" required maxlength="255" value="{{ old('key_results.'.$kr->id.'.baseline', $kr->baseline) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                        <label><span class="text-[10px] text-stone-500">Gap menuju target</span><textarea name="key_results[{{ $kr->id }}][target_gap]" required minlength="20" rows="2" maxlength="2000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('key_results.'.$kr->id.'.target_gap', $kr->target_gap) }}</textarea></label>
                                    </div>
                                    <button type="button" onclick="toggleInlineEditor('{{ $krEditor }}')" class="block ml-auto mt-2 text-[10px] text-stone-500 hover:text-stone-800">Selesai mengedit</button>
                                </div>
                            @endif

                            <div class="grid md:grid-cols-2 gap-2 mt-3">
                                @foreach($kr->tasks as $task)
                                    @php($taskEditor = 'task-editor-'.$task->id)
                                    <article class="rounded-lg border p-3 {{ $task->isCompleted() ? 'border-emerald-200 bg-emerald-50' : 'border-stone-200 bg-stone-50/60' }}">
                                        <div id="{{ $taskEditor }}-view" class="{{ $showEditors ? 'hidden' : '' }} flex items-start gap-2">
                                            <span class="mt-0.5 text-xs">{{ $task->isCompleted() ? '✓' : '○' }}</span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-start justify-between gap-2">
                                                    <p class="text-xs font-bold text-stone-800">{{ $task->title }}</p>
                                                    @if($okr->isDraft() && $canManage)
                                                        <button type="button" onclick="toggleInlineEditor('{{ $taskEditor }}')" class="shrink-0 text-[10px] font-semibold text-stone-500 hover:text-indigo-700">Edit</button>
                                                    @endif
                                                </div>
                                                <p class="text-[11px] leading-relaxed mt-1 {{ $task->description ? 'text-stone-600' : 'font-semibold text-rose-600' }}">{{ $task->description ?: 'Detail pekerjaan belum terisi.' }}</p>
                                                <div class="flex flex-wrap gap-x-3 gap-y-1 mt-2 text-[10px] text-stone-500">
                                                    <span>PIC: <b class="{{ $task->assigneeLabel() ? 'text-stone-700' : 'text-rose-600' }}">{{ $task->assigneeLabel() ?: 'belum terisi' }}</b></span>
                                                    <span>Tenggat: <b class="text-stone-700">{{ $task->due_date?->format('d M Y') ?: 'belum terisi' }}</b></span>
                                                </div>
                                                @if($okr->isDraft())
                                                    <p class="mt-1.5 text-[10px] {{ $task->column?->board ? 'text-indigo-600' : 'text-rose-600' }}">Kanban: {{ $task->column?->board?->name ? $task->column->board->name.' › '.$task->column->name : 'kolom belum terisi' }}</p>
                                                @elseif($task->card && $task->card->column?->board)
                                                    <a href="{{ route('kanban.show', $task->card->column->board) }}" class="inline-block mt-1.5 text-[10px] font-semibold text-indigo-600 hover:underline">{{ $task->card->column->board->name }} › {{ $task->card->column->name }}</a>
                                                @else
                                                    <span class="inline-block mt-1.5 text-[10px] text-rose-500">Kartu tidak tersedia</span>
                                                @endif
                                            </div>
                                        </div>

                                        @if($okr->isDraft() && $canManage)
                                            <div id="{{ $taskEditor }}-edit" class="{{ $showEditors ? '' : 'hidden' }} space-y-2">
                                                <input id="task-assignee-name-{{ $task->id }}" type="hidden" name="tasks[{{ $task->id }}][assignee_name]" value="{{ old('tasks.'.$task->id.'.assignee_name', $task->assigneeLabel()) }}">
                                                <label class="block"><span class="text-[10px] text-stone-500">Nama pekerjaan</span><input name="tasks[{{ $task->id }}][title]" required maxlength="255" value="{{ old('tasks.'.$task->id.'.title', $task->title) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs font-semibold"></label>
                                                <label class="block"><span class="text-[10px] text-stone-500">Detail pekerjaan & hasil yang harus diserahkan</span><textarea name="tasks[{{ $task->id }}][description]" required rows="3" maxlength="4000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">{{ old('tasks.'.$task->id.'.description', $task->description) }}</textarea></label>
                                                <div class="grid sm:grid-cols-2 gap-2">
                                                    <label>
                                                        <span class="text-[10px] text-stone-500">PIC pekerjaan</span>
                                                        <select name="tasks[{{ $task->id }}][assignee_user_id]" required onchange="syncTaskColumn(this, {{ $task->id }})" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                                            <option value="">Pilih PIC</option>
                                                            @foreach($members as $member)
                                                                <option value="{{ $member->id }}" data-member-name="{{ $member->displayName() }}" @selected((int) old('tasks.'.$task->id.'.assignee_user_id', $task->assignee_user_id) === $member->id)>{{ $member->displayName() }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label><span class="text-[10px] text-stone-500">Tenggat pekerjaan</span><input type="date" name="tasks[{{ $task->id }}][due_date]" required min="{{ $minDue }}" max="{{ $okr->end_date->toDateString() }}" value="{{ old('tasks.'.$task->id.'.due_date', $task->due_date?->toDateString()) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs"></label>
                                                    <label class="sm:col-span-2">
                                                        <span class="text-[10px] text-stone-500">Kolom Kanban tujuan (otomatis mengikuti PIC)</span>
                                                        <select id="task-column-{{ $task->id }}" name="tasks[{{ $task->id }}][board_column_id]" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-xs">
                                                            <option value="">Pilih kolom</option>
                                                            @foreach($columns->reject(fn($column) => $column->isDone()) as $column)
                                                                <option value="{{ $column->id }}" data-column-name="{{ $column->name }}" data-board-id="{{ $column->board_id }}" @selected((int) old('tasks.'.$task->id.'.board_column_id', $task->board_column_id) === $column->id)>{{ $column->board->name }} › {{ $column->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                </div>
                                                <button type="button" onclick="toggleInlineEditor('{{ $taskEditor }}')" class="block ml-auto text-[10px] text-stone-500 hover:text-stone-800">Selesai mengedit</button>
                                            </div>
                                        @endif
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
            <div id="okrSaveBar" class="{{ $showEditors ? '' : 'hidden' }} sticky bottom-4 z-10 mt-4 p-3 bg-stone-900/95 rounded-xl shadow-lg flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-white">Simpan semua koreksi yang dilakukan langsung pada kartu.</p>
                <button class="px-4 py-2 text-xs bg-white text-stone-900 rounded-lg hover:bg-stone-100 font-bold">Simpan Perubahan</button>
            </div>
        </form>

        <div class="flex justify-end mt-4">
            <form method="POST" action="{{ route('okr.destroy', $okr) }}" onsubmit="return confirm('Hapus draf OKR ini?')">
                @csrf @method('DELETE')
                <button class="px-3 py-2 text-xs text-rose-600 hover:bg-rose-50 rounded-lg">{{ $legacyDraft ? 'Hapus draf lama' : 'Hapus draf' }}</button>
            </form>
        </div>

        <script>
            function toggleInlineEditor(id) {
                document.getElementById(id + '-view').classList.toggle('hidden');
                document.getElementById(id + '-edit').classList.toggle('hidden');
                document.getElementById('okrSaveBar').classList.remove('hidden');
            }

            function normaliseOkrName(value) {
                return value.toLocaleLowerCase('id-ID').replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
            }

            function syncTaskColumn(picSelect, taskId) {
                const selectedMemberName = picSelect.options[picSelect.selectedIndex]?.dataset.memberName || '';
                const memberName = normaliseOkrName(selectedMemberName);
                if (!memberName) return;
                document.getElementById('task-assignee-name-' + taskId).value = selectedMemberName;

                const tokens = memberName.split(' ').filter(token => token.length >= 3 && !['admin', 'super', 'skinku'].includes(token));
                const columnSelect = document.getElementById('task-column-' + taskId);
                const currentBoard = columnSelect.options[columnSelect.selectedIndex]?.dataset.boardId || '';
                let bestOption = null;
                let bestScore = 0;

                Array.from(columnSelect.options).forEach(option => {
                    const columnName = normaliseOkrName(option.dataset.columnName || '');
                    if (!columnName) return;
                    let score = columnName.includes(memberName) ? 100 : 0;
                    tokens.forEach(token => { if (columnName.includes(token)) score += 20; });
                    if (!score) return;
                    if (option.dataset.boardId === currentBoard) score += 5;
                    if (columnName.includes('to do') || columnName.includes('todo')) score += 5;
                    if (score > bestScore) {
                        bestOption = option;
                        bestScore = score;
                    }
                });

                if (bestOption) columnSelect.value = bestOption.value;
            }
        </script>
    @endif
</div>
@endsection
