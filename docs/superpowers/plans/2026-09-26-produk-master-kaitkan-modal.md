# Produk Master — Modal "Kaitkan Produk" ala Desty Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Ganti picker `<select>` "Tambah ke Marketplace" dengan modal "Kaitkan Produk" ala Desty (tab Semua/Terkait/Tidak Terkait, search, filter channel, tautkan+lepas), + angka Produk/Toko Terkait bisa diklik buka modal.

**Architecture:** Tambah backend bulk link/unlink di `MarketplaceMasterService` + `MarketplaceStockController` (reuse `pushMaster`), embed data listing ringan sekali ke halaman (`json_encode`), render modal via JS vanilla inline (konsisten dg app). Tanpa migrasi, tanpa perubahan skema, HQ tak disentuh.

**Tech Stack:** Laravel 13, PHP 8.3, Blade + Tailwind util classes + JS vanilla inline. PHPUnit class-style.

## Global Constraints

- **Zero-dependency**: tak ada paket composer/npm. JS = vanilla inline `<script>` (app sudah pakai pola ini di banyak view). Tak boleh nambah lib JS.
- **HQ TAK disentuh**: tak baca/tulis `products.hq_stock`, `stock_movements`, `InventoryService`.
- **Izin**: route baru dalam grup `Route::middleware('permission:manage_marketplace_stock')`. Non-izin → 403.
- **Blade**: JANGAN `@json([...])` literal. Embed data pakai `json_encode($x, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)` di dalam `<script>`. Form POST + `@csrf`.
- **Tanpa migrasi baru** (skema lengkap).
- **Runner tes**: `C:/php83/php.exe artisan test`. **Format**: `C:/php83/php.exe vendor/bin/pint --dirty` sebelum commit.
- **Tes**: PHPUnit class-style (NO Pest/ProductFactory). `User`/`Product` via `::create()`. Perilaku JS TAK diuji (tak ada infra) — cukup assert markup + data ter-embed + endpoint backend.
- **Commit**: akhiri `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

Helper admin/reseller untuk tes:
```php
private function admin(): \App\Models\User {
    return \App\Models\User::create(['name'=>'a','fullname'=>'A','username'=>'a'.uniqid(),'email'=>uniqid().'@t.test','password'=>\Illuminate\Support\Facades\Hash::make('secret123'),'role'=>\App\Models\User::ROLE_SUPER_ADMIN,'status'=>\App\Models\User::STATUS_ACTIVE]);
}
private function reseller(): \App\Models\User {
    return \App\Models\User::create(['name'=>'r','fullname'=>'R','username'=>'r'.uniqid(),'email'=>uniqid().'@t.test','password'=>\Illuminate\Support\Facades\Hash::make('secret123'),'role'=>\App\Models\User::ROLE_RESELLER,'status'=>\App\Models\User::STATUS_ACTIVE]);
}
```

---

### Task 1: Backend — bulk link/unlink + data modal + routes

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php` (add `linkListings`, `unlinkListings`)
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (add `kaitkan`, `lepas`; extend `index()`; add imports)
- Modify: `routes/web.php`
- Test: `tests/Feature/MarketplaceMaster/KaitkanTest.php`

**Interfaces:**
- Produces: `linkListings(MarketplaceMaster,array):int`, `unlinkListings(MarketplaceMaster,array):int`; routes `marketplace-stock.kaitkan|lepas`; `index()` view vars tambahan `allListings`, `masterNames`, `shopNames`.
- Consumes: `pushMaster(MarketplaceMaster)` (sudah ada).

- [ ] **Step 1: Tulis `tests/Feature/MarketplaceMaster/KaitkanTest.php` (gagal dulu):**
```php
<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KaitkanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function reseller(): User
    {
        return User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_kaitkan_bulk_menautkan_listing_ke_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Hana', 'name_key' => 'hana']);
        $l1 = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => null]);
        $l2 = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'B', 'item_id' => 'P2', 'master_id' => null]);

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.kaitkan', $m), ['listing_ids' => [$l1->id, $l2->id]])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame($m->id, $l1->refresh()->master_id);
        $this->assertSame($m->id, $l2->refresh()->master_id);
    }

    public function test_lepas_hanya_melepas_listing_milik_master_itu(): void
    {
        $m1 = MarketplaceMaster::create(['master_sku' => 'M1', 'name' => 'M1', 'name_key' => 'm1']);
        $m2 = MarketplaceMaster::create(['master_sku' => 'M2', 'name' => 'M2', 'name_key' => 'm2']);
        $own = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => $m1->id]);
        $other = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'B', 'item_id' => 'P2', 'master_id' => $m2->id]);

        // coba lepas dua-duanya lewat m1 — hanya $own yang boleh lepas
        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.lepas', $m1), ['listing_ids' => [$own->id, $other->id]])
            ->assertRedirect();

        $this->assertNull($own->refresh()->master_id);          // dilepas
        $this->assertSame($m2->id, $other->refresh()->master_id); // TAK terlepas (milik m2)
    }

    public function test_kaitkan_validasi_listing_ids_wajib_array(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X', 'name_key' => 'x']);
        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.kaitkan', $m), [])
            ->assertSessionHasErrors('listing_ids');
    }

    public function test_hq_tak_tersentuh(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X', 'name_key' => 'x', 'base_stock' => 5, 'seeded_at' => now()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => null]);
        $before = DB::table('stock_movements')->count();
        $this->actingAs($this->admin())->post(route('marketplace-stock.kaitkan', $m), ['listing_ids' => [$l->id]]);
        $this->assertSame($before, DB::table('stock_movements')->count());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X', 'name_key' => 'x']);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1']);
        $this->actingAs($this->reseller())
            ->post(route('marketplace-stock.kaitkan', $m), ['listing_ids' => [$l->id]])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan → merah** (`--filter=KaitkanTest`).

- [ ] **Step 3: Service** — tambah di `app/Services/MarketplaceMasterService.php` (mis. dekat `tautkanListing`):
```php
    /** Tautkan banyak listing ke master (bulk). Return jumlah listing ter-update. */
    public function linkListings(MarketplaceMaster $m, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return MarketplaceListing::whereIn('id', $ids)->update(['master_id' => $m->id]);
    }

    /** Lepas banyak listing dari master — HANYA yang memang milik master ini (safety). */
    public function unlinkListings(MarketplaceMaster $m, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return MarketplaceListing::whereIn('id', $ids)->where('master_id', $m->id)->update(['master_id' => null]);
    }
