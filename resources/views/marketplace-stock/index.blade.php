@extends('layouts.app')
@section('title','Produk Master')
@section('heading','Produk Master E-commerce')
@section('content')
@php
    $tabUrl = fn ($t) => route('marketplace-stock.index', array_filter(['tab' => $t === 'semua' ? null : $t]));
@endphp
<div class="mx-auto max-w-[1440px] space-y-5 px-1 sm:px-2">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div><h2 class="text-sm font-bold text-stone-800">Master produk</h2><p class="mt-1 text-xs text-stone-500">Kelola stok, harga dasar, dan tautan listing marketplace.</p></div>
            <span class="px-3 py-1.5 rounded-full bg-stone-100 text-stone-600 text-xs font-semibold">{{ $counts['semua'] }} produk</span>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('marketplace-stock.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>Tambah produk</a>
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-emerald-700 text-white rounded-lg hover:bg-emerald-800"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v5h-5M4 17v-5h5m-3-3a7 7 0 0 1 12-2l2 2M4 13l2 2a7 7 0 0 0 12-2"/></svg>Sinkron semua</button></form>
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 2.6-6.4L3 8m0-5v5h5m4 1v5l3 2"/></svg>Refresh listing</button></form>
            <form method="POST" action="{{ route('marketplace-stock.kosongkan') }}" onsubmit="return confirm('Kosongkan SEMUA produk master? Semua master + tautannya dihapus permanen (stok HQ TIDAK terpengaruh). Tidak bisa dibatalkan.')">@csrf<button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-white border border-rose-200 text-rose-700 rounded-lg hover:bg-rose-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg>Kosongkan master</button></form>
            <a href="{{ route('marketplace-stock.channel', 'tiktok') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok &amp; Harga TikTok <span aria-hidden="true">→</span></a>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Stok &amp; Harga Shopee <span aria-hidden="true">→</span></a>
        </div>
        @if($unlinkedCount > 0)
            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{{ $unlinkedCount }} listing belum ditautkan ke master — pakai "Tambah ke Marketplace" pada produk untuk menautkan.</p>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="flex gap-2 text-sm border-b border-stone-200">
        @foreach(['semua' => 'Semua', 'satuan' => 'Satuan', 'bundle' => 'Bundle'] as $key => $label)
            <a href="{{ $tabUrl($key) }}" class="px-4 py-2.5 rounded-t-xl border-b-2 font-semibold {{ $tab === $key ? 'border-red-600 text-red-700 bg-red-50/50' : 'border-transparent text-stone-500 hover:text-stone-800 hover:bg-stone-50' }}">{{ $label }} <span class="ml-1 opacity-70">{{ $counts[$key] }}</span></a>
        @endforeach
    </div>

    @if($masters->isEmpty())
        <div class="bg-white rounded-2xl border border-stone-200">
            <p class="px-5 py-12 text-center text-stone-400 text-sm">Belum ada produk master. Klik <span class="font-medium text-stone-600">+ Tambah Produk Baru</span> untuk mulai.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[1050px] text-sm">
                <thead class="text-left text-stone-500 border-b border-stone-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">Informasi Produk</th>
                        <th class="px-4 py-3 font-medium">Master SKU</th>
                        <th class="px-4 py-3 font-medium">Harga</th>
                        <th class="px-4 py-3 font-medium">Stok</th>
                        <th class="px-4 py-3 font-medium">Produk Terkait</th>
                        <th class="px-4 py-3 font-medium">Toko Terkait</th>
                        <th class="px-4 py-3 font-medium text-right">Atur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($masters as $m)
                        @php
                            $produkTerkait = $m->listings->count();
                            $tokoTerkait = $m->listings->pluck('channel')->unique()->count();
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @if($m->imageUrl())
                                        <img src="{{ $m->imageUrl() }}" alt="" class="w-10 h-10 rounded-lg object-cover border border-stone-200">
                                    @else
                                        <div class="w-10 h-10 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-300 text-[10px]">no img</div>
                                    @endif
                                    <div>
                                        <div class="font-medium text-stone-800">{{ $m->name }}</div>
                                        @if($m->is_bundle)<span class="text-[10px] uppercase tracking-wide text-amber-700 bg-amber-100 rounded px-1.5 py-0.5">Bundle</span>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $m->master_sku }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('marketplace-stock.master.harga', $m) }}" class="flex items-center gap-1">@csrf
                                    <input type="number" step="0.01" min="0" name="price" value="{{ $m->base_price }}" placeholder="—" class="w-24 px-2 py-1 border border-stone-200 rounded">
                                    <button aria-label="Simpan harga {{ $m->name }}" title="Simpan harga" class="inline-flex items-center justify-center w-8 h-8 text-indigo-700 border border-indigo-100 rounded-lg hover:bg-indigo-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
                                </form>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('marketplace-stock.master.stok', $m) }}" class="flex items-center gap-1">@csrf
                                    <input type="number" min="0" name="quantity" value="{{ $m->base_stock }}" placeholder="—" class="w-20 px-2 py-1 border border-stone-200 rounded">
                                    <button aria-label="Simpan stok {{ $m->name }}" title="Simpan stok" class="inline-flex items-center justify-center w-8 h-8 text-indigo-700 border border-indigo-100 rounded-lg hover:bg-indigo-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg></button>
                                </form>
                            </td>
                            <td class="px-4 py-3">
                                @if($produkTerkait > 0)
                                    <button type="button" data-master-id="{{ $m->id }}" data-master-sku="{{ $m->master_sku }}" data-master-name="{{ $m->name }}" data-tab="terkait" onclick="mpOpenKaitkan(this)" class="text-indigo-600 hover:underline">{{ $produkTerkait }} Produk</button>
                                @else <span class="text-stone-400">—</span> @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($tokoTerkait > 0)
                                    <button type="button" data-master-id="{{ $m->id }}" data-master-sku="{{ $m->master_sku }}" data-master-name="{{ $m->name }}" data-tab="terkait" onclick="mpOpenKaitkan(this)" class="text-indigo-600 hover:underline">{{ $tokoTerkait }} Toko</button>
                                @else <span class="text-stone-400">—</span> @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <details class="relative inline-block text-left">
                                    <summary aria-label="Aksi produk {{ $m->name }}" class="cursor-pointer list-none inline-flex items-center justify-center w-9 h-9 rounded-lg bg-white border border-stone-200 hover:bg-stone-100 text-stone-700"><svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg></summary>
                                    <div class="absolute right-0 mt-1 w-60 bg-white border border-stone-200 rounded-xl shadow-lg z-20 py-1.5 text-left">
                                        <a href="{{ route('marketplace-stock.edit', $m) }}" class="flex items-center gap-2 px-4 py-2.5 text-sm text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 5.5 3 3M4 20l4.5-.9L19.3 8.3a2.1 2.1 0 0 0-3-3L5.5 16.1 4 20Z"/></svg>Ubah master</a>
                                        <form method="POST" action="{{ route('marketplace-stock.duplikat', $m) }}">@csrf<button class="flex items-center gap-2 w-full text-left px-4 py-2.5 text-sm text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="8" y="8" width="12" height="12" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></svg>Duplikat produk</button></form>
                                        <button type="button" data-master-id="{{ $m->id }}" data-master-sku="{{ $m->master_sku }}" data-master-name="{{ $m->name }}" onclick="mpOpenKaitkan(this)" class="flex items-center gap-2 w-full text-left px-4 py-2.5 text-sm text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.1 0l3-3A5 5 0 0 0 13 2.9l-1.7 1.7m2.7 6.4a5 5 0 0 0-7.1 0l-3 3A5 5 0 0 0 11 21.1l1.7-1.7"/></svg>Tambah ke marketplace</button>
                                        <form method="POST" action="{{ route('marketplace-stock.master.konten', $m) }}" onsubmit="return confirm('Dorong SEMUA ke TikTok &amp; Shopee: stok, harga, nama, deskripsi, berat, dimensi, barcode &amp; FOTO. Isi listing DITIMPA dan SEMUA foto listing DIGANTI foto master. Setelah ini, tiap Simpan menyinkronkan otomatis. Lanjut?')">@csrf<button class="flex items-center gap-2 w-full text-left px-4 py-2.5 text-sm text-stone-700 hover:bg-stone-50" title="Dorong SEMUA (stok, harga, nama, deskripsi, berat, dimensi, barcode &amp; foto) ke listing TikTok/Shopee yang tertaut — mengganti SEMUA foto listing"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12h15m-6-6 6 6-6 6M5 5v14"/></svg>Dorong konten &amp; foto</button></form>
                                        <form method="POST" action="{{ route('marketplace-stock.master.bundle', $m) }}">@csrf<button class="flex items-center gap-2 w-full text-left px-4 py-2.5 text-sm text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5m-18 5 9 5 9-5"/></svg>{{ $m->is_bundle ? 'Jadikan satuan' : 'Jadikan bundle' }}</button></form>
                                        <form method="POST" action="{{ route('marketplace-stock.master.hapus', $m) }}" onsubmit="return confirm('Hapus produk master ini?')">@csrf @method('DELETE')<button class="flex items-center gap-2 w-full text-left px-4 py-2.5 text-sm text-rose-600 hover:bg-rose-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg>Hapus master</button></form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>

