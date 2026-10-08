<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\MarketplaceListing;
use App\Models\ShopeeBoostItem;
use App\Models\ShopeeConnection;
use App\Services\AuditService;
use App\Services\ShopeeBoostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Halaman "Naikkan Produk" Shopee otomatis (izin manage_marketplace_stock, sama dgn Stok Marketplace): saklar ON/OFF,
 * pilih maks 5 produk (batas Shopee), status per produk, tombol jalankan sekarang. Putaran berkala: command
 * shopee:naikkan-produk tiap 10 menit (ShopeeBoostService).
 */
class ShopeeNaikkanProdukController extends Controller
{
    public function __construct(private ShopeeBoostService $boost) {}

    public function index(): View
    {
        $dipilih = ShopeeBoostItem::orderBy('id')->get();

        return view('marketplace-stock.naikkan', [
            'aktif' => $this->boost->aktif(),
            'terhubung' => ShopeeConnection::exists(),
            'dipilih' => $dipilih,
            'kandidat' => $this->produkShopee()->except($dipilih->pluck('item_id')->all()),
            'maks' => ShopeeBoostService::MAKS,
        ]);
    }

    public function aktif(Request $request): RedirectResponse
    {
        $nyala = $request->boolean('aktif');
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, $nyala ? '1' : '0');
        AuditService::log(action: 'shopee_naikkan_aktif', targetType: 'app_setting', after: ['aktif' => $nyala]);

        return back()->with('status', $nyala
            ? 'Naikkan Produk otomatis AKTIF — produk pilihan dinaikkan lagi tiap masa naiknya habis (dicek tiap 10 menit).'
            : 'Naikkan Produk otomatis dimatikan.');
    }

    public function tambah(Request $request): RedirectResponse
    {
        $itemId = (int) $request->validate(['item_id' => ['required', 'integer']])['item_id'];
        $produk = $this->produkShopee();
        if (! $produk->has($itemId)) {
            return back()->with('error', 'Produk tidak ada di listing Shopee — klik "Refresh listing" di halaman Stok Shopee dulu.');
        }
        if (ShopeeBoostItem::where('item_id', $itemId)->exists()) {
            return back()->with('error', 'Produk itu sudah dipilih.');
        }
        if (ShopeeBoostItem::count() >= ShopeeBoostService::MAKS) {
            return back()->with('error', 'Maksimal '.ShopeeBoostService::MAKS.' produk (batas Shopee) — hapus salah satu dulu.');
        }
        $item = ShopeeBoostItem::create(['item_id' => $itemId, 'title' => $produk[$itemId], 'created_by' => $request->user()->id]);
        AuditService::log(action: 'shopee_naikkan_tambah', targetType: 'shopee_boost_item', targetId: $item->id,
            after: ['item_id' => $item->item_id, 'produk' => $item->title]);

        return back()->with('status', "\"{$item->title}\" ditambahkan ke Naikkan Produk.");
    }

    public function hapus(ShopeeBoostItem $item): RedirectResponse
    {
        AuditService::log(action: 'shopee_naikkan_hapus', targetType: 'shopee_boost_item', targetId: $item->id,
            before: ['item_id' => $item->item_id, 'produk' => $item->title]);
        $item->delete();

        return back()->with('status', "\"{$item->title}\" dihapus dari Naikkan Produk.");
    }

    /** Tombol "Jalankan sekarang": satu putaran, jalan walau saklar mati (utk uji coba). */
    public function jalankan(): RedirectResponse
    {
        $h = $this->boost->jalankan(paksa: true);
        AuditService::log(action: 'shopee_naikkan_jalankan', targetType: 'shopee_boost_item', after: $h);

        return match ($h['status']) {
            'ok' => back()->with('status', "Selesai: {$h['naik']} produk dinaikkan, {$h['sedang_naik']} masih dalam masa naik"
                .($h['gagal'] ? ", {$h['gagal']} gagal — alasannya ada di tabel." : '.')),
            'kosong' => back()->with('error', 'Belum ada produk yang dipilih.'),
            'belum_terhubung' => back()->with('error', 'Toko Shopee belum terhubung (menu Integrasi → Shopee).'),
            default => back()->with('error', 'Gagal menghubungi Shopee: '.($h['pesan'] ?? $h['status'])),
        };
    }

    /** Produk Shopee di listing Stok Marketplace: item_id => judul (naik per produk; varian satu item digabung). */
    private function produkShopee(): Collection
    {
        return MarketplaceListing::with('master:id,name')->where('channel', 'shopee')->whereNotNull('item_id')->orderBy('id')->get()
            ->groupBy(fn (MarketplaceListing $l) => (int) $l->item_id)
            ->map(fn ($g) => $g->first()->title ?: ($g->first()->master?->name ?? 'Item '.$g->first()->item_id));
    }
}
