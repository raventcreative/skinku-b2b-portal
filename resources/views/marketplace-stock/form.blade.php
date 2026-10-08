@extends('layouts.app')
@section('title', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Master')
@section('heading', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Baru')

@push('head')
<style>
/* Pemilih kategori marketplace (kolom bertingkat ala Seller Center) */
.mp-kol{flex:0 0 15rem;overflow-y:auto;border-right:1px solid #f5f5f4}
.mp-kol button{display:flex;width:100%;justify-content:space-between;gap:.5rem;text-align:left;padding:.45rem .65rem;font-size:13px;color:#292524;border-radius:.35rem}
.mp-kol button:hover{background:#f5f5f4}
.mp-kol button.aktif{background:#fef2f2;color:#b91c1c;font-weight:600}
.mp-kat-hasil button{display:block;width:100%;text-align:left;padding:.45rem .65rem;font-size:12px;border-bottom:1px solid #f5f5f4}
.mp-kat-hasil button:hover{background:#f5f5f4}
/* Galeri foto ala Desty — plain CSS (tak bergantung kelas Tailwind terkompilasi). */
.mps-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem}
@media(min-width:640px){.mps-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
.mps-tile{position:relative;border:1px solid #e7e5e4;border-radius:.6rem;overflow:hidden;aspect-ratio:1/1;background:#fafaf9}
.mps-tile img{width:100%;height:100%;object-fit:cover;display:block}
.mps-badge{position:absolute;top:.25rem;left:.25rem;font-size:9px;line-height:1;padding:.15rem .3rem;border-radius:.25rem;color:#fff;font-weight:600}
.mps-badge-utama{background:#4f46e5}
.mps-badge-baru{background:#059669}
.mps-actions{position:absolute;left:0;right:0;bottom:0;display:flex;justify-content:center;gap:.55rem;padding:.28rem;background:rgba(0,0,0,.55);opacity:0;transition:opacity .15s}
.mps-tile:hover .mps-actions,.mps-tile:focus-within .mps-actions{opacity:1}
.mps-actions form{margin:0}
.mps-actions button{color:#fff;font-size:10px;cursor:pointer;background:none;border:0;padding:0}
.mps-actions button:hover{text-decoration:underline}
.mps-add{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.25rem;aspect-ratio:1/1;border:2px dashed #d6d3d1;border-radius:.6rem;color:#a8a29e;cursor:pointer;transition:border-color .15s,color .15s,background .15s;background:#fafaf9;text-align:center}
.mps-add:hover{border-color:#6366f1;color:#4f46e5;background:#eef2ff}
.mps-add .plus{font-size:1.9rem;line-height:1;font-weight:300}
.mps-add .txt{font-size:11px;font-weight:600}
/* Geser untuk atur urutan (foto tersimpan & foto Baru). Mouse: dari mana saja di foto; HP: lewat ikon ⠿ (touch-action:none). */
.mps-tile[data-file-id],.mps-tile[data-new]{cursor:grab;user-select:none;-webkit-user-select:none}
.mps-badge-baru{top:auto;bottom:.25rem} /* bawah: kiri-atas dipakai badge Utama bila foto Baru digeser ke depan */
.mps-tile.mps-dragging{opacity:.55;outline:2px dashed #6366f1;outline-offset:2px;cursor:grabbing}
.mps-grip{position:absolute;top:.25rem;right:.25rem;width:1.5rem;height:1.5rem;display:flex;align-items:center;justify-content:center;border-radius:.35rem;background:rgba(255,255,255,.9);color:#57534e;font-size:13px;line-height:1;touch-action:none;cursor:grab;box-shadow:0 1px 2px rgba(0,0,0,.15)}
</style>
@endpush

@section('content')
@php $gallery = $master->exists ? $master->fileGallery(\App\Models\MarketplaceMaster::MASTER_IMAGE) : []; @endphp
<div class="mx-auto max-w-4xl space-y-5 px-1 sm:px-2">
    {{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}

    {{-- FOTO PRODUK — kartu WAJIB DI LUAR form utama (tiap tombol Hapus/Jadikan Utama = form sendiri; HTML larang <form> nested).
         Kotak "+" adalah <label for="fotoUpload"> yang memicu input file DI DALAM form utama (label for= tembus batas form). --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-3">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-stone-800">Foto Produk</h3>
            <span class="text-xs text-stone-400">Maks 9 · foto pertama = utama</span>
        </div>
        <div class="mps-grid" id="fotoGrid"@if($master->exists) data-urutan-url="{{ route('marketplace-stock.master.foto.urutan', $master) }}"@endif>
            @foreach($gallery as $i => $g)
                <div class="mps-tile" data-file-id="{{ $g['id'] }}">
                    <img src="{{ $g['url'] }}" alt="Foto {{ $i + 1 }}" draggable="false">
                    @if($i === 0)<span class="mps-badge mps-badge-utama">Utama</span>@endif
                    <span class="mps-grip" title="Geser untuk atur urutan" aria-hidden="true">⠿</span>
                    <div class="mps-actions">
                        @if($i !== 0)
                            <form method="POST" action="{{ route('marketplace-stock.master.foto.utama', [$master, $g['id']]) }}">@csrf<button type="submit" title="Jadikan foto utama">Jadikan Utama</button></form>
                        @endif
                        <form method="POST" action="{{ route('marketplace-stock.master.foto.hapus', [$master, $g['id']]) }}" onsubmit="return confirm('Hapus foto ini?')">@csrf @method('DELETE')<button type="submit" title="Hapus foto">Hapus</button></form>
                    </div>
                </div>
            @endforeach
            {{-- Tombol "+" tambah foto (jelas untuk orang awam) --}}
            <label class="mps-add" id="fotoAddTile" for="fotoUpload" role="button" aria-label="Tambah foto">
                <span class="plus" aria-hidden="true">+</span>
                <span class="txt">Tambah Foto</span>
            </label>
        </div>
        {{-- Tampil bila ada ≥2 foto (tersimpan + Baru) — diatur JS geser di bawah. --}}
        <p id="fotoUrutHint" class="text-[11px] text-stone-500 leading-relaxed"@if(count($gallery) < 2) hidden @endif><b>Atur urutan:</b> geser foto ke posisi yang diinginkan (di HP tahan &amp; geser ikon <b>⠿</b>) — foto paling kiri = <b>Utama</b>. Foto tersimpan langsung tersimpan urutannya; bila ada foto <b>Baru</b>, urutan ikut tersimpan saat klik <b>Simpan</b>. <span id="fotoUrutStatus" class="font-semibold"></span></p>
        <p class="text-[11px] text-stone-400 leading-relaxed">Klik kotak <b>“+ Tambah Foto”</b> untuk memilih gambar dari komputer/HP (JPG/PNG). Bisa pilih beberapa sekaligus — foto <b>otomatis dikecilkan di perangkatmu</b> (maks 1600px) biar upload cepat &amp; tak timeout, kualitas tetap tajam. Foto baru bertanda <span class="text-emerald-600 font-semibold">Baru</span> dan tersimpan saat kamu klik <b>Simpan</b>.</p>
    </div>

    <form method="POST" action="{{ $master->exists ? route('marketplace-stock.update', $master) : route('marketplace-stock.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @if($master->exists)@method('PUT')@endif
        {{-- Input file tersembunyi — dipicu tombol "+" di atas (label for="fotoUpload"). Tetap di DALAM form agar ikut ter-submit. --}}
        <input type="file" name="foto[]" id="fotoUpload" accept="image/*" multiple class="hidden">
        {{-- Urutan campuran (f<id> tersimpan, n<i> foto Baru ke-i) bila foto digeser sebelum Simpan — lihat terapkanUrutanFoto(). --}}
        <input type="hidden" name="urutan_foto" id="urutanFoto" value="">

        {{-- Informasi Produk --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Informasi Produk</h3>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Nama Produk</label>
                <input type="text" name="name" value="{{ old('name', $master->name) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Kategori <span class="text-xs font-normal text-stone-400">(catatan internal — kategori TikTok/Shopee dipilih di kartu Kategori Marketplace)</span></label>
                <input type="text" name="category" value="{{ old('category', $master->category) }}" placeholder="mis. Perawatan Wajah / BB Cream" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Deskripsi</label>
                <textarea name="description" rows="4" class="w-full px-3 py-2 border border-stone-200 rounded-lg">{{ old('description', $master->description) }}</textarea>
            </div>
        </div>

        {{-- Informasi Penjualan --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Informasi Penjualan</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="mp-induk-saja">
                    <label class="block text-sm font-medium text-stone-700 mb-1">Harga</label>
                    @include('partials.rupiah-input')
                    <input type="text" inputmode="numeric" data-rupiah name="price" value="{{ \App\Support\Rupiah::input(old('price', $master->base_price)) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div class="mp-induk-saja mp-stok-manual">
                    <label class="block text-sm font-medium text-stone-700 mb-1">Stok</label>
                    <input type="number" min="0" name="stock" value="{{ old('stock', $master->base_stock) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Master SKU</label>
                    <input type="text" name="master_sku" value="{{ old('master_sku', $master->master_sku) }}" required class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Barcode</label>
                    <input type="text" name="barcode" value="{{ old('barcode', $master->barcode) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div class="mp-produk-hq">
                    <label class="block text-sm font-medium text-stone-700 mb-1">Sama dengan produk gudang (HQ)</label>
                    <select name="product_id" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                        <option value="">— belum ditandai —</option>
                        @foreach($produkHq as $p)<option value="{{ $p->id }}" @selected((int) old('product_id', $master->product_id) === $p->id)>{{ $p->name }}{{ $p->sku ? ' ('.$p->sku.')' : '' }}</option>@endforeach
                    </select>
                    <p class="text-[11px] text-stone-400 mt-1">Untuk produk <b>satuan</b>: produk ini = produk apa di stok gudang HQ. Hanya catatan (stok tak berubah) — dipakai tombol "Ambil resep dari HQ" di bundling.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tipe</label>
                    <select name="is_bundle" id="tipeBundle" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                        <option value="0" @selected(! old('is_bundle', $master->is_bundle))>Satuan</option>
                        <option value="1" @selected(old('is_bundle', $master->is_bundle))>Bundle</option>
                    </select>
                </div>
            </div>
            <p class="text-[11px] text-stone-400">Harga & stok akan disinkron ke TikTok/Shopee. Field lain disimpan di SKINKU.</p>
        </div>

        {{-- ISI BUNDLING: resep bundle = qty × produk komponen. Stok bundle DIHITUNG dari komponen & order bundle memotong
             stok komponen (MarketplaceMasterService). Tampil bila Tipe = Bundle. Disimpan sbg himpunan lengkap (simpanIsiBundle). --}}
        @php
            $isiRows = old('isi_bundle', $master->exists ? $master->bundleItems->map(fn ($b) => ['component_id' => $b->component_id, 'qty' => $b->qty])->all() : []);
        @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-3" id="isiBundleCard">
            <input type="hidden" name="isi_bundle_ada" value="1">
            <h3 class="font-semibold text-stone-800">Isi Bundling</h3>
            <p class="text-[11px] text-stone-500 leading-relaxed">Isi bundling ini terdiri dari apa saja. Setelah diisi: <b>stok bundling dihitung otomatis</b> dari stok isinya (mis. Reina 100 pcs, isi 3 → stok bundling 33), dan <b>tiap bundling terjual otomatis memotong stok isinya</b>. Kosongkan = stok bundling diisi manual seperti biasa.</p>
            <table class="w-full text-sm" id="isiBundleTabel">
                <thead><tr class="text-left text-xs text-stone-500"><th class="py-1 pr-2">Produk isi</th><th class="py-1 pr-2 w-24">Jumlah</th><th></th></tr></thead>
                <tbody>
                    @foreach($isiRows as $i => $row)
                        <tr class="mp-isi">
                            <td class="py-1 pr-2"><select name="isi_bundle[{{ $i }}][component_id]" required class="mp-isi-produk w-full px-2 py-1.5 border border-stone-200 rounded-lg">
                                <option value="">— pilih produk —</option>
                                @foreach($komponenOpsi as $o)<option value="{{ $o['id'] }}" data-stok="{{ $o['stok'] }}" @selected((int) ($row['component_id'] ?? 0) === $o['id'])>{{ $o['label'] }}</option>@endforeach
                            </select></td>
                            <td class="py-1 pr-2"><input type="number" min="1" max="999" name="isi_bundle[{{ $i }}][qty]" value="{{ $row['qty'] ?? 1 }}" required class="mp-isi-qty w-20 px-2 py-1.5 border border-stone-200 rounded-lg"></td>
                            <td class="py-1"><button type="button" class="mp-isi-hapus text-xs text-rose-600 hover:underline">Hapus</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <template id="isiBundleOpsi"><option value="">— pilih produk —</option>@foreach($komponenOpsi as $o)<option value="{{ $o['id'] }}" data-stok="{{ $o['stok'] }}">{{ $o['label'] }}</option>@endforeach</template>
            <p id="isiHqInfo" class="text-[11px] leading-relaxed" hidden></p>
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="flex gap-2 flex-wrap">
                    <button type="button" id="isiTambah" class="px-3 py-1.5 text-sm rounded-lg border border-dashed border-stone-300 text-stone-600 hover:border-indigo-400 hover:text-indigo-700">+ Tambah isi</button>
                    @if($master->exists)<button type="button" id="isiDariHq" data-url="{{ route('marketplace-stock.master.resep-hq', $master) }}" class="px-3 py-1.5 text-sm rounded-lg bg-stone-100 text-stone-700 font-semibold hover:bg-stone-200" title="Isi dari resep SKU map di stok HQ (Order TikTok/Shopee)">Ambil resep dari HQ</button>@endif
                </div>
                <span class="text-xs text-stone-600">Perkiraan stok bundling: <b id="isiPerkiraan">—</b></span>
            </div>
        </div>

        {{-- VARIAN ala Desty: opsi varian = master anak (SKU/harga/stok/barcode + listing sendiri). Nama/foto/deskripsi/
             kategori tetap di produk ini (induk). Daftar terkirim = himpunan lengkap (lihat simpanVarian()). --}}
        @php
            $varianRows = old('varian', $master->exists ? $master->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->variant_name, 'sku' => $v->master_sku, 'price' => $v->base_price, 'stock' => $v->base_stock, 'barcode' => $v->barcode, 'listing' => $v->listings->count(),
                'isi_sku' => $v->bundleItems->first()?->component?->master_sku, 'isi_qty' => $v->bundleItems->first()?->qty])->all() : []);
        @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4" id="varianCard">
            <input type="hidden" name="varian_ada" value="1">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <h3 class="font-semibold text-stone-800">Varian Produk</h3>
                <span class="text-xs text-stone-400">mis. Qty: Scrub 1 Pcs, Scrub 3 Pcs</span>
            </div>
            <p class="text-[11px] text-stone-500 leading-relaxed">Isi bila produk ini punya beberapa varian di TikTok/Shopee. Tiap varian punya <b>SKU, harga, stok & barcode sendiri</b> dan ditautkan ke varian marketplace-nya lewat menu <b>Atur</b> di baris varian (katalog). Nama, foto, deskripsi & kategori cukup diisi sekali di produk ini. Kosongkan tabel = produk tanpa varian.</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tipe Varian</label>
                    <input type="text" name="variant_type" value="{{ old('variant_type', $master->variant_type) }}" list="tipeVarianList" placeholder="Qty / Ukuran / Warna" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                    <datalist id="tipeVarianList"><option value="Qty"><option value="Ukuran"><option value="Isi"><option value="Warna"><option value="Paket"></datalist>
                </div>
            </div>
            <div class="flex flex-wrap items-end gap-2 bg-stone-50 rounded-xl p-3">
                <span class="text-xs font-semibold text-stone-600 w-full">Terapkan ke semua varian</span>
                <input type="text" inputmode="numeric" data-rupiah id="varSemuaHarga" placeholder="Harga" class="w-32 px-2 py-1.5 border border-stone-200 rounded-lg text-sm">
                <input type="number" min="0" id="varSemuaStok" placeholder="Stok" class="w-24 px-2 py-1.5 border border-stone-200 rounded-lg text-sm">
                <button type="button" id="varTerapkan" class="px-3 py-1.5 text-sm rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">Terapkan</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="varianTabel">
                    <thead><tr class="text-left text-xs text-stone-500"><th class="py-1 pr-2">Nama opsi *</th><th class="py-1 pr-2">SKU *</th><th class="py-1 pr-2">Harga</th><th class="py-1 pr-2">Stok</th><th class="py-1 pr-2">Barcode</th><th class="py-1 pr-2" title="Varian berisi beberapa pcs produk lain/saudara (mis. 3 Pcs = SKU Scrub-1 × 3) → stok varian dihitung otomatis & order memotong stok isinya">Isi (SKU × qty) <span class="font-normal text-stone-400">opsional</span></th><th class="py-1 pr-2">Tautan</th><th></th></tr></thead>
                    <tbody>
                        @foreach($varianRows as $i => $v)
                            <tr class="mp-var">
                                <td class="py-1 pr-2"><input type="hidden" name="varian[{{ $i }}][id]" value="{{ $v['id'] ?? '' }}"><input type="text" name="varian[{{ $i }}][name]" value="{{ $v['name'] ?? '' }}" required maxlength="100" class="w-40 px-2 py-1.5 border border-stone-200 rounded-lg"></td>
                                <td class="py-1 pr-2"><input type="text" name="varian[{{ $i }}][sku]" value="{{ $v['sku'] ?? '' }}" required maxlength="255" class="w-32 px-2 py-1.5 border border-stone-200 rounded-lg"></td>
                                <td class="py-1 pr-2"><input type="text" inputmode="numeric" data-rupiah name="varian[{{ $i }}][price]" value="{{ \App\Support\Rupiah::input($v['price'] ?? null) }}" class="mp-var-harga w-28 px-2 py-1.5 border border-stone-200 rounded-lg"></td>
                                <td class="py-1 pr-2"><input type="number" min="0" name="varian[{{ $i }}][stock]" value="{{ $v['stock'] ?? '' }}" class="mp-var-stok w-20 px-2 py-1.5 border border-stone-200 rounded-lg"></td>
                                <td class="py-1 pr-2"><input type="text" name="varian[{{ $i }}][barcode]" value="{{ $v['barcode'] ?? '' }}" maxlength="255" class="w-32 px-2 py-1.5 border border-stone-200 rounded-lg"></td>
                                <td class="py-1 pr-2 whitespace-nowrap"><input type="text" name="varian[{{ $i }}][isi_sku]" value="{{ $v['isi_sku'] ?? '' }}" maxlength="255" list="mpSkuIsi" placeholder="SKU isi" class="mp-var-isi w-24 px-2 py-1.5 border border-stone-200 rounded-lg"> × <input type="number" min="1" max="999" name="varian[{{ $i }}][isi_qty]" value="{{ $v['isi_qty'] ?? '' }}" placeholder="qty" class="w-14 px-2 py-1.5 border border-stone-200 rounded-lg"></td>
                                <td class="py-1 pr-2 text-xs text-stone-500" data-listing="{{ $v['listing'] ?? 0 }}">{{ ($v['listing'] ?? 0) ? $v['listing'].' listing' : '—' }}</td>
                                <td class="py-1"><button type="button" class="mp-var-hapus text-xs text-rose-600 hover:underline">Hapus</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <datalist id="mpSkuIsi">@foreach($komponenOpsi ?? [] as $k)<option value="{{ $k['sku'] }}">{{ $k['label'] }}</option>@endforeach</datalist>
            <p class="text-[11px] text-stone-500">💡 Varian isi banyak (mis. <b>3 Pcs</b>): isi kolom <b>Isi</b> dengan SKU varian satuannya (mis. <code>Scrub-1</code>) × <b>3</b>. Stok varian itu jadi otomatis = stok satuan ÷ 3, dan tiap order memotong stok satuan ×3. Kolom Stok-nya tak perlu diisi.</p>
            <button type="button" id="varTambah" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border border-dashed border-stone-300 text-stone-600 hover:border-indigo-400 hover:text-indigo-700">+ Tambah opsi varian</button>
            @if($master->exists && $master->variants->isEmpty() && $master->listings()->exists())
                <p class="text-[11px] text-amber-700 bg-amber-50 rounded-lg px-3 py-2">Produk ini sudah tertaut ke listing marketplace. Saat varian pertama disimpan, listing itu <b>dipindah ke varian pertama</b> (harga/stok ikut bila varian belum diisi) — tautkan ulang per varian bila perlu.</p>
            @endif
        </div>

        {{-- Pengiriman --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4">
            <h3 class="font-semibold text-stone-800">Pengiriman</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Berat (g)</label>
                    <input type="number" min="0" name="weight_g" value="{{ old('weight_g', $master->weight_g) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Panjang (cm)</label>
                    <input type="number" min="0" name="length_cm" value="{{ old('length_cm', $master->length_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Lebar (cm)</label>
                    <input type="number" min="0" name="width_cm" value="{{ old('width_cm', $master->width_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tinggi (cm)</label>
                    <input type="number" min="0" name="height_cm" value="{{ old('height_cm', $master->height_cm) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
            </div>
        </div>

        {{-- Kategori marketplace: pohon & ID TikTok ≠ Shopee → pemilih terpisah dari API + atribut wajib kategori itu.
             Nilai dikirim lewat input tersembunyi (JSON diisi skrip saat submit); disaring kategoriAttributes() di controller. --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-4" id="kategoriMarketplace"
             data-cari-url="{{ route('marketplace-stock.kategori.cari', '__CH__') }}"
             data-anak-url="{{ route('marketplace-stock.kategori.anak', '__CH__') }}"
             data-atribut-url="{{ route('marketplace-stock.kategori.atribut', ['__CH__', '__ID__']) }}">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <h3 class="font-semibold text-stone-800">Kategori Marketplace</h3>
                @if($master->exists && $master->listings()->whereNotNull('item_id')->exists())
                    <button type="submit" form="tarikKategoriForm" class="text-xs px-3 py-1.5 rounded-lg bg-stone-100 text-stone-700 hover:bg-stone-200 font-semibold" title="Isi kategori & atribut dari yang sekarang terpasang di TikTok/Shopee">Ambil dari listing marketplace</button>
                @endif
            </div>
            <p class="text-[11px] text-stone-500 leading-relaxed">Pilih kategori resmi tiap marketplace lalu isi atributnya (<span class="text-rose-600 font-semibold">*</span> = wajib). Ikut terkirim saat Simpan (listing yang sudah pernah didorong) / tombol Dorong. <b>⚠️ Ganti kategori di TikTok = produk direview ulang</b>, dan atribut lama terhapus — jadi lengkapi atributnya. Belum yakin? Klik <b>Ambil dari listing marketplace</b> dulu supaya mulai dari kategori yang sekarang terpasang.</p>
            @foreach(['tiktok' => 'TikTok Shop', 'shopee' => 'Shopee'] as $ch => $label)
                @php
                    $attrsLama = $master->{$ch.'_attributes'} ?? [];
                    $brandLama = $ch === 'shopee' ? $master->shopee_brand : null;
                @endphp
                <div class="mp-kat border border-stone-200 rounded-xl p-4 space-y-2" data-channel="{{ $ch }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-stone-700">{{ $label }}</span>
                        <button type="button" class="mp-kat-hapus text-[11px] text-rose-600 hover:underline" @if(! $master->{$ch.'_category_id'}) hidden @endif>Kosongkan</button>
                    </div>
                    <div class="mp-kat-terpilih text-xs text-stone-600">{{ $master->{$ch.'_category_name'} ?: 'Belum dipilih — kategori di marketplace tidak diubah.' }}</div>
                    {{-- Pemilih ala Seller Center: kotak → panel (cari + jejak + kolom bertingkat bersebelahan). --}}
                    <div class="mp-kat-pilih" style="position:relative">
                        <button type="button" class="mp-kat-buka w-full px-3 py-2 border border-stone-200 rounded-lg text-sm text-left flex items-center justify-between gap-2 bg-white hover:border-stone-300">
                            <span class="mp-kat-label truncate">{{ $master->{$ch.'_category_name'} ?: 'Pilih kategori '.$label }}</span><span aria-hidden="true">▾</span>
                        </button>
                        <div class="mp-kat-panel" hidden style="position:absolute;left:0;right:0;top:calc(100% + .25rem);z-index:40;background:#fff;border:1px solid #e7e5e4;border-radius:.75rem;box-shadow:0 10px 25px rgba(0,0,0,.12);padding:.6rem">
                            <input type="text" class="mp-kat-cari w-full px-3 py-2 border border-stone-200 rounded-lg text-sm" placeholder="Cari kategori… (atau telusuri kolom di bawah)" autocomplete="off">
                            <div class="mp-kat-jejak" style="font-size:12px;color:#78716c;padding:.5rem .2rem .35rem"></div>
                            <div class="mp-kat-kolom" style="display:flex;overflow-x:auto;height:16rem;border-top:1px solid #f5f5f4"></div>
                            <div class="mp-kat-hasil" hidden style="height:16rem;overflow:auto;border-top:1px solid #f5f5f4"></div>
                        </div>
                    </div>
                    <div class="mp-kat-atribut space-y-2"></div>
                    <input type="hidden" name="{{ $ch }}_category_id" value="{{ $master->{$ch.'_category_id'} }}">
                    <input type="hidden" name="{{ $ch }}_category_name" value="{{ $master->{$ch.'_category_name'} }}">
                    <input type="hidden" name="{{ $ch }}_attributes" value="{{ json_encode($attrsLama) }}">
                    @if($ch === 'shopee')<input type="hidden" name="shopee_brand" value="{{ $brandLama ? json_encode($brandLama) : '' }}">@endif
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-2">
            <button class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-700 text-white rounded-lg hover:bg-red-800 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg>Simpan produk</button>
            <a href="{{ route('marketplace-stock.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>Batal</a>
        </div>
    </form>
    @if($master->exists)
        <form id="tarikKategoriForm" method="POST" action="{{ route('marketplace-stock.master.kategori.tarik', $master) }}" onsubmit="return confirm('Isi kategori & atribut dari yang sekarang terpasang di TikTok/Shopee? Pilihan kategori yang belum disimpan akan diganti.')">@csrf</form>
    @endif

    @if($master->exists)
        {{-- DORONG KONTEN — <form> SENDIRI, WAJIB DI LUAR form Simpan di atas (HTML larang <form> nested; dijaga tes kedalaman-form).
             Mengirim data yang SUDAH TERSIMPAN. Manual = SEMUA dipaksa (stok, harga, konten, foto). Dorong pertama
             mengaktifkan sinkron otomatis tiap Simpan utk listing itu (MarketplaceMasterService::pushMasterContent). --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-3">
            <h3 class="font-semibold text-stone-800">Dorong Konten &amp; Foto ke Marketplace</h3>
            <p class="text-xs text-stone-500 leading-relaxed"><b>Otomatis:</b> tiap klik <b>Simpan</b>, harga &amp; stok langsung terkirim, dan <b>nama, deskripsi, berat, dimensi, barcode &amp; foto</b> yang berubah ikut tersinkron ke listing TikTok &amp; Shopee yang <b>sudah pernah didorong</b> lewat tombol ini. <b>Tombol ini</b> = dorong <b>SEMUA</b> sekarang (paksa): isi listing <b>ditimpa</b> (field kosong dilewati) dan <b>⚠️ SEMUA foto listing DIGANTI</b> foto master (urutan sama, foto pertama = utama) — tak bisa dibatalkan dari SKINKU, jadi pastikan foto master sudah lengkap. Wajib sekali untuk listing baru tertaut supaya sinkron otomatis aktif. <b>Nama</b> hanya dikirim bila produk di marketplace cuma punya 1 varian (judul berlaku untuk semua varian); <b>barcode</b> hanya bila GTIN/EAN valid. <b>Kategori &amp; atribut</b> ikut dari kartu Kategori Marketplace (bila dipilih).</p>amp; atribut</b> ikut dari kartu Kategori Marketplace (bila dipilih).</p>
            <form method="POST" action="{{ route('marketplace-stock.master.konten', $master) }}" onsubmit="return confirm('Dorong SEMUA ke TikTok &amp; Shopee: stok, harga, nama, deskripsi, berat, dimensi, barcode &amp; FOTO. Isi listing DITIMPA dan SEMUA foto listing DIGANTI foto master. Setelah ini, tiap Simpan menyinkronkan otomatis. Lanjut?')">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12h15m-6-6 6 6-6 6M5 5v14"/></svg>Dorong konten &amp; foto ke marketplace</button>
            </form>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function(){
    var input = document.getElementById('fotoUpload');
    var grid = document.getElementById('fotoGrid');
    var addTile = document.getElementById('fotoAddTile');
    var form = input ? input.closest('form') : null;
    if (!input || !grid || !addTile || !form) return;

    var MAX_DIM = 1600, QUALITY = 0.85; // ukuran & kualitas foto ramah-marketplace
    var busy = 0;             // batch kompresi yang sedang berjalan
    var pendingSubmit = false;

    // Kecilkan+kompres 1 gambar via canvas → File JPEG kecil. Kalau gagal, pakai file asli.
    function compress(file, cb){
        if (!file || file.type.indexOf('image/') !== 0) { cb(file); return; }
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function(){
            try {
                var w = img.naturalWidth, h = img.naturalHeight;
                var scale = Math.min(1, MAX_DIM / Math.max(w, h));
                var nw = Math.max(1, Math.round(w * scale)), nh = Math.max(1, Math.round(h * scale));
                var canvas = document.createElement('canvas');
                canvas.width = nw; canvas.height = nh;
                var ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, nw, nh); // ratakan transparansi (JPEG tanpa alpha)
                ctx.drawImage(img, 0, 0, nw, nh);
                URL.revokeObjectURL(url);
                canvas.toBlob(function(blob){
                    if (!blob) { cb(file); return; }
                    var base = (file.name || 'foto').replace(/\.[^.]+$/, '');
                    try { cb(new File([blob], base + '.jpg', { type: 'image/jpeg', lastModified: Date.now() })); }
                    catch (e) { cb(file); } // browser lawas tanpa constructor File
                }, 'image/jpeg', QUALITY);
            } catch (e) { URL.revokeObjectURL(url); cb(file); }
        };
        img.onerror = function(){ URL.revokeObjectURL(url); cb(file); };
        img.src = url;
    }

    // Foto Baru DITAMPUNG (bukan diganti) tiap kali pilih — bisa tambah 1-per-1. input.files dirakit ulang dari `baru`
    // via DataTransfer; browser tanpa DataTransfer → perilaku lama (pilihan baru menggantikan pilihan sebelumnya).
    var baru = []; // [{file, tile}] urut = indeks di foto[] (token n<i>)
    var canDT = (function(){ try { new DataTransfer(); return true; } catch (e) { return false; } })();

    function rakit(){
        if (canDT) {
            var dt = new DataTransfer();
            baru.forEach(function(b){ dt.items.add(b.file); });
            input.files = dt.files;
        }
        baru.forEach(function(b, i){ b.tile.setAttribute('data-new', String(i)); });
        grid.dispatchEvent(new CustomEvent('foto:baru')); // skrip geser: segarkan urutan_foto & hint
    }

    function renderPreview(file){
        var tile = document.createElement('div');
        tile.className = 'mps-tile';
        var im = document.createElement('img');
        im.draggable = false;
        im.src = URL.createObjectURL(file);
        im.onload = function(){ URL.revokeObjectURL(im.src); };
        var badge = document.createElement('span');
        badge.className = 'mps-badge mps-badge-baru';
        badge.textContent = 'Baru';
        var grip = document.createElement('span');
        grip.className = 'mps-grip'; grip.title = 'Geser untuk atur urutan'; grip.setAttribute('aria-hidden', 'true'); grip.textContent = '⠿';
        var actions = document.createElement('div');
        actions.className = 'mps-actions';
        var del = document.createElement('button');
        del.type = 'button'; del.title = 'Batalkan foto ini'; del.textContent = 'Hapus';
        actions.appendChild(del);
        tile.appendChild(im); tile.appendChild(badge); tile.appendChild(grip); tile.appendChild(actions);
        grid.insertBefore(tile, addTile);
        var item = { file: file, tile: tile };
        del.addEventListener('click', function(){
            baru = baru.filter(function(b){ return b !== item; });
            if (tile.parentNode) tile.parentNode.removeChild(tile);
            if (!canDT) { input.value = ''; baru.forEach(function(b){ b.tile.remove(); }); baru = []; } // tak bisa buang 1 file
            rakit();
        });
        return item;
    }

    input.addEventListener('change', function(){
        var files = Array.prototype.slice.call(input.files || []);
        if (!canDT) { baru.forEach(function(b){ b.tile.remove(); }); baru = []; }
        else if (!files.length) { rakit(); return; } // dialog dibatalkan → pertahankan foto Baru yang sudah ada
        var sisa = 9 - grid.querySelectorAll('.mps-tile[data-file-id]').length - baru.length;
        if (files.length > sisa) {
            alert('Maksimal 9 foto per produk — ' + (sisa > 0 ? 'hanya ' + sisa + ' foto pertama yang ditambahkan.' : 'foto tidak ditambahkan.'));
            files = files.slice(0, Math.max(0, sisa));
        }
        if (!files.length) { rakit(); return; }

        var out = new Array(files.length), done = 0;
        busy += 1;
        files.forEach(function(f, i){
            compress(f, function(res){
                out[i] = res;
                if (++done === files.length){
                    out.forEach(function(r){ if (r) baru.push(renderPreview(r)); });
                    rakit(); // ganti isi input dgn SEMUA foto Baru hasil kompres (tak didukung → file asli terkirim)
                    busy -= 1;
                    if (busy === 0 && pendingSubmit) { pendingSubmit = false; form.submit(); }
                }
            });
        });
    });

    // Tahan submit sampai kompresi selesai (cegah upload file asli yang besar → timeout).
    form.addEventListener('submit', function(e){
        if (busy > 0) { e.preventDefault(); pendingSubmit = true; }
    });
})();

(function(){
    // Atur urutan foto dengan geser (drag & drop) — foto tersimpan MAUPUN foto Baru (belum di-Simpan).
    // Mouse: dari mana saja di foto; sentuh/pen: lewat ikon ⠿ (supaya layar HP tetap bisa di-scroll). Foto paling kiri = Utama.
    // Hanya foto tersimpan → urutan langsung disimpan via AJAX. Ada foto Baru → urutan dicatat di input `urutan_foto`
    // (token f<id>/n<i>) dan diterapkan server saat Simpan (terapkanUrutanFoto).
    var grid = document.getElementById('fotoGrid');
    if (!grid) return;
    var url = grid.getAttribute('data-urutan-url');
    var hidden = document.getElementById('urutanFoto');
    var hint = document.getElementById('fotoUrutHint');
    var meta = document.querySelector('meta[name="csrf-token"]');
    var statusEl = document.getElementById('fotoUrutStatus');
    var SEL = '.mps-tile[data-file-id], .mps-tile[data-new]';
    var drag = null, sx = 0, sy = 0, moved = false, before = '';

    function tiles(){ return Array.prototype.slice.call(grid.querySelectorAll(SEL)); }
    function token(t){ return t.hasAttribute('data-file-id') ? 'f' + t.getAttribute('data-file-id') : 'n' + t.getAttribute('data-new'); }
    function tokens(){ return tiles().map(token); }
    function adaBaru(){ return !!grid.querySelector('.mps-tile[data-new]'); }
    function status(msg, err){ if (statusEl) { statusEl.textContent = msg; statusEl.style.color = err ? '#e11d48' : '#059669'; } }

    // Badge "Utama" pindah ke foto pertama; tombol jadikan-utama disembunyikan di foto pertama.
    function rapikanUtama(){
        tiles().forEach(function(t, i){
            var badge = t.querySelector('.mps-badge-utama');
            if (i === 0 && !badge) {
                badge = document.createElement('span');
                badge.className = 'mps-badge mps-badge-utama';
                badge.textContent = 'Utama';
                t.querySelector('img').insertAdjacentElement('afterend', badge);
            } else if (i !== 0 && badge) {
                badge.parentNode.removeChild(badge);
            }
            var utama = t.querySelector('form[action$="/utama"]');
            if (utama) utama.style.display = i === 0 ? 'none' : '';
        });
    }

    // Foto Baru ditambah/dibatalkan → indeks n<i> berubah: bila urutan sudah pernah digeser, catat ulang dari tampilan.
    grid.addEventListener('foto:baru', function(){
        if (hidden && hidden.value !== '') hidden.value = tokens().join(',');
        rapikanUtama();
        if (hint) hint.hidden = tiles().length < 2;
        status('');
    });

    function onMove(e){
        if (!drag) return;
        if (!moved && Math.abs(e.clientX - sx) + Math.abs(e.clientY - sy) < 6) return; // klik biasa ≠ geser
        moved = true;
        e.preventDefault();
        drag.classList.add('mps-dragging');
        drag.style.pointerEvents = 'none';
        var under = document.elementFromPoint(e.clientX, e.clientY);
        drag.style.pointerEvents = '';
        var target = under && under.closest ? under.closest(SEL) : null;
        if (!target || target === drag || target.parentNode !== grid) return;
        var list = tiles();
        grid.insertBefore(drag, list.indexOf(drag) < list.indexOf(target) ? target.nextSibling : target);
    }

    function onUp(){
        document.removeEventListener('pointermove', onMove);
        document.removeEventListener('pointerup', onUp);
        document.removeEventListener('pointercancel', onUp);
        if (!drag) return;
        drag.classList.remove('mps-dragging');
        drag = null;
        if (!moved) return;
        // Telan klik yang menyusul pelepasan geser (jangan sampai memicu tombol Hapus/Utama di bawah kursor).
        var telan = function(ev){ ev.stopPropagation(); ev.preventDefault(); };
        document.addEventListener('click', telan, true);
        setTimeout(function(){ document.removeEventListener('click', telan, true); }, 0);
        if (tokens().join(',') === before) return; // dilepas di tempat semula
        rapikanUtama();
        if (adaBaru() || !url) {
            if (hidden) hidden.value = tokens().join(',');
            status('Urutan dicatat — tersimpan saat klik Simpan.');
        } else {
            simpan();
        }
    }

    grid.addEventListener('pointerdown', function(e){
        var tile = e.target.closest ? e.target.closest(SEL) : null;
        if (!tile || e.button > 0 || e.target.closest('button, a, form')) return; // tombol Hapus/Utama tetap bisa diklik
        if (e.pointerType !== 'mouse' && !e.target.closest('.mps-grip')) return;     // HP: geser lewat ikon ⠿
        drag = tile; sx = e.clientX; sy = e.clientY; moved = false; before = tokens().join(',');
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
        document.addEventListener('pointercancel', onUp);
    });
    // Cegah drag bawaan browser (gambar "hantu") agar tak bentrok dengan geser kita.
    grid.addEventListener('dragstart', function(e){ if (e.target.closest && e.target.closest(SEL)) e.preventDefault(); });

    function simpan(){
        var ids = tiles().map(function(t){ return parseInt(t.getAttribute('data-file-id'), 10); });
        status('Menyimpan urutan…');
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': meta ? meta.content : '' },
            body: JSON.stringify({ urutan: ids })
        }).then(function(res){
            // redirected = dialihkan (mis. sesi login habis → halaman login 200): itu GAGAL, bukan tersimpan.
            if (!res.ok || res.redirected) throw new Error(String(res.status));
            status('Urutan foto tersimpan ✓');
        }).catch(function(){
            status('Gagal menyimpan urutan — muat ulang halaman lalu coba lagi.', true);
        });
    }
})();
(function(){
    // Pemilih kategori marketplace (TikTok & Shopee terpisah) + atribut kategori. Nilai diserialisasi ke input
    // tersembunyi SETIAP kali berubah (bukan saat submit: form bisa dikirim lewat form.submit() oleh skrip kompres
    // foto, yang tak memicu event submit). Atribut gagal dimuat → nilai lama tak disentuh.
    var root = document.getElementById('kategoriMarketplace');
    if (!root) return;
    var form = root.closest('form');
    var cariUrl = root.getAttribute('data-cari-url'), atributUrl = root.getAttribute('data-atribut-url');
    var anakUrl = root.getAttribute('data-anak-url');
    var semua = [];

    function getJSON(url){
        return fetch(url, { headers: { 'Accept': 'application/json' } }).then(function(r){
            if (r.redirected) throw new Error('sesi habis — muat ulang halaman');
            return r.json().then(function(j){ if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status)); return j.data; });
        });
    }
    function parse(v){ try { return JSON.parse(v); } catch (e) { return null; } }
    function make(tag, attrs, text){
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function(k){ e.setAttribute(k, attrs[k]); });
        if (text != null) e.textContent = text;
        return e;
    }
    var KELAS = 'w-full px-3 py-2 border border-stone-200 rounded-lg text-sm';

    root.querySelectorAll('.mp-kat').forEach(function(box){
        var ch = box.getAttribute('data-channel');
        var inId = box.querySelector('[name="' + ch + '_category_id"]');
        var inName = box.querySelector('[name="' + ch + '_category_name"]');
        var inAttr = box.querySelector('[name="' + ch + '_attributes"]');
        var inBrand = box.querySelector('[name="shopee_brand"]');
        var cari = box.querySelector('.mp-kat-cari'), hasil = box.querySelector('.mp-kat-hasil');
        var wadah = box.querySelector('.mp-kat-atribut'), terpilih = box.querySelector('.mp-kat-terpilih');
        var hapus = box.querySelector('.mp-kat-hapus');
        var buka = box.querySelector('.mp-kat-buka'), panel = box.querySelector('.mp-kat-panel'), label = box.querySelector('.mp-kat-label');
        var kolom = box.querySelector('.mp-kat-kolom'), jejak = box.querySelector('.mp-kat-jejak');
        var cacheAnak = {}; // parent → anak (hindari fetch ulang saat bolak-balik kolom)
        var lama = parse(inAttr.value) || [];
        var lamaBrand = inBrand ? parse(inBrand.value) : null;
        var meta = null, timer = null;

        function nilaiLama(id){ var a = lama.filter(function(x){ return String(x.id) === String(id); })[0]; return a ? (a.values || []) : []; }

        // Panel ala Seller Center: buka/tutup, klik di luar / Esc menutup.
        function tutup(){ panel.hidden = true; }
        buka.addEventListener('click', function(){
            panel.hidden = !panel.hidden;
            if (!panel.hidden) { if (!kolom.children.length) kol('0', 0); cari.focus(); }
        });
        document.addEventListener('click', function(e){ if (!panel.hidden && !box.querySelector('.mp-kat-pilih').contains(e.target)) tutup(); });
        panel.addEventListener('keydown', function(e){ if (e.key === 'Escape') { tutup(); buka.focus(); } });

        cari.addEventListener('keydown', function(e){ if (e.key === 'Enter') e.preventDefault(); });
        cari.addEventListener('input', function(){
            clearTimeout(timer);
            var q = cari.value.trim();
            if (q.length < 2) { hasil.hidden = true; kolom.hidden = false; jejak.hidden = false; return; }
            timer = setTimeout(function(){
                kolom.hidden = true; jejak.hidden = true; hasil.hidden = false; hasil.textContent = 'Mencari…';
                getJSON(cariUrl.replace('__CH__', ch) + '?q=' + encodeURIComponent(q)).then(function(list){
                    hasil.innerHTML = '';
                    if (!list.length) { hasil.textContent = 'Tidak ketemu — coba kata lain atau telusuri kolom.'; return; }
                    list.forEach(function(k){
                        var b = make('button', { type: 'button' }, k.path);
                        b.addEventListener('click', function(){ pilih(k.id, k.path); });
                        hasil.appendChild(b);
                    });
                }).catch(function(e){ hasil.textContent = 'Gagal mencari: ' + e.message; });
            }, 250);
        });

        function anak(parent){
            if (cacheAnak[parent]) return Promise.resolve(cacheAnak[parent]);
            return getJSON(anakUrl.replace('__CH__', ch) + '?parent=' + encodeURIComponent(parent)).then(function(l){ cacheAnak[parent] = l; return l; });
        }
        function tulisJejak(){
            var nama = Array.prototype.map.call(kolom.querySelectorAll('button.aktif'), function(b){ return b.getAttribute('data-name'); });
            jejak.textContent = ['Semua kategori'].concat(nama).join('  ›  ');
        }
        // Satu kolom per level; klik non-daun → kolom berikutnya di kanan; klik daun → kategori terpilih & panel tutup.
        function kol(parent, depth){
            Array.prototype.slice.call(kolom.children, depth).forEach(function(x){ x.remove(); });
            var c = make('div', { 'class': 'mp-kol' }, 'Memuat…');
            c.style.padding = '.25rem';
            kolom.appendChild(c);
            anak(parent).then(function(list){
                c.textContent = '';
                list.forEach(function(k){
                    var b = make('button', { type: 'button', 'data-name': k.name });
                    b.appendChild(make('span', null, k.name));
                    if (!k.leaf) b.appendChild(make('span', { 'aria-hidden': 'true', style: 'color:#a8a29e' }, '›'));
                    b.addEventListener('click', function(){
                        Array.prototype.forEach.call(c.querySelectorAll('button.aktif'), function(x){ x.classList.remove('aktif'); });
                        b.classList.add('aktif');
                        tulisJejak();
                        if (k.leaf) {
                            Array.prototype.slice.call(kolom.children, depth + 1).forEach(function(x){ x.remove(); });
                            var jalur = Array.prototype.map.call(kolom.querySelectorAll('button.aktif'), function(x){ return x.getAttribute('data-name'); }).join(' > ');
                            pilih(k.id, jalur);
                        } else {
                            kol(k.id, depth + 1);
                        }
                    });
                    c.appendChild(b);
                });
                kolom.scrollLeft = kolom.scrollWidth;
            }).catch(function(e){ c.textContent = 'Gagal memuat: ' + e.message; });
            tulisJejak();
        }

        function pilih(id, path){
            inId.value = id; inName.value = path; terpilih.textContent = path; label.textContent = path;
            hapus.hidden = false; hasil.hidden = true; kolom.hidden = false; jejak.hidden = false; cari.value = '';
            tutup();
            muat(id);
        }

        hapus.addEventListener('click', function(){
            inId.value = ''; inName.value = ''; inAttr.value = '[]'; if (inBrand) inBrand.value = '';
            meta = null; wadah.innerHTML = ''; hapus.hidden = true;
            terpilih.textContent = 'Belum dipilih — kategori di marketplace tidak diubah.';
            label.textContent = 'Pilih kategori';
        });

        function muat(id){
            meta = null; wadah.textContent = 'Memuat atribut…';
            getJSON(atributUrl.replace('__CH__', ch).replace('__ID__', id)).then(function(d){ meta = d; render(d); simpan(); })
                .catch(function(e){ wadah.textContent = 'Gagal memuat atribut (' + e.message + ') — atribut tersimpan tidak diubah.'; });
        }

        function render(d){
            wadah.innerHTML = '';
            d.attributes = d.attributes || [];
            var attrs = d.attributes.slice().sort(function(a, b){ return (b.required ? 1 : 0) - (a.required ? 1 : 0); });
            if (d.brands) {
                var row = make('div', { 'class': 'mp-brand' });
                row.appendChild(make('label', { 'class': 'block text-xs font-medium text-stone-700 mb-1' }, 'Merek' + (d.brand_required ? ' *' : '')));
                var sel = make('select', { 'class': KELAS });
                sel.appendChild(make('option', { value: '' }, '— tidak diubah —'));
                sel.appendChild(make('option', { value: '0', 'data-name': 'NoBrand' }, 'Tanpa merek (NoBrand)'));
                d.brands.forEach(function(b){ var o = make('option', { value: b.id, 'data-name': b.name }, b.name); sel.appendChild(o); });
                if (lamaBrand && lamaBrand.brand_id != null) sel.value = String(lamaBrand.brand_id);
                row.appendChild(sel);
                wadah.appendChild(row);
            }
            if (!attrs.length) { wadah.appendChild(make('p', { 'class': 'text-[11px] text-stone-400' }, 'Kategori ini tak punya atribut produk.')); return; }
            attrs.forEach(function(a){
                var row = make('div', { 'class': 'mp-attr', 'data-id': a.id });
                var lab = make('label', { 'class': 'block text-xs font-medium text-stone-700 mb-1' }, a.name);
                if (a.required) { var bintang = make('span', { 'class': 'text-rose-600' }, ' *'); lab.appendChild(bintang); }
                row.appendChild(lab);
                var lamaV = nilaiLama(a.id);
                var ctl;
                if (a.values.length && !a.custom) {
                    ctl = make('select', { 'class': KELAS });
                    if (a.multi) { ctl.multiple = true; ctl.size = Math.min(5, a.values.length); }
                    else ctl.appendChild(make('option', { value: '' }, '— pilih —'));
                    a.values.forEach(function(v){
                        var o = make('option', { value: v.id }, v.name);
                        if (lamaV.some(function(x){ return String(x.id) === String(v.id); })) o.selected = true;
                        ctl.appendChild(o);
                    });
                } else {
                    var listId = 'dl-' + ch + '-' + a.id;
                    ctl = make('input', { type: 'text', 'class': KELAS, placeholder: a.multi ? 'Pisahkan dengan koma' : '' });
                    if (a.values.length) {
                        var dl = make('datalist', { id: listId });
                        a.values.forEach(function(v){ dl.appendChild(make('option', { value: v.name })); });
                        row.appendChild(dl); ctl.setAttribute('list', listId);
                    }
                    ctl.value = lamaV.map(function(x){ return x.name; }).join(', ');
                }
                ctl.className += ' mp-attr-nilai';
                row.appendChild(ctl);
                if (a.units && a.units.length) {
                    var u = make('select', { 'class': 'mp-attr-unit mt-1 px-2 py-1 border border-stone-200 rounded text-xs' });
                    a.units.forEach(function(x){ u.appendChild(make('option', { value: x }, x)); });
                    if (lamaV[0] && lamaV[0].unit) u.value = lamaV[0].unit;
                    row.appendChild(u);
                }
                wadah.appendChild(row);
            });
        }

        // Baca isian → JSON [{id, values:[{id,name,unit?}]}] ke input tersembunyi (+ merek Shopee).
        function simpan(){
            if (!meta) return;
            var out = [];
            meta.attributes.forEach(function(a){
                var row = wadah.querySelector('.mp-attr[data-id="' + a.id + '"]');
                if (!row) return;
                var ctl = row.querySelector('.mp-attr-nilai'), unitEl = row.querySelector('.mp-attr-unit');
                var unit = unitEl ? unitEl.value : '';
                var vals = [];
                if (ctl.tagName === 'SELECT') {
                    Array.prototype.forEach.call(ctl.selectedOptions, function(o){ if (o.value !== '') vals.push({ id: o.value, name: o.textContent }); });
                } else {
                    (a.multi ? ctl.value.split(',') : [ctl.value]).map(function(x){ return x.trim(); }).filter(Boolean).forEach(function(nama){
                        var cocok = a.values.filter(function(v){ return v.name.toLowerCase() === nama.toLowerCase(); })[0];
                        vals.push(cocok ? { id: cocok.id, name: cocok.name } : { id: '', name: nama });
                    });
                }
                if (unit) vals.forEach(function(v){ v.unit = unit; });
                if (vals.length) out.push({ id: a.id, values: vals });
            });
            inAttr.value = JSON.stringify(out);
            if (inBrand) {
                var b = wadah.querySelector('.mp-brand select');
                var o = b && b.selectedOptions[0];
                inBrand.value = o && o.value !== '' ? JSON.stringify({ brand_id: parseInt(o.value, 10), original_brand_name: o.getAttribute('data-name') || '' }) : '';
            }
        }
        wadah.addEventListener('change', simpan);
        wadah.addEventListener('input', simpan);

        // Atribut wajib kosong → peringatan (marketplace bisa menolak), bukan blokir.
        function kurang(){
            if (!meta || !inId.value) return [];
            var isi = (parse(inAttr.value) || []).map(function(x){ return String(x.id); });
            return meta.attributes.filter(function(a){ return a.required && isi.indexOf(String(a.id)) < 0; }).map(function(a){ return a.name; });
        }
        semua.push(kurang);

        if (inId.value) muat(inId.value);
    });

    if (form) form.addEventListener('submit', function(e){
        var k = [];
        semua.forEach(function(f){ k = k.concat(f()); });
        if (k.length && !confirm('Atribut wajib belum diisi: ' + k.join(', ') + '.\nMarketplace bisa menolak perubahan kategori. Tetap simpan?')) {
            e.preventDefault(); e.stopImmediatePropagation();
        }
    }, true);
})();
(function(){
    // Tabel Varian: tambah/hapus baris (indeks name terus naik → tak bentrok), "Terapkan ke semua", dan sembunyikan
    // Harga/Stok induk bila ada varian (harga/stok diatur per varian).
    var tabel = document.getElementById('varianTabel');
    if (!tabel) return;
    var tbody = tabel.querySelector('tbody');
    var idx = tbody.querySelectorAll('tr.mp-var').length;
    function rapikan(){
        var ada = tbody.querySelectorAll('tr.mp-var').length > 0;
        document.querySelectorAll('.mp-induk-saja').forEach(function(el){ el.hidden = ada; });
    }
    function sel(name, cls, attrs){
        var i = document.createElement('input');
        i.name = name; i.className = cls + ' px-2 py-1.5 border border-stone-200 rounded-lg';
        Object.keys(attrs || {}).forEach(function(k){ i.setAttribute(k, attrs[k]); });
        var td = document.createElement('td'); td.className = 'py-1 pr-2'; td.appendChild(i);
        return td;
    }
    document.getElementById('varTambah').addEventListener('click', function(){
        var i = idx++, tr = document.createElement('tr');
        tr.className = 'mp-var';
        var tdNama = sel('varian[' + i + '][name]', 'w-40', { type: 'text', required: '', maxlength: '100', placeholder: 'mis. 3 Pcs' });
        var hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'varian[' + i + '][id]'; tdNama.insertBefore(hid, tdNama.firstChild);
        tr.appendChild(tdNama);
        tr.appendChild(sel('varian[' + i + '][sku]', 'w-32', { type: 'text', required: '', maxlength: '255' }));
        tr.appendChild(sel('varian[' + i + '][price]', 'mp-var-harga w-28', { type: 'text', inputmode: 'numeric', 'data-rupiah': '' }));
        tr.appendChild(sel('varian[' + i + '][stock]', 'mp-var-stok w-20', { type: 'number', min: '0' }));
        tr.appendChild(sel('varian[' + i + '][barcode]', 'w-32', { type: 'text', maxlength: '255' }));
        var tdIsi = sel('varian[' + i + '][isi_sku]', 'mp-var-isi w-24', { type: 'text', maxlength: '255', list: 'mpSkuIsi', placeholder: 'SKU isi' });
        tdIsi.className += ' whitespace-nowrap'; tdIsi.appendChild(document.createTextNode(' × '));
        var q = document.createElement('input'); q.type = 'number'; q.min = '1'; q.max = '999'; q.placeholder = 'qty';
        q.name = 'varian[' + i + '][isi_qty]'; q.className = 'w-14 px-2 py-1.5 border border-stone-200 rounded-lg'; tdIsi.appendChild(q);
        tr.appendChild(tdIsi);
        var tdL = document.createElement('td'); tdL.className = 'py-1 pr-2 text-xs text-stone-500'; tdL.setAttribute('data-listing', '0'); tdL.textContent = 'baru'; tr.appendChild(tdL);
        var tdH = document.createElement('td'); tdH.className = 'py-1';
        var b = document.createElement('button'); b.type = 'button'; b.className = 'mp-var-hapus text-xs text-rose-600 hover:underline'; b.textContent = 'Hapus';
        tdH.appendChild(b); tr.appendChild(tdH);
        tbody.appendChild(tr);
        tdNama.querySelector('input[type=text]').focus();
        rapikan();
    });
    tbody.addEventListener('click', function(e){
        var b = e.target.closest('.mp-var-hapus');
        if (!b) return;
        var tr = b.closest('tr');
        var n = parseInt(tr.querySelector('[data-listing]').getAttribute('data-listing'), 10) || 0;
        if (n > 0 && !confirm('Varian ini tertaut ke ' + n + ' listing marketplace. Dihapus saat Simpan → listing jadi tak tertaut (stok tak tersinkron lagi). Lanjut?')) return;
        tr.remove();
        rapikan();
    });
    document.getElementById('varTerapkan').addEventListener('click', function(){
        var h = document.getElementById('varSemuaHarga').value, s = document.getElementById('varSemuaStok').value;
        if (h !== '') tbody.querySelectorAll('.mp-var-harga').forEach(function(x){ x.value = h; });
        if (s !== '') tbody.querySelectorAll('.mp-var-stok').forEach(function(x){ x.value = s; });
    });
    rapikan();
})();
(function(){
    // Isi Bundling: tampil bila Tipe = Bundle; tambah/hapus baris; perkiraan stok = min(floor(stok isi / jumlah));
    // input Stok manual disembunyikan bila ada isi (stok dihitung otomatis).
    var card = document.getElementById('isiBundleCard'), tipe = document.getElementById('tipeBundle');
    if (!card || !tipe) return;
    var tbody = card.querySelector('tbody'), opsi = document.getElementById('isiBundleOpsi').innerHTML;
    var idx = tbody.querySelectorAll('tr.mp-isi').length;
    function rapikan(){
        var bundle = tipe.value === '1', ada = bundle && tbody.querySelectorAll('tr.mp-isi').length > 0;
        card.hidden = !bundle;
        // Penanda produk gudang hanya utk satuan — bundling tak ada di gudang sbg satu barang.
        document.querySelectorAll('.mp-produk-hq').forEach(function(el){ el.hidden = bundle; });
        // Tipe Satuan: isian disable (tak divalidasi/terkirim) — flag isi_bundle_ada tetap terkirim → resep dikosongkan server.
        card.querySelectorAll('select, input:not([name="isi_bundle_ada"])').forEach(function(el){ el.disabled = !bundle; });
        document.querySelectorAll('.mp-stok-manual').forEach(function(el){ if (ada) el.hidden = true; else if (!document.querySelector('#varianTabel tr.mp-var')) el.hidden = false; });
        var min = null, kosong = !ada;
        tbody.querySelectorAll('tr.mp-isi').forEach(function(tr){
            var o = tr.querySelector('.mp-isi-produk').selectedOptions[0], q = parseInt(tr.querySelector('.mp-isi-qty').value, 10) || 1;
            var st = o && o.value ? o.getAttribute('data-stok') : '';
            if (st === '' || st === null) { kosong = true; return; }
            var bisa = Math.floor(parseInt(st, 10) / q);
            min = min === null ? bisa : Math.min(min, bisa);
        });
        document.getElementById('isiPerkiraan').textContent = kosong || min === null ? '—' : min;
    }
    function tambah(komponen, qty){
        var i = idx++, tr = document.createElement('tr');
        tr.className = 'mp-isi';
        tr.innerHTML = '<td class="py-1 pr-2"><select name="isi_bundle[' + i + '][component_id]" required class="mp-isi-produk w-full px-2 py-1.5 border border-stone-200 rounded-lg">' + opsi + '</select></td>'
            + '<td class="py-1 pr-2"><input type="number" min="1" max="999" name="isi_bundle[' + i + '][qty]" value="1" required class="mp-isi-qty w-20 px-2 py-1.5 border border-stone-200 rounded-lg"></td>'
            + '<td class="py-1"><button type="button" class="mp-isi-hapus text-xs text-rose-600 hover:underline">Hapus</button></td>';
        tbody.appendChild(tr);
        if (komponen) { tr.querySelector('.mp-isi-produk').value = String(komponen); tr.querySelector('.mp-isi-qty').value = qty; }
        rapikan();
    }
    document.getElementById('isiTambah').addEventListener('click', function(){ tambah(null, 1); });
    var hq = document.getElementById('isiDariHq'), info = document.getElementById('isiHqInfo');
    if (hq) hq.addEventListener('click', function(){
        function tulis(msg, err){ info.hidden = false; info.textContent = msg; info.style.color = err ? '#b45309' : '#047857'; }
        tulis('Mengambil resep HQ…');
        fetch(hq.getAttribute('data-url'), { headers: { 'Accept': 'application/json' } }).then(function(r){
            if (!r.ok || r.redirected) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function(j){
            var d = j.data;
            if (!d.sumber) { tulis('Belum ada resep HQ untuk SKU produk ini — atur di halaman Order TikTok/Shopee (pemetaan SKU), atau isi manual.', true); return; }
            var kosong = d.kosong || [];
            if ((d.rows.length || kosong.length) && tbody.querySelectorAll('tr.mp-isi').length && !confirm('Ganti isi bundling sekarang dengan resep HQ?')) return;
            if (d.rows.length || kosong.length) {
                tbody.innerHTML = '';
                d.rows.forEach(function(x){ tambah(x.component_id, x.qty); });
                // Tak ketemu padanan: baris tetap dibuat dgn jumlah terisi; produk dipilih manual (disorot).
                kosong.forEach(function(x){
                    tambah(null, 1);
                    var tr = tbody.lastElementChild;
                    tr.querySelector('.mp-isi-qty').value = x.qty;
                    var sel = tr.querySelector('.mp-isi-produk');
                    sel.style.borderColor = '#f59e0b'; sel.title = 'Pilih Produk Master untuk: ' + x.nama;
                    sel.options[0].textContent = '— pilih produk untuk: ' + x.nama + ' —';
                });
            }
            var msg = 'Resep HQ (' + d.sumber + '): ' + (d.rows.length ? d.rows.map(function(x){ return x.qty + ' × ' + x.label; }).join(', ') : 'tak ada yang cocok') + '.';
            if (d.gagal.length) msg += ' Belum ketemu padanan untuk: ' + d.gagal.join(', ') + ' — pilih produknya di baris yang disorot (atau tandai "Produk HQ" di Produk Master satuannya supaya lain kali cocok otomatis).';
            tulis(msg, d.gagal.length > 0);
        }).catch(function(e){ tulis('Gagal mengambil resep HQ: ' + e.message, true); });
    });
    tbody.addEventListener('click', function(e){ var b = e.target.closest('.mp-isi-hapus'); if (b) { b.closest('tr').remove(); rapikan(); } });
    tbody.addEventListener('change', rapikan);
    tbody.addEventListener('input', rapikan);
    tipe.addEventListener('change', rapikan);
    rapikan();
})();
</script>
@endpush
