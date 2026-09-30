@extends('layouts.app')
@section('title', 'Campaign KOL')
@section('heading', 'Campaign KOL')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $rc = fn ($n) => $n >= 1_000_000 ? round($n / 1_000_000, 1).' jt' : number_format($n, 0, ',', '.');
    $sTone = ['planned' => 'bg-sky-100 text-sky-700', 'active' => 'bg-red-100 text-red-700', 'done' => 'bg-emerald-100 text-emerald-700'];
@endphp

<div class="w-full space-y-4">
    <a href="{{ route('kol-deals.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Daftar Deal</a>

    {{-- Daftar campaign (kartu-box + rollup) --}}
    <div class="grid sm:grid-cols-2 gap-3">
        @forelse($campaigns as $c)
            @php
                $a = $agg[$c->id] ?? ['deals' => 0, 'cost' => 0, 'views' => 0];
                $prog = ($c->target_views && $c->target_views > 0) ? min(100, round($a['views'] / $c->target_views * 100)) : null;
            @endphp
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-stone-800">{{ $c->name }}</p>
                        <p class="text-[11px] text-stone-400">{{ $platformLabels[$c->platform] ?? $c->platform }}{{ $c->start_date ? ' · '.$c->start_date->format('d M') : '' }}{{ $c->end_date ? ' – '.$c->end_date->format('d M Y') : '' }}</p>
                    </div>
                    <span class="text-[10px] px-2 py-0.5 rounded-full shrink-0 {{ $sTone[$c->status] ?? 'bg-stone-100 text-stone-500' }}">{{ $statusLabels[$c->status] ?? $c->status }}</span>
                </div>

                <div class="grid grid-cols-3 gap-2 mt-3 text-center">
                    <div><p class="text-[10px] text-stone-400 uppercase">Deal</p><p class="text-lg font-bold text-stone-800 tabular-nums">{{ $a['deals'] }}</p></div>
                    <div><p class="text-[10px] text-stone-400 uppercase">Biaya</p><p class="text-sm font-bold text-stone-800 tabular-nums mt-1">{{ $rc($a['cost']) }}</p></div>
                    <div><p class="text-[10px] text-stone-400 uppercase">Views</p><p class="text-sm font-bold text-stone-800 tabular-nums mt-1">{{ $rc($a['views']) }}</p></div>
                </div>

                @if($prog !== null)
                    <div class="mt-3">
                        <div class="flex justify-between text-[10px] text-stone-500 mb-1">
                            <span>Target views {{ $rc($c->target_views) }}</span>
                            <span class="{{ $prog >= 100 ? 'text-emerald-600 font-semibold' : '' }}">{{ $prog }}%</span>
                        </div>
                        <div class="h-1.5 bg-stone-100 rounded-full overflow-hidden"><div class="h-full {{ $prog >= 100 ? 'bg-emerald-500' : 'bg-red-500' }}" style="width: {{ $prog }}%"></div></div>
                    </div>
                @endif
                @if($c->target_gmv)
                    <p class="text-[11px] text-stone-500 mt-2">Target GMV: {{ $rp($c->target_gmv) }}</p>
                @endif

                <div class="flex items-center gap-3 mt-3 pt-2 border-t border-stone-100">
                    <a href="{{ route('kol-campaigns.index', ['edit' => $c->id]) }}#form" aria-label="Edit campaign {{ $c->name }}" title="Edit campaign" class="inline-flex items-center justify-center w-8 h-8 text-stone-600 border border-stone-200 rounded-lg hover:bg-stone-100"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 5.5 3 3M4 20l4.5-.9L19.3 8.3a2.1 2.1 0 0 0-3-3L5.5 16.1 4 20Z"/></svg></a>
                    <form method="POST" action="{{ route('kol-campaigns.destroy', $c) }}" onsubmit="return confirm('Hapus campaign {{ $c->name }}? Deal tertaut akan dilepas (tidak ikut terhapus).')">
                        @csrf @method('DELETE')
                        <button aria-label="Hapus campaign {{ $c->name }}" title="Hapus campaign" class="inline-flex items-center justify-center w-8 h-8 text-rose-600 border border-rose-100 rounded-lg hover:bg-rose-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg></button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-400 sm:col-span-2">Belum ada campaign. Buat di bawah.</p>
        @endforelse
    </div>

    {{-- Form buat / edit campaign --}}
    <div id="form" class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-bold text-stone-800 mb-3">{{ $editing ? 'Edit campaign' : 'Buat campaign baru' }}</p>
        <form method="POST" action="{{ $editing ? route('kol-campaigns.update', $editing) : route('kol-campaigns.store') }}" class="grid sm:grid-cols-2 gap-3 text-sm">
            @csrf
            @if($editing) @method('PATCH') @endif
            <label class="text-[11px] font-semibold text-stone-500 sm:col-span-2">Nama campaign
                <input name="name" required maxlength="255" value="{{ old('name', $editing->name ?? '') }}" placeholder="mis. Ramadan Glow 2026" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Platform
                <select name="platform" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm bg-white">
                    @foreach($platformLabels as $val => $lbl)<option value="{{ $val }}" @selected(old('platform', $editing->platform ?? 'multi') === $val)>{{ $lbl }}</option>@endforeach
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Status
                <select name="status" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm bg-white">
                    @foreach($statusLabels as $val => $lbl)<option value="{{ $val }}" @selected(old('status', $editing->status ?? 'active') === $val)>{{ $lbl }}</option>@endforeach
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Periode mulai
                <input type="date" name="start_date" value="{{ old('start_date', optional($editing->start_date ?? null)->format('Y-m-d')) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Periode selesai
                <input type="date" name="end_date" value="{{ old('end_date', optional($editing->end_date ?? null)->format('Y-m-d')) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Target views
                <input type="number" name="target_views" min="0" step="1000" value="{{ old('target_views', $editing->target_views ?? '') }}" placeholder="300000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Target GMV (Rp)
                <input type="number" name="target_gmv" min="0" step="100000" value="{{ old('target_gmv', $editing->target_gmv ?? '') }}" placeholder="15000000" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500 sm:col-span-2">Catatan
                <textarea name="notes" rows="2" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('notes', $editing->notes ?? '') }}</textarea>
            </label>
            <div class="sm:col-span-2 flex items-center gap-3">
                <button class="inline-flex items-center gap-2 px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $editing ? 'M4 20h4l10.8-10.8a2.1 2.1 0 0 0-3-3L5 17v3Z' : 'M12 5v14m-7-7h14' }}"/></svg>{{ $editing ? 'Simpan Perubahan' : 'Buat Campaign' }}</button>
                @if($editing)<a href="{{ route('kol-campaigns.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs text-stone-600 hover:bg-stone-100"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>Batal edit</a>@endif
            </div>
        </form>
    </div>
</div>
@endsection