{{-- Modal Kaitkan Produk (ala Desty) --}}
<div id="kaitkanModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl border border-stone-200 w-full max-w-3xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-stone-200">
            <h3 class="font-semibold text-stone-800">Kaitkan Produk (<span id="kaitkanSku"></span>)</h3>
            <button type="button" onclick="mpCloseKaitkan()" class="text-stone-400 hover:text-stone-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-5 py-3 border-b border-stone-100 flex flex-wrap gap-2 items-center">
            <input id="kaitkanSearch" type="text" placeholder="Cari nama produk atau SKU" class="flex-1 min-w-[180px] px-3 py-1.5 border border-stone-200 rounded-lg text-sm" oninput="mpRenderKaitkan()">
            <select id="kaitkanChannel" class="px-3 py-1.5 border border-stone-200 rounded-lg text-sm" onchange="mpRenderKaitkan()">
                <option value="">Semua channel</option>
                <option value="tiktok">TikTok</option>
                <option value="shopee">Shopee</option>
            </select>
        </div>
        <div class="px-5 pt-3 flex gap-1 text-sm flex-wrap">
            <button type="button" data-ktab="semua" onclick="mpSetKaitkanTab('semua')" class="kaitkan-tab px-3 py-1.5 rounded-lg">Semua <span id="kaitkanCountAll" class="opacity-70"></span></button>
            <button type="button" data-ktab="terkait" onclick="mpSetKaitkanTab('terkait')" class="kaitkan-tab px-3 py-1.5 rounded-lg">Produk Terkait <span id="kaitkanCountTerkait" class="opacity-70"></span></button>
            <button type="button" data-ktab="tidak" onclick="mpSetKaitkanTab('tidak')" class="kaitkan-tab px-3 py-1.5 rounded-lg">Produk Tidak Terkait <span id="kaitkanCountTidak" class="opacity-70"></span></button>
        </div>
        <div class="flex-1 overflow-y-auto px-5 py-3">
            <table class="w-full text-sm">
                <thead class="text-left text-stone-500 border-b border-stone-200">
                    <tr><th class="py-2 w-8"></th><th class="py-2 font-medium">Informasi Produk</th><th class="py-2 font-medium">SKU Marketplace</th><th class="py-2 font-medium">Channel / Toko</th><th class="py-2 font-medium">Status</th></tr>
                </thead>
                <tbody id="kaitkanRows" class="divide-y divide-stone-100"></tbody>
            </table>
            <p id="kaitkanEmpty" class="hidden py-8 text-center text-stone-400 text-sm">Tidak ada listing.</p>
        </div>
        <div class="px-5 py-4 border-t border-stone-200 flex items-center justify-between gap-2 flex-wrap">
            <span class="text-xs text-amber-700">Pengaitan akan mendorong stok dari Master ke listing.</span>
            <div class="flex gap-2">
                <button type="button" onclick="mpSubmitKaitkan('link')" class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Tautkan terpilih</button>
                <button type="button" onclick="mpSubmitKaitkan('unlink')" class="px-4 py-2 text-sm bg-rose-600 text-white rounded-lg hover:bg-rose-700">Lepas terpilih</button>
                <button type="button" onclick="mpCloseKaitkan()" class="px-4 py-2 text-sm bg-stone-100 text-stone-600 rounded-lg hover:bg-stone-200">Tutup</button>
            </div>
        </div>
    </div>
    <form id="kaitkanForm" method="POST" class="hidden">@csrf<div id="kaitkanFormIds"></div></form>
    <form id="lepasForm" method="POST" class="hidden">@csrf<div id="lepasFormIds"></div></form>
