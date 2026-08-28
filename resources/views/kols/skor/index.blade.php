@extends('layouts.app')
@section('title', 'Skor KOL')
@section('heading', 'Skor KOL')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $skorFmt = fn ($n) => rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
@endphp

<div class="max-w-5xl space-y-4">

    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- Tab switch --}}
    <div class="flex gap-1 border-b border-stone-200">
        @if($canAffiliate)
            <button type="button" data-tab="aps" onclick="showSkorTab('aps')" class="skor-tab px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition {{ $tab === 'aps' ? 'border-red-600 text-red-700' : 'border-transparent text-stone-400 hover:text-stone-600' }}">🏆 Ranking APS</button>
        @endif
        <button type="button" data-tab="kss" onclick="showSkorTab('kss')" class="skor-tab px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition {{ $tab === 'kss' ? 'border-red-600 text-red-700' : 'border-transparent text-stone-400 hover:text-stone-600' }}">🧮 Kalkulator KSS</button>
    </div>

    {{-- ===================== TAB: Ranking APS ===================== --}}
    @if($canAffiliate)
    <section data-panel="aps" class="{{ $tab === 'aps' ? '' : 'hidden' }} space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-stone-500">Siapa yang layak dibina — dari GMV &amp; konten 4 minggu terakhir. Urut skor tertinggi.</p>
            <form method="POST" action="{{ route('kol-skor.aps-snapshot') }}">
                @csrf
                <button class="text-xs font-semibold text-indigo-600 hover:underline border border-indigo-200 rounded-lg px-3 py-1.5">📸 Snapshot APS sekarang</button>
            </form>
        </div>

        {{-- Rubrik --}}
        <details class="bg-white rounded-2xl border border-stone-200 p-4">
            <summary class="cursor-pointer text-xs font-semibold text-stone-600">Cara APS dihitung (rubrik)</summary>
            <div class="mt-2 text-[12px] text-stone-500 leading-relaxed space-y-1">
                <p>APS = <b>Growth velocity GMV 35%</b> + <b>Efisiensi konversi RPM 25%</b> + <b>Konsistensi posting 20%</b> + <b>Skala GMV 20%</b>. Bila views tak ada, bobot RPM dialihkan (reweight).</p>
                <p>Butuh ≥ 4 minggu data (kalau &lt; 4 → masuk tabel "New"). Bila 2 minggu terakhir tak posting, skor <b>di-cap 40</b>.</p>
                <p>Label: skor ≥ 75 <b>Bina intensif</b> · ≥ 50 <b>Pantau &amp; dorong</b> · &lt; 50 <b>Nurture pasif</b>.</p>
            </div>
        </details>

        {{-- Ranking scored --}}
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-stone-500 text-xs">
                        <tr>
                            <th class="text-left px-4 py-2.5">#</th>
                            <th class="text-left px-4 py-2.5">Creator</th>
                            <th class="text-center px-4 py-2.5">Skor</th>
                            <th class="text-left px-4 py-2.5">Label</th>
                            <th class="text-right px-3 py-2.5" title="Growth velocity GMV (WoW)">Growth</th>
                            <th class="text-right px-3 py-2.5" title="Efisiensi konversi (RPM)">RPM</th>
                            <th class="text-right px-3 py-2.5" title="Konsistensi posting">Konsistensi</th>
                            <th class="text-right px-4 py-2.5">GMV</th>
                            <th class="px-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($apsScored as $i => $r)
                            @php
                                $comp = collect($r['aps']['components'])->keyBy('key');
                                $tone = ['bina_intensif' => 'bg-emerald-100 text-emerald-700', 'pantau' => 'bg-amber-100 text-amber-700', 'nurture' => 'bg-stone-100 text-stone-500'][$r['aps']['label']];
                            @endphp
                            <tr>
                                <td class="px-4 py-2.5 text-stone-400">{{ $i + 1 }}</td>
                                <td class="px-4 py-2.5"><a href="{{ route('kols.show', $r['kol']->id) }}" class="text-indigo-600 hover:underline">{{ '@'.$r['kol']->tiktok_username }}</a></td>
                                <td class="px-4 py-2.5 text-center font-bold text-stone-800">{{ $skorFmt($r['aps']['score']) }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $tone }}">{{ $apsLabels[$r['aps']['label']] }}</span>
                                    @if($r['aps']['capped'])<span class="ml-1 text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700" title="Skor dibatasi 40 — 2 minggu tanpa posting">cap 40</span>@endif
                                </td>
                                <td class="px-3 py-2.5 text-right text-stone-600 text-xs">{{ $comp['growth']['raw'] ?? '—' }}</td>
                                <td class="px-3 py-2.5 text-right text-stone-600 text-xs">{{ $comp['rpm']['raw'] ?? '—' }}</td>
                                <td class="px-3 py-2.5 text-right text-stone-600 text-xs">{{ $comp['consistency']['raw'] ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right text-stone-700">{{ $rp($r['gmv']) }}</td>
                                <td class="px-2 text-right"><button type="button" onclick="toggleAps({{ $i }})" class="text-[11px] text-stone-400 hover:text-stone-700">rincian</button></td>
                            </tr>
                            <tr id="aps-detail-{{ $i }}" class="hidden bg-stone-50">
                                <td colspan="9" class="px-4 py-3">
                                    <div class="grid sm:grid-cols-2 gap-x-6 gap-y-2 max-w-2xl">
                                        @foreach($r['aps']['components'] as $c)
                                            <div>
                                                <div class="flex justify-between text-[11px] text-stone-500"><span>{{ $c['label'] }} <span class="text-stone-300">({{ (int) ($c['weight'] * 100) }}%)</span></span><span>{{ $c['raw'] }} · {{ $c['points'] }}</span></div>
                                                <div class="h-1.5 bg-stone-200 rounded-full overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $c['points'] }}%"></div></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-10 text-center text-stone-400 text-sm">Belum ada creator dengan ≥ 4 minggu data. Import data affiliate dulu di <a href="{{ route('kol-affiliate.index') }}" class="text-indigo-600 hover:underline">Affiliate &amp; GMV</a>.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Tabel "New" (belum cukup data) --}}
        @if($apsNew->isNotEmpty())
            <div>
                <p class="text-sm font-semibold text-stone-700 mb-2">🌱 New — belum cukup data (&lt; 4 minggu)</p>
                <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50 text-stone-500 text-xs"><tr><th class="text-left px-4 py-2.5">Creator</th><th class="text-center px-4 py-2.5">Minggu data</th><th class="text-right px-4 py-2.5">GMV bulan ini</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($apsNew as $r)
                                <tr>
                                    <td class="px-4 py-2.5"><a href="{{ route('kols.show', $r['kol']->id) }}" class="text-indigo-600 hover:underline">{{ '@'.$r['kol']->tiktok_username }}</a></td>
                                    <td class="px-4 py-2.5 text-center text-stone-500">{{ $r['weeks'] }}/4</td>
                                    <td class="px-4 py-2.5 text-right text-stone-700">{{ $rp($r['gmv']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>
    @endif

    {{-- ===================== TAB: Kalkulator KSS ===================== --}}
    <section data-panel="kss" class="{{ $tab === 'kss' ? '' : 'hidden' }} space-y-4">
        <p class="text-sm text-stone-500">Nilai calon KOL baru: layak <b>shortlist</b>, <b>nego</b>, atau <b>tolak</b>. Pilih KOL → rate &amp; median auto-isi dari screening. Skor terhitung langsung saat mengetik.</p>

        <details class="bg-white rounded-2xl border border-stone-200 p-4">
            <summary class="cursor-pointer text-xs font-semibold text-stone-600">Cara KSS dihitung (rubrik)</summary>
            <div class="mt-2 text-[12px] text-stone-500 leading-relaxed space-y-1">
                <p>KSS = <b>Efisiensi biaya eCPM 35%</b> + <b>Engagement rate 20%</b> + <b>Relevansi niche 20%</b> + <b>Riwayat brand 15%</b> + <b>Kesiapan komersial 10%</b>.</p>
                <p>Barter-only → eCPM otomatis dinilai 90 (biaya cuma HPP sampel).</p>
                <p>Keputusan: skor ≥ 70 <b>Shortlist</b> · ≥ 50 <b>Nego dulu</b> · &lt; 50 <b>Tolak sopan, simpan</b>.</p>
            </div>
        </details>

        <div class="grid lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <form id="kssForm" method="POST" action="{{ route('kol-skor.kss') }}" class="space-y-3 text-sm">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-600">KOL (opsional — auto-isi rate &amp; median, skor disimpan ke riwayat)</span>
                        @include('kols._kol-combo', ['kols' => $kols, 'name' => 'kol_id', 'id' => 'kssKolCombo', 'placeholder' => '🔎 ketik / pilih (opsional)…'])
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Ratecard (Rp)</span><input type="number" id="kssRate" name="rate" min="0" value="{{ old('rate', $old['rate'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Median views 10-20 video</span><input type="number" id="kssMedian" name="median_views" min="0" value="{{ old('median_views', $old['median_views'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 mt-5"><input type="checkbox" id="kssBarter" name="barter" value="1" @checked(old('barter', $old['barter'] ?? false))> <span class="text-xs text-stone-600">Barter-only (biaya cuma HPP sampel)</span></label>
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Engagement rate (%)</span><input type="number" step="0.1" id="kssEr" name="engagement_rate" min="0" value="{{ old('engagement_rate', $old['engagement_rate'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                    </div>
                    @foreach(['niche' => ['Relevansi niche', $nicheOpts], 'history' => ['Riwayat dengan brand', $historyOpts], 'readiness' => ['Kesiapan komersial', $readinessOpts]] as $field => $meta)
                        <label class="block">
                            <span class="text-xs font-semibold text-stone-600">{{ $meta[0] }}</span>
                            <select name="{{ $field }}" id="kss{{ ucfirst($field) }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                                @foreach($meta[1] as $val => $lbl)<option value="{{ $val }}" @selected(old($field, $old[$field] ?? '') === $val)>{{ $lbl }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                    {{-- Preview live --}}
                    <div id="kssPreview" class="hidden items-center gap-2 text-xs bg-stone-50 rounded-lg px-3 py-2">
                        <span class="text-stone-500">Preview:</span> <b id="kssPrevScore" class="text-stone-800"></b> <span id="kssPrevDecision" class="px-2 py-0.5 rounded-full"></span>
                    </div>
                    <button class="w-full px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Hitung &amp; Simpan Skor</button>
                </form>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                @if($result)
                    @php $dt = ['shortlist' => 'bg-emerald-100 text-emerald-700', 'nego' => 'bg-amber-100 text-amber-700', 'tolak' => 'bg-rose-100 text-rose-700'][$result['decision']]; @endphp
                    <div class="text-center">
                        <p class="text-5xl font-bold text-stone-800">{{ $skorFmt($result['score']) }}</p>
                        <span class="inline-block mt-1 text-sm px-3 py-1 rounded-full {{ $dt }}">{{ $decisionLabel[$result['decision']] }}</span>
                    </div>
                    <p class="text-xs text-stone-500 mt-3 leading-relaxed">{{ $result['advice'] }}</p>
                    <div class="mt-4 space-y-2">
                        @foreach($result['components'] as $c)
                            <div>
                                <div class="flex justify-between text-[11px] text-stone-500"><span>{{ $c['label'] }} <span class="text-stone-300">({{ (int) ($c['weight'] * 100) }}%)</span></span><span>{{ $c['raw'] }} · {{ $c['points'] }}</span></div>
                                <div class="h-1.5 bg-stone-100 rounded-full overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $c['points'] }}%"></div></div>
                            </div>
                        @endforeach
                    </div>
                    @if(!empty($old['kol_id']))
                        <p class="text-[11px] text-emerald-600 mt-3">✓ Skor ini tersimpan ke jejak historis KOL.</p>
                    @endif
                @else
                    <div class="h-full flex items-center justify-center text-center text-stone-400 text-sm py-10">Isi form → skor, keputusan, dan breakdown muncul di sini.</div>
                @endif
            </div>
        </div>

        {{-- Riwayat KSS (20 terakhir) --}}
        @if($canAffiliate && $kssHistory->isNotEmpty())
            <div>
                <p class="text-sm font-semibold text-stone-700 mb-2">Riwayat KSS (20 terakhir)</p>
                <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-stone-50 text-stone-500 text-xs"><tr>
                                <th class="text-left px-4 py-2.5">Tanggal</th><th class="text-left px-4 py-2.5">Creator</th>
                                <th class="text-center px-4 py-2.5">Skor</th><th class="text-left px-4 py-2.5">Keputusan</th>
                                <th class="text-right px-4 py-2.5">Rate</th><th class="text-right px-4 py-2.5">Median</th><th class="text-right px-4 py-2.5">eCPM</th>
                            </tr></thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach($kssHistory as $h)
                                    @php $m = $h->meta ?? []; $dc = $h->label; $dt = ['shortlist' => 'text-emerald-700', 'nego' => 'text-amber-600', 'tolak' => 'text-rose-600'][$dc] ?? 'text-stone-600'; @endphp
                                    <tr>
                                        <td class="px-4 py-2.5 text-stone-500">{{ $h->created_at?->format('d M Y') }}</td>
                                        <td class="px-4 py-2.5">{{ $h->kol ? '@'.$h->kol->tiktok_username : '—' }}</td>
                                        <td class="px-4 py-2.5 text-center font-semibold text-stone-800">{{ $skorFmt($h->score) }}</td>
                                        <td class="px-4 py-2.5 {{ $dt }}">{{ $decisionLabel[$dc] ?? $dc }}</td>
                                        <td class="px-4 py-2.5 text-right text-stone-600">{{ isset($m['rate']) ? $rp($m['rate']) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right text-stone-600">{{ isset($m['median_views']) ? $num($m['median_views']) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right text-stone-600">{{ isset($m['ecpm']) && $m['ecpm'] !== null ? $rp($m['ecpm']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </section>
</div>

<script>
    function showSkorTab(tab) {
        document.querySelectorAll('[data-panel]').forEach(function (el) { el.classList.toggle('hidden', el.getAttribute('data-panel') !== tab); });
        document.querySelectorAll('.skor-tab').forEach(function (el) {
            var on = el.getAttribute('data-tab') === tab;
            el.classList.toggle('border-red-600', on); el.classList.toggle('text-red-700', on);
            el.classList.toggle('border-transparent', !on); el.classList.toggle('text-stone-400', !on);
        });
    }
    function toggleAps(i) { var r = document.getElementById('aps-detail-' + i); if (r) r.classList.toggle('hidden'); }

    (function () {
        // Prefill kaya: pilih KOL → isi median + rate dari screening.
        var combo = document.getElementById('kssKolCombo');
        var med = document.getElementById('kssMedian'), rate = document.getElementById('kssRate');
        if (combo) combo.addEventListener('combo:select', function (e) {
            var m = parseInt(e.detail.option.getAttribute('data-median') || '0', 10);
            var r = parseInt(e.detail.option.getAttribute('data-rate') || '0', 10);
            if (m > 0) med.value = m;
            if (r > 0) rate.value = r;
            liveCalc();
        });

        // Live-calc: port rumus KSS (harus sama dgn KolScoringService).
        var NICHE = {!! json_encode(\App\Services\KolScoringService::NICHE) !!};
        var HISTORY = {!! json_encode(\App\Services\KolScoringService::HISTORY) !!};
        var READINESS = {!! json_encode(\App\Services\KolScoringService::READINESS) !!};
        var f = document.getElementById('kssForm');
        var prev = document.getElementById('kssPreview'), pScore = document.getElementById('kssPrevScore'), pDec = document.getElementById('kssPrevDecision');

        function liveCalc() {
            var rateV = parseFloat(rate.value) || 0, medV = parseFloat(med.value) || 0;
            var barter = document.getElementById('kssBarter').checked;
            var er = parseFloat(document.getElementById('kssEr').value) || 0;
            if (!barter && (rateV <= 0 || medV <= 0) && er <= 0) { prev.classList.add('hidden'); prev.classList.remove('flex'); return; }
            var e = barter ? null : (medV > 0 ? (rateV / medV) * 1000 : null);
            var ePts = barter ? 90 : (e === null ? 0 : (e <= 2500 ? 100 : e <= 5000 ? 80 : e <= 10000 ? 55 : e <= 20000 ? 30 : 0));
            var erPts = er > 8 ? 100 : er >= 5 ? 80 : er >= 3 ? 55 : er >= 1.5 ? 30 : 0;
            var nPts = NICHE[document.getElementById('kssNiche').value] || 0;
            var hPts = HISTORY[document.getElementById('kssHistory').value] || 0;
            var rPts = READINESS[document.getElementById('kssReadiness').value] || 0;
            var score = Math.round((0.35 * ePts + 0.2 * erPts + 0.2 * nPts + 0.15 * hPts + 0.1 * rPts) * 10) / 10;
            var dec = score >= 70 ? ['Shortlist', 'bg-emerald-100 text-emerald-700'] : score >= 50 ? ['Nego dulu', 'bg-amber-100 text-amber-700'] : ['Tolak, simpan', 'bg-rose-100 text-rose-700'];
            pScore.textContent = String(score).replace('.', ',');
            pDec.textContent = dec[0]; pDec.className = 'px-2 py-0.5 rounded-full ' + dec[1];
            prev.classList.remove('hidden'); prev.classList.add('flex');
        }
        if (f) f.addEventListener('input', liveCalc);
        liveCalc();
    })();
</script>
@endsection