```

- [ ] **Step 4: Controller** — di `app/Http/Controllers/MarketplaceStockController.php`:
  - Tambah import di atas: `use App\Models\ShopeeConnection;` dan `use App\Models\TiktokConnection;`.
  - Tambah var di array `view('marketplace-stock.index', [...])` pada `index()` (setelah `unlinkedCount`):
```php
            'allListings' => MarketplaceListing::orderBy('channel')->orderBy('seller_sku')->get(['id', 'channel', 'seller_sku', 'title', 'master_id']),
            'masterNames' => MarketplaceMaster::pluck('name', 'id'),
            'shopNames' => [
                'tiktok' => TiktokConnection::latest('id')->value('shop_name'),
                'shopee' => ShopeeConnection::latest('id')->value('shop_name'),
            ],
```
  - Tambah dua method (mis. setelah `tautkan`):
```php
    public function kaitkan(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate([
            'listing_ids' => ['required', 'array', 'min:1'],
            'listing_ids.*' => ['integer', 'exists:marketplace_listings,id'],
        ]);
        $n = $svc->linkListings($master, $data['listing_ids']);
        $svc->pushMaster($master);

        return back()->with('status', "$n listing ditautkan ke \"{$master->name}\" & stok didorong.");
    }

    public function lepas(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate([
            'listing_ids' => ['required', 'array', 'min:1'],
            'listing_ids.*' => ['integer', 'exists:marketplace_listings,id'],
        ]);
        $n = $svc->unlinkListings($master, $data['listing_ids']);

        return back()->with('status', "$n listing dilepas dari \"{$master->name}\".");
    }
```

- [ ] **Step 5: Rute** — di `routes/web.php` grup `permission:manage_marketplace_stock`, tambah:
```php
        Route::post('/marketplace-stock/master/{master}/kaitkan', [MarketplaceStockController::class, 'kaitkan'])->name('marketplace-stock.kaitkan');
        Route::post('/marketplace-stock/master/{master}/lepas', [MarketplaceStockController::class, 'lepas'])->name('marketplace-stock.lepas');
```

- [ ] **Step 6: `--filter=KaitkanTest` → hijau.** Pint `--dirty`. Jalankan `--filter="MarketplaceMaster|MarketplaceStock"` pastikan tak ada regresi (view lama masih pakai picker `tautkan`, tetap jalan). Commit:
```
feat(marketplace-master): backend bulk kaitkan/lepas listing + data modal
```

---

### Task 2: Frontend — modal "Kaitkan Produk" + JS + wiring

Ganti picker `<select>` lama dengan tombol pembuka modal; jadikan angka Produk/Toko Terkait dapat diklik; tambah satu modal + JS vanilla + embed data.

**Files:**
- Modify: `resources/views/marketplace-stock/index.blade.php`
- Modify: `tests/Feature/MarketplaceMaster/CatalogPageTest.php`

**Interfaces:**
- Consumes: route `marketplace-stock.kaitkan|lepas`; view vars `allListings`, `masterNames`, `shopNames` (dari Task 1).

- [ ] **Step 1: Perbarui `CatalogPageTest.php`** — ganti test lama `test_picker_tambah_ke_marketplace_berisi_listing_belum_tertaut` (yang cek `<select>` lama) jadi cek modal + data ter-embed, dan tambah cek tombol. Sisipkan/replace method berikut (biarkan test lain apa adanya):
```php
    public function test_modal_kaitkan_dan_data_terembed(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'NEW-SKU', 'item_id' => 'P9', 'master_id' => null, 'title' => 'Produk Baru']);

        $res = $this->actingAs($this->admin())->get(route('marketplace-stock.index'))->assertOk();
        $res->assertSee('id="kaitkanModal"', false);   // modal ada
        $res->assertSee('window.__mp', false);          // data ter-embed
        $res->assertSee('Tambah ke Marketplace');       // tombol pembuka di menu Atur
        $res->assertSee('NEW-SKU');                      // listing masuk data embed
    }

    public function test_angka_terkait_klik_buka_modal(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => $m->id]);

        $res = $this->actingAs($this->admin())->get(route('marketplace-stock.index'))->assertOk();
        $res->assertSee('mpOpenKaitkan(this)', false);  // tombol angka Terkait memicu modal
        $res->assertSee('1 Produk');
    }
