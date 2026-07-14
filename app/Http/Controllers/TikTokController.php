<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\TiktokConnection;
use App\Models\TiktokOrder;
use App\Models\TiktokSkuMap;
use App\Services\AuditService;
use App\Services\TikTokClient;
use App\Services\TikTokOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TikTokController extends Controller
{
    public function __construct(
        private TikTokClient $tiktok,
        private TikTokOrderService $orders,
    ) {}

    public function index()
    {
        return view('tiktok.index', [
            'configured' => $this->tiktok->configured(),
            'connection' => TiktokConnection::latest('id')->first(),
            'orders' => session('tiktok_orders'),
        ]);
    }

    /** Mulai OAuth — arahkan seller ke halaman izin TikTok. */
    public function connect(): RedirectResponse
    {
        abort_unless($this->tiktok->configured(), 400, 'App key/secret TikTok belum diisi di .env server.');

        return redirect()->away($this->tiktok->authorizeUrl());
    }

    /** Callback dari TikTok (?code=...) → tukar token, ambil toko, simpan. */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code') ?: $request->query('auth_code');
        if (! $code) {
            return redirect()->route('tiktok.index')->with('error', 'Otorisasi dibatalkan / tidak ada kode dari TikTok.');
        }

        try {
            $token = $this->tiktok->getToken($code);
            $access = $token['access_token'];
            $shops = $this->tiktok->getShops($access);
            $shop = $shops[0] ?? [];

            TiktokConnection::updateOrCreate(
                ['shop_id' => $shop['id'] ?? ($token['open_id'] ?? 'default')],
                [
                    'shop_cipher' => $shop['cipher'] ?? null,
                    'shop_name' => $shop['name'] ?? ($token['seller_name'] ?? null),
                    'region' => $shop['region'] ?? ($token['seller_base_region'] ?? null),
                    'seller_name' => $token['seller_name'] ?? null,
                    'access_token' => $access,
                    'refresh_token' => $token['refresh_token'],
                    'access_expires_at' => $this->toTime($token['access_token_expire_in'] ?? null),
                    'refresh_expires_at' => $this->toTime($token['refresh_token_expire_in'] ?? null),
                    'connected_by' => $request->user()->id,
                ],
            );

            AuditService::log(action: 'connect_tiktok', targetType: 'tiktok', after: ['shop' => $shop['name'] ?? null]);

            return redirect()->route('tiktok.index')->with('status', 'TikTok Shop berhasil terhubung: '.($shop['name'] ?? 'toko'));
        } catch (\Throwable $e) {
            return redirect()->route('tiktok.index')->with('error', 'Gagal menghubungkan: '.$e->getMessage());
        }
    }

    /** Tarik order terbaru dari TikTok → simpan ke DB (untuk pratinjau potong stok). */
    public function syncOrders(Request $request): RedirectResponse
    {
        $conn = TiktokConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok Shop.');

        try {
            $access = $this->freshToken($conn);
            // Terbaru dulu; ambil beberapa halaman (maks ~500 order) supaya order bulan
            // berjalan ikut ketarik, bukan cuma yang paling lama.
            $all = [];
            $token = '';
            $pages = 0;
            do {
                $data = $this->tiktok->searchOrders($access, $conn->shop_cipher, 50, $token);
                $all = array_merge($all, $data['orders'] ?? []);
                $token = $data['next_page_token'] ?? '';
                $pages++;
            } while ($token && $pages < 10);

            $count = $this->orders->store($all);
            $conn->update(['last_synced_at' => now()]);

            $msg = "Berhasil tarik & simpan {$count} order terbaru dari TikTok.";
            if ($conn->auto_deduct) {
                $r = $this->orders->deductAllReady($request->user()->id);
                $msg .= " Auto-potong: {$r['done']} order dipotong".($r['failed'] ? ", {$r['failed']} gagal (stok kurang?)" : '').'.';
            }

            return redirect()->route('tiktok.orders')->with('status', $msg);
        } catch (\Throwable $e) {
            return redirect()->route('tiktok.index')->with('error', 'Gagal tarik order: '.$e->getMessage());
        }
    }

    /** Daftar order TikTok + pratinjau dampak stok + aksi potong stok. */
    public function orderList()
    {
        $orders = TiktokOrder::latest('order_created_at')->latest('id')->paginate(25);
        $previews = $orders->mapWithKeys(fn ($o) => [$o->id => $this->orders->preview($o)]);

        return view('tiktok.orders', [
            'orders' => $orders,
            'previews' => $previews,
            'connection' => TiktokConnection::latest('id')->first(),
            'skusNeedingMap' => $this->orders->skusNeedingMap(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    /** Tambah komponen resep: 1 SKU TikTok → produk SKINKU × qty (boleh banyak). */
    public function saveSkuMap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tiktok_sku' => ['required', 'string', 'max:190'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        TiktokSkuMap::updateOrCreate(
            ['tiktok_sku' => $data['tiktok_sku'], 'product_id' => $data['product_id']],
            ['qty' => $data['qty']],
        );

        return back()->with('status', "Komponen ditambahkan ke SKU \"{$data['tiktok_sku']}\".");
    }

    /** Hapus 1 komponen resep. */
    public function removeSkuMap(TiktokSkuMap $map): RedirectResponse
    {
        $sku = $map->tiktok_sku;
        $map->delete();

        return back()->with('status', "Komponen dihapus dari SKU \"{$sku}\".");
    }

    /** Potong stok internal untuk order (preview-approve). */
    public function deductStock(Request $request, TiktokOrder $order): RedirectResponse
    {
        try {
            $this->orders->deduct($order, $request->user()->id);
            AuditService::log(action: 'tiktok_deduct_stock', targetType: 'tiktok_order', targetId: $order->id);

            return back()->with('status', "Stok dipotong untuk order {$order->tiktok_order_id}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal potong stok: '.$e->getMessage());
        }
    }

    /** Potong stok SEMUA order yang siap sekaligus. */
    public function deductAll(Request $request): RedirectResponse
    {
        $r = $this->orders->deductAllReady($request->user()->id);
        AuditService::log(action: 'tiktok_deduct_all', targetType: 'tiktok_order', after: $r);

        return back()->with('status', "{$r['done']} order dipotong stoknya".
            ($r['failed'] ? ", {$r['failed']} gagal (stok kurang?)" : '').
            ($r['skipped'] ? ", {$r['skipped']} dilewati (SKU belum lengkap)" : '').'.');
    }

    /** Nyalakan/matikan auto-potong saat sync. */
    public function toggleAuto(Request $request): RedirectResponse
    {
        $conn = TiktokConnection::latest('id')->first();
        abort_unless($conn, 400, 'Belum terhubung.');
        $conn->update(['auto_deduct' => $request->boolean('auto_deduct')]);

        return back()->with('status', $conn->auto_deduct
            ? 'Auto-potong stok DINYALAKAN — tiap tarik order, yang siap langsung dipotong.'
            : 'Auto-potong stok dimatikan — potong manual.');
    }

    /** Batalkan pemotongan stok (kembalikan). */
    public function reverseStock(TiktokOrder $order): RedirectResponse
    {
        try {
            $this->orders->reverse($order);
            AuditService::log(action: 'tiktok_reverse_stock', targetType: 'tiktok_order', targetId: $order->id);

            return back()->with('status', "Pemotongan stok dibatalkan untuk order {$order->tiktok_order_id}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal batalkan: '.$e->getMessage());
        }
    }

    public function disconnect(): RedirectResponse
    {
        TiktokConnection::query()->delete();
        AuditService::log(action: 'disconnect_tiktok', targetType: 'tiktok');

        return redirect()->route('tiktok.index')->with('status', 'Koneksi TikTok diputus.');
    }

    /** Access token yang masih valid (refresh otomatis kalau mau expire). */
    private function freshToken(TiktokConnection $conn): string
    {
        if (! $conn->accessExpiringSoon()) {
            return $conn->access_token;
        }
        $token = $this->tiktok->refreshToken($conn->refresh_token);
        $conn->update([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $conn->refresh_token,
            'access_expires_at' => $this->toTime($token['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->toTime($token['refresh_token_expire_in'] ?? null),
        ]);

        return $token['access_token'];
    }

    /** TikTok kirim expiry sbg epoch detik (atau kadang detik-dari-sekarang). */
    private function toTime(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        $v = (int) $v;

        return $v > 1_000_000_000 ? Carbon::createFromTimestamp($v) : now()->addSeconds($v);
    }
}