</div>
<script>
window.__mp = {
    listings: <?= json_encode($allListings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    masterNames: <?= json_encode($masterNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    shopNames: <?= json_encode($shopNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    kaitkanTpl: '{{ route('marketplace-stock.kaitkan', ['master' => '__ID__']) }}',
    lepasTpl: '{{ route('marketplace-stock.lepas', ['master' => '__ID__']) }}',
};
(function () {
    var state = { masterId: null, tab: 'semua' };
    function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    function statusOf(l){ if(l.master_id===state.masterId) return 'terkait'; if(l.master_id===null||l.master_id===undefined) return 'tidak'; return 'lain'; }
    window.mpOpenKaitkan = function(btn){
        state.masterId = parseInt(btn.getAttribute('data-master-id'),10);
        state.tab = btn.getAttribute('data-tab') || 'semua';
        var sku = document.getElementById('kaitkanSku'); if(sku) sku.textContent = btn.getAttribute('data-master-sku') || '';
        var modal = document.getElementById('kaitkanModal'); modal.classList.remove('hidden'); modal.classList.add('flex');
        var s = document.getElementById('kaitkanSearch'); if(s) s.value=''; var c=document.getElementById('kaitkanChannel'); if(c) c.value='';
        mpSetKaitkanTab(state.tab);
    };
    window.mpCloseKaitkan = function(){ var m=document.getElementById('kaitkanModal'); m.classList.add('hidden'); m.classList.remove('flex'); };
    window.mpSetKaitkanTab = function(tab){
        state.tab = tab;
        document.querySelectorAll('.kaitkan-tab').forEach(function(b){
            var on = b.getAttribute('data-ktab')===tab;
            b.className = 'kaitkan-tab px-3 py-1.5 rounded-lg ' + (on ? 'bg-stone-800 text-white' : 'bg-white border border-stone-200 text-stone-600 hover:bg-stone-50');
        });
        mpRenderKaitkan();
    };
    window.mpRenderKaitkan = function(){
        var q=(document.getElementById('kaitkanSearch').value||'').toLowerCase();
        var ch=document.getElementById('kaitkanChannel').value;
        var all=window.__mp.listings||[]; var terkait=0, tidak=0; var rows=[];
        all.forEach(function(l){
            var st=statusOf(l);
            if(st==='terkait') terkait++; if(st==='tidak') tidak++;
            if(state.tab==='terkait' && st!=='terkait') return;
            if(state.tab==='tidak' && st!=='tidak') return;
            if(ch && l.channel!==ch) return;
            var hay=((l.title||'')+' '+(l.seller_sku||'')).toLowerCase();
            if(q && hay.indexOf(q)===-1) return;
            rows.push({l:l, st:st});
        });
        var setTxt=function(id,v){ var e=document.getElementById(id); if(e) e.textContent='('+v+')'; };
        setTxt('kaitkanCountAll', all.length); setTxt('kaitkanCountTerkait', terkait); setTxt('kaitkanCountTidak', tidak);
        var tb=document.getElementById('kaitkanRows'); tb.innerHTML='';
        rows.forEach(function(r){
            var l=r.l, st=r.st, shop=(window.__mp.shopNames||{})[l.channel]||'';
            var badge = st==='terkait' ? '<span class="text-[11px] text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5">Tertaut</span>'
                : st==='tidak' ? '<span class="text-[11px] text-stone-500 bg-stone-100 rounded px-1.5 py-0.5">Belum tertaut</span>'
                : '<span class="text-[11px] text-amber-700 bg-amber-50 rounded px-1.5 py-0.5">Master lain · '+esc((window.__mp.masterNames||{})[l.master_id]||'')+'</span>';
            var dis = st==='lain' ? 'disabled' : '';
            var tr=document.createElement('tr');
            tr.innerHTML='<td class="py-2"><input type="checkbox" class="kaitkan-cb" data-id="'+l.id+'" data-st="'+st+'" '+dis+'></td>'
                +'<td class="py-2">'+esc(l.title||'(tanpa nama)')+'</td>'
                +'<td class="py-2 text-stone-600">'+esc(l.seller_sku)+'</td>'
                +'<td class="py-2 text-stone-600">'+esc((l.channel||'').toUpperCase())+(shop?' · '+esc(shop):'')+'</td>'
                +'<td class="py-2">'+badge+'</td>';
            tb.appendChild(tr);
        });
        document.getElementById('kaitkanEmpty').classList.toggle('hidden', rows.length>0);
    };
    window.mpSubmitKaitkan = function(mode){
        var want = mode==='link' ? 'tidak' : 'terkait';
        var ids=[];
        document.querySelectorAll('#kaitkanRows .kaitkan-cb:checked').forEach(function(cb){ if(cb.getAttribute('data-st')===want) ids.push(cb.getAttribute('data-id')); });
        if(ids.length===0){ alert(mode==='link'?'Centang listing yang BELUM tertaut untuk ditautkan.':'Centang listing yang TERTAUT ke master ini untuk dilepas.'); return; }
        var form=document.getElementById(mode==='link'?'kaitkanForm':'lepasForm');
        var box=document.getElementById(mode==='link'?'kaitkanFormIds':'lepasFormIds'); box.innerHTML='';
        ids.forEach(function(id){ var i=document.createElement('input'); i.type='hidden'; i.name='listing_ids[]'; i.value=id; box.appendChild(i); });
        form.action=(mode==='link'?window.__mp.kaitkanTpl:window.__mp.lepasTpl).replace('__ID__', state.masterId);
        form.submit();
    };
})();
</script>
@endsection
