@extends('layouts.app')
@section('title', 'Affiliate & GMV')
@section('heading', 'Affiliate & GMV')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $rc = fn ($n) => $n >= 1_000_000 ? 'Rp '.round($n / 1_000_000, 1).' jt' : 'Rp '.number_format($n, 0, ',', '.');
@endphp

<div class="space-y-4">

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('kol-affiliate.index', ['bulan' => $prevMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">←</a>
            <span class="font-semibold text-stone-700">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }}</span>
            <a href="{{ route('kol-affiliate.index', ['bulan' => $nextMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">→</a>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('kol-affiliate.transactions', ['bulan' => $month]) }}" class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">📄 Semua transaksi</a>
            @if($canManage && \Illuminate\Support\Facades\Route::has('kol-affiliate.import'))
                <a href="{{ route('kol-affiliate.import') }}" class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">⬆ Import data affiliate</a>
            @endif
        </div>
    </div>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">GMV bulan ini</p><p class="text-xl font-bold text-stone-800">{{ $rp($summary['gmv']) }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-xs text-stone-500">Komisi (est / settled)</p>
            <p class="text-lg font-bold text-stone-800">{{ $rc($summary['commission']) }} <span class="text-stone-300">/</span> {{ $summary['settled'] ? $rc($summary['settled']) : '—' }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">RPM agregat</p><p class="text-xl font-bold text-stone-800">{{ $summary['rpm'] !== null ? $rp($summary['rpm']) : '—' }}</p><p class="text-[10px] text-stone-400">GMV ÷ views × 1000</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Order</p><p class="text-xl font-bold text-stone-800">{{ number_format($summary['orders'], 0, ',', '.') }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Affiliate aktif</p><p class="text-xl font-bold text-stone-800">{{ $summary['affiliates'] }}</p></div>
    </div>

    {{-- Target GMV + progress --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <p class="text-sm font-semibold text-stone-700">Target GMV bulan ini</p>
            @if($canManage)
                <form method="POST" action="{{ route('kol-affiliate.gmv-target') }}" class="flex items-center gap-1 text-xs">
                    @csrf
                    <span class="text-stone-400">target</span>
                    <input type="number" name="gmv_target" min="0" value="{{ $gmvTarget }}" class="w-32 px-2 py-1 border border-stone-300 rounded text-right">
                    <button class="text-indigo-600 hover:underline">simpan</button>
                </form>
            @endif
        </div>
        @if($gmvTarget > 0)
            @php $gpct = min(100, round($summary['gmv'] / $gmvTarget * 100)); @endphp
            <div class="h-2 bg-stone-100 rounded-full overflow-hidden"><div class="h-full {{ $gpct >= 100 ? 'bg-emerald-500' : 'bg-red-500' }}" style="width: {{ $gpct }}%"></div></div>
            <p class="text-[11px] text-stone-400 mt-1">{{ $rp($summary['gmv']) }} / {{ $rp($gmvTarget) }} · {{ $gpct }}%</p>
        @else
            <p class="text-xs text-stone-400">Belum ada target — isi untuk lihat progress.</p>
        @endif
    </div>

    {{-- Strip GMV per minggu (agregat semua creator) — kecuali order batal. --}}
    @if(!empty($weekly) && collect($weekly)->sum('gmv') > 0)
        @php $maxW = max(1, collect($weekly)->max('gmv')); @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-sm font-semibold text-stone-700 mb-3">GMV per minggu</p>
            <div class="flex items-end gap-3">
                @foreach($weekly as $w)
                    <div class="flex-1 flex flex-col items-center gap-1 min-w-0">
                        <span class="text-[10px] text-stone-500 tabular-nums">{{ $rc($w['gmv']) }}</span>
                        <div class="w-full bg-stone-100 rounded-md overflow-hidden flex items-end" style="height:64px">
                            <div class="w-full bg-red-500 rounded-md" style="height: {{ max(3, round($w['gmv'] / $maxW * 100)) }}%"></div>
                        </div>
                        <span class="text-[10px] text-stone-400 truncate w-full text-center">{{ $w['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Belum cocok --}}
    @if($unmatched->isNotEmpty())
        <div class="bg-amber-50 rounded-2xl border border-amber-200 p-4">
            <p class="text-sm font-semibold text-amber-800 mb-2">⚠ {{ $unmatched->count() }} username belum cocok — GMV tak masuk ranking. Yang GMV-nya besar = calon affiliate belum terdata.</p>
            <div class="space-y-1">
                @foreach($unmatched as $row)
                    <div class="flex flex-wrap items-center justify-between gap-2 bg-white rounded-lg px-3 py-2 text-sm">
                        <span class="text-stone-700 font-medium">{{ $row->raw_username }}</span>
                        <span class="text-stone-500 text-xs">{{ $rp($row->gmv) }} · {{ $row->orders }} order</span>
                        @if($canManage)
                            <div class="flex items-center gap-2 flex-wrap">
                                <form method="POST" action="{{ route('kol-affiliate.match') }}" class="flex items-center gap-1">
                                    @csrf
                                    <input type="hidden" name="raw_username" value="{{ $row->raw_username }}">
                                    <input type="text" data-select-search="matchsel{{ $loop->index }}" placeholder="cari KOL…" class="w-28 px-2 py-1 border border-stone-300 rounded text-xs">
                                    <select name="kol_id" id="matchsel{{ $loop->index }}" required class="px-2 py-1 border border-stone-300 rounded text-xs bg-white">
                                        <option value="">tautkan ke…</option>
                                        @foreach($kols as $k)
                                            <option value="{{ $k->id }}">{{ '@'.$k->tiktok_username }}</option>
                                        @endforeach
                                    </select>
                                    <button class="px-2 py-1 bg-stone-700 hover:bg-stone-800 text-white text-xs rounded">Tautkan</button>
                                </form>
                                <span class="text-[10px] text-stone-400">atau</span>
                                <form method="POST" action="{{ route('kol-affiliate.promote') }}"
                                    onsubmit="return confirm('Tambahkan @{{ $row->raw_username }} ke Database KOL sebagai affiliate baru? Semua ordernya ikut tertaut.')">
                                    @csrf
                                    <input type="hidden" name="raw_username" value="{{ $row->raw_username }}">
                                    <button class="px-2 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded font-semibold">+ Jadikan KOL</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Ranking --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-stone-500 text-xs">
                    <tr>
                        <th class="text-left px-4 py-2.5">#</th>
                        <th class="text-left px-4 py-2.5">Creator</th>
                        <th class="text-right px-4 py-2.5">GMV</th>
                        <th class="text-right px-4 py-2.5">Order</th>
                        <th class="text-right px-4 py-2.5">Komisi (est/settled)</th>
                        <th class="text-right px-4 py-2.5">Views</th>
                        <th class="text-right px-4 py-2.5">RPM</th>
                        <th class="text-center px-3 py-2.5">Tren 4mgg</th>
                        <th class="text-center px-4 py-2.5">APS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($ranking as $i => $r)
                        <tr>
                            <td class="px-4 py-2.5 text-stone-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2.5"><a href="{{ route('kols.show', $r->kol_id) }}" class="text-indigo-600 hover:underline">{{ '@'.$r->kol->tiktok_username }}</a></td>
                            <td class="px-4 py-2.5 text-right font-medium text-stone-800">{{ $rp($r->gmv) }}</td>
                            <td class="px-4 py-2.5 text-right text-stone-600">{{ number_format((int) $r->orders, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right text-stone-600">{{ $rc($r->commission) }} <span class="text-stone-300">/</span> {{ $r->commission_settled ? $rc($r->commission_settled) : '—' }}</td>
                            @php $rm = $rpmMap[$r->kol_id] ?? ['views' => 0, 'rpm' => null]; $spark = $sparkMap[$r->kol_id] ?? []; $sparkMax = max(1, ...(count($spark) ? $spark : [1])); @endphp
                            <td class="px-4 py-2.5 text-right text-stone-600">{{ $rm['views'] ? number_format($rm['views'], 0, ',', '.') : '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-stone-600">{{ $rm['rpm'] !== null ? $rp($rm['rpm']) : '—' }}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-end gap-0.5 justify-center h-6">
                                    @foreach($spark as $g)<div class="w-1.5 bg-red-400 rounded-sm" style="height: {{ max(2, round($g / $sparkMax * 100)) }}%" title="{{ $rc($g) }}"></div>@endforeach
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                @php $a = $aps[$r->kol_id] ?? null; @endphp
                                @if($a && $a['status'] === 'scored')
                                    @php $tone = ['bina_intensif' => 'bg-emerald-100 text-emerald-700', 'pantau' => 'bg-amber-100 text-amber-700', 'nurture' => 'bg-stone-100 text-stone-500'][$a['label']]; @endphp
                                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $tone }}" title="{{ $apsLabels[$a['label']] }}">{{ rtrim(rtrim(number_format($a['score'], 1, ',', '.'), '0'), ',') }}</span>
                                    @if($a['capped'])<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700" title="Skor dibatasi 40 — 2 minggu tanpa posting">cap 40</span>@endif
                                @else
                                    <span class="text-[10px] text-stone-300">new</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-stone-400 text-sm">Belum ada data affiliate bulan ini. Import dulu dari Affiliate Center.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($canManage)
        {{-- Statistik mingguan manual + riwayat --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-800 mb-3">Statistik Mingguan Manual</p>
            <form method="POST" action="{{ route('kol-affiliate.weekly.store') }}" class="grid grid-cols-2 sm:grid-cols-7 gap-2 text-sm mb-4">
                @csrf
                <select name="kol_id" required class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs bg-white col-span-2 sm:col-span-1">
                    <option value="">Creator…</option>
                    @foreach($kols as $k)<option value="{{ $k->id }}">{{ $k->tiktok_username }}</option>@endforeach
                </select>
                <input type="date" name="week_start" value="{{ now()->startOfWeek()->toDateString() }}" required class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs" title="awal minggu">
                <input type="number" name="gmv" min="0" placeholder="GMV" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <input type="number" name="orders" min="0" placeholder="Order" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <input type="number" name="commission" min="0" placeholder="Komisi" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <input type="number" name="content_count" min="0" placeholder="Konten" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <input type="number" name="views" min="0" placeholder="Views" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                <div class="col-span-2 sm:col-span-7"><button class="px-4 py-1.5 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800">Simpan minggu</button> <span class="text-[10px] text-stone-400 ml-2">minggu sama = perbarui</span></div>
            </form>
            @if($weeklyStats->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-stone-50 text-stone-500"><tr>
                            <th class="text-left px-3 py-2">Minggu</th><th class="text-left px-3 py-2">Creator</th>
                            <th class="text-right px-3 py-2">GMV</th><th class="text-right px-3 py-2">Order</th>
                            <th class="text-right px-3 py-2">Komisi</th><th class="text-right px-3 py-2">Konten</th><th class="text-right px-3 py-2">Views</th><th class="px-3 py-2"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($weeklyStats as $ws)
                                <tr>
                                    <td class="px-3 py-2 text-stone-500">{{ $ws->week_start->format('d M Y') }}</td>
                                    <td class="px-3 py-2">{{ '@'.($ws->kol->tiktok_username ?? '?') }}</td>
                                    <td class="px-3 py-2 text-right text-stone-700">{{ $rp($ws->gmv) }}</td>
                                    <td class="px-3 py-2 text-right text-stone-600">{{ number_format($ws->orders, 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-right text-stone-600">{{ $rp($ws->commission) }}</td>
                                    <td class="px-3 py-2 text-right text-stone-600">{{ $ws->content_count }}</td>
                                    <td class="px-3 py-2 text-right text-stone-600">{{ number_format($ws->views, 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-right"><form method="POST" action="{{ route('kol-affiliate.weekly.destroy', $ws) }}" onsubmit="return confirm('Hapus?')">@csrf @method('DELETE')<button class="text-[11px] text-rose-400 hover:text-rose-600">hapus</button></form></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-xs text-stone-400">Belum ada input mingguan.</p>
            @endif
        </div>

        {{-- Log batch import --}}
        @if($batches->isNotEmpty())
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <p class="text-sm font-bold text-stone-800 mb-3">Riwayat Import</p>
                <div class="space-y-1.5">
                    @foreach($batches as $b)
                        <div class="flex items-center justify-between gap-2 text-xs border-b border-stone-50 last:border-0 py-1.5">
                            <span class="text-stone-600">{{ $b->created_at?->format('d M Y H:i') }} · <b>{{ ucfirst($b->platform) }}</b>{{ $b->filename ? ' · '.$b->filename : '' }}{{ $b->creator ? ' · '.$b->creator->fullname : '' }}</span>
                            <span class="text-stone-500">{{ number_format($b->imported, 0, ',', '.') }} import · {{ $b->matched }} cocok · <span class="{{ $b->unmatched ? 'text-amber-600' : '' }}">{{ $b->unmatched }} belum</span></span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
