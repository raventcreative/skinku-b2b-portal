<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Services\MarketplaceMasterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pemetaan Produk Master ↔ stok gudang (HQ) dalam satu halaman — persiapan penggabungan stok (Tahap 1).
 * HANYA merapikan data Produk Master: penanda produk gudang (product_id) utk satuan, tipe Bundle, resep bundling
 * dari resep HQ (SKU map). Stok HQ, SKU map, dan marketplace TIDAK disentuh; stok tak berubah.
 */
class MarketplacePemetaanHqController extends Controller
{
    public function index(MarketplaceMasterService $svc): View
    {
        $produk = Product::orderBy('name')->get(['id', 'name', 'sku']);
        // Unit jual saja: induk bervarian dilewati (variannya yang dipetakan).
        $masters = MarketplaceMaster::whereDoesntHave('variants')->with(['listings:id,master_id,channel,seller_sku', 'bundleItems', 'parent:id,name'])
            ->orderBy('is_bundle')->orderBy('name')->get();

        $rows = $masters->map(function (MarketplaceMaster $m) use ($svc, $produk) {
            $row = ['m' => $m, 'tebak' => null, 'seperti_bundle' => false, 'resep' => null];
            if ($m->is_bundle) {
                if ($m->bundleItems->isEmpty()) {
                    $row['resep'] = $svc->resepDariHq($m);
                }
            } else {
                $row['tebak'] = $m->product_id ? null : $svc->tebakProdukHq($m, $produk);
                $row['seperti_bundle'] = MarketplaceMaster::detectBundle($m->name);
            }

            return $row;
        });

        return view('marketplace-stock.pemetaan-hq', [
            'rows' => $rows,
            'produk' => $produk,
            'stat' => [
                'satuan' => $masters->where('is_bundle', false)->count(),
                'satuan_ok' => $masters->where('is_bundle', false)->whereNotNull('product_id')->count(),
                'bundle' => $masters->where('is_bundle', true)->count(),
                'bundle_ok' => $masters->where('is_bundle', true)->filter(fn ($m) => $m->bundleItems->isNotEmpty())->count(),
            ],
        ]);
    }

    public function simpan(Request $r, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate([
            'produk' => ['nullable', 'array'],
            'produk.*' => ['nullable', 'integer', 'exists:products,id'],
            'jadikan_bundle' => ['nullable', 'array'],
            'jadikan_bundle.*' => ['integer'],
            'resep_hq' => ['nullable', 'array'],
            'resep_hq.*' => ['integer'],
        ]);
        $unit = fn () => MarketplaceMaster::whereDoesntHave('variants');

        // 1) Penanda produk gudang (satuan saja) — disimpan dulu supaya pencocokan resep di langkah 3 ikut memakainya.
        $ditandai = 0;
        foreach ((array) ($data['produk'] ?? []) as $id => $produkId) {
            $m = $unit()->where('is_bundle', false)->find((int) $id);
            if ($m && (int) $m->product_id !== (int) $produkId) {
                $m->update(['product_id' => $produkId ?: null]);
                $ditandai++;
            }
        }

        // 2) Jadikan Bundle.
        $jadiBundle = $unit()->whereIn('id', (array) ($data['jadikan_bundle'] ?? []))->where('is_bundle', false)->update(['is_bundle' => true, 'product_id' => null]);

        // 3) Resep dari HQ — hanya bundle tanpa resep & hanya bila SEMUA isi resep HQ ketemu padanannya (tak setengah).
        $resepOk = 0;
        $resepLewat = [];
        foreach ($unit()->where('is_bundle', true)->whereIn('id', (array) ($data['resep_hq'] ?? []))->whereDoesntHave('bundleItems')->get() as $b) {
            $res = $svc->resepDariHq($b);
            if ($res['rows'] === [] || $res['gagal'] !== []) {
                $resepLewat[] = $b->name;

                continue;
            }
            foreach ($res['rows'] as $row) {
                $b->bundleItems()->updateOrCreate(['component_id' => $row['component_id']], ['qty' => $row['qty']]);
            }
            $resepOk++;
        }

        $back = redirect()->route('marketplace-stock.pemetaan-hq')
            ->with('status', "Pemetaan disimpan: {$ditandai} produk ditandai, {$jadiBundle} dijadikan Bundle, {$resepOk} resep bundling diisi dari HQ. Stok tidak berubah.");
        if ($resepLewat !== []) {
            $back->with('error', 'Resep belum lengkap (ada isi tanpa padanan) — isi manual di form: '.implode(', ', $resepLewat));
        }

        return $back;
    }
}
