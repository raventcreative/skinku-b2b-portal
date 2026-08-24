<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Models\ShopeeReturn;
use App\Models\ShopeeSkuMap;
use App\Services\AuditService;
use App\Services\ShopeeClient;
use App\Services\ShopeeOrderService;
use App\Services\ShopeeReturnService;
use App\Services\ShopeeSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopeeController extends Controller
{
    public function __construct(
        private ShopeeClient $shopee,
        private ShopeeOrderService $orders,
        private ShopeeSyncService $sync,
        private ShopeeReturnService $returns,
    ) {}

    public function index()
    {
        return view('shopee.index', [
            'configured' => $this->shopee->configured(),
            'connection' => ShopeeConnection::latest('id')->first(),
            'needMap' => $this->orders->skusNeedingMap(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function connect(): RedirectResponse
    {
        abort_unless($this->shopee->configured(), 400, 'Kredensial Shopee belum diisi di .env server.');

        return redirect()->away($this->shopee->authorizeUrl(route('shopee.callback')));
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code');
        $shopId = (string) $request->query('shop_id');
        if ($code === '' || $shopId === '') {
            return redirect()->route('shopee.index')->with('error', 'Otorisasi dibatalkan / kode Shopee tidak lengkap.');
        }

        try {
            $t = $this->shopee->getToken($code, $shopId);

            ShopeeConnection::updateOrCreate(
                ['shop_id' => $shopId],
                [
                    'access_token' => $t['access_token'],
                    'refresh_token' => $t['refresh_token'],
                    'access_expires_at' => $this->sync->toTime($t['expire_in'] ?? null),
                    'refresh_expires_at' => now()->addDays(30),
                    'connected_by' => $request->user()->id,
                ],
            );

            AuditService::log(action: 'connect_shopee', targetType: 'shopee', after: ['shop_id' => $shopId]);

            return redirect()->route('shopee.index')->with('status', 'Toko Shopee berhasil terhubung.');
        } catch (\Throwable $e) {
            return redirect()->route('shopee.index')->with('error', 'Gagal menghubungkan: '.$e->getMessage());
        }
    }

    public function syncOrders(Request $request): RedirectResponse
    {
        $conn = ShopeeConnection::latest('id')->first();
        abort_unless($conn, 400, 'Belum terhubung ke toko Shopee.');
        try {
            $r = $this->sync->syncOrders($conn, $request->user()->id);
            $msg = "Berhasil tarik & simpan {$r['count']} order Shopee.";
            if ($r['deducted']) {
                $d = $r['deducted'];
                $msg .= " Auto-potong: {$d['done']} dipotong".($d['failed'] ? ", {$d['failed']} gagal" : '').'.';
            }

            return redirect()->route('shopee.orders')->with('status', $msg);
        } catch (\Throwable $e) {
            return redirect()->route('shopee.index')->with('error', 'Gagal tarik order: '.$e->getMessage());
        }
    }

    public function orderList()
    {
        $orders = ShopeeOrder::latest('order_created_at')->latest('id')->paginate(25);
        $previews = $orders->mapWithKeys(fn ($o) => [$o->id => $this->orders->preview($o)]);

        return view('shopee.orders', ['orders' => $orders, 'previews' => $previews, 'needMap' => $this->orders->skusNeedingMap()]);
    }

    public function stockFunnel()
    {
        return view('shopee.stock', [
            'needMap' => $this->orders->skusNeedingMap(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function saveSkuMap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shopee_sku' => ['required', 'string', 'max:190'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        // Satu shopee_sku bisa memetakan ke BANYAK produk (resep) — kunci gabungan.
        ShopeeSkuMap::updateOrCreate(
            ['shopee_sku' => $data['shopee_sku'], 'product_id' => $data['product_id']],
            ['qty' => $data['qty']],
        );

        return back()->with('status', 'Pemetaan SKU disimpan.');
    }

    public function removeSkuMap(ShopeeSkuMap $map): RedirectResponse
    {
        $map->delete();

        return back()->with('status', 'Pemetaan SKU dihapus.');
    }

    public function deductStock(Request $request, ShopeeOrder $order): RedirectResponse
    {
        try {
            $this->orders->deduct($order, $request->user()->id);
            AuditService::log(action: 'shopee_deduct_stock', targetType: 'shopee', targetId: $order->id, after: ['order_sn' => $order->order_sn]);

            return back()->with('status', "Stok order {$order->order_sn} dipotong.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deductAll(Request $request): RedirectResponse
    {
        $d = $this->orders->deductAllReady($request->user()->id);
        AuditService::log(action: 'shopee_deduct_all', targetType: 'shopee', after: $d);

        return back()->with('status', "Potong massal: {$d['done']} dipotong, {$d['failed']} gagal, {$d['skipped']} dilewati.");
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_deduct' => ['nullable', 'boolean'],
            'deduct_from' => ['nullable', 'date'],
        ]);
        $conn = ShopeeConnection::latest('id')->first();
        abort_unless($conn, 400, 'Belum terhubung.');
        $conn->update(['auto_deduct' => (bool) ($data['auto_deduct'] ?? false), 'deduct_from' => $data['deduct_from'] ?? null]);
        AuditService::log(action: 'shopee_settings', targetType: 'shopee', after: $data);

        return back()->with('status', 'Pengaturan Shopee disimpan.');
    }

    /* ---------------- Retur Shopee ---------------- */

    /** Daftar retur + pratinjau + aksi review (approve/tolak). */
    public function returnList()
    {
        $returns = ShopeeReturn::latest('return_created_at')->latest('id')->paginate(25);
        $previews = [];
        foreach ($returns as $r) {
            $previews[$r->id] = $this->returns->preview($r);
        }

        return view('shopee.returns', compact('returns', 'previews'));
    }

    /** Tarik retur/refund terbaru dari Shopee → simpan (otomatis). */
    public function syncReturns(Request $request): RedirectResponse
    {
        $conn = $this->sync->connection();
        if (! $conn) {
            return back()->with('error', 'Belum terhubung ke Shopee.');
        }
        try {
            $n = $this->sync->syncReturns($conn);

            return redirect()->route('shopee.returns')->with('status', "Retur ditarik: {$n}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal tarik retur: '.$e->getMessage().' (cek izin scope Return di app Shopee).');
        }
    }

    /** Approve: barang layak jual → tambah stok. */
    public function restockReturn(Request $request, ShopeeReturn $ret): RedirectResponse
    {
        try {
            $this->returns->restock($ret, $request->user()->id, $request->input('note'));
            AuditService::log(action: 'shopee_return_restock', targetType: 'shopee_return', targetId: $ret->id);

            return back()->with('status', 'Retur di-restock (stok ditambah).');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Tolak: barang cacat → tidak masuk stok. */
    public function rejectReturn(Request $request, ShopeeReturn $ret): RedirectResponse
    {
        $this->returns->reject($ret, $request->user()->id, $request->input('note'));
        AuditService::log(action: 'shopee_return_reject', targetType: 'shopee_return', targetId: $ret->id);

        return back()->with('status', 'Retur ditolak (tidak masuk stok).');
    }

    /** Batalkan keputusan review (balik ke pending; tarik stok lagi jika perlu). */
    public function resetReturn(ShopeeReturn $ret): RedirectResponse
    {
        $this->returns->resetReview($ret);

        return back()->with('status', 'Review retur direset.');
    }
}
