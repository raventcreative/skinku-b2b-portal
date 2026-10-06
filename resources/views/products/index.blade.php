@extends('layouts.app')
@section('title', 'Manajemen Produk')
@section('heading', 'Manajemen Produk')

@section('content')
@php
    // HPP (harga pokok) hanya utk izin Lihat HPP — default super admin; admin/gudang tak perlu tahu.
    $lihatHpp = auth()->user()->canDo('view_hpp');
    $kolomEdit = array_values(array_diff(['id', 'name', 'sku', 'category', 'description', 'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs', 'weight_grams', 'hq_stock', 'hq_min_stock', 'status'], $lihatHpp ? [] : ['cogs']));
@endphp
<div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
    <form method="GET" class="flex flex-wrap gap-2">
        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama/SKU…" class="w-full px-3 py-2 text-sm border border-stone-300 rounded-lg sm:w-56">
        <select name="status" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
            <option value="">Semua Status</option>
            @foreach(['active','inactive','deleted'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '')===$s)>{{ $s }}</option>@endforeach
        </select>
        <select name="stok" class="px-3 py-2 text-sm border border-stone-300 rounded-lg" title="Stok pusat ≤ stok minimum yang diisi">
            <option value="">Semua stok</option>
            <option value="menipis" @selected(($filters['stok'] ?? '') === 'menipis')>Stok pusat menipis</option>
        </select>
        <button class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 text-sm font-medium text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"><svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h14l-5.5 6.2v4.3l-3 1.5v-5.8L3 4Z"/></svg>Filter</button>
    </form>
    <button type="button" onclick="openProduct()" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-red-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"><svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20"><path stroke-linecap="round" d="M10 4v12M4 10h12"/></svg>Tambah Produk</button>
</div>

