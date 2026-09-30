@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('heading', 'Purchase Orders')

@section('content')
@php $u = auth()->user(); $canBulk = $u->isStaff() && $u->canDo('update_po_status'); @endphp
<div class="flex flex-wrap justify-between items-center mb-4 gap-y-2">
    <form method="GET" class="flex flex-wrap gap-2">
        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari no PO / perusahaan…" class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-60">
        <select name="status" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
            <option value="">Semua Status</option>
            @foreach($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '')===$s)>{{ $s }}</option>@endforeach
        </select>
        <select name="bayar" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
            <option value="">Semua Pembayaran</option>
            <option value="belum" @selected(($filters['bayar'] ?? '')==='belum')>Belum Lunas</option>
            <option value="lunas" @selected(($filters['bayar'] ?? '')==='lunas')>Lunas</option>
        </select>
        <select name="product" class="px-3 py-2 text-sm border border-stone-300 rounded-lg max-w-48" title="Tampilkan hanya PO yang memuat produk ini">
            <option value="">Semua Produk</option>
            @foreach($products as $p)<option value="{{ $p->id }}" @selected((string) ($filters['product'] ?? '') === (string) $p->id)>{{ $p->name }}</option>@endforeach
        </select>
        <input type="date" name="dari" value="{{ $filters['dari'] ?? '' }}" class="px-3 py-2 text-sm border border-stone-300 rounded-lg" title="Tanggal PO dari">
        <input type="date" name="sampai" value="{{ $filters['sampai'] ?? '' }}" class="px-3 py-2 text-sm border border-stone-300 rounded-lg" title="Tanggal PO sampai">
        <button class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 text-sm font-medium text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"><svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h14l-5.5 6.2v4.3l-3 1.5v-5.8L3 4Z"/></svg>Filter</button>
        <a href="{{ route('purchase-orders.export') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 text-sm font-medium text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" focusable="false" class="h-4 w-4 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M10 2.5v10m0 0 3.5-3.5M10 12.5 6.5 9M3.5 13v3.5h13V13"/></svg>Export Excel</a>
    </form>
    @if(!is_null($piutang ?? null))
        {{-- Total piutang sesungguhnya: tagihan − cicilan masuk. Super admin
             tinggal filter "Belum Lunas" untuk melihat siapa saja & totalnya. --}}
        <span class="basis-full mt-1 px-4 py-2.5 rounded-xl bg-rose-50 border border-rose-200 text-sm">
            <span class="text-rose-800 font-semibold">Total piutang (belum lunas):</span>
            <span class="text-rose-700 font-bold">Rp {{ number_format($piutang, 0, ',', '.') }}</span>
            <span class="text-[11px] text-rose-500">— tagihan dikurangi cicilan masuk; PO batal/draft tak dihitung</span>
        </span>
    @endif
    @if($u->isPartner())
        <a href="{{ route('purchase-orders.create') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-red-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"><svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20"><path stroke-linecap="round" d="M10 4v12M4 10h12"/></svg>Buat PO</a>
    @endif
</div>

@if($canBulk && $orders->count())
    {{-- Bar aksi massal: centang PO → ubah status sekaligus (mass approve dll). --}}
    <form id="poBulkForm" method="POST" action="{{ route('purchase-orders.bulk-status') }}" onsubmit="return poBulkSubmit(this)"
        class="flex flex-wrap items-center gap-2 mb-3 px-4 py-2.5 bg-white rounded-xl border border-stone-200">
        @csrf
        <span class="text-xs text-stone-500"><b data-bulk-count>0</b> PO dipilih</span>
        <span class="text-stone-300">·</span>
        <label class="text-[11px] font-semibold text-stone-500">Ubah status terpilih ke
            <select name="status" class="ml-1 px-2 py-1.5 border border-stone-300 rounded-lg text-sm">
                <option value="approved">Setujui (approved)</option>
                <option value="processing">Proses (processing)</option>
                <option value="shipped">Kirim (shipped)</option>
                <option value="completed">Selesai (completed)</option>
                <option value="cancelled">Batalkan (cancelled)</option>
            </select>
        </label>
        <button data-bulk-apply disabled class="px-4 py-1.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold disabled:opacity-40">Terapkan</button>
        <span class="text-[11px] text-stone-400">PO dijalankan maju bertahap sampai status target. Belum lunas otomatis berhenti di langkah aman (aturan sama seperti ubah 1 PO).</span>
    </form>
