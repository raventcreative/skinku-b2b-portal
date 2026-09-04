@extends('layouts.app')
@section('title', 'Tim Affiliate Gapok')
@section('heading', 'Tim Affiliate Gapok')

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $rc = fn ($n) => $n >= 1_000_000 ? 'Rp '.round($n / 1_000_000, 1).' jt' : 'Rp '.number_format($n, 0, ',', '.');
    $roiColor = function ($roi) {
        if ($roi === null) return 'bg-stone-100 text-stone-400';
        if ($roi >= 3) return 'bg-emerald-50 text-emerald-700';
        if ($roi >= 1) return 'bg-amber-50 text-amber-700';
        return 'bg-rose-50 text-rose-700';
    };
    $roiFmt = fn ($roi) => $roi !== null ? number_format($roi, 1, ',', '.').'×' : '—';
    $teamRoi = $totals['salary'] > 0 ? round($totals['gmv'] / $totals['salary'], 1) : null;
@endphp

<div class="space-y-4">
    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- Bulan --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('kol-gapok.index', ['bulan' => $prevMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">←</a>
            <span class="font-semibold text-stone-700">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }}</span>
            <a href="{{ route('kol-gapok.index', ['bulan' => $nextMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">→</a>
        </div>
        <a href="{{ route('kol-affiliate.index', ['bulan' => $month]) }}" class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">📈 Semua affiliate</a>
    </div>

    {{-- Ringkasan tim --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">GMV tim</p><p class="text-xl font-bold text-stone-800">{{ $rc($totals['gmv']) }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Komisi</p><p class="text-xl font-bold text-stone-800">{{ $rc($totals['commission']) }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Order</p><p class="text-xl font-bold text-stone-800">{{ number_format($totals['orders'], 0, ',', '.') }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Total gaji</p><p class="text-xl font-bold text-stone-800">{{ $rc($totals['salary']) }}</p></div>
        <div class="rounded-2xl border border-stone-200 p-4 {{ $roiColor($teamRoi) }}"><p class="text-xs opacity-70">ROI tim (GMV÷gaji)</p><p class="text-xl font-bold">{{ $roiFmt($teamRoi) }}</p></div>
    </div>

    {{-- Tabel performa --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-stone-500 border-b border-stone-200 bg-stone-50">
                        <th class="px-4 py-3">Kreator</th>
                        <th class="px-4 py-3 text-right">GMV</th>
                        <th class="px-4 py-3 text-right">Order</th>
                        <th class="px-4 py-3 text-right">Komisi</th>
                        <th class="px-4 py-3 text-right">Gaji pokok</th>
                        <th class="px-4 py-3 text-right">ROI</th>
                        @if($canManage)<th class="px-4 py-3"></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($rows as $r)
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-stone-800">{{ $r['kol']->display_name }}</p>
                                <p class="text-xs text-stone-400">{{ '@'.$r['kol']->tiktok_username }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <p class="font-semibold text-stone-800">{{ $rp($r['gmv']) }}</p>
                                @if($r['gmv_live'] || $r['gmv_video'])
                                    <p class="text-[10px] text-stone-400">🔴 LIVE {{ $rc($r['gmv_live']) }} · 🎬 Video {{ $rc($r['gmv_video']) }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-stone-700">{{ number_format($r['orders'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right text-stone-700">{{ $rp($r['commission']) }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($canManage)
                                    <form method="POST" action="{{ route('kol-gapok.salary') }}" class="flex items-center justify-end gap-1">
                                        @csrf
                                        <input type="hidden" name="kol_id" value="{{ $r['kol']->id }}">
                                        <input type="hidden" name="bulan" value="{{ $month }}">
                                        <span class="text-stone-400 text-xs">Rp</span>
                                        <input type="hidden" name="monthly_salary" value="{{ $r['salary'] }}">
                                        <input type="text" inputmode="numeric" placeholder="0"
                                               value="{{ $r['salary'] ? number_format($r['salary'], 0, ',', '.') : '' }}"
                                               class="salary-input w-28 px-2 py-1 border border-stone-300 rounded text-right text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                                        <button class="text-xs text-red-600 hover:underline">simpan</button>
                                    </form>
                                @else
                                    {{ $r['salary'] ? $rp($r['salary']) : '—' }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span class="inline-block px-2 py-1 rounded-lg text-xs font-bold {{ $roiColor($r['roi']) }}">{{ $roiFmt($r['roi']) }}</span>
                            </td>
                            @if($canManage)
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('kol-gapok.toggle') }}" onsubmit="return confirm('Keluarkan {{ $r['kol']->display_name }} dari Tim Gapok? (gaji tersimpan tetap ada)')">
                                        @csrf
                                        <input type="hidden" name="kol_id" value="{{ $r['kol']->id }}">
                                        <input type="hidden" name="is_gapok" value="0">
                                        <button class="text-xs text-stone-400 hover:text-rose-600" title="Keluarkan dari tim">✕</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canManage ? 7 : 6 }}" class="px-4 py-10 text-center text-stone-400 text-sm">Belum ada anggota Tim Gapok.@if($canManage) Tambah dari form di bawah.@endif</td></tr>
                    @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t border-stone-200 bg-stone-50 font-bold text-stone-800">
                            <td class="px-4 py-3">TOTAL ({{ $totals['members'] }} orang)</td>
                            <td class="px-4 py-3 text-right">{{ $rp($totals['gmv']) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($totals['orders'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">{{ $rp($totals['commission']) }}</td>
                            <td class="px-4 py-3 text-right">{{ $rp($totals['salary']) }}</td>
                            <td class="px-4 py-3 text-right">{{ $roiFmt($teamRoi) }}</td>
                            @if($canManage)<td></td>@endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Tambah anggota --}}
    @if($canManage)
        <div class="bg-white rounded-2xl border border-stone-200 p-4">
            <p class="text-sm font-semibold text-stone-700 mb-2">+ Tambah anggota gapok</p>
            @if($nonGapok->isEmpty())
                <p class="text-xs text-stone-400">Semua KOL sudah jadi anggota gapok. Kreator baru? Tambahkan dulu lewat <a href="{{ route('kol-affiliate.index') }}" class="text-red-600 hover:underline">Affiliate &amp; GMV</a> atau Database KOL.</p>
            @else
                <form method="POST" action="{{ route('kol-gapok.toggle') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="is_gapok" value="1">
                    <select name="kol_id" required class="px-3 py-2 border border-stone-300 rounded-xl text-sm min-w-[260px] focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="">— pilih kreator (klik lalu ketik namanya) —</option>
                        @foreach($nonGapok as $k)
                            <option value="{{ $k->id }}">{{ $k->tiktok_username }}{{ $k->name ? ' — '.$k->name : '' }}</option>
                        @endforeach
                    </select>
                    <button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Tambahkan</button>
                </form>
            @endif
        </div>
    @endif

    <p class="text-xs text-stone-400 leading-relaxed">
        Angka performa (GMV/order/komisi) diambil dari data affiliate yang sama dengan halaman <strong>Affiliate &amp; GMV</strong> —
        otomatis dari TikTok API setelah tersambung, atau dari import manual. <strong>Gaji &amp; ROI</strong> khusus Tim Gapok.
        ROI = GMV ÷ gaji (🟢 ≥3× sehat · 🟡 1–3× · 🔴 &lt;1× gaji lebih besar dari hasil).
    </p>
</div>

<script>
(function () {
    // Input gaji: tampilkan ribuan bertitik saat diketik; kirim angka mentah via hidden.
    document.querySelectorAll('.salary-input').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var raw = this.value.replace(/\D/g, '');
            var hidden = this.closest('form').querySelector('input[name="monthly_salary"]');
            if (hidden) hidden.value = raw;
            this.value = raw ? Number(raw).toLocaleString('id-ID') : '';
        });
    });
})();
</script>
@endsection
