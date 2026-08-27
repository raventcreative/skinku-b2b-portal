@extends('layouts.app')
@section('title', 'Deal KOL')
@section('heading', 'Kerjasama / Deal KOL')

@section('content')
@php
    $u = auth()->user();
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $canFinance = $u->canDo('kol.deal.finance');
    $canApprove = $u->canDo('kol.deal.approve');   // Acc/Tolak — penyetuju saja
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
    </form>
    <a href="{{ route('kol-deals.laporan') }}" class="ml-auto px-4 py-2 text-sm bg-white border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50">📊 Ringkasan Hasil</a>
    <a href="{{ route('kol-deals.create') }}" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Deal Baru</a>
</div>

@if($budget)
    <div class="bg-white rounded-2xl border border-stone-200 p-4 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <p class="text-sm font-semibold text-stone-700">Budget {{ now()->translatedFormat('F Y') }}</p>
            <form method="POST" action="{{ route('kol-deals.budget') }}" class="flex items-center gap-1 text-xs">
                @csrf
                <span class="text-stone-400">cap</span>
                <input type="number" name="budget" min="0" value="{{ $budget['budget'] }}" class="w-28 px-2 py-1 border border-stone-300 rounded text-right">
                <span class="text-stone-400">CPM anchor</span>
                <input type="number" name="anchor" min="0" value="{{ $budget['anchor'] }}" class="w-20 px-2 py-1 border border-stone-300 rounded text-right">
                <button class="text-indigo-600 hover:underline">simpan</button>
            </form>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div><p class="text-xs text-stone-500">Spent (lunas)</p><p class="font-bold text-stone-800">{{ $rp($budget['spent']) }}</p></div>
            <div><p class="text-xs text-stone-500">Committed</p><p class="font-bold text-stone-800">{{ $rp($budget['committed']) }}</p></div>
            <div><p class="text-xs text-stone-500">Sisa (dari {{ $rp($budget['budget']) }})</p><p class="font-bold {{ $budget['sisa'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $rp($budget['sisa']) }}</p></div>
            <div><p class="text-xs text-stone-500">Blended CPM paid</p><p class="font-bold {{ $budget['overAnchor'] ? 'text-rose-600' : 'text-stone-800' }}">{{ $budget['cpm'] !== null ? $rp($budget['cpm']) : '—' }}</p><p class="text-[10px] text-stone-400">anchor {{ $rp($budget['anchor']) }}</p></div>
        </div>
        @if($budget['overConcentration'] || $budget['overAnchor'])
            <div class="mt-3 flex flex-wrap gap-2">
                @if($budget['overConcentration'])<span class="text-[11px] px-2 py-1 rounded-full bg-amber-100 text-amber-700">⚠ 1 KOL menyerap {{ $budget['topSharePct'] }}% budget</span>@endif
                @if($budget['overAnchor'])<span class="text-[11px] px-2 py-1 rounded-full bg-rose-100 text-rose-700">⚠ CPM paid di atas anchor</span>@endif
            </div>
        @endif
    </div>
@endif

{{-- Bar aksi massal (muncul saat ada centang) --}}
<div id="bulkBar" class="hidden items-center gap-2 mb-3 p-2 bg-stone-800 text-white rounded-xl text-xs">
    <span id="bulkCount" class="px-2 font-semibold">0 dipilih</span>
    @if($canApprove)<button type="button" onclick="submitBulk('berjalan')" class="px-3 py-1.5 bg-blue-600 rounded-lg hover:bg-blue-700 font-semibold">✓ Acc (jalan)</button>@endif
    <button type="button" onclick="submitBulk('selesai')" class="px-3 py-1.5 bg-emerald-600 rounded-lg hover:bg-emerald-700 font-semibold">✓ Selesai</button>
    @if($canApprove)<button type="button" onclick="submitBulk('batal')" class="px-3 py-1.5 bg-rose-600 rounded-lg hover:bg-rose-700 font-semibold">✕ Tolak</button>@endif
    <button type="button" onclick="clearChecks()" class="ml-auto px-2 text-stone-300 hover:text-white">batal pilih</button>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
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
                    <td class="px-2 py-2.5 font-semibold text-stone-700">{{ $d->kode }}</td>
                    <td>
                        <a href="{{ route('kols.show', $d->kol_id) }}" class="text-red-700 hover:underline font-semibold">{{ '@'.($d->kol->tiktok_username ?? '?') }}</a>
                        <div class="flex flex-wrap items-center gap-1 mt-0.5">
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $levelBadge[$d->kol?->level] ?? 'bg-stone-100 text-stone-600' }}">{{ $d->kol?->level ?? '—' }}</span>
                            @if($sc)
                                <span class="text-[10px] text-stone-500">{{ $sc->verdict_median }}</span>
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
                        <button type="button" class="hasilBtn text-[10px] hover:underline @if(! $d->hasil_terisi) text-stone-400 @endif"
                            data-hasil="{{ json_encode(['id' => $d->id, 'kode' => $d->kode, 'tujuan' => $d->hasil_tujuan, 'video_upload' => $d->hasil_video_upload, 'video_fyp' => $d->hasil_video_fyp, 'views' => $d->hasil_views, 'revenue' => $d->hasil_revenue, 'catatan' => $d->hasil_catatan]) }}"
                            onclick="openHasil(this)" title="Isi/lihat laporan hasil (popup)">{{ $d->hasil_terisi ? $d->hasil_verdict : '+ isi laporan' }}</button>
                    </td>
                    <td class="text-right px-4">
                        @if($canApprove && $d->status !== 'berjalan')<button type="button" onclick="submitBulk('berjalan', {{ $d->id }})" class="text-blue-600 hover:text-blue-800 font-semibold" title="Acc → jalan">Acc</button>@endif
                        @if($d->status !== 'selesai')<button type="button" onclick="submitBulk('selesai', {{ $d->id }})" class="ml-1 text-emerald-600 hover:text-emerald-800 font-semibold" title="Tandai selesai">Selesai</button>@endif
                        @if($canApprove && $d->status !== 'batal')<button type="button" onclick="submitBulk('batal', {{ $d->id }})" class="ml-1 text-rose-500 hover:text-rose-700 font-semibold" title="Tolak">Tolak</button>@endif
                        <a href="{{ route('kol-deals.edit', $d) }}" class="ml-2 text-stone-500 hover:text-stone-900 font-semibold">Edit</a>
                        <form method="POST" action="{{ route('kol-deals.destroy', $d) }}" class="inline"
                            onsubmit="return confirm('Hapus deal {{ $d->kode }}? (soft delete, tercatat di Audit Log)')">
                            @csrf @method('DELETE')
                            <button class="ml-1 text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
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
            <span id="hm_status" class="ml-auto text-[11px] text-emerald-600 hidden">tersimpan ✓</span>
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
                  hasilTarget.textContent = data.verdict;
                  hasilTarget.classList.remove('text-stone-400');
              }
              document.getElementById('hm_status').classList.remove('hidden');
              setTimeout(function () { document.getElementById('hasilModal').close(); }, 600);
          }).catch(function () { alert('Gagal menyimpan laporan. Coba lagi.'); });
    });
</script>
@endsection