@endif

<div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
    <table class="ui-table ui-table--actions min-w-[900px] w-full text-xs whitespace-nowrap">
        <thead class="ui-table-groups text-stone-600">
            <tr class="ui-table-groups__row">
                @if($canBulk)<th scope="col" rowspan="2" class="w-10"><input type="checkbox" id="poCheckAll" title="Pilih semua" class="h-4 w-4 rounded border-stone-300 accent-red-700"></th>@endif
                <th scope="colgroup" colspan="3" class="text-left">Pesanan</th>
                <th scope="colgroup" colspan="1" class="text-right">Nilai</th>
                <th scope="colgroup" colspan="2" class="text-center">Progres</th>
                <th scope="col" rowspan="2" class="ui-table-actions-head text-right">Aksi</th>
            </tr>
            <tr class="ui-table-columns">
                <th scope="col" class="text-left">No. PO</th>
                <th scope="col" class="text-left">Mitra</th>
                <th scope="col" class="text-left">Tanggal</th>
                <th scope="col" class="text-right">Total</th>
                <th scope="col" class="text-left">Status</th>
                <th scope="col" class="text-left">Pembayaran</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $po)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    @if($canBulk)<td class="px-4"><input type="checkbox" class="po-check h-4 w-4 rounded border-stone-300 accent-red-700" value="{{ $po->id }}"></td>@endif
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $po->po_number }}</td>
                    <td class="text-stone-600">{{ $po->company_name ?? ($po->user->fullname ?? '-') }}</td>
                    <td class="text-stone-500">{{ $po->created_at?->format('d M Y H:i') }}</td>
                    <td class="text-right">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</td>
                    <td><span class="inline-flex min-h-6 items-center px-2.5 py-1 rounded-full text-xs leading-none font-semibold {{ $po->statusColor() }}">{{ $po->status }}</span></td>
                    <td class="whitespace-nowrap">
                        @php
                            // Sisa dari withSum (tanpa query per baris) — dikurangi cicilan
                            // masuk & potongan retur. Badge bayar terpisah dari status order:
                            // PO 'completed' pun bisa belum lunas kalau tempo. Lunas juga bila
                            // sisa 0 karena barang diretur menutup tagihan.
                            $batal = in_array($po->status, [\App\Models\PurchaseOrder::STATUS_CANCELLED, \App\Models\PurchaseOrder::STATUS_DRAFT], true);
                            $sisa = max(0, (float) $po->total_amount - (float) ($po->payments_sum_amount ?? 0) - (float) ($po->applied_returns_sum_credit_amount ?? 0));
                            $lunas = $po->payment_status === \App\Models\PurchaseOrder::PAYMENT_PAID || $sisa <= 0.01;
                        @endphp
                        @if($batal)
                            <span class="text-stone-300 text-[10px]">—</span>
                        @elseif($lunas)
                            <span class="inline-flex min-h-6 items-center px-2.5 py-1 rounded-full text-xs leading-none bg-emerald-100 text-emerald-800 font-semibold">Lunas</span>
                        @elseif($po->is_tempo)
                            <span class="inline-flex min-h-6 items-center px-2.5 py-1 rounded-full text-xs leading-none bg-violet-100 text-violet-800 font-semibold" title="Tempo/cicilan{{ $po->tempo_due_date ? ' · jatuh tempo '.$po->tempo_due_date->format('d M Y') : '' }}">
                                Tempo · sisa Rp {{ number_format($sisa, 0, ',', '.') }}
                            </span>
                            @if($po->tempo_due_date && $po->tempo_due_date->isPast())
                                <span class="block text-[9px] text-rose-600 font-bold mt-0.5">jatuh tempo lewat!</span>
                            @endif
                        @else
                            <span class="inline-flex min-h-6 items-center px-2.5 py-1 rounded-full text-xs leading-none bg-rose-100 text-rose-800 font-semibold">Belum Lunas</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <div class="inline-flex items-center justify-end gap-1">
                        <button type="button" onclick="poQuickView({{ $po->id }})" class="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-stone-200 bg-white px-2.5 text-[11px] font-medium text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700" aria-label="Lihat ringkasan PO {{ $po->po_number }}" title="Lihat ringkasan"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M1.8 10s2.8-5 8.2-5 8.2 5 8.2 5-2.8 5-8.2 5-8.2-5-8.2-5Z"/><circle cx="10" cy="10" r="2.2"/></svg>Lihat</button>
                        <a href="{{ route('purchase-orders.show', $po) }}" class="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-stone-200 bg-white px-2.5 text-[11px] font-medium text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700" aria-label="Detail PO {{ $po->po_number }}" title="Buka detail PO"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M5 2.8h6l4 4V17H5zM11 3v4h4M8 11h4M8 14h4"/></svg>Detail</a>
                        @if($u->isStaff() && $u->canDo('delete_po'))
                            <form method="POST" action="{{ route('purchase-orders.force-destroy', $po) }}" class="inline" onsubmit="return confirm('Hapus PERMANEN PO {{ $po->po_number }}? Tidak bisa dikembalikan. Gunakan untuk membersihkan data test.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-rose-200 bg-white px-2.5 text-[11px] font-medium text-rose-700 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700" aria-label="Hapus PO {{ $po->po_number }}" title="Hapus PO"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h14M8 5V3h4v2m-7 0 1 12h8l1-12m-6 3v6m4-6v6"/></svg>Hapus</button>
                            </form>
                        @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canBulk ? 8 : 7 }}" class="px-4 py-6 text-center text-stone-400">Belum ada PO.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $orders->links() }}</div>

