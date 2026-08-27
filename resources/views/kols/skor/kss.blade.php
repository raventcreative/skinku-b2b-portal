@extends('layouts.app')
@section('title', 'Skor Seleksi KOL (KSS)')
@section('heading', 'Skor Seleksi KOL (KSS)')

@section('content')
<div class="max-w-3xl space-y-4">

    <p class="text-sm text-stone-500">Nilai calon KOL baru: layak <b>shortlist</b>, <b>nego</b>, atau <b>tolak</b>. Median views auto-isi dari screening terakhir bila ada.</p>

    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid lg:grid-cols-2 gap-4">
        {{-- Form --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <form method="POST" action="{{ route('kol-skor.kss') }}" class="space-y-3 text-sm">
                @csrf
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">KOL (opsional — auto-isi median)</span>
                    <input type="text" data-select-search="kssKol" placeholder="🔎 cari…" class="mt-1 w-full px-3 py-1.5 border border-stone-300 rounded-lg text-xs">
                    <select id="kssKol" name="kol_id" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        <option value="">— manual —</option>
                        @foreach($kols as $k)
                            <option value="{{ $k->id }}" data-median="{{ (int) ($k->latestScreening->median_views ?? 0) }}" @selected(old('kol_id', $old['kol_id'] ?? null) == $k->id)>{{ '@'.$k->tiktok_username }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-600">Ratecard (Rp)</span>
                        <input type="number" name="rate" min="0" value="{{ old('rate', $old['rate'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-600">Median views 10-20 video</span>
                        <input type="number" id="kssMedian" name="median_views" min="0" value="{{ old('median_views', $old['median_views'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center gap-2 mt-5"><input type="checkbox" name="barter" value="1" @checked(old('barter', $old['barter'] ?? false))> <span class="text-xs text-stone-600">Barter-only (biaya cuma HPP sampel)</span></label>
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-600">Engagement rate (%)</span>
                        <input type="number" step="0.1" name="engagement_rate" min="0" value="{{ old('engagement_rate', $old['engagement_rate'] ?? '') }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                    </label>
                </div>
                @foreach(['niche' => ['Relevansi niche', $nicheOpts], 'history' => ['Riwayat dengan brand', $historyOpts], 'readiness' => ['Kesiapan komersial', $readinessOpts]] as $field => $meta)
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-600">{{ $meta[0] }}</span>
                        <select name="{{ $field }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                            @foreach($meta[1] as $val => $lbl)
                                <option value="{{ $val }}" @selected(old($field, $old[$field] ?? '') === $val)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                <button class="w-full px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Hitung Skor</button>
            </form>
        </div>

        {{-- Hasil --}}
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
                            <div class="flex justify-between text-[11px] text-stone-500">
                                <span>{{ $c['label'] }} <span class="text-stone-300">({{ (int) ($c['weight'] * 100) }}%)</span></span>
                                <span>{{ $c['raw'] }} · {{ $c['points'] }}</span>
                            </div>
                            <div class="h-1.5 bg-stone-100 rounded-full overflow-hidden"><div class="h-full bg-red-500" style="width: {{ $c['points'] }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="h-full flex items-center justify-center text-center text-stone-400 text-sm py-10">
                    Isi form → skor, keputusan, dan breakdown muncul di sini.
                </div>
            @endif
        </div>
    </div>
</div>

<script>
    // Auto-isi median views dari screening KOL terpilih.
    (function () {
        var sel = document.getElementById('kssKol'), med = document.getElementById('kssMedian');
        if (!sel || !med) return;
        sel.addEventListener('change', function () {
            var o = sel.options[sel.selectedIndex];
            var m = o ? parseInt(o.getAttribute('data-median') || '0', 10) : 0;
            if (m > 0) med.value = m;
        });
    })();
</script>
@endsection
