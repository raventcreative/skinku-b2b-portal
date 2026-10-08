@extends('layouts.app')
@section('title', 'Konten & Views')
@section('heading', 'Konten & Views')

@section('content')
@php $u = auth()->user(); $canManage = $u->canDo('kol.content.manage'); @endphp

<div class="mx-auto max-w-[1440px] space-y-5 px-1 sm:px-2">

    {{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}

    {{-- Nav bulan + aksi --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex items-center gap-3 rounded-xl border border-stone-200 bg-white p-1.5 text-sm shadow-sm">
            <a aria-label="Bulan sebelumnya" title="Bulan sebelumnya" href="{{ route('kol-konten.index', ['bulan' => $prevMonth]) }}" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-stone-600 hover:bg-stone-100"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg></a>
            <span class="font-semibold text-stone-800 min-w-32 text-center">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }}</span>
            <a aria-label="Bulan berikutnya" title="Bulan berikutnya" href="{{ route('kol-konten.index', ['bulan' => $nextMonth]) }}" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-stone-600 hover:bg-stone-100"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg></a>
        </div>
        @if($canManage)
            <div class="flex gap-2">
                <a href="{{ route('kol-konten.grid', ['bulan' => $month]) }}" class="inline-flex items-center gap-2 px-4 py-2.5 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16v14H4zM4 10h16M9 5v14m6-14v14"/></svg>Isi views massal</a>
                <a href="{{ route('kol-konten.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>Tambah konten</a>
            </div>
        @endif
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap items-center gap-2 text-xs rounded-2xl border border-stone-200 bg-white p-3 shadow-sm">
        <input type="hidden" name="bulan" value="{{ $month }}">
        <select name="creator" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg bg-white max-w-[160px]">
            <option value="">Semua creator</option>
            @foreach($kols as $k)<option value="{{ $k->id }}" @selected(($filters['creator'] ?? '') == $k->id)>{{ $k->tiktok_username }}</option>@endforeach
        </select>
        <select name="platform" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg bg-white">
            <option value="">Semua platform</option>
            @foreach($platforms as $key => $p)<option value="{{ $key }}" @selected(($filters['platform'] ?? '') === $key)>{{ $p['label'] }}</option>@endforeach
        </select>
        <select name="label" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg bg-white">
            <option value="">Semua label</option>
            <option value="paid" @selected(($filters['label'] ?? '') === 'paid')>Paid</option>
            <option value="earned" @selected(($filters['label'] ?? '') === 'earned')>Earned</option>
        </select>
        <select name="type" onchange="this.form.submit()" class="px-2 py-1.5 border border-stone-300 rounded-lg bg-white">
            <option value="">Semua tipe</option>
            @foreach($types as $val => $lbl)<option value="{{ $val }}" @selected(($filters['type'] ?? '') === $val)>{{ $lbl }}</option>@endforeach
        </select>
        @if(array_filter($filters))<a href="{{ route('kol-konten.index', ['bulan' => $month]) }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-red-700 hover:bg-red-50 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M7 7V4h10v3m-9 0 1 13h6l1-13"/></svg>Reset filter</a>@endif
    </form>

    {{-- Ringkasan --}}
    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-stone-200 p-5 shadow-sm">
            <p class="text-xs text-stone-500">Total views bulan ini</p>
            <p class="text-2xl font-bold text-stone-800">{{ number_format($total, 0, ',', '.') }}</p>
            <p class="text-[11px] text-stone-400">{{ $contents->count() }} konten</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-5 shadow-sm">
            <p class="text-xs text-stone-500">Paid vs Earned</p>
            <p class="text-sm font-semibold text-stone-800 mt-1">
                <span class="text-indigo-600">{{ number_format($paid, 0, ',', '.') }}</span> paid ·
                <span class="text-emerald-600">{{ number_format($earned, 0, ',', '.') }}</span> earned
            </p>
            <p class="text-[11px] text-stone-400">{{ $total > 0 ? round($paid / $total * 100) : 0 }}% paid</p>
        </div>
        <div class="bg-white rounded-2xl border border-stone-200 p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs text-stone-500">Target &amp; proyeksi</p>
                @if($isCurrent)
                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $aman ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $aman ? 'Aman' : 'Berisiko' }}</span>
                @endif
            </div>
            @if($isCurrent)
                <p class="text-sm font-semibold text-stone-800 mt-1">Proyeksi {{ number_format($proj, 0, ',', '.') }}</p>
            @endif
            @if($canManage)
                <form method="POST" action="{{ route('kol-konten.target') }}" class="mt-1 flex items-center gap-1">
                    @csrf
                    <span class="text-[11px] text-stone-400">target</span>
                    <input type="number" name="target" min="0" value="{{ $target }}" class="w-28 px-2 py-1 border border-stone-300 rounded-sm text-xs text-right">
                    <button aria-label="Simpan target views" title="Simpan target" class="inline-flex items-center justify-center w-8 h-8 text-indigo-700 border border-indigo-100 rounded-lg hover:bg-indigo-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
                </form>
            @else
                <p class="text-[11px] text-stone-400">target {{ number_format($target, 0, ',', '.') }}</p>
            @endif
            @if($target > 0)
                @php $paidPct = min(100, round($paid / $target * 100)); $earnedPct = min(100 - $paidPct, round($earned / $target * 100)); $pct = min(100, round($total / $target * 100)); @endphp
                <div class="mt-2">
                    <div class="h-2 bg-stone-100 rounded-full overflow-hidden flex">
                        <div class="h-full bg-indigo-500" style="width: {{ $paidPct }}%" title="paid"></div>
                        <div class="h-full bg-emerald-500" style="width: {{ $earnedPct }}%" title="earned"></div>
                    </div>
                    <p class="text-[10px] text-stone-400 mt-1">{{ $pct }}% dari target{{ $isCurrent && $perDayNeeded ? ' · butuh ~'.number_format($perDayNeeded, 0, ',', '.').' views/hari ('.$daysLeft.' hari lagi)' : '' }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Tabel konten --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden shadow-sm">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-stone-100">
            <div><h2 class="text-sm font-bold text-stone-800">Performa konten</h2><p class="text-xs text-stone-500 mt-0.5">Views terbaru, engagement, dan riwayat setiap posting.</p></div>
            <span class="text-xs text-stone-500">{{ $contents->count() }} konten</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-stone-500 text-xs">
                    <tr>
                        <th class="text-left px-4 py-2.5">Konten</th>
                        <th class="text-left px-4 py-2.5">KOL</th>
                        <th class="text-left px-4 py-2.5">Label</th>
                        <th class="text-left px-4 py-2.5">Tipe</th>
                        <th class="text-right px-4 py-2.5">Views</th>
                        <th class="text-right px-4 py-2.5">Like / Komen</th>
                        <th class="text-left px-4 py-2.5">Tanggal</th>
                        @if($canManage)<th class="px-4 py-2.5"></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($contents as $c)
                        <tr>
                            <td class="px-4 py-2.5 max-w-xs">
                                <a href="{{ $c->url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline truncate block">{{ $c->title ?: $c->url }}</a>
                                <span class="flex items-center gap-2">
                                    <a href="{{ route('kol-konten.show', $c) }}" class="inline-flex items-center gap-1 text-[10px] font-semibold text-stone-600 hover:text-red-700"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 14l4-4 3 3 6-7"/></svg>Riwayat &amp; grafik</a>
                                    @if($c->deal)<span class="text-[10px] text-stone-400">· deal {{ $c->deal->kode }}</span>@endif
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-stone-600">{{ '@'.$c->kol->tiktok_username }}</td>
                            <td class="px-4 py-2.5">
                                <span class="text-[10px] px-2 py-0.5 rounded-full {{ $c->label === 'paid' ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $c->label }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-stone-500 text-xs">{{ $c->content_type ? (\App\Models\KolContent::TYPE_LABELS[$c->content_type] ?? $c->content_type) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-stone-700">
                                {{ number_format((int) ($c->latestSnapshot->views ?? 0), 0, ',', '.') }}
                                @if($c->latestSnapshot)<span class="block text-[10px] text-stone-400">{{ $c->latestSnapshot->captured_on->format('d M') }} · {{ $c->latestSnapshot->source }}</span>@endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-stone-500 text-xs">
                                {{ $c->latestSnapshot && $c->latestSnapshot->likes !== null ? number_format($c->latestSnapshot->likes, 0, ',', '.') : '—' }} / {{ $c->latestSnapshot && $c->latestSnapshot->comments !== null ? number_format($c->latestSnapshot->comments, 0, ',', '.') : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-stone-500">{{ $c->posted_at->format('d M Y') }}</td>
                            @if($canManage)
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <a href="{{ route('kol-konten.edit', $c) }}" aria-label="Edit konten {{ $c->title ?: $c->url }}" title="Edit konten" class="inline-flex items-center justify-center w-8 h-8 text-stone-600 border border-stone-200 rounded-lg hover:bg-stone-100"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 5.5 3 3M4 20l4.5-.9L19.3 8.3a2.1 2.1 0 0 0-3-3L5.5 16.1 4 20Z"/></svg></a>
                                    <form method="POST" action="{{ route('kol-konten.destroy', $c) }}" class="inline ml-1" onsubmit="return confirm('Hapus konten ini?')">
                                        @csrf @method('DELETE')
                                        <button aria-label="Hapus konten {{ $c->title ?: $c->url }}" title="Hapus konten" class="inline-flex items-center justify-center w-8 h-8 text-rose-600 border border-rose-100 rounded-lg hover:bg-rose-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg></button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canManage ? 8 : 7 }}" class="px-4 py-10 text-center text-stone-400 text-sm">Belum ada konten bulan ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
