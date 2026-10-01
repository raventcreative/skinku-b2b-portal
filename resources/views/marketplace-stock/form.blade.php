@extends('layouts.app')
@section('title', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Master')
@section('heading', $master->exists ? 'Ubah Produk Master' : 'Tambah Produk Baru')

@push('head')
<style>
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
    {{-- Flash session('status') sudah ditampilkan layouts.app — jangan diulang di sini (banner dobel habis Hapus/Jadikan Utama). --}}
    @if($errors->any())<div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input.</div>@endif

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
                <label class="block text-sm font-medium text-stone-700 mb-1">Kategori</label>
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
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Harga</label>
                    <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $master->base_price) }}" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                </div>
                <div>
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
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Tipe</label>
                    <select name="is_bundle" class="w-full px-3 py-2 border border-stone-200 rounded-lg">
                        <option value="0" @selected(! old('is_bundle', $master->is_bundle))>Satuan</option>
                        <option value="1" @selected(old('is_bundle', $master->is_bundle))>Bundle</option>
                    </select>
                </div>
            </div>
            <p class="text-[11px] text-stone-400">Harga & stok akan disinkron ke TikTok/Shopee. Field lain disimpan di SKINKU.</p>
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

        <div class="flex flex-wrap gap-2">
            <button class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-700 text-white rounded-lg hover:bg-red-800 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg>Simpan produk</button>
            <a href="{{ route('marketplace-stock.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200 font-semibold"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>Batal</a>
        </div>
    </form>

    @if($master->exists)
        {{-- DORONG KONTEN — <form> SENDIRI, WAJIB DI LUAR form Simpan di atas (HTML larang <form> nested; dijaga tes kedalaman-form).
             Mengirim data yang SUDAH TERSIMPAN (bukan isian form yang belum di-Simpan). Nama produk tak ikut;
             foto ikut: dorong PERTAMA ke tiap listing mengganti semua fotonya, setelahnya hanya bila set foto
             master berubah (diff-guard photo_hash) atau varian lain produk yang sama didorong dari master lain. --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 space-y-3">
            <h3 class="font-semibold text-stone-800">Dorong Konten &amp; Foto ke Marketplace</h3>
            <p class="text-xs text-stone-500 leading-relaxed">Kirim <b>deskripsi, berat, dimensi, dan foto</b> produk ini ke listing TikTok &amp; Shopee yang tertaut — isi di marketplace akan <b>ditimpa</b> (field yang kosong dilewati). <b>⚠️ Foto:</b> dorong <b>PERTAMA</b> ke tiap listing <b>MENGGANTI SEMUA foto listing itu</b> dengan foto master (urutan sama, foto pertama = utama) dan tak bisa dibatalkan dari SKINKU — <b>pastikan foto master sudah lengkap &amp; bagus dulu</b>. Setelah itu foto hanya diganti bila foto master berubah — atau bila varian lain dari produk yang sama didorong dari master lain (foto milik produk: klik terakhir yang menang). Kalau master belum punya foto, foto listing tidak disentuh. Nama produk tidak ikut. Yang dikirim = data yang sudah <b>tersimpan</b>: klik <b>Simpan</b> dulu bila baru mengubahnya. Berlaku untuk seluruh produk di marketplace (tingkat produk, bukan per varian).</p>
            <form method="POST" action="{{ route('marketplace-stock.master.konten', $master) }}" onsubmit="return confirm('Kirim &amp; timpa deskripsi/berat/dimensi di TikTok &amp; Shopee. FOTO: dorong pertama ke tiap listing MENGGANTI SEMUA fotonya dengan foto master (setelah itu hanya bila foto master berubah). Lanjut?')">
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
</script>
@endpush
