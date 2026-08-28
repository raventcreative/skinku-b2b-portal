@extends('layouts.app')
@section('title', 'Pipeline KOL')
@section('heading', 'Pipeline KOL')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $isAff = $track === \App\Models\KolPipelineCard::TRACK_AFFILIATE;
@endphp

<div class="space-y-4">

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- Toggle papan: KOL scouting vs Affiliate pembinaan --}}
    <div class="flex gap-1 border-b border-stone-200">
        <a href="{{ route('kol-pipeline.index', ['kind' => 'kol']) }}" class="px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition {{ ! $isAff ? 'border-red-600 text-red-700' : 'border-transparent text-stone-400 hover:text-stone-600' }}">🔎 Scouting KOL <span class="text-[10px] px-1.5 py-0.5 rounded-full {{ ! $isAff ? 'bg-red-100 text-red-700' : 'bg-stone-100 text-stone-500' }}">{{ $countKol }}</span></a>
        <a href="{{ route('kol-pipeline.index', ['kind' => 'affiliate']) }}" class="px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition {{ $isAff ? 'border-red-600 text-red-700' : 'border-transparent text-stone-400 hover:text-stone-600' }}">🤝 Pembinaan Affiliate <span class="text-[10px] px-1.5 py-0.5 rounded-full {{ $isAff ? 'bg-red-100 text-red-700' : 'bg-stone-100 text-stone-500' }}">{{ $countAffiliate }}</span></a>
    </div>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Kartu aktif</p>
            <p id="stat-aktif" class="text-2xl font-bold text-stone-800">{{ $statAktif }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Terlambat</p>
            <p id="stat-terlambat" class="text-2xl font-bold {{ $statTerlambat ? 'text-rose-600' : 'text-stone-800' }}">{{ $statTerlambat }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Hari ini / besok</p>
            <p id="stat-dekat" class="text-2xl font-bold {{ $statDekat ? 'text-amber-600' : 'text-stone-800' }}">{{ $statDekat }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Tanpa next action</p>
            <p id="stat-tanpaaksi" class="text-2xl font-bold {{ $statTanpaAksi ? 'text-amber-600' : 'text-stone-800' }}">{{ $statTanpaAksi }}</p>
        </div>
    </div>

    {{-- Tambah kartu --}}
    @if($u->canDo('kol.pipeline.manage'))
        <details class="bg-white rounded-2xl border border-stone-200 p-4">
            <summary class="cursor-pointer text-sm font-semibold text-stone-700">+ Tambah kartu ke papan {{ $isAff ? 'Affiliate' : 'KOL' }}</summary>
            <form method="POST" action="{{ route('kol-pipeline.store', ['kind' => $track]) }}" class="mt-3 grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
                @csrf
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">KOL</span>
                    @include('kols._kol-combo', ['kols' => $kolsTanpaKartu, 'name' => 'kol_id', 'id' => 'pipelineKolCombo'])
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Tahap awal</span>
                    <select name="stage" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        @foreach($labels as $val => $lbl)
                            <option value="{{ $val }}" @selected($loop->first)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Rate diminta (opsional)</span>
                    <input type="number" name="ask_rate" min="0" placeholder="mis. 500000" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Next action</span>
                    <input name="next_action" maxlength="255" placeholder="mis. DM perkenalan" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Tanggal</span>
                    <input type="date" name="next_action_at" value="{{ now()->toDateString() }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Catatan awal (opsional)</span>
                    <input name="note" maxlength="2000" placeholder="konteks / dari mana" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <div class="lg:col-span-3">
                    <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Tambah kartu</button>
                    <span class="ml-3 text-[11px] text-stone-400">Tahap aktif wajib next action. Follow-up maks {{ \App\Models\KolPipelineCard::FOLLOW_UP_LIMIT }}× → parkir/drop.</span>
                </div>
            </form>
        </details>

        <p class="text-[11px] text-stone-400">💡 <b>Seret</b> kartu antar kolom untuk pindah tahap — kalau masuk tahap aktif tanpa next action, kamu diminta isi dulu.</p>
    @endif

    {{-- Papan kanban --}}
    <div class="flex gap-3 overflow-x-auto pb-4">
        @foreach($stages as $stage)
            @php $cards = $byStage[$stage] ?? collect(); @endphp
            <div class="min-w-[248px] w-[248px] shrink-0">
                <div class="flex items-center justify-between px-1 mb-2">
                    <span class="text-xs font-bold uppercase tracking-wide text-stone-500">{{ $labels[$stage] }}</span>
                    <span data-count-for="{{ $stage }}" class="text-[10px] px-1.5 py-0.5 rounded-full bg-stone-100 text-stone-500">{{ $cards->count() }}</span>
                </div>
                <div class="space-y-2 kanban-col min-h-[56px] rounded-lg transition" data-stage="{{ $stage }}">
                    @foreach($cards as $c)
                        @php
                            $terminal = \App\Models\KolPipelineCard::isTerminalStage($c->stage);
                            $late = ! $terminal && $c->next_action_at && $c->next_action_at->lt($today);
                            $soon = ! $terminal && $c->next_action_at && $c->next_action_at->between($today, $today->copy()->addDay()->endOfDay());
                            $besok = ! $terminal && $c->next_action_at && $c->next_action_at->isSameDay($today->copy()->addDay());
                            $overLimit = $c->followup_count >= \App\Models\KolPipelineCard::FOLLOW_UP_LIMIT;
                        @endphp
                        <div id="card-{{ $c->id }}" data-card-id="{{ $c->id }}"
                            data-late="{{ $late ? 1 : 0 }}" data-soon="{{ $soon ? 1 : 0 }}" data-noaction="{{ ! $c->next_action_at ? 1 : 0 }}"
                            data-hasaction="{{ $c->next_action_at ? 1 : 0 }}"
                            @if($u->canDo('kol.pipeline.manage')) draggable="true" @endif
                            class="bg-white rounded-xl border border-stone-200 p-3 space-y-1.5 {{ $u->canDo('kol.pipeline.manage') ? 'cursor-move' : '' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a href="{{ route('kols.show', $c->kol_id) }}" class="text-sm font-semibold text-indigo-600 hover:underline">{{ '@'.$c->kol->tiktok_username }}</a>
                                    <span class="ml-1 text-[9px] uppercase tracking-wide text-stone-400">{{ $c->kol->level }}</span>
                                </div>
                                @if($c->followup_count > 0)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full shrink-0 {{ $overLimit ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-500' }}" title="{{ $overLimit ? 'Sudah '.$c->followup_count.'× — putuskan parkir/drop' : '' }}">FU {{ $c->followup_count }}×</span>
                                @endif
                            </div>

                            @if($c->ask_rate)
                                <p class="text-[11px] text-stone-500">💰 diminta {{ $rp($c->ask_rate) }}{{ $c->final_rate ? ' → final '.$rp($c->final_rate) : '' }}</p>
                            @endif

                            @if($terminal)
                                <p class="text-[11px] text-stone-400">✓ tahap akhir</p>
                            @elseif($c->next_action)
                                <p class="text-xs {{ $late ? 'text-rose-600 font-medium' : ($soon ? 'text-amber-600' : 'text-stone-500') }}">
                                    {{ $c->next_action }}
                                    <span class="block text-[10px] {{ $late ? 'text-rose-500' : 'text-stone-400' }}">{{ $c->next_action_at?->format('d M Y') }}{{ $late ? ' · terlambat' : ($besok ? ' · besok' : '') }}</span>
                                </p>
                            @else
                                <p class="text-[11px] text-amber-600">⚠ belum ada next action</p>
                            @endif

                            <div class="flex items-center gap-2 pt-0.5">
                                <a href="{{ route('kol-pipeline.show', $c) }}" class="text-[11px] text-stone-500 hover:text-stone-800">detail →</a>
                                @if($u->canDo('kol.pipeline.manage') && ! $terminal)
                                    <form method="POST" action="{{ route('kol-pipeline.follow-up', $c) }}" class="inline">
                                        @csrf
                                        <button class="text-[11px] text-indigo-600 hover:underline" title="Catat follow-up + jadwalkan +{{ \App\Models\KolPipelineCard::FOLLOW_UP_SLA_DAYS }} hari">+ follow-up</button>
                                    </form>
                                @endif
                            </div>

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
                    <p class="text-[11px] text-stone-300 px-1 kanban-empty {{ $cards->isEmpty() ? '' : 'hidden' }}">—</p>
                </div>
            </div>
        @endforeach
    </div>
</div>

@if($u->canDo('kol.pipeline.manage'))
<script>
    // Drag-and-drop TANPA reload + guardrail: masuk tahap aktif tanpa next action
    // → minta isi dulu. Tahap akhir mengosongkan next action. Simpan di latar
    // belakang; revert kalau gagal.
    (function () {
        var TERMINAL = {!! json_encode(array_values($terminals)) !!};
        var TODAY = '{{ $today->toDateString() }}';
        var dragEl = null;
        function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
        function recount() {
            var aktif = 0, terlambat = 0, dekat = 0, tanpa = 0;
            document.querySelectorAll('.kanban-col').forEach(function (col) {
                var cards = col.querySelectorAll('[data-card-id]');
                var badge = document.querySelector('[data-count-for="' + col.getAttribute('data-stage') + '"]');
                if (badge) badge.textContent = cards.length;
                var empty = col.querySelector('.kanban-empty');
                if (empty) empty.classList.toggle('hidden', cards.length > 0);
                if (TERMINAL.indexOf(col.getAttribute('data-stage')) >= 0) return; // tahap akhir = tak aktif
                cards.forEach(function (c) {
                    aktif++;
                    if (c.getAttribute('data-late') === '1') terlambat++;
                    if (c.getAttribute('data-soon') === '1') dekat++;
                    if (c.getAttribute('data-noaction') === '1') tanpa++;
                });
            });
            setText('stat-aktif', aktif); setText('stat-terlambat', terlambat);
            setText('stat-dekat', dekat); setText('stat-tanpaaksi', tanpa);
        }
        document.querySelectorAll('[data-card-id][draggable=true]').forEach(function (card) {
            card.addEventListener('dragstart', function (e) { dragEl = card; e.dataTransfer.effectAllowed = 'move'; setTimeout(function () { card.classList.add('opacity-40'); }, 0); });
            card.addEventListener('dragend', function () { card.classList.remove('opacity-40'); });
        });
        document.querySelectorAll('.kanban-col').forEach(function (col) {
            col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('bg-stone-100'); });
            col.addEventListener('dragleave', function () { col.classList.remove('bg-stone-100'); });
            col.addEventListener('drop', function (e) {
                e.preventDefault(); col.classList.remove('bg-stone-100');
                if (!dragEl || dragEl.parentElement === col) { dragEl = null; return; }
                var stage = col.getAttribute('data-stage');
                var body = { stage: stage };
                // Guardrail: masuk tahap aktif tanpa next action → minta isi.
                if (TERMINAL.indexOf(stage) < 0 && dragEl.getAttribute('data-hasaction') !== '1') {
                    var na = prompt('Kartu masuk tahap aktif — tulis next action-nya:');
                    if (!na || !na.trim()) { dragEl = null; return; }
                    body.next_action = na.trim();
                    body.next_action_at = TODAY;
                }
                var fromCol = dragEl.parentElement, moving = dragEl, id = dragEl.getAttribute('data-card-id');
                // Isi kartu berubah hanya saat masuk tahap akhir (→ "tahap akhir") atau
                // next action baru diisi lewat prompt — dua kasus itu perlu sinkron ulang.
                var contentChanges = (TERMINAL.indexOf(stage) >= 0) || !!body.next_action;
                col.appendChild(dragEl);
                recount();
                fetch('{{ url('/kol-pipeline') }}/' + id + '/stage', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify(body)
                }).then(function (r) {
                    if (!(r.ok || r.redirected)) throw new Error();
                    if (contentChanges) location.reload(); // kasus jarang; selain itu tetap tanpa reload
                }).catch(function () { fromCol.appendChild(moving); recount(); alert('Gagal memindahkan kartu. Coba lagi.'); });
                dragEl = null;
            });
        });
    })();
</script>
@endif
@endsection