{{-- Popup mini-detail PO — buka isi PO tanpa pindah halaman (data via /quick). --}}
<div id="poQuickModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-4" onclick="poQuickClose()">
    <div class="bg-white rounded-2xl border border-stone-200 w-full max-w-lg max-h-[85vh] flex flex-col" onclick="event.stopPropagation()">
        <div class="flex items-start justify-between gap-3 px-5 py-3 border-b border-stone-100">
            <div>
                <p class="text-sm font-bold text-stone-800" data-q-po>—</p>
                <p class="text-[11px] text-stone-500" data-q-meta></p>
            </div>
            <button type="button" onclick="poQuickClose()" class="text-stone-400 hover:text-stone-700 text-xl leading-none shrink-0" aria-label="Tutup">&times;</button>
        </div>
        <div class="p-4 overflow-y-auto">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="text-stone-400 uppercase text-[10px]"><tr>
                        <th class="text-left py-1">Produk</th>
                        <th class="text-right py-1">Qty</th>
                        <th class="text-right py-1">Harga</th>
                        <th class="text-right py-1 pl-2">Sisa bisa retur</th>
                    </tr></thead>
                    <tbody data-q-items></tbody>
                </table>
            </div>
        </div>
        <div class="flex items-center justify-between gap-2 px-5 py-3 border-t border-stone-100 bg-stone-50 rounded-b-2xl">
            <span class="text-sm"><span class="text-stone-500">Total:</span> <b class="text-stone-800" data-q-total>—</b></span>
            <a href="#" data-q-detail class="text-xs px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700 font-semibold">Buka detail lengkap →</a>
        </div>
    </div>