```
> Jika `test_menu_atur_berisi_aksi_desty` sudah meng-assert "Tambah ke Marketplace", biarkan — masih ada sbg tombol. Hapus/replace HANYA test lama yang meng-assert markup `<select name="listing_id">` picker lama (kini tak ada).

- [ ] **Step 2: Jalankan → merah** (`--filter=CatalogPageTest`).

- [ ] **Step 3: Ganti blok picker lama** di `index.blade.php` — GANTI baris menu Atur "Tambah ke Marketplace" (`<details class="group">…</details>`, sekarang baris ~92-104) dengan satu tombol:
```blade
                                        <button type="button" data-master-id="{{ $m->id }}" data-master-sku="{{ $m->master_sku }}" data-master-name="{{ $m->name }}" onclick="mpOpenKaitkan(this)" class="w-full text-left px-4 py-2 hover:bg-stone-50">Tambah ke Marketplace</button>
```

- [ ] **Step 4: Jadikan kolom Produk/Toko Terkait dapat diklik** — GANTI dua `<td>` (sekarang baris ~84-85) dengan:
```blade
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
```

- [ ] **Step 5: Tambah modal + JS** — sebelum `</div>` penutup `<div class="space-y-4">` (yaitu tepat sebelum baris `@endsection`, letakkan di dalam section), tambahkan blok modal + script berikut. (Boleh taruh setelah penutup `<div class="space-y-4">` tapi masih di dalam `@section('content')`.)
```blade
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
            var badge = st==='terkait' ? '<span class="text-[11px] text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5">✅ Tertaut</span>'
                : st==='tidak' ? '<span class="text-[11px] text-stone-500 bg-stone-100 rounded px-1.5 py-0.5">⬜ Belum</span>'
                : '<span class="text-[11px] text-amber-700 bg-amber-50 rounded px-1.5 py-0.5">🔗 '+esc((window.__mp.masterNames||{})[l.master_id]||'master lain')+'</span>';
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
```

- [ ] **Step 6: `--filter=CatalogPageTest` → hijau.** Pint `--dirty`.

- [ ] **Step 7: Suite marketplace** (`--filter="MarketplaceMaster|MarketplaceStock"`) hijau. Commit:
```
feat(marketplace-master): modal Kaitkan Produk ala Desty (tab/search/filter, tautkan+lepas) + angka Terkait klik
```

---

### Task 3: Docs + full suite

**Files:**
- Modify: `docs/SISTEM.md` (§9d), `docs/PETA-SISTEM.md`

- [ ] **Step 1: Update `docs/SISTEM.md` §9d** — tambahkan bahwa "Tambah ke Marketplace" kini modal "Kaitkan Produk" (tab Semua/Terkait/Tidak Terkait, search, filter channel, tautkan+lepas bulk), angka Produk/Toko Terkait bisa diklik buka modal; route baru `kaitkan`/`lepas`; picker `<select>` lama diganti. Baca seksi dulu, sisipkan ringkas.
- [ ] **Step 2: Update `docs/PETA-SISTEM.md`** — status line Produk Master: + modal Kaitkan Produk (kaitkan/lepas bulk), CODE-VERIFIED via route:list.
- [ ] **Step 3: SUITE PENUH** `C:/php83/php.exe artisan test` → semua hijau. Pint `--dirty`. Commit:
```
docs(marketplace-master): dokumentasikan modal Kaitkan Produk
```

---

## Self-Review (penulis rencana)

- **Cakupan spec**: modal tab Semua/Terkait/Tidak Terkait ✅; search+filter channel ✅; tautkan+lepas bulk ✅; angka Terkait/Toko klik buka modal ✅; data ringan embed json_encode (JSON_HEX_TAG) ✅; reuse pushMaster ✅; HQ tak disentuh (tes) ✅; tanpa migrasi ✅; picker lama diganti ✅.
- **Placeholder**: tak ada.
- **Konsistensi**: `linkListings/unlinkListings(MarketplaceMaster,array):int`; route `kaitkan/lepas`; view vars `allListings/masterNames/shopNames`; JS `mpOpenKaitkan/mpCloseKaitkan/mpSetKaitkanTab/mpRenderKaitkan/mpSubmitKaitkan` konsisten HTML↔JS.
- **Urutan aman-hijau**: T1 backend + data (view lama tetap jalan) → T2 ganti view ke modal + test → T3 docs+suite. Tiap task hijau.