<div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
    <table class="ui-table ui-table--actions min-w-[1120px] w-full text-xs whitespace-nowrap">
        <thead class="ui-table-groups text-stone-600">
            <tr class="ui-table-groups__row">
                <th scope="colgroup" colspan="3" class="text-left">Katalog</th>
                <th scope="colgroup" colspan="4" class="text-center">Harga jual <span>(Rp)</span></th>
                <th scope="colgroup" colspan="{{ $lihatHpp ? 4 : 3 }}" class="text-center">{{ $lihatHpp ? 'Biaya & logistik' : 'Logistik' }}</th>
                <th scope="col" rowspan="2" class="text-left">Status</th>
                <th scope="col" rowspan="2" class="ui-table-actions-head text-right">Aksi</th>
            </tr>
            <tr class="ui-table-columns">
                <th scope="col" class="text-left">Produk</th>
                <th scope="col" class="text-left">SKU</th>
                <th scope="col" class="text-left">Kategori</th>
                <th scope="col" class="text-right">Grand</th>
                <th scope="col" class="text-right">Distributor</th>
                <th scope="col" class="text-right">Reseller</th>
                <th scope="col" class="text-right">Retail</th>
                @if($lihatHpp)<th scope="col" class="text-right">HPP</th>@endif
                <th scope="col" class="text-right">Berat</th>
                <th scope="col" class="text-right">Stok Pusat</th>
                <th scope="col" class="text-right" title="Stok minimum pusat: pengingat di Dashboard bila stok ≤ angka ini. Kosong = tanpa pengingat. Tersimpan otomatis.">Stok Min.</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $p)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    @php $urls = $p->imageUrls(); @endphp
                    <td class="px-4 py-3 font-semibold text-stone-800">
                        <div class="flex items-center gap-3">
                            @if(count($urls))
                                <a href="{{ $urls[0] }}" class="glightbox shrink-0" data-gallery="prod-{{ $p->id }}" title="Klik untuk lihat foto">
                                    <img src="{{ $urls[0] }}" alt="{{ $p->name }}" class="w-10 h-10 rounded-lg object-cover border border-stone-200 hover:opacity-80 transition">
                                </a>
                                @foreach(array_slice($urls, 1) as $u)
                                    <a href="{{ $u }}" class="glightbox" data-gallery="prod-{{ $p->id }}" style="display:none"></a>
                                @endforeach
                            @else
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-stone-200 bg-stone-50 text-stone-400"><svg aria-hidden="true" focusable="false" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3h6m-5 0v5l-4 4v8a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-8l-4-4V3M7 13h10"/></svg></span>
                            @endif
                            <span class="min-w-0">
                                <span class="block max-w-[15rem] truncate text-[13px] font-semibold text-stone-900">{{ $p->name }}</span>
                                @if(count($urls) > 1)<span class="mt-1 block text-[10px] font-medium text-stone-400">{{ count($urls) }} foto produk</span>@endif
                            </span>
                        </div>
                    </td>
                    <td class="font-mono text-[11px] font-medium text-stone-500">{{ $p->sku }}</td>
                    <td><span class="inline-flex rounded-md bg-stone-100 px-2 py-1 text-[10px] font-medium text-stone-600">{{ $p->category ?? 'Tanpa kategori' }}</span></td>
                    <td class="text-right tabular-nums">{{ $p->price_grand !== null ? 'Rp '.number_format($p->price_grand, 0, ',', '.') : '—' }}</td>
                    <td class="text-right tabular-nums">Rp {{ number_format($p->price_distributor, 0, ',', '.') }}</td>
                    <td class="text-right tabular-nums">Rp {{ number_format($p->price_reseller, 0, ',', '.') }}</td>
                    <td class="text-right tabular-nums">Rp {{ number_format($p->price_retail, 0, ',', '.') }}</td>
                    @if($lihatHpp)
                    <td class="text-right text-stone-500">
                        @if(auth()->user()->canDo('manage_production'))
                            <a href="{{ route('products.hpp-history', $p) }}" class="font-medium text-stone-600 underline decoration-stone-300 underline-offset-2 hover:text-red-700 hover:decoration-red-600" title="Buka riwayat HPP">Rp {{ number_format($p->cogs, 0, ',', '.') }}</a>
                        @else
                            Rp {{ number_format($p->cogs, 0, ',', '.') }}
                        @endif
                    </td>
                    @endif
                    <td class="text-right {{ (int) $p->weight_grams <= 0 ? 'text-amber-600 font-semibold' : 'text-stone-600' }}">{{ (int) $p->weight_grams > 0 ? number_format($p->weight_grams, 0, ',', '.').' g' : 'belum diisi' }}</td>
                    <td class="text-right tabular-nums"><span data-stok-pusat class="inline-flex min-w-10 justify-center rounded-md px-2 py-1 font-semibold {{ $p->hq_stock <= 0 ? 'bg-rose-50 text-rose-700' : ($p->isStokPusatMenipis() ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-800') }}">{{ $p->hq_stock }}</span><span data-tanda-menipis class="block text-[10px] text-amber-700 font-semibold {{ $p->isStokPusatMenipis() ? '' : 'hidden' }}">menipis</span></td>
                    <td class="text-right">
                        @if($p->status !== 'deleted')
                            <input type="number" min="0" step="1" inputmode="numeric" value="{{ $p->hq_min_stock }}" placeholder="—" data-min-stock data-url="{{ route('products.min-stock', $p) }}" aria-label="Stok minimum pusat {{ $p->name }}" title="Stok minimum pusat — tersimpan otomatis. Kosong = tanpa pengingat." class="w-20 px-2 py-1 text-right border border-stone-300 rounded-md">
                            <span data-status-min class="block text-[10px]"></span>
                        @else <span class="text-stone-400">—</span> @endif
                    </td>
                    <td><span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-medium {{ $p->status==='active' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-stone-200 bg-stone-100 text-stone-600' }}"><span class="h-1.5 w-1.5 rounded-full {{ $p->status==='active' ? 'bg-emerald-600' : 'bg-stone-400' }}"></span>{{ $p->status }}</span></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="inline-flex items-center justify-end gap-1">
                        @if($lihatHpp && auth()->user()->canDo('manage_production'))
                            <a href="{{ route('products.hpp-history', $p) }}" class="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-stone-200 bg-white px-2.5 text-[11px] font-medium text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700" title="Riwayat HPP"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 15.5 7 11l3 2 6-7m0 0v4m0-4h-4"/></svg><span>Riwayat HPP</span></a>
                        @endif
                        @if($p->status !== 'deleted')
                            @php $gallery = $p->fileGallery(\App\Models\Product::GALLERY); @endphp
                            <button type="button" class="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-stone-200 bg-white px-2.5 text-[11px] font-medium text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700" aria-label="Edit {{ $p->name }}" title="Edit produk" onclick='openProduct({{ json_encode($p->only($kolomEdit) + ["gallery" => $gallery]) }})'><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m12.5 3.5 4 4M4 16l1-4 8.5-8.5a1.4 1.4 0 0 1 2 2L7 14l-3 2Z"/></svg>Edit</button>
                            <form method="POST" action="{{ route('products.destroy', $p) }}" class="inline" onsubmit="return confirm('Hapus produk ini (soft delete)?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-rose-200 bg-white px-2.5 text-[11px] font-medium text-rose-700 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700" aria-label="Hapus {{ $p->name }}" title="Hapus produk"><svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h14M8 5V3h4v2m-7 0 1 12h8l1-12m-6 3v6m4-6v6"/></svg>Hapus</button>
                            </form>
                        @else <span class="text-stone-400">—</span> @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $lihatHpp ? 13 : 12 }}" class="px-4 py-6 text-center text-stone-400">Belum ada produk.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $products->links() }}</div>