</div>
<script>
(function () {
    var poBase = @json(url('/purchase-orders'));
    var modal = document.getElementById('poQuickModal');
    function rp(n) { return 'Rp ' + (Number(n) || 0).toLocaleString('id-ID'); }
    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

    window.poQuickView = function (id) {
        var itemsEl = modal.querySelector('[data-q-items]');
        itemsEl.innerHTML = '<tr><td colspan="4" class="py-4 text-center text-stone-400">Memuat…</td></tr>';
        modal.querySelector('[data-q-po]').textContent = 'PO #' + id;
        modal.querySelector('[data-q-meta]').textContent = '';
        modal.querySelector('[data-q-total]').textContent = '—';
        modal.querySelector('[data-q-detail]').setAttribute('href', poBase + '/' + id);
        modal.classList.remove('hidden'); modal.classList.add('flex');

        fetch(poBase + '/' + id + '/quick', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (! r.ok) throw new Error(r.status); return r.json(); })
            .then(function (d) {
                modal.querySelector('[data-q-po]').textContent = d.po_number;
                modal.querySelector('[data-q-meta]').textContent = [d.company, d.created_at, d.status].filter(Boolean).join(' · ');
                modal.querySelector('[data-q-total]').textContent = rp(d.total);
                if (! d.items || ! d.items.length) {
                    itemsEl.innerHTML = '<tr><td colspan="4" class="py-4 text-center text-stone-400">Tak ada item.</td></tr>';
                    return;
                }
                itemsEl.innerHTML = d.items.map(function (it) {
                    var note = it.returned > 0 ? ' <span class="text-[9px] text-stone-400">(diretur ' + it.returned + ')</span>' : '';
                    return '<tr class="border-t border-stone-100">'
                        + '<td class="py-1.5 pr-2 text-stone-800 font-medium">' + esc(it.product_name)
                        + '<div class="text-[9px] text-stone-400 font-mono">' + esc(it.sku || '') + '</div></td>'
                        + '<td class="py-1.5 text-right">' + it.qty + '</td>'
                        + '<td class="py-1.5 text-right text-stone-500">' + rp(it.unit_price) + '</td>'
                        + '<td class="py-1.5 text-right pl-2 font-semibold ' + (it.returnable > 0 ? 'text-emerald-700' : 'text-stone-300') + '">' + it.returnable + note + '</td>'
                        + '</tr>';
                }).join('');
            })
            .catch(function () {
                itemsEl.innerHTML = '<tr><td colspan="4" class="py-4 text-center text-rose-500">Gagal memuat detail.</td></tr>';
            });
    };

    window.poQuickClose = function () { modal.classList.add('hidden'); modal.classList.remove('flex'); };
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') window.poQuickClose(); });
})();
</script>

@if($canBulk)
<script>
(function () {
    var all = document.getElementById('poCheckAll');
    var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.po-check')); };
    var countEl = document.querySelector('[data-bulk-count]');
    var applyBtn = document.querySelector('[data-bulk-apply]');

    function refresh() {
        var list = boxes();
        var checked = list.filter(function (b) { return b.checked; });
        if (countEl) countEl.textContent = checked.length;
        if (applyBtn) applyBtn.disabled = checked.length === 0;
        if (all) all.checked = list.length > 0 && checked.length === list.length;
    }

    if (all) all.addEventListener('change', function () {
        boxes().forEach(function (b) { b.checked = all.checked; });
        refresh();
    });
    boxes().forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();

    window.poBulkSubmit = function (form) {
        var checked = boxes().filter(function (b) { return b.checked; });
        if (! checked.length) return false;
        var status = form.querySelector('[name=status]').value;
        if (! confirm('Jalankan ' + checked.length + ' PO terpilih maju sampai status "' + status + '"?\n\nTiap PO dilangkahkan bertahap melewati status antara. PO yang belum lunas otomatis berhenti di langkah aman. Menuju "completed" akan memotong stok.')) return false;
        form.querySelectorAll('input[name="ids[]"]').forEach(function (i) { i.remove(); });
        checked.forEach(function (b) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'ids[]'; h.value = b.value;
            form.appendChild(h);
        });
        return true;
    };
})();
</script>
@endif
@endsection
