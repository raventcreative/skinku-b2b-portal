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

    {{-- Filter periode: nav bulan + preset harian + rentang custom --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('kol-gapok.index', ['bulan' => $prevMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">←</a>
                <a href="{{ route('kol-gapok.index', ['bulan' => \Illuminate\Support\Carbon::parse($from)->format('Y-m')]) }}"
                   class="px-3 py-1 text-sm rounded-lg border font-semibold {{ $mode === 'month' ? 'bg-stone-800 text-white border-stone-800' : 'border-stone-300 text-stone-600 hover:bg-stone-50' }}">{{ \Illuminate\Support\Carbon::parse($from)->translatedFormat('M Y') }}</a>
                <a href="{{ route('kol-gapok.index', ['bulan' => $nextMonth]) }}" class="px-2 py-1 rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">→</a>
                <span class="mx-1 text-stone-300">|</span>
                @foreach(['today' => 'Hari ini', '7d' => '7 hari', '30d' => '30 hari'] as $key => $lbl)
                    <a href="{{ route('kol-gapok.index', ['preset' => $key]) }}"
                       class="px-3 py-1 text-sm rounded-lg border {{ $mode === $key ? 'bg-stone-800 text-white border-stone-800' : 'border-stone-300 text-stone-600 hover:bg-stone-50' }}">{{ $lbl }}</a>
                @endforeach
            </div>
            <a href="{{ route('kol-affiliate.index', ['bulan' => $month]) }}" class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">📈 Semua affiliate</a>
        </div>
        <form method="GET" action="{{ route('kol-gapok.index') }}" class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-stone-400 text-xs">Rentang custom:</span>
            <input type="date" name="dari" value="{{ $mode === 'custom' ? $from : '' }}" class="px-2 py-1 border border-stone-300 rounded-lg text-sm">
            <span class="text-stone-400">–</span>
            <input type="date" name="sampai" value="{{ $mode === 'custom' ? $to : '' }}" class="px-2 py-1 border border-stone-300 rounded-lg text-sm">
            <button class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg">Terapkan</button>
        </form>
    </div>

    <p class="text-xs text-stone-500">Performa periode: <strong class="text-stone-700">{{ $periodLabel }}</strong>. <span class="text-stone-400">Gaji &amp; ROI dihitung dari gaji bulanan (ROI = GMV periode ÷ gaji bulan).</span></p>

    {{-- Ringkasan tim --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3">
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">GMV tim</p><p class="text-xl font-bold text-stone-800">{{ $rc($totals['gmv']) }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Komisi</p><p class="text-xl font-bold text-stone-800">{{ $rc($totals['commission']) }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Order</p><p class="text-xl font-bold text-stone-800">{{ number_format($totals['orders'], 0, ',', '.') }}</p></div>
        <div class="bg-white rounded-2xl border border-stone-200 p-4"><p class="text-xs text-stone-500">Total gaji</p><p id="sumSalary" class="text-xl font-bold text-stone-800">{{ $rc($totals['salary']) }}</p></div>
        <div id="sumRoiCard" class="rounded-2xl border border-stone-200 p-4 {{ $roiColor($teamRoi) }}"><p class="text-xs opacity-70">ROI tim (GMV÷gaji)</p><p id="sumRoi" class="text-xl font-bold">{{ $roiFmt($teamRoi) }}</p></div>
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
                        <tr class="hover:bg-stone-50" data-gmv="{{ $r['gmv'] }}">
                            <td class="px-4 py-3">
                                @php $profil = $r['kol']->profileUrl() ?: 'https://www.tiktok.com/@'.$r['kol']->handle(); @endphp
                                <a href="{{ $profil }}" target="_blank" rel="noopener" class="group inline-block" title="Buka profil TikTok @{{ $r['kol']->handle() }}">
                                    <p class="font-semibold text-stone-800 group-hover:text-red-600 group-hover:underline">{{ $r['kol']->display_name }}</p>
                                    <p class="text-xs text-stone-400 group-hover:text-red-500">{{ '@'.$r['kol']->tiktok_username }} <span class="text-[9px]">↗</span></p>
                                </a>
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
                                    <form method="POST" action="{{ route('kol-gapok.salary') }}" class="salary-form flex items-center justify-end gap-1">
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
                                <span class="roi-badge inline-block px-2 py-1 rounded-lg text-xs font-bold {{ $roiColor($r['roi']) }}">{{ $roiFmt($r['roi']) }}</span>
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
                            <td class="px-4 py-3 text-right" id="totSalary">{{ $rp($totals['salary']) }}</td>
                            <td class="px-4 py-3 text-right" id="totRoi">{{ $roiFmt($teamRoi) }}</td>
                            @if($canManage)<td></td>@endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Tambah anggota --}}
    @if($canManage)
        <div class="bg-white rounded-2xl border border-stone-200 p-4 space-y-3">
            <p class="text-sm font-semibold text-stone-700">+ Tambah anggota gapok</p>

            {{-- Cara tercepat: ketik username (dibuatin kalau belum ada di KOL) --}}
            <form method="POST" action="{{ route('kol-gapok.add-username') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <span class="text-stone-500 text-sm">Ketik username:</span>
                <div class="flex items-center">
                    <span class="px-2 py-2 border border-r-0 border-stone-300 rounded-l-xl text-stone-400 text-sm bg-stone-50">@</span>
                    <input type="text" name="username" required placeholder="mis. dianci22" autocomplete="off"
                           class="px-3 py-2 border border-stone-300 rounded-r-xl text-sm w-52 focus:outline-none focus:ring-2 focus:ring-red-500">
                </div>
                <button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Tambahkan</button>
            </form>
            <p class="text-xs text-stone-400">Kalau username-nya belum ada di Database KOL, <strong>otomatis dibuatin</strong> (peran affiliate). Angkanya keisi sendiri begitu dia mulai jualan &amp; ke-sync — pas buat gapok baru yang masih 0. 👍</p>

            @if($nonGapok->isNotEmpty())
                <div class="pt-2 border-t border-stone-100">
                    <form method="POST" action="{{ route('kol-gapok.toggle') }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="hidden" name="is_gapok" value="1">
                        <span class="text-stone-500 text-sm">atau pilih dari KOL yang ada:</span>
                        <select name="kol_id" required class="px-3 py-2 border border-stone-300 rounded-xl text-sm min-w-[240px] focus:outline-none focus:ring-2 focus:ring-red-500">
                            <option value="">— pilih kreator (klik lalu ketik) —</option>
                            @foreach($nonGapok as $k)
                                <option value="{{ $k->id }}">{{ $k->tiktok_username }}{{ $k->name ? ' — '.$k->name : '' }}</option>
                            @endforeach
                        </select>
                        <button class="px-4 py-2 border border-stone-300 text-stone-700 hover:bg-stone-50 text-sm font-semibold rounded-xl">Tambahkan</button>
                    </form>
                </div>
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
    var meta = document.querySelector('meta[name="csrf-token"]');
    var csrf = meta ? meta.getAttribute('content') : '';
    var teamGmv = Number('{{ (int) $totals['gmv'] }}');

    function rp(n) { return 'Rp ' + Number(n).toLocaleString('id-ID'); }
    function rc(n) { return n >= 1000000 ? 'Rp ' + (Math.round(n / 1000000 * 10) / 10) + ' jt' : rp(n); }
    function fmtRoi(roi) { return roi === null ? '—' : (Math.round(roi * 10) / 10).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '×'; }
    function roiClass(roi) { if (roi === null) return 'bg-stone-100 text-stone-400'; if (roi >= 3) return 'bg-emerald-50 text-emerald-700'; if (roi >= 1) return 'bg-amber-50 text-amber-700'; return 'bg-rose-50 text-rose-700'; }
    function set(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; }
    function flash(el, ok) { if (!el) return; el.style.transition = 'background-color .3s'; el.style.backgroundColor = ok ? '#d1fae5' : '#ffe4e6'; setTimeout(function () { el.style.backgroundColor = ''; }, 900); }

    function recalcTotals() {
        var total = 0;
        document.querySelectorAll('input[name="monthly_salary"]').forEach(function (h) { total += Number(h.value || 0); });
        var roi = total > 0 ? teamGmv / total : null;
        set('sumSalary', rc(total)); set('totSalary', rp(total));
        set('sumRoi', fmtRoi(roi)); set('totRoi', fmtRoi(roi));
        var card = document.getElementById('sumRoiCard');
        if (card) card.className = 'rounded-2xl border border-stone-200 p-4 ' + roiClass(roi);
    }

    document.querySelectorAll('.salary-form').forEach(function (form) {
        var display = form.querySelector('.salary-input');
        var hidden = form.querySelector('input[name="monthly_salary"]');
        var row = form.closest('tr');
        var saved = hidden.value;

        function doSave() {
            var raw = Number(hidden.value || 0);
            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: new FormData(form)
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok) throw new Error('gagal');
                saved = hidden.value;
                flash(display, true);
                var gmv = Number(row.dataset.gmv || 0);
                var roi = raw > 0 ? gmv / raw : null;
                var badge = row.querySelector('.roi-badge');
                if (badge) { badge.textContent = fmtRoi(roi); badge.className = 'roi-badge inline-block px-2 py-1 rounded-lg text-xs font-bold ' + roiClass(roi); }
                recalcTotals();
            }).catch(function () { flash(display, false); });
        }

        if (display) {
            // Format ribuan bertitik saat diketik; angka mentah ke hidden.
            display.addEventListener('input', function () {
                var raw = this.value.replace(/\D/g, '');
                hidden.value = raw;
                this.value = raw ? Number(raw).toLocaleString('id-ID') : '';
            });
            display.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); this.blur(); } });
            display.addEventListener('blur', function () { if (hidden.value !== saved) doSave(); }); // auto-save
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); doSave(); });
    });
})();
</script>
@endsection
