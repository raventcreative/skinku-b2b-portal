@extends('layouts.app')
@section('title', 'Skor KOL')
@section('heading', 'Skor KOL')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="max-w-4xl space-y-4">

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
    <section data-panel="aps" class="{{ $tab === 'aps' ? '' : 'hidden' }}">
        <p class="text-sm text-stone-500 mb-2">Siapa yang layak dibina — dari GMV & konten 4 minggu terakhir.</p>
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-stone-500 text-xs">
                        <tr><th class="text-left px-4 py-2.5">#</th><th class="text-left px-4 py-2.5">Creator</th><th class="text-center px-4 py-2.5">Skor</th><th class="text-left px-4 py-2.5">Label</th><th class="text-right px-4 py-2.5">GMV</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($apsRanking as $i => $r)
                            <tr>
                                <td class="px-4 py-2.5 text-stone-400">{{ $i + 1 }}</td>
                                <td class="px-4 py-2.5"><a href="{{ route('kols.show', $r['kol']->id) }}" class="text-indigo-600 hover:underline">{{ '@'.$r['kol']->tiktok_username }}</a></td>
                                <td class="px-4 py-2.5 text-center font-bold text-stone-800">{{ $r['aps']['status'] === 'scored' ? rtrim(rtrim(number_format($r['aps']['score'], 1, ',', '.'), '0'), ',') : '—' }}</td>
                                <td class="px-4 py-2.5">
                                    @if($r['aps']['status'] === 'scored')
                                        @php $tone = ['bina_intensif' => 'bg-emerald-100 text-emerald-700', 'pantau' => 'bg-amber-100 text-amber-700', 'nurture' => 'bg-stone-100 text-stone-500'][$r['aps']['label']]; @endphp
                                        <span class="text-[10px] px-2 py-0.5 rounded-full {{ $tone }}">{{ $apsLabels[$r['aps']['label']] }}</span>
                                    @else
                                        <span class="text-[10px] text-stone-400">{{ $apsLabels['new'] }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right text-stone-700">{{ $rp($r['gmv']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-stone-400 text-sm">Belum ada data affiliate — import dulu di <a href="{{ route('kol-affiliate.index') }}" class="text-indigo-600 hover:underline">Affiliate & GMV</a>.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    @endif

    {{-- ===================== TAB: Kalkulator KSS ===================== --}}
    <section data-panel="kss" class="{{ $tab === 'kss' ? '' : 'hidden' }}">
        <p class="text-sm text-stone-500 mb-2">Nilai calon KOL baru: layak <b>shortlist</b>, <b>nego</b>, atau <b>tolak</b>. Median views auto-isi dari screening terakhir bila ada.</p>
        <div class="grid lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <form method="POST" action="{{ route('kol-skor.kss') }}" class="space-y-3 text-sm">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-600">KOL (opsional — auto-isi median)</span>
                        @include('kols._kol-combo', ['kols' => $kols, 'name' => 'kol_id', 'id' => 'kssKolCombo', 'placeholder' => '🔎 ketik / pilih (opsional)…'])
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Ratecard (Rp)</span><input type="number" name="rate" min="0" value="{{ old('rate', $old['rate'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Median views 10-20 video</span><input type="number" id="kssMedian" name="median_views" min="0" value="{{ old('median_views', $old['median_views'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 mt-5"><input type="checkbox" name="barter" value="1" @checked(old('barter', $old['barter'] ?? false))> <span class="text-xs text-stone-600">Barter-only (biaya cuma HPP sampel)</span></label>
                        <label class="block"><span class="text-xs font-semibold text-stone-600">Engagement rate (%)</span><input type="number" step="0.1" name="engagement_rate" min="0" value="{{ old('engagement_rate', $old['engagement_rate'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
                    </div>
                    @foreach(['niche' => ['Relevansi niche', $nicheOpts], 'history' => ['Riwayat dengan brand', $historyOpts], 'readiness' => ['Kesiapan komersial', $readinessOpts]] as $field => $meta)
                        <label class="block">
                            <span class="text-xs font-semibold text-stone-600">{{ $meta[0] }}</span>
                            <select name="{{ $field }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                                @foreach($meta[1] as $val => $lbl)<option value="{{ $val }}" @selected(old($field, $old[$field] ?? '') === $val)>{{ $lbl }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                    <button class="w-full px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Hitung Skor</button>
                </form>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                @if($result)
                    @php $dt = ['shortlist' => 'bg-emerald-100 text-emerald-700', 'nego' => 'bg-amber-100 text-amber-700', 'tolak' => 'bg-rose-100 text-rose-700'][$result['decision']]; @endphp
                    <div class="text-center">
                        <p class="text-5xl font-bold text-stone-800">{{ rtrim(rtrim(number_format($result['score'], 1, ',', '.'), '0'), ',') }}</p>
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
                @else
                    <div class="h-full flex items-center justify-center text-center text-stone-400 text-sm py-10">Isi form → skor, keputusan, dan breakdown muncul di sini.</div>
                @endif
            </div>
        </div>
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
    (function () {
        var combo = document.getElementById('kssKolCombo'), med = document.getElementById('kssMedian');
        if (combo && med) combo.addEventListener('combo:select', function (e) {
            var m = parseInt(e.detail.option.getAttribute('data-median') || '0', 10);
            if (m > 0) med.value = m;
        });
    })();
</script>
@endsection
