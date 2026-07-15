<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\TiktokConnection;
use App\Models\TiktokOrder;
use App\Models\TiktokReturn;
use App\Models\TiktokSettlement;
use App\Models\TiktokSkuMap;
use App\Services\AuditService;
use App\Services\TikTokClient;
use App\Services\TikTokOrderService;
use App\Services\TikTokReturnService;
use App\Services\TikTokSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TikTokController extends Controller
{
    public function __construct(
        private TikTokClient $tiktok,
        private TikTokOrderService $orders,
        private TikTokReturnService $returns,
        private TikTokSettlementService $settlements,
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

    /** Laporan konversi stok per item: Total / Terkirim / Dalam Perjalanan / Sisa. */
    public function stockFunnel()
    {
        return view('tiktok.stock', ['rows' => $this->orders->stockFunnel()]);
    }

    /* ---------------- Retur TikTok ---------------- */

    /** Tarik retur/refund terbaru dari TikTok → simpan (otomatis). */
    public function syncReturns(Request $request): RedirectResponse
    {
        $conn = TiktokConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok Shop.');

        try {
            $access = $this->freshToken($conn);
            $all = [];
            $token = '';
            $pages = 0;
            do {
                $data = $this->tiktok->searchReturns($access, $conn->shop_cipher, 50, $token);
                $all = array_merge($all, $data['return_orders'] ?? ($data['returns'] ?? []));
                $token = $data['next_page_token'] ?? '';
                $pages++;
            } while ($token && $pages < 10);

            $count = $this->returns->store($all);

            return redirect()->route('tiktok.returns')->with('status', "Berhasil tarik {$count} retur dari TikTok.");
        } catch (\Throwable $e) {
            return redirect()->route('tiktok.returns')->with('error', 'Gagal tarik retur: '.$e->getMessage().' (mungkin scope "Return" belum aktif di Partner Center)');
        }
    }

    /** Daftar retur + pratinjau + aksi review (approve/tolak). */
    public function returnList()
    {
        $returns = TiktokReturn::latest('return_created_at')->latest('id')->paginate(25);
        $previews = $returns->mapWithKeys(fn ($r) => [$r->id => $this->returns->preview($r)]);

        return view('tiktok.returns', compact('returns', 'previews'));
    }

    /** Approve: barang layak jual → tambah stok. */
    public function restockReturn(Request $request, TiktokReturn $ret): RedirectResponse
    {
        try {
            $this->returns->restock($ret, $request->user()->id, $request->input('note'));
            AuditService::log(action: 'tiktok_return_restock', targetType: 'tiktok_return', targetId: $ret->id);

            return back()->with('status', "Retur {$ret->tiktok_return_id}: stok ditambahkan (layak jual).");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal: '.$e->getMessage());
        }
    }

    /** Tolak: barang cacat → tidak masuk stok. */
    public function rejectReturn(Request $request, TiktokReturn $ret): RedirectResponse
    {
        $this->returns->reject($ret, $request->user()->id, $request->input('note'));
        AuditService::log(action: 'tiktok_return_reject', targetType: 'tiktok_return', targetId: $ret->id);

        return back()->with('status', "Retur {$ret->tiktok_return_id}: ditolak (cacat), stok tidak ditambah.");
    }

    /** Batalkan keputusan review (balik ke pending; tarik stok lagi jika perlu). */
    public function resetReturn(TiktokReturn $ret): RedirectResponse
    {
        $this->returns->resetReview($ret);

        return back()->with('status', "Retur {$ret->tiktok_return_id} dikembalikan ke status review.");
    }

    /** M3a — tarik daftar pencairan (settlement) dari Finance API + simpan (read-only). */
    public function syncSettlements(Request $request): RedirectResponse
    {
        $conn = TiktokConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok Shop.');

        try {
            $access = $this->freshToken($conn);
            $all = [];
            $token = '';
            $pages = 0;
            $firstKeys = [];
            do {
                $data = $this->tiktok->getStatements($access, $conn->shop_cipher, 50, $token);
                if ($pages === 0) {
                    $firstKeys = array_keys($data);
                }
                // Nama pembungkus bisa beda antar versi API — coba beberapa.
                $batch = $data['statements'] ?? ($data['statement_list'] ?? ($data['list'] ?? []));
                $all = array_merge($all, $batch);
                $token = $data['next_page_token'] ?? '';
                $pages++;
            } while ($token && $pages < 10);

            $count = $this->settlements->store($all);
            $conn->update(['last_synced_at' => now()]);

            if ($count === 0) {
                // Diagnostik: tampilkan struktur respons supaya nama field asli terlihat.
                $hint = $firstKeys ? implode(', ', $firstKeys) : 'kosong';

                return redirect()->route('tiktok.settlements')->with('status',
                    "0 pencairan tersimpan. Struktur respons TikTok: [{$hint}]. "
                    .'Kalau memang belum ada payout, ini wajar. Kalau ada key aneh, kirim ini ke Claude.');
            }

            return redirect()->route('tiktok.settlements')->with('status', "Berhasil tarik {$count} pencairan dari TikTok.");
        } catch (\Throwable $e) {
            return redirect()->route('tiktok.settlements')->with('error', 'Gagal tarik pencairan: '.$e->getMessage().' (pastikan scope "Finance" aktif di Partner Center)');
        }
    }

    /** Daftar pencairan (read-only, M3a). Jurnal menyusul di M3b/M3c. */
    public function settlementList()
    {
        $settlements = TiktokSettlement::latest('statement_time')->latest('id')->paginate(25);

        return view('tiktok.settlements', compact('settlements'));
    }

    /** Rincian 1 pencairan — tarik transaksi dari TikTok biar jenis potongan kelihatan. */
    public function settlementDetail(TiktokSettlement $settlement)
    {
        $conn = TiktokConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok Shop.');

        $transactions = null;
        $rawKeys = [];
        $error = null;
        try {
            $access = $this->freshToken($conn);
            $data = $this->tiktok->getStatementTransactions($access, $conn->shop_cipher, $settlement->tiktok_statement_id, 50);
            $rawKeys = array_keys($data);
            $transactions = $data['statement_transactions'] ?? ($data['transactions'] ?? ($data['list'] ?? []));
            // Sekalian isi keterangan (kind) supaya kolom di daftar terisi.
            if (is_array($transactions) && ! $settlement->kind) {
                $k = $this->settlements->deriveKind($transactions, $settlement);
                $settlement->update(['kind' => $k['label'], 'kind_raw' => $k['raw']]);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tiktok.settlement_detail', compact('settlement', 'transactions', 'rawKeys', 'error'));
    }

    /** Isi kolom keterangan untuk pencairan "potongan" yang belum berketerangan (bertahap). */
    public function describeSettlements(Request $request): RedirectResponse
    {
        $conn = TiktokConnection::latest('id')->first();
        abort_unless($conn && $conn->shop_cipher, 400, 'Belum terhubung ke TikTok Shop.');

        $batch = 60;
        $stale = ['Potongan lain', 'Penyesuaian TikTok'];
        $targets = TiktokSettlement::where(fn ($q) => $q->whereNull('kind')->orWhereIn('kind', $stale))
            ->orderByDesc('statement_time')->limit($batch)->get();

        $done = 0;
        $failed = 0;
        try {
            $access = $this->freshToken($conn);
            foreach ($targets as $s) {
                try {
                    $data = $this->tiktok->getStatementTransactions($access, $conn->shop_cipher, $s->tiktok_statement_id, 50);
                    $txns = $data['statement_transactions'] ?? ($data['transactions'] ?? ($data['list'] ?? []));
                    $k = $this->settlements->deriveKind(is_array($txns) ? $txns : [], $s);
                    $s->update(['kind' => $k['label'], 'kind_raw' => $k['raw']]);
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                }
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal ambil keterangan: '.$e->getMessage());
        }

        $remaining = TiktokSettlement::where(fn ($q) => $q->whereNull('kind')->orWhere('kind', 'Potongan lain'))->count();

        return back()->with('status', "Keterangan diisi: {$done}"
            .($failed ? ", {$failed} gagal" : '')
            .($remaining ? ". Masih {$remaining} belum berketerangan — klik lagi untuk lanjut." : '. Semua sudah berketerangan.'));
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
