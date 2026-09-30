@extends('layouts.app')
@section('title', 'Deal KOL')
@section('heading', 'Kerjasama / Deal KOL')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $canFinance = $u->canDo('kol.deal.finance');
    $canApprove = $u->canDo('kol.deal.approve');   // Acc/Tolak — penyetuju saja
    $plainVerdict = fn ($v) => preg_replace('/^[^\\pL\\pN]+/u', '', (string) $v);
    $statusBadge = [
        'draft' => 'bg-stone-100 text-stone-600',
        'berjalan' => 'bg-blue-100 text-blue-700',
        'selesai' => 'bg-emerald-100 text-emerald-700',
        'batal' => 'bg-rose-100 text-rose-700',
    ];
    // Warna badge level (samakan dengan tabel Database KOL).
    $levelBadge = [
        'Nano' => 'bg-stone-100 text-stone-600', 'Mikro' => 'bg-sky-100 text-sky-700',
        'Middle' => 'bg-indigo-100 text-indigo-700', 'Makro' => 'bg-violet-100 text-violet-700',
        'Mega' => 'bg-amber-100 text-amber-700', 'Super Mega' => 'bg-rose-100 text-rose-700',
    ];
    $cur = request('status');
@endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Database KOL</a>
    <form method="GET" class="flex items-center gap-1">
        <select name="status" onchange="this.form.submit()" class="px-2 py-1.5 text-xs border border-stone-300 rounded-lg">
            <option value="">Semua status</option>
            @foreach(\App\Models\KolDeal::STATUSES as $s)
                <option value="{{ $s }}" @selected($cur === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        @if($bulan)<input type="hidden" name="bulan" value="{{ $bulan }}">@endif
    </form>
    {{-- Month picker: scope budget & (bila dipilih) daftar deal ke satu bulan. --}}
    <div class="flex items-center gap-1 text-xs">
        <a href="{{ route('kol-deals.index', ['bulan' => $prevMonth, 'status' => $cur]) }}" class="px-2 py-1.5 border border-stone-300 rounded-lg hover:bg-stone-50" title="Bulan sebelumnya">←</a>
        <span class="px-2 py-1.5 font-semibold text-stone-700 {{ $bulan ? '' : 'text-stone-400' }}">{{ $monthLabel }}{{ $bulan ? '' : ' (berjalan)' }}</span>
        <a href="{{ route('kol-deals.index', ['bulan' => $nextMonth, 'status' => $cur]) }}" class="px-2 py-1.5 border border-stone-300 rounded-lg hover:bg-stone-50" title="Bulan berikutnya">→</a>
        @if($bulan)<a href="{{ route('kol-deals.index', ['status' => $cur]) }}" class="ml-1 text-stone-400 hover:text-stone-700">semua bulan</a>@endif
    </div>
    <a href="{{ route('kol-campaigns.index') }}" class="ml-auto inline-flex items-center gap-2 px-4 py-2 text-sm bg-white border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m0 14h16M7 15l4-4 3 2 6-7"/></svg>Campaign</a>
    <a href="{{ route('kol-deals.laporan') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm bg-white border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m0 14h16M8 15v-4m4 4V8m4 7v-6"/></svg>Ringkasan Hasil</a>
    <a href="{{ route('kol-deals.create') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>Deal Baru</a>
</div>

@if($budget)
    @php
        // Utilisasi budget: Spent (lunas) + Committed (belum lunas) terhadap cap.
        $cap = max(1, (float) $budget['budget']);
        $spentPct = (int) min(100, round($budget['spent'] / $cap * 100));
        $commitPct = (int) min(100 - $spentPct, round($budget['committed'] / $cap * 100));
        $terpakaiPct = (int) round(($budget['spent'] + $budget['committed']) / $cap * 100);
    @endphp
    <div class="mb-4 space-y-3">
        {{-- Header + setelan cap/anchor --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-semibold text-stone-700">Budget {{ $monthLabel }}</p>
            <form method="POST" action="{{ route('kol-deals.budget') }}" class="flex items-center gap-1 text-xs">
                @csrf
                <span class="text-stone-400">cap</span>
                <input type="number" name="budget" min="0" value="{{ $budget['budget'] }}" class="w-28 px-2 py-1 border border-stone-300 rounded-sm text-right">
                <span class="text-stone-400">CPM anchor</span>
                <input type="number" name="anchor" min="0" value="{{ $budget['anchor'] }}" class="w-20 px-2 py-1 border border-stone-300 rounded-sm text-right">
                <button aria-label="Simpan budget dan CPM anchor" title="Simpan budget" class="inline-flex items-center justify-center w-8 h-8 text-indigo-700 border border-indigo-100 rounded-lg hover:bg-indigo-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
            </form>
        </div>

        {{-- Kartu-kotak terpisah per metrik (gaya laporan Iyuro) --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">Budget bulan ini</p>
                <p class="text-xl font-bold text-stone-800 mt-1">{{ $rp($budget['budget']) }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">Spent (lunas)</p>
                <p class="text-xl font-bold text-stone-800 mt-1">{{ $rp($budget['spent']) }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">Committed (belum lunas)</p>
                <p class="text-xl font-bold text-amber-600 mt-1">{{ $rp($budget['committed']) }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">Sisa</p>
                <p class="text-xl font-bold {{ $budget['sisa'] < 0 ? 'text-rose-600' : 'text-emerald-600' }} mt-1">{{ $rp($budget['sisa']) }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">Blended CPM paid</p>
                <p class="text-xl font-bold {{ $budget['overAnchor'] ? 'text-rose-600' : 'text-stone-800' }} mt-1">{{ $budget['cpm'] !== null ? $rp($budget['cpm']) : '—' }}</p>
                <p class="text-[10px] text-stone-400">anchor {{ $rp($budget['anchor']) }}</p>
            </div>
        </div>

        {{-- Bar utilisasi: merah = lunas, amber = committed, sisanya kosong --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <div class="flex items-center justify-between text-xs mb-1.5">
                <span class="text-stone-500">Terpakai {{ $terpakaiPct }}% dari {{ $rp($budget['budget']) }}</span>
                <span class="flex items-center gap-3 text-[10px] text-stone-500">
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-xs bg-red-500 inline-block"></span>Lunas</span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-xs bg-amber-400 inline-block"></span>Committed</span>
                </span>
            </div>
            <div class="h-2.5 w-full bg-stone-100 rounded-full overflow-hidden flex">
                <div class="h-full bg-red-500" style="width: {{ $spentPct }}%"></div>
                <div class="h-full bg-amber-400" style="width: {{ $commitPct }}%"></div>
            </div>
            @if($budget['overConcentration'] || $budget['overAnchor'])
                <div class="mt-3 flex flex-wrap gap-2">
                    @if($budget['overConcentration'])<span class="inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded-full bg-amber-100 text-amber-800"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 2.8 20h18.4L12 3Zm0 6v5m0 3h.01"/></svg>1 KOL menyerap {{ $budget['topSharePct'] }}% budget (batas {{ $budget['shareLimitPct'] }}%)</span>@endif
                    @if($budget['overAnchor'])<span class="inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded-full bg-rose-100 text-rose-700"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 2.8 20h18.4L12 3Zm0 6v5m0 3h.01"/></svg>CPM paid di atas anchor</span>@endif
                </div>
            @endif
        </div>

        {{-- Rincian per-creator: biaya deal + share bar terhadap budget --}}
        @if($budget['perCreator']->isNotEmpty())
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs font-semibold text-stone-600 mb-2">Biaya per Creator — {{ $monthLabel }}</p>
                <div class="space-y-1.5">
                    @foreach($budget['perCreator']->take(8) as $c)
                        <div class="flex items-center gap-3 text-xs">
                            <span class="w-32 truncate text-stone-600">{{ $c['name'] }}</span>
                            <span class="text-[10px] text-stone-400 w-14 shrink-0">{{ $c['deals'] }} deal</span>
                            <div class="flex-1 h-2 bg-stone-100 rounded-full overflow-hidden">
                                <div class="h-full {{ $c['sharePct'] > $budget['shareLimitPct'] ? 'bg-amber-400' : 'bg-red-400' }}" style="width: {{ min(100, $c['sharePct']) }}%"></div>
                            </div>
                            <span class="w-24 text-right text-stone-700 tabular-nums shrink-0">{{ $rp($c['cost']) }}</span>
                            <span class="w-10 text-right text-[10px] text-stone-400 shrink-0">{{ $c['sharePct'] }}%</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Pengeluaran budget tambahan (boost/hadiah/dll) — masuk "spent" --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs font-semibold text-stone-600">Pengeluaran Tambahan — {{ $monthLabel }}</p>
                @if($budget['extras'] > 0)<span class="text-xs text-stone-500">total <b class="text-stone-700 tabular-nums">{{ $rp($budget['extras']) }}</b></span>@endif
            </div>
            @if($extraTx->isNotEmpty())
                <div class="divide-y divide-stone-50 mb-3">
                    @foreach($extraTx as $tx)
                        <div class="flex items-center justify-between py-1.5 text-xs">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded-sm bg-stone-100 text-stone-600 text-[10px]">{{ $tx->categoryLabel() }}</span>
                                <span class="text-stone-600">{{ $tx->note ?: '—' }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-stone-700 tabular-nums">{{ $rp($tx->amount) }}</span>
                                <form method="POST" action="{{ route('kol-deals.budget-tx.destroy', $tx) }}" onsubmit="return confirm('Hapus pengeluaran ini?')">
                                    @csrf @method('DELETE')
                                    <button aria-label="Hapus pengeluaran" title="Hapus pengeluaran" class="inline-flex items-center justify-center w-8 h-8 text-rose-600 border border-rose-100 rounded-lg hover:bg-rose-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg></button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            <form method="POST" action="{{ route('kol-deals.budget-tx.store') }}" class="flex flex-wrap items-end gap-2 text-xs">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <select name="category" class="px-2 py-1.5 border border-stone-300 rounded-lg bg-white">
                    @foreach($txCategories as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                </select>
                <input type="number" name="amount" min="1" placeholder="nominal (Rp)" required class="w-32 px-2 py-1.5 border border-stone-300 rounded-lg tabular-nums">
                <input name="note" maxlength="200" placeholder="catatan (opsional)" class="flex-1 min-w-32 px-2 py-1.5 border border-stone-300 rounded-lg">
                <button class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-stone-800 text-white rounded-lg hover:bg-stone-900 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>Catat</button>
            </form>
        </div>
    </div>
@endif

{{-- Bar aksi massal (muncul saat ada centang) --}}
<div id="bulkBar" class="hidden items-center gap-2 mb-3 p-2 bg-stone-800 text-white rounded-xl text-xs">
    <span id="bulkCount" class="px-2 font-semibold">0 dipilih</span>
    @if($canApprove)<button type="button" onclick="submitBulk('berjalan')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 rounded-lg hover:bg-blue-700 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>Acc</button>@endif
    <button type="button" onclick="submitBulk('selesai')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 rounded-lg hover:bg-emerald-700 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>Selesai</button>
    @if($canApprove)<button type="button" onclick="submitBulk('batal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-600 rounded-lg hover:bg-rose-700 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m7 7 10 10M17 7 7 17"/></svg>Tolak</button>@endif
    <button type="button" onclick="clearChecks()" class="ml-auto inline-flex items-center gap-1.5 px-2 text-stone-300 hover:text-white"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m7 7 10 10M17 7 7 17"/></svg>Batal pilih</button>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full min-w-[1180px] text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="px-3 py-2"><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th>
                <th class="text-left px-2">Kode</th><th class="text-left">KOL &amp; Indikator</th><th class="text-left">Jenis</th>
                <th class="text-right">Ratecard</th><th class="text-left px-3">Periode</th>
                <th class="text-left">PIC</th><th class="text-left">Status</th>
                @if($canFinance)<th class="text-right">Total Biaya</th><th class="text-left px-3">Bayar</th>@endif
                <th class="text-left">Hasil</th>
                <th class="text-right px-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($deals as $d)
                @php $sc = $d->kol?->latestScreening; @endphp
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-3"><input type="checkbox" class="kolDealCheck" value="{{ $d->id }}" onchange="refreshBulk()"></td>
                    <td class="px-2 py-2.5 font-semibold"><a href="{{ route('kol-deals.show', $d) }}" class="text-stone-700 hover:text-red-700 hover:underline">{{ $d->kode }}</a></td>
                    <td>
                        <a href="{{ route('kols.show', $d->kol_id) }}" class="text-red-700 hover:underline font-semibold">{{ '@'.($d->kol->tiktok_username ?? '?') }}</a>
                        @if($d->campaign)<span class="block text-[10px] text-indigo-600">Campaign · {{ $d->campaign->name }}</span>@endif
                        <div class="flex flex-wrap items-center gap-1 mt-0.5">
                            <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-semibold {{ $levelBadge[$d->kol?->level] ?? 'bg-stone-100 text-stone-600' }}">{{ $d->kol?->level ?? '—' }}</span>
                            @if($sc)
                                <span class="text-[10px] text-stone-500">{{ $plainVerdict($sc->verdict_median) }}</span>
                                @if($sc->cpv_median !== null)<span class="text-[10px] text-stone-400">CPV {{ number_format($sc->cpv_median, 0, ',', '.') }}</span>@endif
                            @else
                                <span class="text-[10px] text-stone-300">belum screening</span>
                            @endif
                        </div>
                    </td>
                    <td class="uppercase text-stone-600">{{ $d->jenis }}{{ $d->jenis === 'vt' ? ' ×'.$d->jumlah_slot : '' }}</td>
                    <td class="text-right text-stone-700">{{ $rp($d->ratecard_deal) }}</td>
                    <td class="px-3 text-stone-600">{{ $d->periode_mulai?->format('d M') }} – {{ $d->periode_selesai?->format('d M Y') ?: '—' }}</td>
                    <td class="text-stone-600">{{ $d->pic->fullname ?? '—' }}</td>
                    <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $statusBadge[$d->status] ?? 'bg-stone-100 text-stone-600' }}">{{ $d->status }}</span></td>
                    @if($canFinance)
                        <td class="text-right text-stone-700">{{ $rp($d->total_biaya) }}</td>
                        <td class="px-3 text-stone-600">{{ $d->status_bayar }}</td>
                    @endif
                    <td>
                        <button type="button" class="hasilBtn inline-flex items-center gap-1.5 text-[10px] font-semibold rounded-lg px-2 py-1.5 border border-stone-200 hover:border-red-200 hover:bg-red-50 @if(! $d->hasil_terisi) text-stone-500 @else text-red-700 @endif"
                            data-hasil="{{ json_encode(['id' => $d->id, 'kode' => $d->kode, 'tujuan' => $d->hasil_tujuan, 'video_upload' => $d->hasil_video_upload, 'video_fyp' => $d->hasil_video_fyp, 'views' => $d->hasil_views, 'revenue' => $d->hasil_revenue, 'catatan' => $d->hasil_catatan]) }}"
                            onclick="openHasil(this)" title="Isi atau lihat laporan hasil"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m0 14h16M7 15l4-4 3 2 6-7"/></svg>{{ $d->hasil_terisi ? $plainVerdict($d->hasil_verdict) : 'Isi laporan' }}</button>
                    </td>
                    <td class="text-right px-4">
                        @if($canApprove && $d->status !== 'berjalan')<button type="button" aria-label="Acc deal {{ $d->kode }}" onclick="submitBulk('berjalan', {{ $d->id }})" class="inline-flex items-center justify-center w-8 h-8 text-blue-700 border border-blue-100 rounded-lg hover:bg-blue-50" title="Acc dan jalankan"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg></button>@endif
                        @if($d->status !== 'selesai')<button type="button" aria-label="Tandai deal {{ $d->kode }} selesai" onclick="submitBulk('selesai', {{ $d->id }})" class="inline-flex items-center justify-center w-8 h-8 ml-1 text-emerald-700 border border-emerald-100 rounded-lg hover:bg-emerald-50" title="Tandai selesai"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg></button>@endif
                        @if($canApprove && $d->status !== 'batal')<button type="button" aria-label="Tolak deal {{ $d->kode }}" onclick="submitBulk('batal', {{ $d->id }})" class="inline-flex items-center justify-center w-8 h-8 ml-1 text-rose-600 border border-rose-100 rounded-lg hover:bg-rose-50" title="Tolak deal"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m7 7 10 10M17 7 7 17"/></svg></button>@endif
                        <a aria-label="Detail deal {{ $d->kode }}" title="Detail deal" href="{{ route('kol-deals.show', $d) }}" class="inline-flex items-center justify-center w-8 h-8 ml-1 text-stone-600 border border-stone-200 rounded-lg hover:bg-stone-100"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></a>
                        <a aria-label="Edit deal {{ $d->kode }}" title="Edit deal" href="{{ route('kol-deals.edit', $d) }}" class="inline-flex items-center justify-center w-8 h-8 ml-1 text-stone-600 border border-stone-200 rounded-lg hover:bg-stone-100"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 5.5 3 3M4 20l4.5-.9L19.3 8.3a2.1 2.1 0 0 0-3-3L5.5 16.1 4 20Z"/></svg></a>
                        <form method="POST" action="{{ route('kol-deals.destroy', $d) }}" class="inline"
                            onsubmit="return confirm('Hapus deal {{ $d->kode }}? (soft delete, tercatat di Audit Log)')">
                            @csrf @method('DELETE')
                            <button aria-label="Hapus deal {{ $d->kode }}" title="Hapus deal" class="inline-flex items-center justify-center w-8 h-8 ml-1 text-rose-600 border border-rose-100 rounded-lg hover:bg-rose-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canFinance ? 12 : 10 }}" class="px-4 py-8 text-center text-stone-400">Belum ada deal.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($deals->hasPages())
        <div class="px-4 py-3 border-t border-stone-100">{{ $deals->links() }}</div>
    @endif
</div>

<script>
    function checkedIds() {
        return Array.from(document.querySelectorAll('.kolDealCheck:checked')).map(function (c) { return c.value; });
    }
    function refreshBulk() {
        var ids = checkedIds();
        var bar = document.getElementById('bulkBar');
        document.getElementById('bulkCount').textContent = ids.length + ' dipilih';
        bar.classList.toggle('hidden', ids.length === 0);
        bar.classList.toggle('flex', ids.length > 0);
    }
    function toggleAll(box) {
        document.querySelectorAll('.kolDealCheck').forEach(function (c) { c.checked = box.checked; });
        refreshBulk();
    }
    function clearChecks() {
        document.querySelectorAll('.kolDealCheck').forEach(function (c) { c.checked = false; });
        var all = document.getElementById('checkAll'); if (all) all.checked = false;
        refreshBulk();
    }
    function submitBulk(status, singleId) {
        var ids = singleId ? [String(singleId)] : checkedIds();
        if (!ids.length) { alert('Pilih dulu deal-nya (centang di kiri).'); return; }
        var labels = { berjalan: 'Acc (jalankan)', selesai: 'tandai Selesai', batal: 'Tolak' };
        if (!confirm((labels[status] || status) + ' ' + ids.length + ' deal?')) return;
        var f = document.createElement('form');
        f.method = 'POST'; f.action = '{{ route('kol-deals.bulk-status') }}';
        f.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="hidden" name="status" value="' + status + '">';
        ids.forEach(function (id) {
            var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; f.appendChild(i);
        });
        document.body.appendChild(f); f.submit();
    }
</script>

{{-- Modal Laporan Hasil — isi tanpa pindah halaman, submit AJAX (verdict update di baris). --}}
<dialog id="hasilModal" class="rounded-2xl p-0 w-full max-w-lg backdrop:bg-black/40">
    <form id="hasilForm" method="POST" class="p-5">
        @csrf
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-stone-900">Laporan Hasil — <span id="hm_kode" class="text-red-700"></span></h3>
            <button type="button" onclick="document.getElementById('hasilModal').close()" class="text-stone-400 hover:text-stone-700 text-lg leading-none">&times;</button>
        </div>
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
            <label class="text-[11px] font-semibold text-stone-500">Tujuan endorse
                <select name="hasil_tujuan" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— pilih tujuan —</option>
                    <option value="penjualan">Penjualan (dinilai ROMI)</option>
                    <option value="awareness">Awareness / Views (dinilai CPM)</option>
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Total video ter-upload
                <input type="number" name="hasil_video_upload" min="0" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Jumlah video FYP
                <input type="number" name="hasil_video_fyp" min="0" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Total views
                <input type="number" name="hasil_views" min="0" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500 sm:col-span-2">Total revenue (Rp) <span class="text-stone-400 font-normal">— boleh kosong bila awareness</span>
                <input type="number" name="hasil_revenue" min="0" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500 sm:col-span-2">Catatan hasil
                <textarea name="hasil_catatan" rows="2" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm"></textarea>
            </label>
        </div>
        <div class="flex items-center gap-2 mt-4">
            <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Laporan</button>
            <button type="button" onclick="document.getElementById('hasilModal').close()" class="px-3 py-2 text-sm text-stone-500 hover:text-stone-800">Tutup</button>
            <span id="hm_status" class="ml-auto inline-flex items-center gap-1 text-[11px] text-emerald-700 hidden"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>Tersimpan</span>
        </div>
    </form>
</dialog>
<script>
    var hasilTarget = null;
    function openHasil(btn) {
        hasilTarget = btn;
        var d = JSON.parse(btn.dataset.hasil);
        var f = document.getElementById('hasilForm');
        f.action = '{{ url('kol-deals') }}/' + d.id + '/hasil';
        document.getElementById('hm_kode').textContent = d.kode;
        f.hasil_tujuan.value = d.tujuan || '';
        f.hasil_video_upload.value = (d.video_upload != null ? d.video_upload : '');
        f.hasil_video_fyp.value = (d.video_fyp != null ? d.video_fyp : '');
        f.hasil_views.value = (d.views != null ? d.views : '');
        f.hasil_revenue.value = (d.revenue != null ? d.revenue : '');
        f.hasil_catatan.value = d.catatan || '';
        document.getElementById('hm_status').classList.add('hidden');
        document.getElementById('hasilModal').showModal();
    }
    document.getElementById('hasilForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = e.target;
        fetch(f.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(f),
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
          .then(function (data) {
              if (hasilTarget) {
                  hasilTarget.textContent = data.verdict.replace(/^[^\\p{L}\\p{N}]+/u, '');
                  hasilTarget.classList.remove('text-stone-400');
              }
              document.getElementById('hm_status').classList.remove('hidden');
              setTimeout(function () { document.getElementById('hasilModal').close(); }, 600);
          }).catch(function () { alert('Gagal menyimpan laporan. Coba lagi.'); });
    });
</script>
@endsection
