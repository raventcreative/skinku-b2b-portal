<?php

namespace App\Http\Controllers;

use App\Models\TiktokConnection;
use App\Models\TiktokOrder;
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
            $data = $this->tiktok->searchOrders($access, $conn->shop_cipher, 50);
            $count = $this->orders->store($data['orders'] ?? []);
            $conn->update(['last_synced_at' => now()]);

            return redirect()->route('tiktok.orders')->with('status', "Berhasil tarik & simpan {$count} order dari TikTok.");
        } catch (\Throwable $e) {
            return redirect()->route('tiktok.index')->with('error', 'Gagal tarik order: '.$e->getMessage());
        }
    }

    /** Daftar order TikTok + pratinjau dampak stok (read-only, belum memotong). */
    public function orderList()
    {
        $orders = TiktokOrder::latest('order_created_at')->latest('id')->paginate(25);
        $previews = $orders->mapWithKeys(fn ($o) => [$o->id => $this->orders->preview($o)]);

        return view('tiktok.orders', compact('orders', 'previews'));
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
