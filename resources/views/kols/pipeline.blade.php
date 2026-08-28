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

    {{-- Flash status & error dirender global oleh layout (hindari banner dobel). --}}

    {{-- Toggle papan (pill, ala Iyuro): KOL scouting vs Affiliate pembinaan --}}
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div class="inline-flex rounded-xl border border-stone-200 bg-white p-1 text-sm shadow-sm">
            <a href="{{ route('kol-pipeline.index', ['kind' => 'kol']) }}" class="px-3.5 py-1.5 rounded-lg font-semibold transition {{ ! $isAff ? 'bg-red-600 text-white' : 'text-stone-500 hover:text-stone-800' }}">Scouting KOL <span class="ml-1 text-[11px] tabular-nums {{ ! $isAff ? 'text-red-100' : 'text-stone-400' }}">{{ $countKol }}</span></a>
            <a href="{{ route('kol-pipeline.index', ['kind' => 'affiliate']) }}" class="px-3.5 py-1.5 rounded-lg font-semibold transition {{ $isAff ? 'bg-red-600 text-white' : 'text-stone-500 hover:text-stone-800' }}">Pembinaan Affiliate <span class="ml-1 text-[11px] tabular-nums {{ $isAff ? 'text-red-100' : 'text-stone-400' }}">{{ $countAffiliate }}</span></a>
        </div>
    </div>

    {{-- Ringkasan (label uppercase + aktif/total, ala Iyuro) --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-400">Kartu aktif</p>
            <p class="text-2xl font-bold text-stone-800 tabular-nums mt-0.5"><span id="stat-aktif">{{ $statAktif }}</span><span class="text-base font-semibold text-stone-300">/{{ $total }}</span></p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-400">Terlambat</p>
            <p id="stat-terlambat" class="text-2xl font-bold tabular-nums mt-0.5 {{ $statTerlambat ? 'text-rose-600' : 'text-stone-800' }}">{{ $statTerlambat }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-400">Hari ini / besok</p>
            <p id="stat-dekat" class="text-2xl font-bold tabular-nums mt-0.5 {{ $statDekat ? 'text-amber-600' : 'text-stone-800' }}">{{ $statDekat }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-400">Tanpa next action</p>
            <p id="stat-tanpaaksi" class="text-2xl font-bold tabular-nums mt-0.5 {{ $statTanpaAksi ? 'text-amber-600' : 'text-stone-800' }}">{{ $statTanpaAksi }}</p>
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
                            $overLimit = $c->followup_count >= \App\Models\KolPipelineCard::FOLLOW_UP_LIMIT;
                            $rc = fn ($n) => $n >= 1_000_000 ? 'Rp '.rtrim(rtrim(number_format($n / 1_000_000, 1, ',', '.'), '0'), ',').'jt' : 'Rp '.number_format($n, 0, ',', '.');
                            $dl = null;
                            if (! $terminal && $c->next_action_at) {
                                $d = (int) $today->diffInDays($c->next_action_at->copy()->startOfDay(), false);
                                $dl = $d < 0 ? 'Terlambat '.abs($d).' hari' : ($d === 0 ? 'Hari ini' : ($d === 1 ? 'Besok' : $d.' hari lagi'));
                            }
                        @endphp
                        <div id="card-{{ $c->id }}" data-card-id="{{ $c->id }}"
                            data-late="{{ $late ? 1 : 0 }}" data-soon="{{ $soon ? 1 : 0 }}" data-noaction="{{ ! $c->next_action_at ? 1 : 0 }}"
                            data-hasaction="{{ $c->next_action_at ? 1 : 0 }}"
                            @if($u->canDo('kol.pipeline.manage')) draggable="true" @endif
                            class="bg-white rounded-xl border border-stone-200 p-3 space-y-2 shadow-sm {{ $u->canDo('kol.pipeline.manage') ? 'cursor-grab active:cursor-grabbing' : '' }}">
                            {{-- Kepala: handle + nama + tier + FU --}}
                            <div class="flex items-start gap-2">
                                @if($u->canDo('kol.pipeline.manage'))<span class="text-stone-300 leading-none mt-0.5 shrink-0 select-none" aria-hidden="true">⠿</span>@endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <a href="{{ route('kols.show', $c->kol_id) }}" class="text-sm font-semibold text-stone-800 hover:text-indigo-600 truncate">{{ '@'.$c->kol->tiktok_username }}</a>
                                        <span class="text-[9px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded bg-stone-100 text-stone-500 shrink-0">{{ $c->kol->level }}</span>
                                    </div>
                                </div>
                                @if($c->followup_count > 0)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full shrink-0 {{ $overLimit ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-500' }}" title="{{ $overLimit ? 'Sudah '.$c->followup_count.'× — putuskan parkir/drop' : '' }}">FU {{ $c->followup_count }}×</span>
                                @endif
                            </div>

                            {{-- Next action + tanggal relatif (ikon kalender, warna sesuai status) --}}
                            @if($terminal)
                                <p class="text-[11px] text-stone-400">✓ tahap akhir</p>
                            @elseif($c->next_action)
                                <div>
                                    <p class="text-[11px] font-semibold flex items-center gap-1 {{ $late ? 'text-rose-600' : ($soon ? 'text-amber-600' : 'text-stone-500') }}">
                                        <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                        {{ $dl }} <span class="text-stone-400 font-normal">· {{ $c->next_action_at->format('d M Y') }}</span>
                                    </p>
                                    <p class="text-xs text-stone-600 mt-1">{{ $c->next_action }}</p>
                                </div>
                            @else
                                <p class="text-[11px] text-amber-600">⚠ belum ada next action</p>
                            @endif

                            {{-- Rate diminta → final --}}
                            @if($c->ask_rate)
                                <p class="text-[11px] text-stone-500 tabular-nums">{{ $rc($c->ask_rate) }} <span class="text-stone-300">→</span> {{ $c->final_rate ? $rc($c->final_rate) : '—' }}</p>
                            @endif

                            {{-- Aksi inline (ala Iyuro): pindah dropdown + follow-up --}}
                            @if($u->canDo('kol.pipeline.manage'))
                                <div class="flex items-center gap-1.5 pt-0.5">
                                    <form method="POST" action="{{ route('kol-pipeline.stage', $c) }}" class="flex-1 min-w-0">
                                        @csrf @method('PATCH')
                                        <select name="stage" onchange="if(this.value)this.form.submit()" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg text-xs bg-white text-stone-600">
                                            <option value="" selected>Pindah ke…</option>
                                            @foreach($labels as $val => $lbl)@if($val !== $c->stage)<option value="{{ $val }}">{{ $lbl }}</option>@endif @endforeach
                                        </select>
                                    </form>
                                    @if(! $terminal)
                                        <form method="POST" action="{{ route('kol-pipeline.follow-up', $c) }}" class="shrink-0">
                                            @csrf
                                            <button class="px-2 py-1.5 border border-indigo-200 text-indigo-600 hover:bg-indigo-50 text-xs font-semibold rounded-lg whitespace-nowrap" title="Catat follow-up + jadwalkan +{{ \App\Models\KolPipelineCard::FOLLOW_UP_SLA_DAYS }} hari">✓ Follow-up</button>
                                        </form>
                                    @endif
                                </div>
                            @endif

                            <div class="flex items-center justify-between gap-2">
                                <a href="{{ route('kol-pipeline.show', $c) }}" class="text-[11px] text-stone-500 hover:text-stone-800">detail → <span class="text-stone-300">(rate, nego, riwayat)</span></a>
                                @if($u->role === \App\Models\User::ROLE_SUPER_ADMIN)
                                    <form method="POST" action="{{ route('kol-pipeline.destroy', $c) }}" onsubmit="return confirm('Hapus kartu ini permanen?')" class="shrink-0">
                                        @csrf @method('DELETE')
                                        <button class="text-[11px] text-rose-400 hover:text-rose-600" title="Hapus kartu permanen">hapus</button>
                                    </form>
                                @endif
                            </div>
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