{{-- Product modal (create + edit) --}}
<div id="productModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 id="productModalTitle" class="text-sm font-bold text-stone-900">Tambah Produk</h3>
            <button type="button" onclick="toggleModal('productModal')" aria-label="Tutup formulir produk" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-stone-400 hover:bg-stone-100 hover:text-stone-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"><svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" d="m5 5 10 10M15 5 5 15"/></svg></button>
        </div>
        <form method="POST" id="productForm" enctype="multipart/form-data" action="{{ route('products.store') }}" class="grid grid-cols-2 gap-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="productMethod" value="POST">
            <div class="col-span-2"><label class="block text-xs font-semibold mb-1">Nama *</label><input name="name" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">SKU *</label><input name="sku" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Kategori</label><input name="category" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Harga Distributor *</label><input type="number" step="0.01" name="price_distributor" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Harga Grand Distributor</label><input type="number" step="0.01" name="price_grand" class="w-full px-3 py-2 border border-stone-300 rounded-lg" placeholder="kosong = ikut distributor"></div>
            <div><label class="block text-xs font-semibold mb-1">Harga Reseller *</label><input type="number" step="0.01" name="price_reseller" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Harga Retail *</label><input type="number" step="0.01" name="price_retail" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            @if($lihatHpp)<div><label class="block text-xs font-semibold mb-1">HPP / COGS *</label><input type="number" step="0.01" name="cogs" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>@endif
            <div><label class="block text-xs font-semibold mb-1">Berat (gram)</label><input type="number" name="weight_grams" min="0" step="1" class="w-full px-3 py-2 border border-stone-300 rounded-lg" placeholder="untuk booking kurir"></div>
            <div><label class="block text-xs font-semibold mb-1">Stok Pusat *</label><input type="number" name="hq_stock" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Stok Minimum Pusat</label><input type="number" name="hq_min_stock" min="0" step="1" class="w-full px-3 py-2 border border-stone-300 rounded-lg" placeholder="kosong = tanpa pengingat"><p class="text-[10px] text-stone-400 mt-1">Stok pusat ≤ angka ini → muncul pengingat di Dashboard.</p></div>
            <div><label class="block text-xs font-semibold mb-1">Status *</label>
                <select name="status" class="w-full px-3 py-2 border border-stone-300 rounded-lg"><option value="active">active</option><option value="inactive">inactive</option></select>
            </div>
            <div class="col-span-2"><label class="block text-xs font-semibold mb-1">Deskripsi</label><textarea name="description" rows="2" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></textarea></div>
            <div class="col-span-2">
                <label class="block text-xs font-semibold mb-1">Foto Produk (maks 8 foto · otomatis di-resize)</label>
                <div id="productExistingImages" class="flex flex-wrap gap-2 mb-2"></div>
                <input type="file" name="images[]" accept="image/*" multiple class="w-full text-xs">
                <p class="text-[10px] text-stone-400 mt-1">Bisa pilih beberapa foto sekaligus. Foto besar otomatis dikecilkan agar hemat penyimpanan. Saat edit, centang "hapus" untuk membuang foto lama.</p>
            </div>
            <div class="col-span-2 flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('productModal')" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-stone-200 px-4 text-sm font-medium text-stone-700 hover:bg-stone-50"><svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" d="m5 5 10 10M15 5 5 15"/></svg>Batal</button>
                <button class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-red-700 px-5 text-sm font-semibold text-white hover:bg-red-800"><svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M4 3h10l3 3v11H4zM7 3v5h7V3M7 17v-6h7v6"/></svg>Simpan Produk</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openProduct(p) {
        const f = document.getElementById('productForm');
        const existing = document.getElementById('productExistingImages');
        existing.innerHTML = '';
        if (p) {
            f.action = '/products/' + p.id;
            document.getElementById('productMethod').value = 'PUT';
            document.getElementById('productModalTitle').textContent = 'Edit Produk';
            for (const k of ['name','sku','category','description','price_grand','price_distributor','price_reseller','price_retail','cogs','weight_grams','hq_stock','hq_min_stock','status']) {
                if (f.querySelector('[name='+k+']')) f.querySelector('[name='+k+']').value = p[k] ?? '';
            }
            // render existing gallery with "hapus" checkboxes
            (p.gallery || []).forEach(img => {
                const wrap = document.createElement('label');
                wrap.className = 'relative block w-16 h-16 cursor-pointer';
                wrap.innerHTML =
                    '<img src="' + img.url + '" class="w-16 h-16 object-cover rounded-lg border border-stone-200">' +
                    '<span class="absolute -top-1 -right-1 bg-white rounded-full border border-stone-200 p-0.5">' +
                    '<input type="checkbox" name="remove_files[]" value="' + img.id + '" class="accent-red-600" title="hapus"></span>';
                existing.appendChild(wrap);
            });
        } else {
            f.action = '{{ route('products.store') }}';
            document.getElementById('productMethod').value = 'POST';
            document.getElementById('productModalTitle').textContent = 'Tambah Produk';
            f.reset();
        }
        toggleModal('productModal');
    }

    // Stok minimum pusat langsung di tabel: tersimpan otomatis saat pindah kolom / Enter (tanpa buka Edit satu-satu).
    const KELAS_STOK = {merah: 'bg-rose-50 text-rose-700', menipis: 'bg-amber-100 text-amber-700', biasa: 'bg-stone-100 text-stone-800'};
    document.querySelectorAll('[data-min-stock]').forEach(inp => {
        let tersimpan = inp.value;
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
        inp.addEventListener('change', async () => {
            const tr = inp.closest('tr');
            const status = tr.querySelector('[data-status-min]');
            const stok = tr.querySelector('[data-stok-pusat]');
            status.className = 'block text-[10px] text-stone-400';
            status.textContent = 'menyimpan…';
            try {
                const res = await fetch(inp.dataset.url, {
                    method: 'PATCH',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.CSRF},
                    body: JSON.stringify({hq_min_stock: inp.value === '' ? null : inp.value}),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || res.redirected) throw new Error(data.message || 'Gagal menyimpan');
                tersimpan = inp.value = data.hq_min_stock ?? '';
                Object.values(KELAS_STOK).forEach(k => stok.classList.remove(...k.split(' ')));
                stok.classList.add(...KELAS_STOK[data.stok <= 0 ? 'merah' : (data.menipis ? 'menipis' : 'biasa')].split(' '));
                tr.querySelector('[data-tanda-menipis]').classList.toggle('hidden', !data.menipis);
                status.className = 'block text-[10px] text-emerald-700';
                status.textContent = '✓ tersimpan';
            } catch (e) {
                inp.value = tersimpan;
                status.className = 'block text-[10px] text-rose-600';
                status.textContent = '✗ gagal'; // sel sempit — pesan lengkap di tooltip & isian kembali ke angka lama
                status.title = e.message;
            }
        });
    });
</script>
@endpush
