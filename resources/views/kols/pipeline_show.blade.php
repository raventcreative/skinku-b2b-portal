@extends('layouts.app')
@section('title', 'Detail Kartu Pipeline')
@section('heading', 'Detail Kartu Pipeline')

@section('content')
@php
    $u = auth()->user();
    $canManage = $u->canDo('kol.pipeline.manage');
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $isAff = $card->track === \App\Models\KolPipelineCard::TRACK_AFFILIATE;
@endphp

<div class="max-w-4xl space-y-4">
    <a href="{{ route('kol-pipeline.index', ['kind' => $card->track]) }}" class="text-xs text-stone-500 hover:text-stone-800">← Pipeline {{ $isAff ? 'Affiliate' : 'KOL' }}</a>

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- Header --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('kols.show', $card->kol_id) }}" class="text-lg font-bold text-indigo-600 hover:underline">{{ '@'.$card->kol->tiktok_username }}</a>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm">
                    <span class="text-[10px] uppercase tracking-wide text-stone-400">{{ $card->kol->level }}</span>
                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $isTerminal ? 'bg-stone-100 text-stone-500' : 'bg-indigo-100 text-indigo-700' }}">{{ $labels[$card->stage] ?? $card->stage }}</span>
                    <span class="text-xs text-stone-400">papan {{ $isAff ? 'Affiliate' : 'KOL' }}</span>
                </div>
            </div>
            @if($canManage && ! $isTerminal)
                <form method="POST" action="{{ route('kol-pipeline.follow-up', $card) }}">
                    @csrf
                    <button class="text-xs font-semibold text-indigo-600 hover:underline border border-indigo-200 rounded-lg px-3 py-1.5">+ Follow-up (jadwal +{{ \App\Models\KolPipelineCard::FOLLOW_UP_SLA_DAYS }} hari)</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Stat: next action, follow-up, rate ask→final + %turun --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Next action</p>
            @if($isTerminal)
                <p class="text-sm font-semibold text-stone-400 mt-1">✓ tahap akhir</p>
            @elseif($card->next_action)
                <p class="text-sm font-semibold text-stone-800 mt-1">{{ $card->next_action }}</p>
                <p class="text-[11px] {{ $card->next_action_at && $card->next_action_at->isPast() ? 'text-rose-500' : 'text-stone-400' }}">{{ $card->next_action_at?->format('d M Y') ?? '—' }}</p>
            @else
                <p class="text-sm font-semibold text-amber-600 mt-1">⚠ belum ada</p>
            @endif
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Follow-up</p>
            <p class="text-2xl font-bold {{ $card->followup_count >= \App\Models\KolPipelineCard::FOLLOW_UP_LIMIT ? 'text-amber-600' : 'text-stone-800' }} mt-1">{{ $card->followup_count }}×</p>
            <p class="text-[11px] text-stone-400">maks {{ \App\Models\KolPipelineCard::FOLLOW_UP_LIMIT }}× → parkir/drop</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Rate diminta → final</p>
            <p class="text-sm font-semibold text-stone-800 mt-1">{{ $card->ask_rate ? $rp($card->ask_rate) : '—' }} <span class="text-stone-300">→</span> {{ $card->final_rate ? $rp($card->final_rate) : '—' }}</p>
            @if($turun !== null)
                <p class="text-[11px] {{ $turun > 0 ? 'text-emerald-600' : ($turun < 0 ? 'text-rose-600' : 'text-stone-400') }}">{{ $turun > 0 ? 'turun '.rtrim(rtrim(number_format($turun, 1, ',', '.'), '0'), ',').'%' : ($turun < 0 ? 'naik '.rtrim(rtrim(number_format(abs($turun), 1, ',', '.'), '0'), ',').'%' : 'sama') }}</p>
            @endif
        </div>
    </div>

    @if($canManage)
        <div class="grid lg:grid-cols-2 gap-4">
            {{-- Edit rate, next action, catatan nego --}}
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <p class="text-sm font-semibold text-stone-700 mb-3">Ubah negosiasi</p>
                <form method="POST" action="{{ route('kol-pipeline.update', $card) }}" class="space-y-3 text-sm">
                    @csrf @method('PATCH')
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Rate diminta (Rp)</span><input type="number" name="ask_rate" min="0" value="{{ old('ask_rate', $card->ask_rate) }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Rate final (Rp)</span><input type="number" name="final_rate" min="0" value="{{ old('final_rate', $card->final_rate) }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Next action</span><input name="next_action" maxlength="255" value="{{ old('next_action', $card->next_action) }}" @if(! $isTerminal) required @endif class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Tanggal</span><input type="date" name="next_action_at" value="{{ old('next_action_at', optional($card->next_action_at)->toDateString()) }}" @if(! $isTerminal) required @endif class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                    </div>
                    <label class="block"><span class="text-xs font-semibold text-stone-600">Catatan nego</span><textarea name="negotiation_notes" rows="3" maxlength="5000" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg" placeholder="mis. minta barter + komisi, rate terlalu tinggi…">{{ old('negotiation_notes', $card->negotiation_notes) }}</textarea></label>
                    <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan</button>
                </form>
            </div>

            {{-- Pindah tahap --}}
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <p class="text-sm font-semibold text-stone-700 mb-3">Pindah tahap</p>
                <form method="POST" action="{{ route('kol-pipeline.stage', $card) }}" class="space-y-3 text-sm">
                    @csrf @method('PATCH')
                    <label class="block"><span class="text-xs font-semibold text-stone-600">Tahap</span>
                        <select name="stage" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                            @foreach($labels as $val => $lbl)<option value="{{ $val }}" @selected($val === $card->stage)>{{ $lbl }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block"><span class="text-xs font-semibold text-stone-600">Next action (untuk tahap aktif)</span><input name="next_action" maxlength="255" value="{{ $card->next_action }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                    <div class="flex items-center gap-2">
                        <input type="date" name="next_action_at" value="{{ optional($card->next_action_at)->toDateString() ?: now()->toDateString() }}" class="flex-1 px-3 py-2 border border-stone-300 rounded-lg">
                        <button class="px-4 py-2 bg-stone-700 hover:bg-stone-800 text-white text-sm font-semibold rounded-lg">Pindah</button>
                    </div>
                    <p class="text-[11px] text-stone-400">Tahap akhir ({{ implode(', ', array_map(fn ($s) => $labels[$s] ?? $s, array_values(array_intersect(\App\Models\KolPipelineCard::TERMINAL_STAGES, array_keys($labels))))) }}) mengosongkan next action.</p>
                </form>
            </div>
        </div>

        @if($card->note)
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs font-semibold text-stone-600 mb-1">Catatan awal</p>
                <p class="text-sm text-stone-600 whitespace-pre-line">{{ $card->note }}</p>
            </div>
        @endif
    @endif

    {{-- Riwayat tahap (dari kol_pipeline_events — dulu direkam tapi tak pernah ditampilkan) --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-semibold text-stone-700 mb-3">Riwayat tahap</p>
        @if($card->events->isEmpty())
            <p class="text-sm text-stone-400">Belum ada riwayat.</p>
        @else
            <ol class="space-y-3">
                @foreach($card->events as $e)
                    <li class="flex gap-3">
                        <div class="flex flex-col items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-red-500 mt-1.5"></span>
                            @if(! $loop->last)<span class="w-px flex-1 bg-stone-200"></span>@endif
                        </div>
                        <div class="pb-1">
                            <p class="text-sm text-stone-700">
                                @if($e->from_stage)
                                    {{ $labels[$e->from_stage] ?? $e->from_stage }} <span class="text-stone-300">→</span> <b>{{ $labels[$e->to_stage] ?? $e->to_stage }}</b>
                                @else
                                    Masuk pipeline di <b>{{ $labels[$e->to_stage] ?? $e->to_stage }}</b>
                                @endif
                            </p>
                            @if($e->note)<p class="text-xs text-stone-500 whitespace-pre-line mt-0.5">{{ $e->note }}</p>@endif
                            <p class="text-[10px] text-stone-400 mt-0.5">{{ $e->created_at?->format('d M Y H:i') }}{{ $e->creator ? ' · '.$e->creator->fullname : '' }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
@endsection
